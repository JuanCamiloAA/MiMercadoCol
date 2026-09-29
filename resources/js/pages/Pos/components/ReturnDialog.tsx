import { router } from '@inertiajs/react';
import { LockKeyIcon, WarningCircleIcon, WrenchIcon } from '@phosphor-icons/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { cn } from '@/lib/cn';
import { suggestedDisposition } from '@/lib/customer-returns';
import { date, dateTime, money, number } from '@/lib/format';
import type { Disposition, Option, ReturnableLine, ReturnableSale } from '@/types/customer-returns';

type Resolution = 'refund' | 'store_credit' | 'exchange' | 'rejected';

/** Lo que el cambio se lleva al tiquete: qué vuelve y cuánto vale para pagar lo nuevo. */
export interface ExchangeDraft {
    sale_id: number;
    sale_number: string;
    /** Lo que vale lo devuelto después de descontar lo que se debía de esa venta a crédito. */
    value: number;
    items: ReturnPayloadItem[];
    reason: string;
    customer: { id: number; name: string } | null;
}

// Alias y no interfaz: Inertia exige que lo que se envía admita cualquier clave.
export type ReturnPayloadItem = {
    sale_item_id: number;
    quantity: number;
    reason: string;
    disposition: Disposition | null;
};

interface LineState {
    quantity: number;
    reason: string;
    disposition: Disposition;
}

interface ReturnDialogProps {
    saleId: number | null;
    onClose: () => void;
    /** El cliente del tiquete, para abonarle saldo si la venta fue a consumidor final. */
    ticketCustomer: { id: number; name: string } | null;
    windowApprovedBy?: string;
    onRequestWindowApproval: (message: string) => void;
    onExchange: (draft: ExchangeDraft) => void;
    onDone: () => void;
}

const RESOLUTIONS: { value: Resolution; label: string; hint: string }[] = [
    { value: 'refund', label: 'Reembolso', hint: 'Plata de vuelta' },
    { value: 'store_credit', label: 'Saldo a favor', hint: 'Para otra compra' },
    { value: 'exchange', label: 'Cambio', hint: 'Se lleva otra cosa' },
    { value: 'rejected', label: 'No procede', hint: 'Queda documentado' },
];

const METHODS: Option[] = [
    { value: 'cash', label: 'Efectivo' },
    { value: 'card', label: 'Tarjeta' },
    { value: 'transfer', label: 'Transferencia' },
];

const q3 = (value: number) => Math.round(value * 1000) / 1000;
const unitNet = (line: ReturnableLine) => (line.quantity > 0 ? line.line_total / line.quantity : 0);

/**
 * Devolución en caja: se elige qué vuelve, por qué y a dónde va cada artículo, y cómo se le
 * responde al cliente. Queda un documento DV que se imprime. El cambio abre el tiquete para que
 * el cliente elija lo que se lleva; lo devuelto paga primero.
 */
export default function ReturnDialog({ saleId, onClose, ticketCustomer, windowApprovedBy, onRequestWindowApproval, onExchange, onDone }: ReturnDialogProps) {
    const [sale, setSale] = useState<ReturnableSale | null>(null);
    const [reasons, setReasons] = useState<Option[]>([]);
    const [wasteReasons, setWasteReasons] = useState<string[]>([]);
    const [lines, setLines] = useState<Record<number, LineState>>({});
    const [resolution, setResolution] = useState<Resolution>('refund');
    const [method, setMethod] = useState('cash');
    const [note, setNote] = useState('');
    const [sending, setSending] = useState(false);

    const load = useCallback(async (id: number) => {
        const response = await fetch(route('pos.sales.detail', id), { headers: { Accept: 'application/json' } });

        if (!response.ok) {
            toast.error('No se pudo cargar la venta.');
            onClose();

            return;
        }

        const payload = (await response.json()) as { sale: ReturnableSale; reasons: Option[]; wasteReasons: string[] };
        setSale(payload.sale);
        setReasons(payload.reasons);
        setWasteReasons(payload.wasteReasons);
        setLines({});
        setResolution('refund');
        setMethod('cash');
        setNote('');
    }, [onClose]);

    useEffect(() => {
        if (saleId !== null) {
            setSale(null);
            void load(saleId);
        }
    }, [saleId, load]);

    const lineOf = (id: number, fallback?: Partial<LineState>): LineState =>
        lines[id] ?? { quantity: 0, reason: fallback?.reason ?? 'changed_mind', disposition: fallback?.disposition ?? 'restock' };

    const setLine = (id: number, changes: Partial<LineState>) =>
        setLines((current) => {
            const next = { ...lineOf(id), ...changes };

            // Al cambiar el motivo se vuelve a proponer el destino, como lo hace el servidor.
            if (changes.reason && !changes.disposition) {
                next.disposition = suggestedDisposition(changes.reason, wasteReasons);
            }

            return { ...current, [id]: next };
        });

    /** Un servicio vuelve con sus artículos en proporción; se pueden bajar, no subir. */
    const setServiceQuantity = (item: ReturnableSale['items'][number], quantity: number) => {
        const value = Math.min(item.available, Math.max(0, quantity));
        const share = item.quantity > 0 ? value / item.quantity : 0;
        const parent = lineOf(item.id);

        setLines((current) => ({
            ...current,
            [item.id]: { ...parent, quantity: value },
            ...Object.fromEntries(
                item.components.map((component) => [
                    component.id,
                    {
                        quantity: Math.min(component.available, q3(component.quantity * share)),
                        reason: parent.reason,
                        disposition:
                            current[component.id]?.disposition ??
                            (component.returnable_by_default ? suggestedDisposition(parent.reason, wasteReasons) : ('none' as Disposition)),
                    },
                ]),
            ),
        }));
    };

    const payload = useMemo((): ReturnPayloadItem[] => {
        if (!sale) {
            return [];
        }

        return sale.items.flatMap((item) => {
            const line = lineOf(item.id);

            if (line.quantity <= 0) {
                return [];
            }

            return [
                { sale_item_id: item.id, quantity: line.quantity, reason: line.reason, disposition: item.product_id ? line.disposition : null },
                ...item.components
                    .map((component) => ({ component, state: lineOf(component.id) }))
                    .filter(({ state }) => state.quantity > 0)
                    .map(({ component, state }) => ({ sale_item_id: component.id, quantity: state.quantity, reason: line.reason, disposition: state.disposition })),
            ];
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [sale, lines]);

    const total = useMemo(() => {
        if (!sale) {
            return 0;
        }

        const all = sale.items.flatMap((item) => [item, ...item.components]);

        return payload.reduce((sum, entry) => {
            const line = all.find((candidate) => candidate.id === entry.sale_item_id);

            return sum + (line ? Math.round(entry.quantity * unitNet(line)) : 0);
        }, 0);
    }, [sale, payload]);

    if (saleId === null) {
        return null;
    }

    const relief = sale ? Math.min(total, sale.due_total) : 0;
    const value = total - relief;
    // El consumidor final no recibe saldo: si la venta fue a él, cuenta el cliente del tiquete.
    const customer = sale?.customer && !sale.customer.generic ? sale.customer : ticketCustomer;
    const outOfWindow = sale !== null && !sale.window.within;
    const blockedByWindow = outOfWindow && !windowApprovedBy;
    const needsCustomer = resolution === 'store_credit' && !customer;
    const canSubmit = sale !== null && payload.length > 0 && note.trim() !== '' && !blockedByWindow && !needsCustomer && !sending;

    const submit = () => {
        if (!sale || !canSubmit) {
            return;
        }

        if (resolution === 'exchange') {
            onExchange({ sale_id: sale.id, sale_number: sale.number, value, items: payload, reason: note.trim(), customer: customer ? { id: customer.id, name: customer.name } : null });
            onClose();

            return;
        }

        setSending(true);
        router.post(
            route('pos.sales.refund', sale.id),
            {
                items: payload,
                resolution,
                method,
                reason: note.trim(),
                customer_id: resolution === 'store_credit' ? customer?.id : null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    onDone();
                    onClose();
                },
                onError: (errors) => toast.error(Object.values(errors)[0] ?? 'No se pudo registrar la devolución.'),
                onFinish: () => setSending(false),
            },
        );
    };

    const confirmLabel =
        resolution === 'exchange'
            ? `Continuar al cambio · ${money(value)}`
            : resolution === 'rejected'
              ? 'Registrar que no procede'
              : resolution === 'store_credit'
                ? `Abonar ${money(value)}`
                : `Devolver ${money(value)}`;

    return (
        <Modal
            open
            onClose={onClose}
            size="xl"
            title={sale ? `Devolución de la venta ${sale.number}` : 'Devolución'}
            description={sale ? `${dateTime(sale.sold_at)} · ${sale.customer?.name ?? 'Consumidor final'} · ${sale.channel === 'online' ? 'Tienda en línea' : 'Caja'}` : 'Cargando…'}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    {blockedByWindow ? (
                        <Button
                            variant="warn"
                            icon={<LockKeyIcon size={16} />}
                            onClick={() =>
                                onRequestWindowApproval(
                                    `La venta tiene ${sale?.window.days} días y el plazo para devolver es de ${sale?.window.max}. Un supervisor debe autorizarla.`,
                                )
                            }
                        >
                            Pedir autorización
                        </Button>
                    ) : (
                        <Button variant={resolution === 'rejected' ? 'danger' : 'warn'} disabled={!canSubmit} loading={sending} onClick={submit}>
                            {confirmLabel}
                        </Button>
                    )}
                </>
            }
        >
            {!sale ? (
                <p className="py-6 text-center text-[0.8125rem] text-[var(--muted)]">Cargando la venta…</p>
            ) : (
                <div className="flex flex-col gap-4">
                    {outOfWindow && (
                        <p className="flex items-start gap-2 rounded-control bg-[var(--warn-soft)] px-3 py-2 text-[0.8125rem] text-[var(--warn)]">
                            <WarningCircleIcon size={16} weight="fill" className="mt-px shrink-0" />
                            {sale.window.days} días desde la {sale.channel === 'online' ? 'entrega' : 'venta'}; el plazo venció el {date(sale.window.until)}.
                            {windowApprovedBy ? ` Autorizó ${windowApprovedBy}.` : ' Necesita la autorización de un supervisor.'}
                        </p>
                    )}

                    <ul className="flex max-h-[18.75rem] flex-col gap-2 overflow-y-auto pr-1">
                        {sale.items.map((item) => {
                            const line = lineOf(item.id);
                            const disabled = item.available <= 0;

                            return (
                                <li key={item.id} className={cn('rounded-control border px-3 py-2.5', line.quantity > 0 ? 'border-[var(--warn)]' : 'border-[var(--line)]', disabled && 'opacity-55')}>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="min-w-0 flex-1">
                                            <span className="flex items-center gap-1 truncate text-[0.84375rem] font-medium">
                                                {item.is_service && <WrenchIcon size={13} className="shrink-0 text-[var(--accent)]" />}
                                                {item.description}
                                            </span>
                                            <span className="block font-mono text-[0.6875rem] text-[var(--muted)] tabular">
                                                vendidas {number(item.quantity)} · devueltas {number(item.refunded)}
                                                {item.held > 0 ? ` · ${number(item.held)} en otra devolución` : ''} · quedan {number(item.available)}
                                            </span>
                                        </span>
                                        <input
                                            type="number"
                                            min={0}
                                            max={item.available}
                                            step={item.sold_by_weight ? '0.001' : '1'}
                                            disabled={disabled}
                                            aria-label={`Cantidad a devolver de ${item.description}`}
                                            value={line.quantity}
                                            onChange={(event) =>
                                                item.is_service
                                                    ? setServiceQuantity(item, Number(event.target.value) || 0)
                                                    : setLine(item.id, { quantity: Math.min(item.available, Math.max(0, Number(event.target.value) || 0)) })
                                            }
                                            className="h-10 w-20 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2 text-right font-mono text-[0.8125rem] tabular focus:border-[var(--warn)] focus:outline-none"
                                        />
                                        {line.quantity > 0 && (
                                            <select
                                                aria-label={`Motivo de ${item.description}`}
                                                value={line.reason}
                                                onChange={(event) => {
                                                    setLine(item.id, { reason: event.target.value });
                                                    // Los artículos del servicio heredan el motivo y su destino.
                                                    item.components.forEach((component) =>
                                                        setLine(component.id, {
                                                            reason: event.target.value,
                                                            disposition: component.returnable_by_default ? suggestedDisposition(event.target.value, wasteReasons) : 'none',
                                                        }),
                                                    );
                                                }}
                                                className="h-10 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2 text-[0.78125rem] focus:border-[var(--warn)] focus:outline-none"
                                            >
                                                {reasons.map((reason) => (
                                                    <option key={reason.value} value={reason.value}>
                                                        {reason.label}
                                                    </option>
                                                ))}
                                            </select>
                                        )}
                                        {line.quantity > 0 && item.product_id && resolution !== 'rejected' && (
                                            <DispositionSelect
                                                label={`Destino de ${item.description}`}
                                                value={line.disposition}
                                                allowNone={false}
                                                onChange={(value) => setLine(item.id, { disposition: value })}
                                            />
                                        )}
                                    </div>

                                    {item.is_service && line.quantity > 0 && item.components.length > 0 && (
                                        <ul className="mt-2 flex flex-col gap-1 border-l-2 border-[var(--line)] pl-3">
                                            {item.components.map((component) => {
                                                const state = lineOf(component.id);
                                                const share = item.quantity > 0 ? line.quantity / item.quantity : 0;
                                                const max = Math.min(component.available, q3(component.quantity * share));

                                                return (
                                                    <li key={component.id} className="flex flex-wrap items-center gap-2 text-[0.75rem]">
                                                        <span className="min-w-0 flex-1 truncate">
                                                            {component.description}
                                                            {item.pricing_mode === 'itemized' && component.line_total > 0 && (
                                                                <span className="text-[var(--muted)]"> · devuelve su valor</span>
                                                            )}
                                                        </span>
                                                        <input
                                                            type="number"
                                                            min={0}
                                                            max={max}
                                                            step="0.001"
                                                            aria-label={`Cantidad de ${component.description}`}
                                                            value={state.quantity}
                                                            onChange={(event) => setLine(component.id, { quantity: Math.min(max, Math.max(0, Number(event.target.value) || 0)) })}
                                                            className="h-9 w-20 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2 text-right font-mono text-[0.75rem] tabular focus:border-[var(--warn)] focus:outline-none"
                                                        />
                                                        {resolution !== 'rejected' && (
                                                            <DispositionSelect
                                                                label={`Destino de ${component.description}`}
                                                                value={state.disposition}
                                                                allowNone
                                                                onChange={(value) => setLine(component.id, { disposition: value })}
                                                            />
                                                        )}
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    )}
                                </li>
                            );
                        })}
                    </ul>

                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        {RESOLUTIONS.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                onClick={() => setResolution(option.value)}
                                className={cn(
                                    'rounded-control border p-2.5 text-left transition-colors',
                                    resolution === option.value ? 'border-[var(--warn)] bg-[var(--warn-soft)]' : 'border-[var(--line)] bg-[var(--surface-2)] hover:border-[var(--warn)]',
                                )}
                            >
                                <span className="block text-[0.8125rem] font-semibold">{option.label}</span>
                                <span className="block text-[0.6875rem] text-[var(--muted)]">{option.hint}</span>
                            </button>
                        ))}
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {resolution === 'refund' && (
                            <select
                                aria-label="Medio de la devolución"
                                value={method}
                                onChange={(event) => setMethod(event.target.value)}
                                className="h-10 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2 text-[0.8125rem] focus:border-[var(--warn)] focus:outline-none"
                            >
                                {METHODS.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        )}
                        <input
                            type="text"
                            maxLength={160}
                            value={note}
                            onChange={(event) => setNote(event.target.value)}
                            placeholder={resolution === 'rejected' ? 'Por qué no procede' : 'Motivo o lo que contó el cliente'}
                            className="h-10 min-w-[12.5rem] flex-1 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2 text-[0.8125rem] focus:border-[var(--warn)] focus:outline-none"
                        />
                        <span className="font-mono text-[1.125rem] font-semibold tabular">{money(total)}</span>
                    </div>

                    <p className="text-[0.75rem] text-[var(--muted)]">
                        {resolution === 'refund' && (method === 'cash' ? 'Sale de la gaveta de este turno.' : 'Tarjeta y transferencia se reversan por fuera; aquí queda registrado.')}
                        {resolution === 'store_credit' &&
                            (customer ? `Se le abona a ${customer.name}; lo usa en caja o en la tienda en línea.` : 'Elige el cliente del tiquete (F2) para abonarle el saldo.')}
                        {resolution === 'exchange' && 'Se abre el tiquete para que agregue lo que se lleva: lo devuelto paga primero y, si sobra, se le devuelve.'}
                        {resolution === 'rejected' && 'No se mueve inventario ni plata: queda el documento con el motivo.'}
                        {relief > 0 && resolution !== 'rejected' && ` Primero se descuentan ${money(relief)} de lo que debía de esa venta.`}
                    </p>
                </div>
            )}
        </Modal>
    );
}

function DispositionSelect({ label, value, allowNone, onChange }: { label: string; value: Disposition; allowNone: boolean; onChange: (value: Disposition) => void }) {
    return (
        <select
            aria-label={label}
            value={value}
            onChange={(event) => onChange(event.target.value as Disposition)}
            className={cn(
                'h-9 rounded-control border bg-[var(--surface)] px-2 text-[0.75rem] focus:outline-none',
                value === 'waste' ? 'border-[var(--danger)] text-[var(--danger)]' : 'border-[var(--line)]',
            )}
        >
            <option value="restock">Vuelve al inventario</option>
            <option value="waste">Avería</option>
            {allowNone && <option value="none">No se recibe</option>}
        </select>
    );
}

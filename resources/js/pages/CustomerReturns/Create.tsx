import { Link, router, useForm } from '@inertiajs/react';
import { ArrowUUpLeftIcon, MagnifyingGlassIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { type FormEvent, type ReactNode, useMemo, useState } from 'react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Checkbox, Input } from '@/components/ui/Field';
import { EmptyState, Table } from '@/components/ui/Table';
import { PageHeader } from '@/components/ui/Misc';
import AppLayout from '@/layouts/AppLayout';
import { suggestedDisposition } from '@/lib/customer-returns';
import { date, dateTime, money, number } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { Disposition, Option, ReturnableSale } from '@/types/customer-returns';

interface CreateProps {
    search: string;
    notFound: boolean;
    cancelled: string | null;
    sale: ReturnableSale | null;
    reasons: Option[];
    wasteReasons: string[];
    canApprove: boolean;
}

interface Line {
    quantity: number;
    reason: string;
    disposition: Disposition;
}

const DISPOSITIONS: { value: Disposition; label: string }[] = [
    { value: 'restock', label: 'Vuelve al inventario' },
    { value: 'waste', label: 'Avería' },
    { value: 'none', label: 'No se recibe (consumido)' },
];

/**
 * Registrar desde el panel una devolución de cualquier venta: se busca la venta, se elige qué
 * vuelve y por qué. Si la mercancía ya está en la sede, queda recibida con su destino; si no,
 * queda aprobada esperándola. La plata o el saldo se entregan al resolver.
 */
export default function CustomerReturnCreate({ search, notFound, cancelled, sale, reasons, wasteReasons, canApprove }: CreateProps) {
    const { can } = usePermission();
    const [term, setTerm] = useState(search);
    const [lines, setLines] = useState<Record<number, Line>>({});
    const form = useForm<{ received: boolean; reason: string }>({ received: true, reason: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});

    const lookup = (event: FormEvent) => {
        event.preventDefault();
        router.get(route('customer_returns.create'), { numero: term.trim() }, { preserveState: false });
    };

    const lineFor = (id: number, fallbackReason = 'changed_mind'): Line => lines[id] ?? { quantity: 0, reason: fallbackReason, disposition: suggestedDisposition(fallbackReason, wasteReasons) };

    const setLine = (id: number, changes: Partial<Line>) =>
        setLines((current) => {
            const previous = current[id] ?? { quantity: 0, reason: 'changed_mind', disposition: 'restock' as Disposition };
            const next = { ...previous, ...changes };

            // Al cambiar el motivo se vuelve a proponer el destino, como lo hace el servidor.
            if (changes.reason && !changes.disposition) {
                next.disposition = suggestedDisposition(changes.reason, wasteReasons);
            }

            return { ...current, [id]: next };
        });

    const payloadItems = useMemo(() => {
        if (!sale) {
            return [];
        }

        return sale.items.flatMap((item) => {
            const line = lineFor(item.id);

            if (line.quantity <= 0) {
                return [];
            }

            const share = item.quantity > 0 ? line.quantity / item.quantity : 0;

            return [
                { sale_item_id: item.id, quantity: line.quantity, reason: line.reason, disposition: item.product_id ? line.disposition : null },
                // Los artículos de un servicio vuelven con él, en proporción.
                ...item.components.map((component) => {
                    const own = lines[component.id];

                    return {
                        sale_item_id: component.id,
                        quantity: Math.round(component.quantity * share * 1000) / 1000,
                        reason: line.reason,
                        disposition: own?.disposition ?? (component.returnable_by_default ? suggestedDisposition(line.reason, wasteReasons) : 'none'),
                    };
                }),
            ];
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [sale, lines, wasteReasons]);

    const total = useMemo(() => {
        if (!sale) {
            return 0;
        }

        return sale.items.reduce((sum, item) => {
            const quantity = lineFor(item.id).quantity;
            const unit = item.quantity > 0 ? item.line_total / item.quantity : 0;
            const components = item.components.reduce((acc, component) => acc + (item.quantity > 0 ? (component.line_total * quantity) / item.quantity : 0), 0);

            return sum + Math.round(quantity * unit) + Math.round(components);
        }, 0);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [sale, lines]);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!sale) {
            return;
        }

        router.post(
            route('customer_returns.store'),
            { sale_id: sale.id, received: form.data.received, reason: form.data.reason, items: payloadItems },
            {
                preserveScroll: true,
                onStart: () => form.clearErrors(),
                onError: (bag) => setErrors(bag as Record<string, string>),
            },
        );
    };

    const firstError = Object.values(errors)[0];

    return (
        <AppLayout title="Registrar devolución">
            <PageHeader
                title="Registrar devolución de venta"
                description="Busca la venta, elige qué vuelve y por qué. La plata o el saldo a favor se entregan al resolverla."
                breadcrumb={
                    <ButtonLink href={route('customer_returns.index')} size="sm" variant="ghost">
                        Volver a devoluciones
                    </ButtonLink>
                }
            />

            <Card className="mb-4">
                <form onSubmit={lookup} className="flex flex-wrap items-end gap-3">
                    <Input
                        label="Número de la venta"
                        placeholder="Ej: C1-000123"
                        prefix={<MagnifyingGlassIcon size={16} />}
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                        containerClassName="w-full sm:w-80"
                    />
                    <Button type="submit" variant="secondary">
                        Buscar
                    </Button>
                </form>
                {notFound && <p className="mt-2 text-[0.8125rem] text-[var(--danger)]">No encontramos esa venta en esta empresa.</p>}
                {cancelled && <p className="mt-2 text-[0.8125rem] text-[var(--danger)]">La venta {cancelled} está anulada: no hay nada que devolver.</p>}
            </Card>

            {!sale ? (
                <EmptyState
                    title="Busca la venta que el cliente quiere devolver"
                    description="Está en el tiquete o en el historial de ventas. Las de la tienda en línea también aparecen en el pedido."
                    icon={<ArrowUUpLeftIcon size={24} />}
                />
            ) : (
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <Card>
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="text-[0.8125rem]">
                                <p className="font-mono text-[1rem] font-semibold">
                                    {can('sales.index.view') ? (
                                        <Link href={route('sales.show', sale.id)} className="text-[var(--accent)] hover:underline">
                                            {sale.number}
                                        </Link>
                                    ) : (
                                        sale.number
                                    )}
                                </p>
                                <p className="text-[var(--muted)]">
                                    {dateTime(sale.sold_at)} · {sale.channel === 'online' ? 'Tienda en línea' : 'Caja'}
                                    {sale.order ? ` · pedido #${sale.order.number}` : ''}
                                </p>
                                <p>{sale.customer?.name ?? 'Consumidor final'}</p>
                            </div>
                            <div className="text-right text-[0.8125rem]">
                                <p className="font-mono text-[1rem] font-semibold tabular">{money(sale.total)}</p>
                                <p className="text-[var(--muted)]">{sale.payments.map((payment) => payment.label).join(' + ')}</p>
                            </div>
                        </div>
                        {!sale.window.within && (
                            <p className="mt-3 flex items-start gap-2 text-[0.8125rem] text-[var(--warn)]">
                                <WarningCircleIcon size={16} weight="fill" className="mt-px shrink-0" />
                                {sale.window.days} días desde la {sale.channel === 'online' ? 'entrega' : 'venta'}; el plazo es de {sale.window.max} (venció el{' '}
                                {date(sale.window.until)}).{' '}
                                {canApprove ? 'Al registrarla quedas como quien la autoriza.' : 'Necesitas el permiso de aprobar devoluciones fuera de plazo.'}
                            </p>
                        )}
                        {sale.due_total > 0 && (
                            <p className="mt-2 text-[0.78125rem] text-[var(--muted)]">
                                Esa venta tiene {money(sale.due_total)} a crédito: lo devuelto se descuenta primero de la deuda.
                            </p>
                        )}
                    </Card>

                    <Card padded={false}>
                        <div className="px-5 pt-5">
                            <CardHeader title="¿Qué devuelve?" description="Por artículo: cuánto y por qué. Un servicio vuelve con sus artículos, en proporción." />
                        </div>
                        <Table className="mt-4 rounded-none border-x-0 border-b-0">
                            <thead className="bg-[var(--surface-2)] text-left">
                                <tr className="text-[0.75rem] uppercase tracking-wide text-[var(--muted)]">
                                    <th className="px-4 py-2.5 font-semibold">Artículo</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Quedan</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Devuelve</th>
                                    <th className="px-4 py-2.5 font-semibold">Motivo</th>
                                    {form.data.received && <th className="px-4 py-2.5 font-semibold">Destino</th>}
                                </tr>
                            </thead>
                            <tbody>
                                {sale.items.map((item) => {
                                    const line = lineFor(item.id);
                                    const share = item.quantity > 0 ? line.quantity / item.quantity : 0;

                                    return (
                                        <ItemRow
                                            key={item.id}
                                            description={item.description}
                                            detail={`Vendidas ${number(item.quantity)}${item.refunded > 0 ? ` · devueltas ${number(item.refunded)}` : ''}${item.held > 0 ? ` · ${number(item.held)} en otra devolución abierta` : ''}`}
                                            available={item.available}
                                            quantity={line.quantity}
                                            onQuantity={(value) => setLine(item.id, { quantity: Math.min(item.available, Math.max(0, value)) })}
                                            step={item.sold_by_weight ? '0.001' : '1'}
                                            reason={line.reason}
                                            reasons={reasons}
                                            onReason={(value) => setLine(item.id, { reason: value })}
                                            showDisposition={form.data.received}
                                            disposition={item.product_id ? line.disposition : null}
                                            dispositions={DISPOSITIONS.filter((option) => option.value !== 'none')}
                                            onDisposition={(value) => setLine(item.id, { disposition: value })}
                                        >
                                            {line.quantity > 0 &&
                                                item.components.map((component) => {
                                                    const own = lines[component.id];
                                                    const value: Disposition = own?.disposition ?? (component.returnable_by_default ? suggestedDisposition(line.reason, wasteReasons) : 'none');

                                                    return (
                                                        <tr key={component.id} className="text-[0.78125rem] text-[var(--muted)]">
                                                            <td className="px-4 py-1.5 pl-9">· {component.description}</td>
                                                            <td />
                                                            <td className="px-4 py-1.5 text-right font-mono tabular">{number(Math.round(component.quantity * share * 1000) / 1000)}</td>
                                                            <td />
                                                            {form.data.received && (
                                                                <td className="px-4 py-1.5">
                                                                    <select
                                                                        aria-label={`Destino de ${component.description}`}
                                                                        value={value}
                                                                        onChange={(event) =>
                                                                            setLines((current) => ({
                                                                                ...current,
                                                                                [component.id]: { quantity: 0, reason: line.reason, disposition: event.target.value as Disposition },
                                                                            }))
                                                                        }
                                                                        className="h-8 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2 text-[0.75rem] focus:border-[var(--accent)] focus:outline-none"
                                                                    >
                                                                        {DISPOSITIONS.map((option) => (
                                                                            <option key={option.value} value={option.value}>
                                                                                {option.label}
                                                                            </option>
                                                                        ))}
                                                                    </select>
                                                                </td>
                                                            )}
                                                        </tr>
                                                    );
                                                })}
                                        </ItemRow>
                                    );
                                })}
                            </tbody>
                        </Table>
                    </Card>

                    <Card>
                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="flex flex-col gap-3">
                                <Checkbox
                                    label="La mercancía ya está en la sede"
                                    hint="Queda recibida y se decide aquí a dónde va cada artículo. Si no, queda aprobada esperándola."
                                    checked={form.data.received}
                                    onChange={(event) => form.setData('received', event.target.checked)}
                                />
                                <Input
                                    label="Nota"
                                    placeholder="Opcional: lo que contó el cliente"
                                    maxLength={160}
                                    value={form.data.reason}
                                    onChange={(event) => form.setData('reason', event.target.value)}
                                />
                            </div>
                            <div className="flex flex-col items-end justify-end gap-2">
                                <p className="text-[0.8125rem] text-[var(--muted)]">Valor de lo que vuelve</p>
                                <p className="font-mono text-[1.625rem] font-semibold text-[var(--accent)] tabular">{money(total)}</p>
                                {firstError && <p className="text-right text-[0.8125rem] font-medium text-[var(--danger)]">{firstError}</p>}
                                <Button type="submit" disabled={payloadItems.length === 0 || (!sale.window.within && !canApprove)}>
                                    Registrar devolución
                                </Button>
                            </div>
                        </div>
                    </Card>
                </form>
            )}
        </AppLayout>
    );
}

interface ItemRowProps {
    description: string;
    detail: string;
    available: number;
    quantity: number;
    onQuantity: (value: number) => void;
    step: string;
    reason: string;
    reasons: Option[];
    onReason: (value: string) => void;
    showDisposition: boolean;
    disposition: Disposition | null;
    dispositions: { value: Disposition; label: string }[];
    onDisposition: (value: Disposition) => void;
    children?: ReactNode;
}

function ItemRow({ description, detail, available, quantity, onQuantity, step, reason, reasons, onReason, showDisposition, disposition, dispositions, onDisposition, children }: ItemRowProps) {
    const control = 'h-9 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2 text-[0.8125rem] focus:border-[var(--accent)] focus:outline-none disabled:opacity-50';

    return (
        <>
            <tr className="border-t border-[var(--line)]">
                <td className="px-4 py-2.5">
                    <p className="text-[0.8125rem] font-medium">{description}</p>
                    <p className="text-[0.71875rem] text-[var(--muted)]">{detail}</p>
                </td>
                <td className="px-4 py-2.5 text-right font-mono text-[0.8125rem] tabular">{number(available)}</td>
                <td className="px-4 py-2.5 text-right">
                    <input
                        type="number"
                        min={0}
                        max={available}
                        step={step}
                        disabled={available <= 0}
                        aria-label={`Cantidad a devolver de ${description}`}
                        value={quantity}
                        onChange={(event) => onQuantity(Number(event.target.value) || 0)}
                        className={`${control} w-20 text-right font-mono tabular`}
                    />
                </td>
                <td className="px-4 py-2.5">
                    <select aria-label={`Motivo de ${description}`} value={reason} disabled={quantity <= 0} onChange={(event) => onReason(event.target.value)} className={control}>
                        {reasons.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </td>
                {showDisposition && (
                    <td className="px-4 py-2.5">
                        {disposition && (
                            <select
                                aria-label={`Destino de ${description}`}
                                value={disposition}
                                disabled={quantity <= 0}
                                onChange={(event) => onDisposition(event.target.value as Disposition)}
                                className={control}
                            >
                                {dispositions.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        )}
                    </td>
                )}
            </tr>
            {children}
        </>
    );
}

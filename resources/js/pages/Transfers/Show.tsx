import { Link, router, useForm } from '@inertiajs/react';
import { ArrowRightIcon, ArrowsLeftRightIcon, TruckIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { type FormEvent, useState } from 'react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { ConfirmDialog, Modal } from '@/components/ui/Modal';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { dateTime, money, number, UNIT_SHORT } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { TransferDetail, TransferItem, TransferStatus } from '@/types/transfers';

interface TransferShowProps {
    transfer: TransferDetail;
    items: TransferItem[];
    can: { dispatch: boolean; direct: boolean; receive: boolean; cancel: boolean };
}

const TONES: Record<TransferStatus, 'neutral' | 'warn' | 'accent' | 'danger'> = {
    draft: 'neutral',
    in_transit: 'warn',
    received: 'accent',
    cancelled: 'danger',
};

/**
 * Un traslado, con su historia.
 *
 * Dos actos, cada uno de su bodega: la de origen despacha y la de destino recibe. Mientras
 * está en camino la mercancía no cuenta en ninguna bodega. Por eso la recepción se pide
 * renglón por renglón: lo que se despachó y no llegó queda como avería con el número del
 * traslado, en vez de desaparecer sin que nadie lo note.
 */
export default function TransferShow({ transfer, items, can: allowed }: TransferShowProps) {
    const { can } = usePermission();
    const [receiving, setReceiving] = useState(false);
    const [cancelling, setCancelling] = useState(false);

    const form = useForm({
        items: items.map((item) => ({ id: item.id, received_quantity: item.quantity })),
    });

    const setReceived = (id: number, quantity: number) =>
        form.setData(
            'items',
            form.data.items.map((row) =>
                row.id === id ? { ...row, received_quantity: Number.isFinite(quantity) ? Math.max(0, quantity) : 0 } : row,
            ),
        );

    const submitReceive = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('transfers.receive', transfer.id), { preserveScroll: true, onSuccess: () => setReceiving(false) });
    };

    const dispatchNow = () =>
        router.post(route('transfers.dispatch', transfer.id), {}, { preserveScroll: true });

    const moveDirect = () => router.post(route('transfers.direct', transfer.id), {}, { preserveScroll: true });

    const cancel = () =>
        router.post(route('transfers.cancel', transfer.id), {}, { preserveScroll: true, onSuccess: () => setCancelling(false) });

    const missing = items.reduce((sum, item) => sum + item.missing, 0);

    return (
        <AppLayout title={`Traslado ${transfer.number}`}>
            <PageHeader
                title={`Traslado ${transfer.number}`}
                description={`${transfer.origin?.name ?? '—'} → ${transfer.destination?.name ?? '—'}`}
                breadcrumb={
                    <ButtonLink href={route('transfers.index')} size="sm" variant="ghost">
                        Volver a traslados
                    </ButtonLink>
                }
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge tone={TONES[transfer.status]}>{transfer.status_label}</Badge>
                        {allowed.dispatch && can('inventory.transfers.dispatch') && (
                            <Button onClick={dispatchNow} icon={<TruckIcon size={16} />}>
                                Despachar
                            </Button>
                        )}
                        {allowed.direct && can('inventory.transfers.direct') && (
                            <Button variant="secondary" onClick={moveDirect} icon={<ArrowsLeftRightIcon size={16} />}>
                                Trasladar directo
                            </Button>
                        )}
                        {allowed.receive && can('inventory.transfers.receive') && (
                            <Button onClick={() => setReceiving(true)}>Recibir</Button>
                        )}
                        {allowed.cancel && can('inventory.transfers.cancel') && (
                            <Button variant="ghost" onClick={() => setCancelling(true)}>
                                Anular
                            </Button>
                        )}
                    </div>
                }
            />

            {transfer.status === 'draft' && (
                <Card className="mb-4">
                    <p className="text-[0.8125rem] text-[var(--muted)]">
                        Todavía no ha salido nada de {transfer.origin?.name}. Cuando lo despachen, quedará en camino hasta que lo
                        reciban en {transfer.destination?.name}.
                    </p>
                </Card>
            )}

            {transfer.status === 'in_transit' && (
                <Card className="mb-4">
                    <p className="flex items-start gap-2 text-[0.8125rem] font-medium text-[var(--warn)]">
                        <WarningCircleIcon size={16} weight="fill" className="mt-px shrink-0" />
                        Esta mercancía va en camino: no cuenta en el inventario de ninguna de las dos bodegas hasta que alguien la
                        reciba en {transfer.destination?.name}.
                    </p>
                </Card>
            )}

            <div className="mb-4 grid gap-4 lg:grid-cols-3">
                <Card>
                    <CardHeader title="Ruta" />
                    <div className="flex items-center gap-2 text-[0.8125rem]">
                        <div>
                            <p className="font-medium">{transfer.origin?.name}</p>
                            <p className="text-[0.71875rem] text-[var(--muted)]">{transfer.origin?.store_name}</p>
                        </div>
                        <ArrowRightIcon size={16} className="text-[var(--muted)]" />
                        <div>
                            <p className="font-medium">{transfer.destination?.name}</p>
                            <p className="text-[0.71875rem] text-[var(--muted)]">{transfer.destination?.store_name}</p>
                        </div>
                    </div>
                    {transfer.note && <p className="mt-3 text-[0.78125rem] text-[var(--muted)]">{transfer.note}</p>}
                    {transfer.request && (
                        <p className="mt-2 text-[0.78125rem] text-[var(--muted)]">
                            Sale de la solicitud{' '}
                            {can('inventory.transfer_requests.view') ? (
                                <Link
                                    href={route('transfer_requests.show', transfer.request.id)}
                                    className="font-mono font-medium text-[var(--accent)] hover:underline"
                                >
                                    {transfer.request.number}
                                </Link>
                            ) : (
                                <span className="font-mono font-medium text-[var(--text)]">{transfer.request.number}</span>
                            )}
                        </p>
                    )}
                </Card>

                <Card>
                    <CardHeader title="Quién y cuándo" />
                    <dl className="flex flex-col gap-1.5 text-[0.78125rem]">
                        <div className="flex justify-between gap-2">
                            <dt className="text-[var(--muted)]">Creado</dt>
                            <dd className="text-right">
                                {transfer.created_by ?? '—'}
                                {transfer.created_at ? ` · ${dateTime(transfer.created_at)}` : ''}
                            </dd>
                        </div>
                        <div className="flex justify-between gap-2">
                            <dt className="text-[var(--muted)]">Despachado</dt>
                            <dd className="text-right">
                                {transfer.dispatched_at
                                    ? `${transfer.dispatched_by ?? '—'} · ${dateTime(transfer.dispatched_at)}`
                                    : 'Todavía no'}
                            </dd>
                        </div>
                        <div className="flex justify-between gap-2">
                            <dt className="text-[var(--muted)]">Recibido</dt>
                            <dd className="text-right">
                                {transfer.received_at
                                    ? `${transfer.received_by ?? '—'} · ${dateTime(transfer.received_at)}`
                                    : 'Todavía no'}
                            </dd>
                        </div>
                    </dl>
                </Card>

                <Card>
                    <CardHeader title="Valor al costo" />
                    <p className="font-mono text-[1.75rem] font-semibold leading-none text-[var(--accent)] tabular">
                        {money(transfer.value)}
                    </p>
                    {missing > 0 && (
                        <p className="mt-2 text-[0.78125rem] font-medium text-[var(--danger)]">
                            Faltaron {number(missing)} unidades: quedaron dadas de baja como avería.
                        </p>
                    )}
                </Card>
            </div>

            <Card padded={false}>
                <Table>
                    <THead>
                        <TR>
                            <TH>Artículo</TH>
                            <TH align="right">Despachado</TH>
                            <TH align="right">Recibido</TH>
                            <TH align="right">Faltó</TH>
                            <TH align="right">Valor</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {items.map((item) => (
                            <TR key={item.id}>
                                <TD>
                                    <p className="text-[0.84375rem] font-medium">{item.name}</p>
                                    <p className="font-mono text-[0.71875rem] text-[var(--muted)]">{item.sku}</p>
                                </TD>
                                <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                    {number(item.quantity)} {UNIT_SHORT[item.unit ?? 'unit'] ?? ''}
                                </TD>
                                <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                    {transfer.status === 'received' ? number(item.received_quantity) : '—'}
                                </TD>
                                <TD
                                    align="right"
                                    className="font-mono text-[0.8125rem] tabular"
                                    style={{ color: item.missing > 0 ? 'var(--danger)' : undefined }}
                                >
                                    {item.missing > 0 ? number(item.missing) : '—'}
                                </TD>
                                <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                    {money(Math.round(item.quantity * item.unit_cost))}
                                </TD>
                            </TR>
                        ))}
                    </TBody>
                </Table>
            </Card>

            <Modal
                open={receiving}
                onClose={() => setReceiving(false)}
                title="Recibir el traslado"
                description={`Lo que de verdad llegó a ${transfer.destination?.name}`}
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setReceiving(false)}>
                            Cancelar
                        </Button>
                        <Button onClick={submitReceive} loading={form.processing}>
                            Recibir
                        </Button>
                    </>
                }
            >
                <form onSubmit={submitReceive} className="flex flex-col gap-3">
                    <p className="text-[0.78125rem] text-[var(--muted)]">
                        Viene prellenado con lo que se despachó. Lo que corrijas hacia abajo queda como avería con el número del
                        traslado: no desaparece sin explicación.
                    </p>

                    {items.map((item) => {
                        const row = form.data.items.find((entry) => entry.id === item.id);

                        return (
                            <label key={item.id} className="flex items-center justify-between gap-3">
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[0.8125rem] font-medium">{item.name}</span>
                                    <span className="block text-[0.71875rem] text-[var(--muted)]">
                                        Se despacharon {number(item.quantity)} {UNIT_SHORT[item.unit ?? 'unit'] ?? ''}
                                    </span>
                                </span>
                                <input
                                    type="number"
                                    min={0}
                                    max={item.quantity}
                                    step="any"
                                    value={row?.received_quantity ?? 0}
                                    onChange={(event) => setReceived(item.id, Number(event.target.value))}
                                    className="h-11 w-24 shrink-0 rounded-control border border-[var(--line)] bg-[var(--surface-2)] text-center font-mono text-[0.9375rem] tabular focus:border-[var(--accent)] focus:outline-none"
                                />
                            </label>
                        );
                    })}
                </form>
            </Modal>

            <ConfirmDialog
                open={cancelling}
                onClose={() => setCancelling(false)}
                onConfirm={cancel}
                title="Anular el traslado"
                message={
                    transfer.request
                        ? `Todavía no ha salido mercancía, así que anularlo no mueve inventario. Lo que llevaba vuelve a quedar pendiente en la solicitud ${transfer.request.number}.`
                        : 'Todavía no ha salido mercancía, así que anularlo no mueve inventario.'
                }
                confirmLabel="Anular"
                tone="danger"
            />
        </AppLayout>
    );
}

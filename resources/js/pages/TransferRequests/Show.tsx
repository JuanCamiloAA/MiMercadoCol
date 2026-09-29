import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeftIcon, ArrowsLeftRightIcon, TruckIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { type FormEvent, useState } from 'react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Textarea } from '@/components/ui/Field';
import { ConfirmDialog, Modal } from '@/components/ui/Modal';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { dateTime, money, number, UNIT_SHORT } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type {
    TransferRequestDetail,
    TransferRequestItem,
    TransferRequestStatus,
    TransferRequestTransfer,
    TransferStatus,
} from '@/types/transfers';

interface TransferRequestShowProps {
    request: TransferRequestDetail;
    items: TransferRequestItem[];
    transfers: TransferRequestTransfer[];
    can: { fulfill: boolean; reject: boolean; close: boolean; cancel: boolean };
}

const TONES: Record<TransferRequestStatus, 'neutral' | 'warn' | 'accent' | 'danger'> = {
    pending: 'warn',
    partial: 'warn',
    fulfilled: 'accent',
    rejected: 'danger',
    closed: 'neutral',
    cancelled: 'neutral',
};

const TRANSFER_TONES: Record<TransferStatus, 'neutral' | 'warn' | 'accent' | 'danger'> = {
    draft: 'neutral',
    in_transit: 'warn',
    received: 'accent',
    cancelled: 'danger',
};

/**
 * Una solicitud de traslado.
 *
 * Quien surte la atiende generando un traslado con lo que de verdad puede mandar; lo que falte
 * queda pendiente y se puede atender después, en otro traslado. Si no va a mandar nada, la
 * rechaza; si ya mandó una parte y no va a mandar el resto, la cierra. En los dos casos dice
 * por qué, porque del otro lado alguien está esperando esa mercancía.
 */
export default function TransferRequestShow({ request, items, transfers, can: allowed }: TransferRequestShowProps) {
    const { can } = usePermission();
    const [fulfilling, setFulfilling] = useState(false);
    const [rejecting, setRejecting] = useState(false);
    const [closing, setClosing] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const [sending, setSending] = useState<'draft' | 'dispatch' | null>(null);

    const pendingItems = items.filter((item) => item.pending > 0);

    // Se propone mandar lo que falta, sin pasar de lo que hay: es lo que se podría despachar ya.
    const fulfillForm = useForm({
        items: pendingItems.map((item) => ({ id: item.id, quantity: Math.max(0, Math.min(item.pending, item.origin_available)) })),
    });

    const rejectForm = useForm({ reason: '' });
    const closeForm = useForm({ reason: '' });

    const setToSend = (id: number, quantity: number) =>
        fulfillForm.setData(
            'items',
            fulfillForm.data.items.map((row) =>
                row.id === id ? { ...row, quantity: Number.isFinite(quantity) ? Math.max(0, quantity) : 0 } : row,
            ),
        );

    const submitFulfill = (dispatch: boolean) => {
        setSending(dispatch ? 'dispatch' : 'draft');
        fulfillForm.transform((data) => ({ ...data, dispatch }));
        fulfillForm.post(route('transfer_requests.fulfill', request.id), { preserveScroll: true, onFinish: () => setSending(null) });
    };

    const submitReject = (event: FormEvent) => {
        event.preventDefault();
        rejectForm.post(route('transfer_requests.reject', request.id), { preserveScroll: true, onSuccess: () => setRejecting(false) });
    };

    const submitClose = (event: FormEvent) => {
        event.preventDefault();
        closeForm.post(route('transfer_requests.close', request.id), { preserveScroll: true, onSuccess: () => setClosing(false) });
    };

    const cancel = () =>
        router.post(route('transfer_requests.cancel', request.id), {}, { preserveScroll: true, onSuccess: () => setCancelling(false) });

    const units = items.reduce((sum, item) => sum + item.quantity, 0);
    const sent = items.reduce((sum, item) => sum + item.sent_quantity, 0);
    const toSend = fulfillForm.data.items.reduce((sum, row) => sum + row.quantity, 0);
    const shortToDispatch = fulfillForm.data.items.filter((row) => {
        const item = items.find((entry) => entry.id === row.id);

        return item !== undefined && row.quantity > item.origin_available;
    });
    const isOpen = request.status === 'pending' || request.status === 'partial';

    return (
        <AppLayout title={`Solicitud ${request.number}`}>
            <PageHeader
                title={`Solicitud ${request.number}`}
                description={`${request.destination?.name ?? '—'} le pide a ${request.origin?.name ?? '—'}`}
                breadcrumb={
                    <ButtonLink href={route('transfer_requests.index')} size="sm" variant="ghost" icon={<ArrowLeftIcon size={14} />}>
                        Volver a solicitudes
                    </ButtonLink>
                }
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge tone={TONES[request.status]}>{request.status_label}</Badge>
                        {allowed.fulfill && can('inventory.transfer_requests.fulfill') && (
                            <Button onClick={() => setFulfilling(true)} icon={<ArrowsLeftRightIcon size={16} />}>
                                Generar traslado
                            </Button>
                        )}
                        {allowed.reject && can('inventory.transfer_requests.fulfill') && (
                            <Button variant="ghost" onClick={() => setRejecting(true)}>
                                Rechazar
                            </Button>
                        )}
                        {allowed.close && can('inventory.transfer_requests.fulfill') && (
                            <Button variant="ghost" onClick={() => setClosing(true)}>
                                Cerrar lo pendiente
                            </Button>
                        )}
                        {allowed.cancel && can('inventory.transfer_requests.cancel') && (
                            <Button variant="ghost" onClick={() => setCancelling(true)}>
                                Anular
                            </Button>
                        )}
                    </div>
                }
            />

            {isOpen && (
                <Card className="mb-4">
                    <p className="flex items-start gap-2 text-[0.8125rem] font-medium text-[var(--warn)]">
                        <WarningCircleIcon size={16} weight="fill" className="mt-px shrink-0" />
                        {allowed.fulfill
                            ? `Te piden esta mercancía. Genera el traslado con lo que puedas mandar desde ${request.origin?.name}; lo que falte queda pendiente.`
                            : `Esperando a que ${request.origin?.name} la atienda. Pedir no mueve inventario: la mercancía entra cuando se recibe el traslado.`}
                    </p>
                </Card>
            )}

            {(request.status === 'rejected' || request.status === 'closed') && (
                <Card className="mb-4">
                    <p className="text-[0.8125rem]">
                        <span className="font-semibold">
                            {request.status === 'rejected' ? 'Rechazada' : 'Cerrada sin completar'}
                            {request.responded_by ? ` por ${request.responded_by}` : ''}.
                        </span>{' '}
                        <span className="text-[var(--muted)]">{request.response_note ?? 'Sin motivo escrito.'}</span>
                    </p>
                </Card>
            )}

            <div className="mb-4 grid gap-4 lg:grid-cols-3">
                <Card>
                    <CardHeader title="Ruta" />
                    <div className="flex items-center gap-2 text-[0.8125rem]">
                        <div>
                            <p className="text-[0.6875rem] text-[var(--muted)]">Se le pide a</p>
                            <p className="font-medium">{request.origin?.name}</p>
                            <p className="text-[0.71875rem] text-[var(--muted)]">{request.origin?.store_name}</p>
                        </div>
                        <TruckIcon size={16} className="text-[var(--muted)]" />
                        <div>
                            <p className="text-[0.6875rem] text-[var(--muted)]">Pide</p>
                            <p className="font-medium">{request.destination?.name}</p>
                            <p className="text-[0.71875rem] text-[var(--muted)]">{request.destination?.store_name}</p>
                        </div>
                    </div>
                    {request.note && <p className="mt-3 text-[0.78125rem] text-[var(--muted)]">{request.note}</p>}
                </Card>

                <Card>
                    <CardHeader title="Quién y cuándo" />
                    <dl className="flex flex-col gap-1.5 text-[0.78125rem]">
                        <div className="flex justify-between gap-2">
                            <dt className="text-[var(--muted)]">Pidió</dt>
                            <dd className="text-right">
                                {request.created_by ?? '—'}
                                {request.created_at ? ` · ${dateTime(request.created_at)}` : ''}
                            </dd>
                        </div>
                        {request.responded_at && (
                            <div className="flex justify-between gap-2">
                                <dt className="text-[var(--muted)]">{request.status === 'rejected' ? 'Rechazó' : 'Cerró'}</dt>
                                <dd className="text-right">
                                    {request.responded_by ?? '—'} · {dateTime(request.responded_at)}
                                </dd>
                            </div>
                        )}
                        {request.cancelled_at && (
                            <div className="flex justify-between gap-2">
                                <dt className="text-[var(--muted)]">Anulada</dt>
                                <dd className="text-right">{dateTime(request.cancelled_at)}</dd>
                            </div>
                        )}
                    </dl>
                </Card>

                <Card>
                    <CardHeader title="Avance" />
                    <p className="font-mono text-[1.75rem] font-semibold leading-none text-[var(--accent)] tabular">
                        {number(sent)} <span className="text-[1rem] text-[var(--muted)]">/ {number(units)}</span>
                    </p>
                    <p className="mt-2 text-[0.78125rem] text-[var(--muted)]">
                        Unidades que ya van en traslados, de las que se pidieron.
                    </p>
                </Card>
            </div>

            <Card padded={false} className="mb-4">
                <Table>
                    <THead>
                        <TR>
                            <TH>Artículo</TH>
                            <TH align="right">Pedido</TH>
                            <TH align="right">En traslados</TH>
                            <TH align="right">Pendiente</TH>
                            <TH align="right">Tiene quien pide</TH>
                            <TH align="right">Disponible en origen</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {items.map((item) => {
                            const unit = UNIT_SHORT[item.unit ?? 'unit'] ?? '';

                            return (
                                <TR key={item.id}>
                                    <TD>
                                        <p className="text-[0.84375rem] font-medium">{item.name}</p>
                                        <p className="font-mono text-[0.71875rem] text-[var(--muted)]">{item.sku}</p>
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                        {number(item.quantity)} {unit}
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                        {item.sent_quantity > 0 ? number(item.sent_quantity) : '—'}
                                    </TD>
                                    <TD
                                        align="right"
                                        className="font-mono text-[0.8125rem] tabular"
                                        style={{ color: item.pending > 0 && isOpen ? 'var(--warn)' : undefined }}
                                    >
                                        {item.pending > 0 ? number(item.pending) : '—'}
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                        {number(item.destination_stock)}
                                    </TD>
                                    <TD
                                        align="right"
                                        className="font-mono text-[0.8125rem] tabular"
                                        style={{ color: isOpen && item.origin_available < item.pending ? 'var(--danger)' : undefined }}
                                    >
                                        {number(item.origin_available)}
                                    </TD>
                                </TR>
                            );
                        })}
                    </TBody>
                </Table>
            </Card>

            <Card padded={false}>
                <div className="px-4 pt-4">
                    <CardHeader
                        title="Traslados generados"
                        description="Cada traslado se despacha en la bodega que surte y se recibe en la que pide."
                    />
                </div>
                {transfers.length === 0 ? (
                    <p className="px-4 pb-4 text-[0.78125rem] text-[var(--muted)]">Todavía no se ha generado ningún traslado.</p>
                ) : (
                    <Table>
                        <THead>
                            <TR>
                                <TH>Traslado</TH>
                                <TH>Estado</TH>
                                <TH align="right">Unidades</TH>
                                <TH align="right">Valor</TH>
                                <TH align="right" />
                            </TR>
                        </THead>
                        <TBody>
                            {transfers.map((transfer) => (
                                <TR key={transfer.id}>
                                    <TD>
                                        <p className="font-mono text-[0.8125rem] font-medium">{transfer.number}</p>
                                        <p className="text-[0.71875rem] text-[var(--muted)]">
                                            {transfer.created_at ? dateTime(transfer.created_at) : '—'}
                                        </p>
                                    </TD>
                                    <TD>
                                        <Badge tone={TRANSFER_TONES[transfer.status]}>{transfer.status_label}</Badge>
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                        {number(transfer.units)}
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                        {money(transfer.value)}
                                    </TD>
                                    <TD align="right">
                                        {can('inventory.transfers.view') && (
                                            <Link
                                                href={route('transfers.show', transfer.id)}
                                                className="text-[0.78125rem] font-medium text-[var(--accent)] hover:underline"
                                            >
                                                Ver
                                            </Link>
                                        )}
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                )}
            </Card>

            <Modal
                open={fulfilling}
                onClose={() => setFulfilling(false)}
                title="Generar el traslado"
                description={`Lo que va a mandar ${request.origin?.name} a ${request.destination?.name}`}
                size="lg"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setFulfilling(false)}>
                            Cancelar
                        </Button>
                        <Button
                            variant={can('inventory.transfers.dispatch') ? 'secondary' : 'primary'}
                            onClick={() => submitFulfill(false)}
                            loading={sending === 'draft'}
                            disabled={toSend <= 0 || sending !== null}
                        >
                            Generar sin despachar
                        </Button>
                        {can('inventory.transfers.dispatch') && (
                            <Button
                                onClick={() => submitFulfill(true)}
                                loading={sending === 'dispatch'}
                                disabled={toSend <= 0 || shortToDispatch.length > 0 || sending !== null}
                                icon={<TruckIcon size={16} />}
                            >
                                Generar y despachar
                            </Button>
                        )}
                    </>
                }
            >
                <div className="flex flex-col gap-3">
                    <p className="text-[0.78125rem] text-[var(--muted)]">
                        Viene prellenado con lo que falta, sin pasar de lo disponible. Lo que no mandes ahora queda pendiente en la
                        solicitud; pon cero en lo que no vayas a mandar.
                    </p>

                    {pendingItems.map((item) => {
                        const row = fulfillForm.data.items.find((entry) => entry.id === item.id);
                        const quantity = row?.quantity ?? 0;
                        const unit = UNIT_SHORT[item.unit ?? 'unit'] ?? '';
                        const short = quantity > item.origin_available;

                        return (
                            <label key={item.id} className="flex items-center justify-between gap-3">
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[0.8125rem] font-medium">{item.name}</span>
                                    <span className="block text-[0.71875rem] text-[var(--muted)]">
                                        Faltan {number(item.pending)} {unit} ·{' '}
                                        <span style={{ color: short ? 'var(--danger)' : undefined }}>
                                            disponible {number(item.origin_available)} {unit}
                                        </span>
                                    </span>
                                </span>
                                <input
                                    type="number"
                                    min={0}
                                    max={item.pending}
                                    step="any"
                                    value={quantity}
                                    onChange={(event) => setToSend(item.id, Number(event.target.value))}
                                    aria-label={`Cantidad a mandar de ${item.name}`}
                                    className="h-11 w-24 shrink-0 rounded-control border border-[var(--line)] bg-[var(--surface-2)] text-center font-mono text-[0.9375rem] tabular focus:border-[var(--accent)] focus:outline-none"
                                />
                            </label>
                        );
                    })}

                    {shortToDispatch.length > 0 && (
                        <p className="flex items-start gap-2 rounded-control bg-[var(--warn-soft)] px-3 py-2.5 text-[0.78125rem] font-medium text-[var(--warn)]">
                            <WarningCircleIcon size={15} weight="fill" className="mt-px shrink-0" />
                            Hay cantidades por encima de lo disponible. Puedes generarlo sin despachar y despacharlo cuando entre la
                            mercancía.
                        </p>
                    )}

                    {(['items', 'status'] as const).map((key) =>
                        (fulfillForm.errors as Record<string, string | undefined>)[key] ? (
                            <p key={key} className="text-[0.78125rem] font-medium text-[var(--danger)]">
                                {(fulfillForm.errors as Record<string, string>)[key]}
                            </p>
                        ) : null,
                    )}
                </div>
            </Modal>

            <Modal
                open={rejecting}
                onClose={() => setRejecting(false)}
                title="Rechazar la solicitud"
                description={`${request.destination?.name} verá el motivo.`}
                size="sm"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setRejecting(false)}>
                            Cancelar
                        </Button>
                        <Button variant="danger" onClick={submitReject} loading={rejectForm.processing}>
                            Rechazar
                        </Button>
                    </>
                }
            >
                <form onSubmit={submitReject} className="flex flex-col gap-2">
                    <Textarea
                        label="Motivo"
                        required
                        maxLength={160}
                        rows={3}
                        value={rejectForm.data.reason}
                        onChange={(event) => rejectForm.setData('reason', event.target.value)}
                        placeholder="No hay existencia en el depósito"
                        error={rejectForm.errors.reason ?? (rejectForm.errors as Record<string, string | undefined>).status}
                    />
                </form>
            </Modal>

            <Modal
                open={closing}
                onClose={() => setClosing(false)}
                title="Cerrar lo pendiente"
                description="Lo que ya se generó sigue su curso y se recibe. Lo que falta deja de estar pendiente."
                size="sm"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setClosing(false)}>
                            Cancelar
                        </Button>
                        <Button onClick={submitClose} loading={closeForm.processing}>
                            Cerrar la solicitud
                        </Button>
                    </>
                }
            >
                <form onSubmit={submitClose} className="flex flex-col gap-2">
                    <Textarea
                        label="Motivo"
                        maxLength={160}
                        rows={3}
                        value={closeForm.data.reason}
                        onChange={(event) => closeForm.setData('reason', event.target.value)}
                        placeholder="El resto se va a comprar al proveedor"
                        error={closeForm.errors.reason ?? (closeForm.errors as Record<string, string | undefined>).status}
                    />
                </form>
            </Modal>

            <ConfirmDialog
                open={cancelling}
                onClose={() => setCancelling(false)}
                onConfirm={cancel}
                title="Anular la solicitud"
                message="Nadie ha empezado a atenderla, así que anularla no mueve nada."
                confirmLabel="Anular"
                tone="danger"
            />
        </AppLayout>
    );
}

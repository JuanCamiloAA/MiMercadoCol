import { Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowCounterClockwiseIcon, FilePdfIcon, PackageIcon, PencilSimpleIcon, ReceiptIcon, TruckIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { type FormEvent, useState } from 'react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Input, Select, Textarea } from '@/components/ui/Field';
import { ConfirmDialog, Modal } from '@/components/ui/Modal';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { date, dateTime, money, number, UNIT_SHORT } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import { RETURN_TONES } from '@/lib/supplier-returns';
import type { PageProps } from '@/types';
import type { Option, SupplierReturnDetail, SupplierReturnItemRow, SupplierReturnSettlementRow, TimelineEvent } from '@/types/supplier-returns';

interface SupplierReturnShowProps {
    supplierReturn: SupplierReturnDetail;
    items: SupplierReturnItemRow[];
    settlements: SupplierReturnSettlementRow[];
    timeline: TimelineEvent[];
    paymentMethods: Option[];
    settlementTypes: Option[];
    can: { act_here: boolean };
}

const today = () => new Date().toISOString().slice(0, 10);
const qty = (value: number) => Math.round((Number.isFinite(value) ? value : 0) * 1000) / 1000;

/**
 * Una devolución a proveedor, con su historia.
 *
 * Despachada, queda «pendiente de respuesta» hasta que lo que responda el proveedor (nota
 * crédito, reembolso o reposición) más lo que rechace cubra el total. Lo rechazado vuelve a la
 * misma bodega con sus lotes.
 */
export default function SupplierReturnShow({ supplierReturn: ret, items, settlements, timeline, paymentMethods, settlementTypes, can: allowed }: SupplierReturnShowProps) {
    const { can } = usePermission();
    const errors = (usePage<PageProps>().props.errors ?? {}) as Record<string, string>;
    const [dispatching, setDispatching] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const [settling, setSettling] = useState(false);
    const [rejecting, setRejecting] = useState(false);
    const [replacing, setReplacing] = useState(false);

    const isDraft = ret.status === 'draft';
    const isPending = ret.status === 'dispatched';
    const covered = ret.rejected_total + ret.settled_total;
    const progress = ret.total > 0 ? Math.min(100, Math.round((covered / ret.total) * 100)) : 0;

    const cancelForm = useForm({ reason: '' });
    const settleForm = useForm({
        type: 'credit_note',
        document_number: '',
        amount: ret.pending_total,
        received_at: today(),
        payment_method: '',
        note: '',
    });
    const rejectForm = useForm({ lines: items.map((item) => ({ id: item.id, quantity: 0 })), note: '' });
    const replaceForm = useForm({ lines: items.map((item) => ({ id: item.id, quantity: item.replaceable })), note: '' });

    const dispatchNow = () =>
        router.post(route('supplier_returns.dispatch', ret.id), {}, { preserveScroll: true, onFinish: () => setDispatching(false) });

    const submitCancel = () =>
        cancelForm.post(route('supplier_returns.cancel', ret.id), { preserveScroll: true, onSuccess: () => setCancelling(false) });

    const submitSettle = (event?: FormEvent) => {
        event?.preventDefault();
        settleForm.post(route('supplier_returns.settle', ret.id), { preserveScroll: true, onSuccess: () => setSettling(false) });
    };

    const submitReject = (event?: FormEvent) => {
        event?.preventDefault();
        rejectForm.post(route('supplier_returns.reject', ret.id), { preserveScroll: true, onSuccess: () => setRejecting(false) });
    };

    const submitReplace = (event?: FormEvent) => {
        event?.preventDefault();
        replaceForm.post(route('supplier_returns.replacement', ret.id), { preserveScroll: true, onSuccess: () => setReplacing(false) });
    };

    const setLineQuantity = (form: typeof rejectForm, id: number, value: number) =>
        form.setData(
            'lines',
            form.data.lines.map((line) => (line.id === id ? { ...line, quantity: qty(Math.max(0, value)) } : line)),
        );

    const rejectValue = rejectForm.data.lines.reduce((sum, line) => sum + Math.round(line.quantity * (items.find((item) => item.id === line.id)?.unit_cost ?? 0)), 0);
    const replaceValue = replaceForm.data.lines.reduce((sum, line) => sum + Math.round(line.quantity * (items.find((item) => item.id === line.id)?.unit_cost ?? 0)), 0);
    const units = items.reduce((sum, item) => sum + item.quantity, 0);
    const generalError = errors.stock ?? errors.items ?? errors.status ?? errors.warehouse_id;

    return (
        <AppLayout title={`Devolución ${ret.number}`}>
            <PageHeader
                title={`Devolución ${ret.number}`}
                description={`${ret.supplier.name ?? '—'} · ${ret.store ?? '—'} · ${ret.warehouse ?? '—'}`}
                breadcrumb={
                    <ButtonLink href={route('supplier_returns.index')} size="sm" variant="ghost">
                        Volver a devoluciones
                    </ButtonLink>
                }
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge tone={RETURN_TONES[ret.status]}>{ret.status_label}</Badge>
                        <a
                            href={route('supplier_returns.document', ret.id)}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex h-[calc(2.5rem*var(--density))] items-center gap-2 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-4 text-sm font-medium text-[var(--text)] hover:bg-[var(--surface-3)]"
                        >
                            <FilePdfIcon size={16} />
                            Documento PDF
                        </a>
                        {isDraft && can('purchases.returns.create') && (
                            <ButtonLink href={route('supplier_returns.edit', ret.id)} variant="secondary" icon={<PencilSimpleIcon size={16} />}>
                                Editar
                            </ButtonLink>
                        )}
                        {isDraft && allowed.act_here && can('purchases.returns.dispatch') && (
                            <Button icon={<TruckIcon size={16} />} onClick={() => setDispatching(true)}>
                                Despachar
                            </Button>
                        )}
                        {isDraft && can('purchases.returns.cancel') && (
                            <Button variant="ghost" onClick={() => setCancelling(true)}>
                                Anular
                            </Button>
                        )}
                        {isPending && can('purchases.returns.respond') && (
                            <>
                                <Button icon={<ReceiptIcon size={16} />} onClick={() => setSettling(true)}>
                                    Registrar nota crédito o reembolso
                                </Button>
                                <Button variant="secondary" icon={<PackageIcon size={16} />} onClick={() => setReplacing(true)}>
                                    Registrar reposición
                                </Button>
                                {allowed.act_here && (
                                    <Button variant="secondary" icon={<ArrowCounterClockwiseIcon size={16} />} onClick={() => setRejecting(true)}>
                                        Registrar rechazo
                                    </Button>
                                )}
                            </>
                        )}
                    </div>
                }
            />

            {generalError && (
                <Card className="mb-4">
                    <p className="flex items-start gap-2 text-[0.8125rem] font-medium text-[var(--danger)]">
                        <WarningCircleIcon size={16} weight="fill" className="mt-px shrink-0" />
                        {generalError}
                    </p>
                </Card>
            )}

            {isDraft && (
                <Card className="mb-4">
                    <p className="text-[0.8125rem] text-[var(--muted)]">
                        Es un borrador: todavía no ha salido nada de {ret.warehouse}. Al despacharla, la mercancía sale del inventario y la devolución queda
                        esperando lo que responda el proveedor.
                    </p>
                </Card>
            )}

            {ret.status === 'cancelled' && ret.cancel_reason && (
                <Card className="mb-4">
                    <p className="text-[0.8125rem] text-[var(--danger)]">Anulada: {ret.cancel_reason}</p>
                </Card>
            )}

            <div className="mb-4 grid gap-4 lg:grid-cols-3">
                <Card>
                    <CardHeader title="Proveedor y documento" />
                    <dl className="flex flex-col gap-1.5 text-[0.78125rem]">
                        <Row label="Proveedor" value={ret.supplier.name} />
                        {ret.supplier.contact_name && <Row label="Contacto" value={`${ret.supplier.contact_name}${ret.supplier.phone ? ` · ${ret.supplier.phone}` : ''}`} />}
                        <div className="flex justify-between gap-2">
                            <dt className="text-[var(--muted)]">Orden</dt>
                            <dd className="text-right">
                                {ret.order ? (
                                    can('purchases.index.view') ? (
                                        <Link href={route('purchases.show', ret.order.id)} className="font-mono font-medium text-[var(--accent)] hover:underline">
                                            {ret.order.code}
                                        </Link>
                                    ) : (
                                        <span className="font-mono">{ret.order.code}</span>
                                    )
                                ) : (
                                    'Suelta'
                                )}
                            </dd>
                        </div>
                        <Row label="Sede y bodega" value={`${ret.store ?? '—'} · ${ret.warehouse ?? '—'}`} />
                        <Row label="Guía" value={ret.shipping_reference ?? '—'} />
                        <Row label="Creó" value={`${ret.created_by ?? '—'}${ret.created_at ? ` · ${dateTime(ret.created_at)}` : ''}`} />
                        <Row label="Despachó" value={ret.dispatched_at ? `${ret.dispatched_by ?? '—'} · ${dateTime(ret.dispatched_at)}` : 'Todavía no'} />
                    </dl>
                    {ret.note && <p className="mt-3 text-[0.78125rem] text-[var(--muted)]">{ret.note}</p>}
                </Card>

                <Card className="lg:col-span-2">
                    <CardHeader title="Valor a favor" description="Lo que se le reclama al proveedor, con IVA." />
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <Figure label="Total" value={money(ret.total)} />
                        <Figure label="Rechazado" value={money(ret.rejected_total)} />
                        <Figure label="Respondido" value={money(ret.settled_total)} />
                        <Figure label="Pendiente a favor" value={money(ret.pending_total)} strong />
                    </div>
                    {(isPending || ret.status === 'closed') && (
                        <div className="mt-4">
                            <div className="h-2 overflow-hidden rounded-full bg-[var(--surface-2)]">
                                <div className="h-full rounded-full bg-[var(--accent)] transition-[width]" style={{ width: `${progress}%` }} />
                            </div>
                            <p className="mt-1.5 text-[0.75rem] text-[var(--muted)]">
                                {progress}% cubierto entre respuestas y rechazos. Base {money(ret.subtotal)} · IVA {money(ret.tax_total)}.
                            </p>
                        </div>
                    )}
                </Card>
            </div>

            <Card padded={false} className="mb-4">
                <Table className="rounded-none border-0">
                    <THead>
                        <TR>
                            <TH>Artículo</TH>
                            <TH>Motivo</TH>
                            <TH align="right">Cantidad</TH>
                            <TH align="right">Rechazado</TH>
                            <TH align="right">Neto</TH>
                            <TH align="right">Costo</TH>
                            <TH align="right">Subtotal</TH>
                            <TH>Lotes</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {items.map((item) => (
                            <TR key={item.id}>
                                <TD>
                                    <p className="text-[0.8125rem] font-medium">{item.name}</p>
                                    <p className="font-mono text-[0.71875rem] text-[var(--muted)]">{item.sku}</p>
                                </TD>
                                <TD className="text-[0.78125rem]">
                                    {item.reason_label}
                                    {item.note && <span className="block text-[var(--muted)]">{item.note}</span>}
                                </TD>
                                <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                    {number(item.quantity)} {UNIT_SHORT[item.unit ?? 'unit'] ?? ''}
                                </TD>
                                <TD align="right" className="font-mono text-[0.8125rem] tabular" style={{ color: item.rejected_quantity > 0 ? 'var(--danger)' : undefined }}>
                                    {item.rejected_quantity > 0 ? number(item.rejected_quantity) : '—'}
                                </TD>
                                <TD align="right" className="font-mono text-[0.8125rem] font-medium tabular">
                                    {number(item.net_quantity)}
                                </TD>
                                <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                    {money(item.unit_cost)}
                                </TD>
                                <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                    {money(item.line_total)}
                                </TD>
                                <TD className="text-[0.75rem]">
                                    {item.lots.length > 0
                                        ? item.lots.map((lot, position) => (
                                              <span key={position} className="block">
                                                  {lot.code ?? 'Sin código'} · {number(lot.quantity)}
                                                  {lot.expires_at ? ` · vence ${date(lot.expires_at)}` : ''}
                                              </span>
                                          ))
                                        : item.chosen_lot
                                          ? `Elegido: ${item.chosen_lot}`
                                          : '—'}
                                </TD>
                            </TR>
                        ))}
                    </TBody>
                </Table>
            </Card>

            <div className="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader title="Respuestas del proveedor" />
                    {settlements.length === 0 ? (
                        <p className="text-[0.8125rem] text-[var(--muted)]">{isDraft ? 'Se registran después de despachar.' : 'Todavía no ha respondido.'}</p>
                    ) : (
                        <ul className="flex flex-col divide-y divide-[var(--line)]">
                            {settlements.map((settlement) => (
                                <li key={settlement.id} className="flex items-start justify-between gap-3 py-2.5">
                                    <div className="min-w-0 text-[0.8125rem]">
                                        <p className="font-medium">
                                            {settlement.type_label}
                                            {settlement.document_number && settlement.type !== 'replacement' && <span className="ml-1.5 font-mono text-[var(--muted)]">{settlement.document_number}</span>}
                                        </p>
                                        <p className="text-[0.75rem] text-[var(--muted)]">
                                            {settlement.received_at ? date(settlement.received_at) : '—'}
                                            {settlement.payment_method ? ` · ${settlement.payment_method}` : ''}
                                            {settlement.user ? ` · ${settlement.user}` : ''}
                                        </p>
                                        {settlement.order && (
                                            <p className="text-[0.75rem]">
                                                Orden{' '}
                                                {can('purchases.index.view') ? (
                                                    <Link href={route('purchases.show', settlement.order.id)} className="font-mono font-medium text-[var(--accent)] hover:underline">
                                                        {settlement.order.code}
                                                    </Link>
                                                ) : (
                                                    <span className="font-mono">{settlement.order.code}</span>
                                                )}{' '}
                                                · {settlement.order.status_label}
                                            </p>
                                        )}
                                        {settlement.note && <p className="text-[0.75rem] text-[var(--muted)]">{settlement.note}</p>}
                                    </div>
                                    <span className="shrink-0 font-mono text-[0.8125rem] font-medium tabular">{money(settlement.amount)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <Card>
                    <CardHeader title="Historial" />
                    <ol className="flex flex-col gap-3">
                        {timeline.map((event, position) => (
                            <li key={position} className="flex gap-3">
                                <span
                                    className="mt-1.5 size-2 shrink-0 rounded-full"
                                    style={{ background: event.tone === 'neutral' ? 'var(--muted)' : `var(--${event.tone})` }}
                                />
                                <div className="text-[0.8125rem]">
                                    <p className="font-medium">{event.label}</p>
                                    <p className="text-[0.75rem] text-[var(--muted)]">
                                        {dateTime(event.at)}
                                        {event.user ? ` · ${event.user}` : ''}
                                    </p>
                                    {event.detail && <p className="text-[0.75rem]">{event.detail}</p>}
                                </div>
                            </li>
                        ))}
                    </ol>
                </Card>
            </div>

            <ConfirmDialog
                open={dispatching}
                onClose={() => setDispatching(false)}
                onConfirm={dispatchNow}
                title="Despachar la devolución"
                message={`Saldrán ${number(units)} unidades de ${ret.warehouse}. Después no se puede anular: si el proveedor devuelve algo, se registra como rechazo.`}
                confirmLabel="Despachar"
                tone="primary"
            />

            <Modal
                open={cancelling}
                onClose={() => setCancelling(false)}
                title="Anular el borrador"
                description="No ha salido nada, así que anularlo no mueve inventario."
                size="sm"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setCancelling(false)}>
                            Volver
                        </Button>
                        <Button variant="danger" loading={cancelForm.processing} onClick={submitCancel}>
                            Anular
                        </Button>
                    </>
                }
            >
                <Textarea
                    label="Motivo"
                    required
                    value={cancelForm.data.reason}
                    error={cancelForm.errors.reason}
                    onChange={(event) => cancelForm.setData('reason', event.target.value)}
                />
            </Modal>

            <Modal
                open={settling}
                onClose={() => setSettling(false)}
                title="Registrar nota crédito o reembolso"
                description={`El proveedor todavía debe ${money(ret.pending_total)} por esta devolución.`}
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setSettling(false)}>
                            Cancelar
                        </Button>
                        <Button loading={settleForm.processing} onClick={() => submitSettle()}>
                            Registrar
                        </Button>
                    </>
                }
            >
                <form onSubmit={submitSettle} className="grid gap-3 sm:grid-cols-2">
                    <Select
                        label="Tipo"
                        required
                        options={settlementTypes}
                        value={settleForm.data.type}
                        error={settleForm.errors.type}
                        onChange={(event) => settleForm.setData('type', event.target.value)}
                    />
                    <Input
                        label={settleForm.data.type === 'credit_note' ? 'Número de la nota crédito' : 'Comprobante (opcional)'}
                        required={settleForm.data.type === 'credit_note'}
                        maxLength={40}
                        value={settleForm.data.document_number}
                        error={settleForm.errors.document_number}
                        onChange={(event) => settleForm.setData('document_number', event.target.value)}
                    />
                    <Input
                        label="Valor"
                        type="number"
                        required
                        min={1}
                        max={ret.pending_total}
                        value={settleForm.data.amount}
                        error={settleForm.errors.amount}
                        onChange={(event) => settleForm.setData('amount', Math.max(0, Math.round(Number(event.target.value) || 0)))}
                    />
                    <Input
                        label="Fecha del documento o del pago"
                        type="date"
                        required
                        max={today()}
                        value={settleForm.data.received_at}
                        error={settleForm.errors.received_at}
                        onChange={(event) => settleForm.setData('received_at', event.target.value)}
                    />
                    {settleForm.data.type === 'refund' && (
                        <Select
                            label="Cómo llegó"
                            required
                            options={paymentMethods}
                            placeholder="Elige"
                            value={settleForm.data.payment_method}
                            error={settleForm.errors.payment_method}
                            onChange={(event) => settleForm.setData('payment_method', event.target.value)}
                        />
                    )}
                    <Input
                        label="Nota"
                        maxLength={160}
                        value={settleForm.data.note}
                        error={settleForm.errors.note}
                        onChange={(event) => settleForm.setData('note', event.target.value)}
                        containerClassName={settleForm.data.type === 'refund' ? '' : 'sm:col-span-2'}
                    />
                </form>
            </Modal>

            <Modal
                open={rejecting}
                onClose={() => setRejecting(false)}
                title="Registrar rechazo"
                description={`Esta mercancía volverá a entrar a ${ret.warehouse}, con sus lotes.`}
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setRejecting(false)}>
                            Cancelar
                        </Button>
                        <Button loading={rejectForm.processing} onClick={() => submitReject()}>
                            Registrar rechazo · {money(rejectValue)}
                        </Button>
                    </>
                }
            >
                <form onSubmit={submitReject} className="flex flex-col gap-3">
                    {(rejectForm.errors as Record<string, string>).lines && (
                        <p className="text-[0.78125rem] font-medium text-[var(--danger)]">{(rejectForm.errors as Record<string, string>).lines}</p>
                    )}
                    {items.map((item) => {
                        const row = rejectForm.data.lines.find((line) => line.id === item.id);
                        const error = (rejectForm.errors as Record<string, string>)[`lines.${item.id}`];

                        return (
                            <label key={item.id} className="flex items-center justify-between gap-3">
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[0.8125rem] font-medium">{item.name}</span>
                                    <span className="block text-[0.71875rem] text-[var(--muted)]">El proveedor se quedó con {number(item.net_quantity)}</span>
                                    {error && <span className="block text-[0.71875rem] font-medium text-[var(--danger)]">{error}</span>}
                                </span>
                                <input
                                    type="number"
                                    min={0}
                                    max={item.net_quantity}
                                    step={item.sold_by_weight ? '0.001' : '1'}
                                    disabled={item.net_quantity <= 0}
                                    value={row?.quantity || ''}
                                    placeholder="0"
                                    onChange={(event) => setLineQuantity(rejectForm, item.id, Number(event.target.value))}
                                    className="h-10 w-24 shrink-0 rounded-control border border-[var(--line)] bg-[var(--surface-2)] text-center font-mono text-[0.875rem] tabular focus:border-[var(--accent)] focus:outline-none"
                                />
                            </label>
                        );
                    })}
                    <Input label="Nota" maxLength={160} value={rejectForm.data.note} onChange={(event) => rejectForm.setData('note', event.target.value)} />
                </form>
            </Modal>

            <Modal
                open={replacing}
                onClose={() => setReplacing(false)}
                title="Registrar reposición"
                description="Se crea una orden de compra de reposición con estos artículos, que se recibe con el botón de siempre. No lleva plazo de pago."
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setReplacing(false)}>
                            Cancelar
                        </Button>
                        <Button loading={replaceForm.processing} onClick={() => submitReplace()}>
                            Crear orden de reposición · {money(replaceValue)}
                        </Button>
                    </>
                }
            >
                <form onSubmit={submitReplace} className="flex flex-col gap-3">
                    {((replaceForm.errors as Record<string, string>).lines || (replaceForm.errors as Record<string, string>).amount) && (
                        <p className="text-[0.78125rem] font-medium text-[var(--danger)]">
                            {(replaceForm.errors as Record<string, string>).lines ?? (replaceForm.errors as Record<string, string>).amount}
                        </p>
                    )}
                    {items.map((item) => {
                        const row = replaceForm.data.lines.find((line) => line.id === item.id);
                        const error = (replaceForm.errors as Record<string, string>)[`lines.${item.id}`];

                        return (
                            <label key={item.id} className="flex items-center justify-between gap-3">
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[0.8125rem] font-medium">{item.name}</span>
                                    <span className="block text-[0.71875rem] text-[var(--muted)]">
                                        Quedan {number(item.replaceable)} por reponer · {money(item.unit_cost)} c/u
                                    </span>
                                    {error && <span className="block text-[0.71875rem] font-medium text-[var(--danger)]">{error}</span>}
                                </span>
                                <input
                                    type="number"
                                    min={0}
                                    max={item.replaceable}
                                    step={item.sold_by_weight ? '0.001' : '1'}
                                    disabled={item.replaceable <= 0}
                                    value={row?.quantity || ''}
                                    placeholder="0"
                                    onChange={(event) => setLineQuantity(replaceForm, item.id, Number(event.target.value))}
                                    className="h-10 w-24 shrink-0 rounded-control border border-[var(--line)] bg-[var(--surface-2)] text-center font-mono text-[0.875rem] tabular focus:border-[var(--accent)] focus:outline-none"
                                />
                            </label>
                        );
                    })}
                    <Input label="Nota" maxLength={160} value={replaceForm.data.note} onChange={(event) => replaceForm.setData('note', event.target.value)} />
                </form>
            </Modal>
        </AppLayout>
    );
}

function Row({ label, value }: { label: string; value: string | null }) {
    return (
        <div className="flex justify-between gap-2">
            <dt className="text-[var(--muted)]">{label}</dt>
            <dd className="text-right">{value ?? '—'}</dd>
        </div>
    );
}

function Figure({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
    return (
        <div>
            <p className="text-[0.75rem] text-[var(--muted)]">{label}</p>
            <p className={`font-mono tabular ${strong ? 'text-[1.375rem] font-semibold text-[var(--accent)]' : 'text-[1.0625rem] font-medium'}`}>{value}</p>
        </div>
    );
}

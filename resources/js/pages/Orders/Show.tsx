import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeftIcon, CheckCircleIcon, CurrencyCircleDollarIcon, MotorcycleIcon, PackageIcon } from '@phosphor-icons/react';
import { type FormEvent, useState } from 'react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Input, Select } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import type { PresentedPaymentMethod } from '@/components/payments/payment-icons';
import PaymentDialog, { type PaymentPayload } from '@/pages/Pos/components/PaymentDialog';
import { dateTime, money, number, time, UNIT_SHORT } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import { STATUS_TONES } from './Index';

interface Item {
    id: number;
    product_id: number | null;
    /** La fila del servicio: no se alista, se alistan sus artículos. */
    is_service: boolean;
    /** En un artículo de servicio: la fila del servicio. */
    parent_id: number | null;
    service_name: string | null;
    name: string | null;
    sku: string | null;
    unit: string | null;
    quantity: number;
    picked_quantity: number | null;
    unit_price: number;
    line_total: number;
    substitution_policy: string;
    substitution_label: string;
    pick_note: string | null;
}

interface OrderShowProps {
    order: {
        id: number;
        number: string;
        status: string;
        status_label: string;
        channel_label: string;
        payment_method: string;
        payment_label: string;
        payment_status: string;
        delivery_address: string;
        delivery_notes: string | null;
        delivery_slot_start: string | null;
        delivery_slot_end: string | null;
        subtotal: number;
        tax_total: number;
        delivery_fee: number;
        discount_total: number;
        coupon: string | null;
        total: number;
        placed_at: string;
        delivered_at: string | null;
        cancel_reason: string | null;
        store: string | null;
        sale: { id: number; number: string; total: number } | null;
        /** Saldo a favor apartado al pedir; al entregar, lo que pagó. */
        store_credit_amount: number;
        customer: { id: number; name: string; phone: string | null; document_number: string; neighborhood: string | null };
        courier: { id: number; name: string } | null;
        items: Item[];
        histories: {
            id: number;
            from_status: string | null;
            from_label: string | null;
            to_status: string;
            to_label: string;
            user: string | null;
            note: string | null;
            changed_at: string;
        }[];
        allowed_transitions: string[];
    };
    couriers: { value: number; label: string }[];
    /** Con que se cobra en la puerta: los medios activos que son plata (Medios de pago). */
    collectMethods: PresentedPaymentMethod[];
    statusLabels: Record<string, string>;
    returns: { id: number; number: string; status_label: string; resolution_label: string | null; total: number; created_at: string }[];
    /** La venta del pedido, si se le puede registrar una devolución. */
    returnSaleId: number | null;
}

export default function OrderShow({ order, couriers, collectMethods, statusLabels, returns, returnSaleId }: OrderShowProps) {
    const { can } = usePermission();
    const [modal, setModal] = useState<'pick' | 'courier' | 'collect' | 'cancel' | null>(null);

    const pickForm = useForm({
        items: order.items.filter((item) => !item.is_service).map((item) => ({
            id: item.id,
            picked_quantity: item.picked_quantity ?? item.quantity,
            pick_note: item.pick_note ?? '',
        })),
    });

    const courierForm = useForm({ courier_user_id: order.courier?.id ?? (couriers[0]?.value ?? '') });
    const [collecting, setCollecting] = useState(false);
    const cancelForm = useForm({ status: 'cancelled', note: '' });

    const updatePick = (id: number, key: 'picked_quantity' | 'pick_note', value: string | number) => {
        pickForm.setData(
            'items',
            pickForm.data.items.map((line) => (line.id === id ? { ...line, [key]: value } : line)),
        );
    };

    const submitPick = (event: FormEvent) => {
        event.preventDefault();
        pickForm.post(route('orders.pick', order.id), { preserveScroll: true, onSuccess: () => setModal(null) });
    };

    const submitCourier = (event: FormEvent) => {
        event.preventDefault();
        courierForm.post(route('orders.courier', order.id), { preserveScroll: true, onSuccess: () => setModal(null) });
    };

    const submitCollect = (payments: PaymentPayload[]) => {
        setCollecting(true);

        router.post(
            route('orders.collect', order.id),
            {
                // Se rearma como objetos planos: es lo que Inertia sabe serializar.
                payments: payments.map((payment) => ({
                    payment_method_id: payment.payment_method_id,
                    method: payment.method,
                    amount: payment.amount,
                    received_amount: payment.received_amount,
                    reference: payment.reference,
                })),
            },
            {
                preserveScroll: true,
                onSuccess: () => setModal(null),
                onFinish: () => setCollecting(false),
            },
        );
    };

    const submitCancel = (event: FormEvent) => {
        event.preventDefault();
        cancelForm.patch(route('orders.status', order.id), { preserveScroll: true, onSuccess: () => setModal(null) });
    };

    const advance = (status: string) => {
        router.patch(route('orders.status', order.id), { status }, { preserveScroll: true });
    };

    const nextStatuses = order.allowed_transitions.filter((status) => status !== 'cancelled');

    return (
        <AppLayout title={`Pedido #${order.number}`}>
            <PageHeader
                title={`Pedido #${order.number}`}
                description={`${order.channel_label} · ${order.customer.name}`}
                breadcrumb={
                    <Link href={route('orders.index')} className="hover:text-[var(--accent)]">
                        Pedidos
                    </Link>
                }
                actions={
                    <>
                        <ButtonLink href={route('orders.index')} variant="secondary" icon={<ArrowLeftIcon size={16} />}>
                            Volver
                        </ButtonLink>
                        {can('orders.show.pick') && ['new', 'picking'].includes(order.status) && (
                            <Button variant="secondary" icon={<PackageIcon size={16} />} onClick={() => setModal('pick')}>
                                Alistar
                            </Button>
                        )}
                        {can('orders.show.assign_courier') && ['ready', 'picking', 'on_route'].includes(order.status) && (
                            <Button variant="secondary" icon={<MotorcycleIcon size={16} />} onClick={() => setModal('courier')}>
                                {order.courier ? 'Cambiar domiciliario' : 'Asignar domiciliario'}
                            </Button>
                        )}
                        {can('orders.show.collect') && order.payment_status !== 'paid' && order.status === 'delivered' && (
                            <Button variant="secondary" icon={<CurrencyCircleDollarIcon size={16} />} onClick={() => setModal('collect')}>
                                Registrar cobro
                            </Button>
                        )}
                        {can('orders.show.status') &&
                            nextStatuses.map((status) => (
                                <Button key={status} icon={<CheckCircleIcon size={16} weight="bold" />} onClick={() => advance(status)}>
                                    Marcar {statusLabels[status]?.toLowerCase()}
                                </Button>
                            ))}
                        {can('orders.show.cancel') && order.allowed_transitions.includes('cancelled') && (
                            <Button variant="secondary" onClick={() => setModal('cancel')}>
                                Cancelar
                            </Button>
                        )}
                    </>
                }
            />

            <div className="grid gap-4 lg:grid-cols-[1fr_340px]">
                <div className="flex flex-col gap-4">
                    <Card padded={false}>
                        <div className="flex flex-wrap items-center justify-between gap-3 px-5 pt-5">
                            <CardHeader title="Articulos" className="mb-0" />
                            <Badge tone={STATUS_TONES[order.status] ?? 'neutral'}>{order.status_label}</Badge>
                        </div>

                        <Table className="mt-4 rounded-none border-x-0 border-b-0">
                            <THead>
                                <TR>
                                    <TH>Articulo</TH>
                                    <TH align="right">Pedido</TH>
                                    <TH align="right">Alistado</TH>
                                    <TH>Si falta</TH>
                                    <TH align="right">Subtotal</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {order.items.map((item) => (
                                    <TR key={item.id} className={item.parent_id ? 'bg-[var(--surface-2)]/40' : undefined}>
                                        <TD className={item.parent_id ? 'pl-8' : undefined}>
                                            <p className="text-[0.84375rem] font-medium">
                                                {item.parent_id ? '↳ ' : ''}
                                                {item.name}
                                                {item.is_service && <span className="ml-1.5 rounded-chip bg-[var(--accent-soft)] px-1.5 py-0.5 text-[0.6875rem] font-semibold text-[var(--accent)]">Servicio</span>}
                                            </p>
                                            <p className="font-mono text-[0.71875rem] text-[var(--muted)]">{item.sku}</p>
                                            {item.pick_note && <p className="mt-0.5 text-[0.71875rem] text-[var(--warn)]">{item.pick_note}</p>}
                                        </TD>
                                        <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                            {number(item.quantity)} {UNIT_SHORT[item.unit ?? 'unit'] ?? ''}
                                        </TD>
                                        <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                            {item.picked_quantity === null ? (
                                                <span className="text-[var(--muted)]">—</span>
                                            ) : (
                                                <span
                                                    style={{
                                                        color:
                                                            item.picked_quantity < item.quantity
                                                                ? 'var(--warn)'
                                                                : 'var(--accent)',
                                                    }}
                                                >
                                                    {number(item.picked_quantity)}
                                                </span>
                                            )}
                                        </TD>
                                        <TD className="text-[0.75rem] text-[var(--muted)]">{item.substitution_label}</TD>
                                        <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                            {money(item.line_total)}
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>

                        <div className="flex flex-col gap-1.5 border-t border-[var(--line)] px-5 py-4 text-[0.8125rem]">
                            <Row label="Base" value={money(order.subtotal)} />
                            <Row label="IVA" value={money(order.tax_total)} />
                            <Row label="Domicilio" value={money(order.delivery_fee)} />
                            {order.discount_total > 0 && (
                                <Row
                                    label={order.coupon ? `Cupón ${order.coupon}` : 'Descuento'}
                                    value={`− ${money(order.discount_total)}`}
                                />
                            )}
                            <div className="flex items-center justify-between border-t border-[var(--line)] pt-2">
                                <span className="text-[0.9375rem] font-semibold">Total</span>
                                <span className="font-mono text-[1.375rem] font-semibold text-[var(--accent)] tabular">{money(order.total)}</span>
                            </div>
                        </div>
                    </Card>

                    <Card>
                        <CardHeader title="Linea de tiempo" description="Cada cambio de estado con quien lo hizo." />
                        <ol className="flex flex-col gap-0">
                            {order.histories.map((history, index) => (
                                <li key={history.id} className="flex gap-3">
                                    <div className="flex flex-col items-center">
                                        <span className="mt-1 size-2.5 rounded-full bg-[var(--accent)]" />
                                        {index < order.histories.length - 1 && <span className="w-px flex-1 bg-[var(--line)]" />}
                                    </div>
                                    <div className="pb-4">
                                        <p className="text-[0.84375rem] font-medium">
                                            {history.from_label ? `${history.from_label} → ${history.to_label}` : history.to_label}
                                        </p>
                                        <p className="text-[0.75rem] text-[var(--muted)]">
                                            {dateTime(history.changed_at)}
                                            {history.user ? ` · ${history.user}` : ''}
                                        </p>
                                        {history.note && <p className="mt-0.5 text-[0.78125rem] text-[var(--muted)]">{history.note}</p>}
                                    </div>
                                </li>
                            ))}
                        </ol>
                    </Card>

                    {(returns.length > 0 || returnSaleId) && (
                        <Card>
                            <CardHeader
                                title="Devoluciones"
                                description="Las que pidió el cliente desde la tienda o registró el equipo."
                                action={
                                    returnSaleId ? (
                                        <ButtonLink href={`${route('customer_returns.create')}?venta=${returnSaleId}`} size="sm" variant="secondary">
                                            Registrar devolución
                                        </ButtonLink>
                                    ) : undefined
                                }
                            />
                            {returns.length === 0 ? (
                                <p className="text-[0.8125rem] text-[var(--muted)]">Este pedido no tiene devoluciones.</p>
                            ) : (
                                <ul className="flex flex-col gap-2">
                                    {returns.map((row) => (
                                        <li key={row.id} className="flex items-center justify-between gap-3 rounded-control border border-[var(--line)] px-3 py-2">
                                            <div className="min-w-0">
                                                {can('sales.returns.view') ? (
                                                    <Link href={route('customer_returns.show', row.id)} className="font-mono text-[0.8125rem] font-medium hover:text-[var(--accent)]">
                                                        {row.number}
                                                    </Link>
                                                ) : (
                                                    <span className="font-mono text-[0.8125rem] font-medium">{row.number}</span>
                                                )}
                                                <p className="text-[0.71875rem] text-[var(--muted)]">
                                                    {row.status_label}
                                                    {row.resolution_label ? ` · ${row.resolution_label}` : ''} · {dateTime(row.created_at)}
                                                </p>
                                            </div>
                                            <span className="shrink-0 font-mono text-[0.8125rem] tabular">{money(row.total)}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    )}
                </div>

                <div className="flex flex-col gap-4">
                    <Card>
                        <CardHeader title="Cliente" />
                        <p className="text-[0.875rem] font-medium">{order.customer.name}</p>
                        <p className="font-mono text-[0.78125rem] text-[var(--muted)] tabular">{order.customer.document_number}</p>
                        {order.customer.phone && <p className="font-mono text-[0.78125rem] text-[var(--muted)] tabular">{order.customer.phone}</p>}
                        {can('customers.show.view') && (
                            <Link
                                href={route('customers.show', order.customer.id)}
                                className="mt-2 inline-block text-[0.78125rem] font-medium text-[var(--accent)] hover:underline"
                            >
                                Ver ficha del cliente
                            </Link>
                        )}
                    </Card>

                    <Card>
                        <CardHeader title="Entrega" />
                        <dl className="flex flex-col gap-2.5 text-[0.8125rem]">
                            <Row label="Direccion" value={order.delivery_address} />
                            {order.customer.neighborhood && <Row label="Barrio" value={order.customer.neighborhood} />}
                            <Row
                                label="Franja"
                                value={
                                    order.delivery_slot_start
                                        ? `${dateTime(order.delivery_slot_start)}${order.delivery_slot_end ? ` – ${time(order.delivery_slot_end)}` : ''}`
                                        : 'Sin franja'
                                }
                            />
                            <Row label="Domiciliario" value={order.courier?.name ?? 'Sin asignar'} />
                            <Row label="Sede" value={order.store ?? '—'} />
                            <Row label="Entregado" value={order.delivered_at ? dateTime(order.delivered_at) : '—'} />
                        </dl>
                        {order.delivery_notes && (
                            <p className="mt-3 rounded-control bg-[var(--surface-2)] px-3 py-2 text-[0.78125rem] text-[var(--muted)]">{order.delivery_notes}</p>
                        )}
                    </Card>

                    <Card>
                        <CardHeader title="Pago" />
                        <dl className="flex flex-col gap-2.5 text-[0.8125rem]">
                            <Row label="Medio" value={order.payment_label} />
                            {order.store_credit_amount > 0 && <Row label="Saldo a favor" value={money(order.store_credit_amount)} />}
                            <div className="flex items-center justify-between">
                                <dt className="text-[var(--muted)]">Estado</dt>
                                <dd>
                                    <Badge tone={order.payment_status === 'paid' ? 'accent' : 'warn'}>
                                        {order.payment_status === 'paid' ? 'Pagado' : 'Pendiente'}
                                    </Badge>
                                </dd>
                            </div>
                            {order.sale && (
                                <div className="flex items-center justify-between">
                                    <dt className="text-[var(--muted)]">Venta</dt>
                                    <dd className="font-mono text-[0.78125rem]">{order.sale.number}</dd>
                                </div>
                            )}
                        </dl>

                        {order.cancel_reason && (
                            <p className="mt-3 rounded-control bg-[var(--danger-soft)] px-3 py-2 text-[0.78125rem] text-[var(--danger)]">{order.cancel_reason}</p>
                        )}
                    </Card>
                </div>
            </div>

            <Modal
                open={modal === 'pick'}
                onClose={() => setModal(null)}
                title="Alistar el pedido"
                description="Ajusta lo que de verdad se pudo recoger. Los totales se recalculan."
                size="lg"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setModal(null)}>
                            Cancelar
                        </Button>
                        <Button onClick={submitPick} loading={pickForm.processing}>
                            Guardar alistamiento
                        </Button>
                    </>
                }
            >
                <form onSubmit={submitPick}>
                    <Table className="border-0">
                        <THead>
                            <TR>
                                <TH>Articulo</TH>
                                <TH align="right">Pedido</TH>
                                <TH align="right">Alistado</TH>
                                <TH>Nota</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {order.items.filter((item) => !item.is_service).map((item) => {
                                const line = pickForm.data.items.find((entry) => entry.id === item.id);

                                return (
                                    <TR key={item.id}>
                                        <TD>
                                            <p className="text-[0.8125rem] font-medium">{item.name}</p>
                                            <p className="text-[0.6875rem] text-[var(--muted)]">
                                                {item.service_name ? `Para ${item.service_name}: alista lo que de verdad lleva` : item.substitution_label}
                                            </p>
                                        </TD>
                                        <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                            {number(item.quantity)}
                                        </TD>
                                        <TD align="right">
                                            <input
                                                type="number"
                                                min={0}
                                                max={item.quantity}
                                                step="0.001"
                                                value={line?.picked_quantity ?? 0}
                                                onChange={(event) => updatePick(item.id, 'picked_quantity', Number(event.target.value))}
                                                className="h-10 w-24 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2 text-right font-mono text-[0.8125rem] tabular focus:border-[var(--accent)] focus:outline-none"
                                            />
                                        </TD>
                                        <TD>
                                            <input
                                                type="text"
                                                value={line?.pick_note ?? ''}
                                                onChange={(event) => updatePick(item.id, 'pick_note', event.target.value)}
                                                placeholder="Reemplazo, agotado..."
                                                className="h-10 w-full rounded-control border border-[var(--line)] bg-[var(--surface)] px-2 text-[0.78125rem] focus:border-[var(--accent)] focus:outline-none"
                                            />
                                        </TD>
                                    </TR>
                                );
                            })}
                        </TBody>
                    </Table>
                </form>
            </Modal>

            <Modal
                open={modal === 'courier'}
                onClose={() => setModal(null)}
                title="Asignar domiciliario"
                size="sm"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setModal(null)}>
                            Cancelar
                        </Button>
                        <Button onClick={submitCourier} loading={courierForm.processing}>
                            Asignar
                        </Button>
                    </>
                }
            >
                <form onSubmit={submitCourier}>
                    <Select
                        label="Domiciliario"
                        required
                        options={couriers}
                        value={courierForm.data.courier_user_id}
                        onChange={(event) => courierForm.setData('courier_user_id', Number(event.target.value))}
                        error={courierForm.errors.courier_user_id}
                    />
                </form>
            </Modal>

            <PaymentDialog
                open={modal === 'collect'}
                onClose={() => setModal(null)}
                title="Registrar el cobro"
                subtitle={`Pedido ${order.number} · ${order.customer.name}`}
                methods={collectMethods}
                confirmLabel="Registrar el cobro"
                cancelLabel="Cancelar"
                total={(order.sale?.total ?? order.total) - order.store_credit_amount}
                cashRounding={0}
                ticketKey={order.id}
                ticketNumber={order.number}
                submitting={collecting}
                onConfirm={submitCollect}
            />

            <Modal
                open={modal === 'cancel'}
                onClose={() => setModal(null)}
                title="Cancelar el pedido"
                size="sm"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setModal(null)}>
                            Volver
                        </Button>
                        <Button variant="danger" onClick={submitCancel} loading={cancelForm.processing}>
                            Cancelar el pedido
                        </Button>
                    </>
                }
            >
                <form onSubmit={submitCancel}>
                    <Input
                        label="Motivo"
                        autoFocus
                        value={cancelForm.data.note}
                        onChange={(event) => cancelForm.setData('note', event.target.value)}
                        error={cancelForm.errors.note}
                        hint="Queda en la linea de tiempo del pedido."
                    />
                </form>
            </Modal>
        </AppLayout>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-start justify-between gap-3">
            <dt className="shrink-0 text-[var(--muted)]">{label}</dt>
            <dd className="text-right">{value}</dd>
        </div>
    );
}

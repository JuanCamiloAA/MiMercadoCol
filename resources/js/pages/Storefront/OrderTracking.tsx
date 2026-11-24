import { Link, router } from '@inertiajs/react';
import { ArrowUUpLeftIcon, CheckCircleIcon, CircleIcon, PhoneIcon } from '@phosphor-icons/react';
import { useState } from 'react';
import StoreLayout from '@/layouts/StoreLayout';
import { cn } from '@/lib/cn';
import { dateTime, money, number, time, UNIT_SHORT } from '@/lib/format';

interface TrackingProps {
    order: {
        id: number;
        number: string;
        status: string;
        status_label: string;
        payment_label: string;
        payment_status: string;
        delivery_address: string;
        delivery_notes: string | null;
        delivery_slot_start: string | null;
        delivery_slot_end: string | null;
        subtotal: number;
        delivery_fee: number;
        discount_total: number;
        coupon: string | null;
        total: number;
        placed_at: string;
        delivered_at: string | null;
        store: { name: string; phone: string | null } | null;
        courier: string | null;
        items: {
            id: number;
            is_service: boolean;
            /** Un artículo de un servicio: va debajo, como «Incluye». */
            parent_id: number | null;
            name: string | null;
            unit: string | null;
            quantity: number;
            picked_quantity: number | null;
            unit_price: number;
            line_total: number;
            pick_note: string | null;
        }[];
        histories: { id: number; to_status: string; to_label: string; note: string | null; changed_at: string }[];
        /** Lo que pagó (o tiene apartado) el saldo a favor. */
        store_credit_amount: number;
    };
    returns: {
        id: number;
        number: string;
        status: string;
        status_label: string;
        created_at: string;
        total: number;
        reject_reason: string | null;
        resolution: string | null;
        refunded_total: number;
        credited_total: number;
        can_cancel: boolean;
        items: { id: number; description: string; quantity: number; reason_label: string }[];
    }[];
    canRequestReturn: boolean;
}

const RETURN_COLORS: Record<string, string> = {
    requested: 'var(--warn)',
    approved: 'var(--accent)',
    received: 'var(--accent)',
    resolved: 'var(--accent)',
    rejected: 'var(--danger)',
    cancelled: 'var(--muted)',
};

const FLOW = [
    { status: 'new', label: 'Recibido' },
    { status: 'picking', label: 'Alistando' },
    { status: 'ready', label: 'Listo' },
    { status: 'on_route', label: 'En camino' },
    { status: 'delivered', label: 'Entregado' },
];

export default function StorefrontOrderTracking({ order, returns, canRequestReturn }: TrackingProps) {
    const [withdrawing, setWithdrawing] = useState<number | null>(null);

    const withdraw = (id: number) => {
        setWithdrawing(id);
        router.post(route('storefront.returns.cancel', id), {}, { preserveScroll: true, onFinish: () => setWithdrawing(null) });
    };

    const currentIndex = FLOW.findIndex((step) => step.status === order.status);
    const cancelled = order.status === 'cancelled' || order.status === 'returned';

    return (
        <StoreLayout title={`Pedido #${order.number}`} current="orders">
            <h1 className="text-[20px] md:text-[24px]">Pedido #{order.number}</h1>
            <p className="mb-4 text-[13px] text-[var(--muted)]">Hecho el {dateTime(order.placed_at)}</p>

            <div className="grid gap-4 lg:grid-cols-2">

            <section className="rounded-[14px] border border-[var(--line)] bg-[var(--surface)] p-4 lg:col-span-2">
                {cancelled ? (
                    <p className="rounded-[10px] bg-[var(--danger-soft)] px-3 py-2 text-[13.5px] font-medium text-[var(--danger)]">
                        Este pedido quedó {order.status_label.toLowerCase()}.
                    </p>
                ) : (
                    <ol className="flex flex-col gap-0">
                        {FLOW.map((step, index) => {
                            const done = index <= currentIndex;

                            return (
                                <li key={step.status} className="flex gap-3">
                                    <div className="flex flex-col items-center">
                                        {done ? (
                                            <CheckCircleIcon size={20} weight="fill" className="text-[var(--accent)]" />
                                        ) : (
                                            <CircleIcon size={20} className="text-[var(--line-2)]" />
                                        )}
                                        {index < FLOW.length - 1 && (
                                            <span className={cn('w-px flex-1', index < currentIndex ? 'bg-[var(--accent)]' : 'bg-[var(--line)]')} />
                                        )}
                                    </div>
                                    <div className="pb-4">
                                        <p className={cn('text-[14px] font-medium', done ? 'text-[var(--text)]' : 'text-[var(--muted)]')}>{step.label}</p>
                                        {done && (
                                            <p className="text-[12px] text-[var(--muted)]">
                                                {order.histories.find((history) => history.to_status === step.status)
                                                    ? dateTime(order.histories.find((history) => history.to_status === step.status)!.changed_at)
                                                    : ''}
                                            </p>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ol>
                )}

                {order.courier && !cancelled && (
                    <p className="mt-1 rounded-[10px] bg-[var(--accent-soft)] px-3 py-2 text-[13px] text-[var(--accent)]">
                        Tu domiciliario es {order.courier}.
                    </p>
                )}
            </section>

            <section className="rounded-[14px] border border-[var(--line)] bg-[var(--surface)] p-4">
                <h2 className="mb-2 text-[15px] font-semibold">Entrega</h2>
                <p className="text-[13.5px]">{order.delivery_address}</p>
                {order.delivery_notes && <p className="text-[12.5px] text-[var(--muted)]">{order.delivery_notes}</p>}
                {order.delivery_slot_start && (
                    <p className="mt-1 text-[12.5px] text-[var(--muted)] tabular-nums">
                        Franja {time(order.delivery_slot_start)} – {time(order.delivery_slot_end)}
                    </p>
                )}
                {order.store?.phone && (
                    <a
                        href={`tel:${order.store.phone}`}
                        className="mt-3 flex h-11 items-center justify-center gap-2 rounded-[11px] border border-[var(--line)] text-[13.5px] font-medium"
                    >
                        <PhoneIcon size={16} />
                        Llamar a la sede {order.store.name}
                    </a>
                )}
            </section>

            <section className="rounded-[14px] border border-[var(--line)] bg-[var(--surface)] p-4">
                <h2 className="mb-2 text-[15px] font-semibold">Tu pedido</h2>
                <ul className="flex flex-col gap-1.5 text-[13px]">
                    {order.items.map((item) => (
                        <li key={item.id} className={cn('flex items-start justify-between gap-3', item.parent_id !== null && 'pl-3 text-[12px] text-[var(--muted)]')}>
                            <span className="min-w-0">
                                <span className="block truncate">
                                    {item.parent_id !== null ? 'Incluye: ' : ''}
                                    {item.name}
                                    {item.is_service && <span className="ml-1.5 rounded-[6px] bg-[var(--accent-soft)] px-1.5 py-0.5 text-[10.5px] font-semibold text-[var(--accent)]">Servicio</span>}
                                </span>
                                <span className="text-[11.5px] text-[var(--muted)] tabular-nums">
                                    {number(item.picked_quantity ?? item.quantity)} {UNIT_SHORT[item.unit ?? 'unit'] ?? ''}
                                    {item.picked_quantity !== null && item.picked_quantity < item.quantity
                                        ? ` (pediste ${number(item.quantity)})`
                                        : ''}
                                </span>
                                {item.pick_note && <span className="block text-[11.5px] text-[var(--warn)]">{item.pick_note}</span>}
                            </span>
                            <span className="tabular-nums">{item.parent_id !== null && item.line_total === 0 ? 'incluido' : money(item.line_total)}</span>
                        </li>
                    ))}
                </ul>

                <dl className="mt-3 flex flex-col gap-1.5 border-t border-[var(--line)] pt-3 text-[13.5px]">
                    <div className="flex items-center justify-between">
                        <dt className="text-[var(--muted)]">Productos</dt>
                        <dd className="tabular-nums">{money(order.subtotal)}</dd>
                    </div>
                    <div className="flex items-center justify-between">
                        <dt className="text-[var(--muted)]">Domicilio</dt>
                        <dd className="tabular-nums">{order.delivery_fee === 0 ? 'Gratis' : money(order.delivery_fee)}</dd>
                    </div>
                    {order.discount_total > 0 && (
                        <div className="flex items-center justify-between">
                            <dt className="text-[var(--muted)]">{order.coupon ? `Cupón ${order.coupon}` : 'Descuento'}</dt>
                            <dd className="tabular-nums">− {money(order.discount_total)}</dd>
                        </div>
                    )}
                    <div className="flex items-center justify-between border-t border-[var(--line)] pt-2">
                        <dt className="text-[15px] font-semibold">Total</dt>
                        <dd className="text-[20px] font-semibold text-[var(--accent)] tabular-nums">{money(order.total)}</dd>
                    </div>
                    {order.store_credit_amount > 0 && (
                        <div className="flex items-center justify-between">
                            <dt className="text-[var(--muted)]">Con saldo a favor</dt>
                            <dd className="tabular-nums">− {money(order.store_credit_amount)}</dd>
                        </div>
                    )}
                    <div className="flex items-center justify-between">
                        <dt className="text-[var(--muted)]">Pago</dt>
                        <dd>
                            {order.payment_label} · {order.payment_status === 'paid' ? 'pagado' : 'pendiente'}
                        </dd>
                    </div>
                </dl>
            </section>

            {(returns.length > 0 || canRequestReturn) && (
                <section className="rounded-[14px] border border-[var(--line)] bg-[var(--surface)] p-4 lg:col-span-2">
                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-[15px] font-semibold">Devoluciones</h2>
                        {canRequestReturn && (
                            <Link
                                href={route('storefront.returns.create', order.id)}
                                className="inline-flex h-10 items-center gap-1.5 rounded-[11px] border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[13px] font-medium"
                            >
                                <ArrowUUpLeftIcon size={16} />
                                Solicitar devolución
                            </Link>
                        )}
                    </div>
                    {returns.length === 0 ? (
                        <p className="text-[13px] text-[var(--muted)]">¿Algo no llegó bien? Puedes pedir la devolución de lo que quieras dentro del plazo.</p>
                    ) : (
                        <ul className="flex flex-col gap-2">
                            {returns.map((row) => (
                                <li key={row.id} className="rounded-[11px] border border-[var(--line)] p-3 text-[13px]">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="font-semibold">{row.number}</span>
                                        <span className="font-semibold" style={{ color: RETURN_COLORS[row.status] ?? 'var(--muted)' }}>
                                            {row.status_label}
                                        </span>
                                    </div>
                                    <p className="text-[12px] text-[var(--muted)]">
                                        {dateTime(row.created_at)} · {money(row.total)}
                                    </p>
                                    <ul className="mt-1 text-[12px]">
                                        {row.items.map((item) => (
                                            <li key={item.id}>
                                                {number(item.quantity)} × {item.description} · {item.reason_label}
                                            </li>
                                        ))}
                                    </ul>
                                    {row.status === 'rejected' && row.reject_reason && <p className="mt-1 text-[12px] text-[var(--danger)]">No procede: {row.reject_reason}</p>}
                                    {row.status === 'resolved' && (
                                        <p className="mt-1 text-[12px] text-[var(--accent)]">
                                            {row.credited_total > 0
                                                ? `Te abonamos ${money(row.credited_total)} de saldo a favor.`
                                                : `Te devolvimos ${money(row.refunded_total)}.`}
                                        </p>
                                    )}
                                    {row.status === 'approved' && (
                                        <p className="mt-1 text-[12px] text-[var(--muted)]">Aprobada: te contactamos para recoger los artículos, o llévalos a la sede.</p>
                                    )}
                                    {row.can_cancel && (
                                        <button
                                            type="button"
                                            disabled={withdrawing === row.id}
                                            onClick={() => withdraw(row.id)}
                                            className="mt-2 text-[12px] font-medium text-[var(--danger)] hover:underline disabled:opacity-50"
                                        >
                                            Retirar la solicitud
                                        </button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            )}

            <Link
                href={route('storefront.home')}
                className="mt-4 flex h-12 w-full items-center justify-center rounded-[13px] border border-[var(--line)] bg-[var(--surface)] text-[14px] font-medium"
            >
                Seguir comprando
            </Link>
            </div>
        </StoreLayout>
    );
}

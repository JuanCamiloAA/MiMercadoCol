import { Link } from '@inertiajs/react';
import { ClipboardTextIcon } from '@phosphor-icons/react';
import { Badge } from '@/components/ui/Misc';
import { Pagination } from '@/components/ui/Table';
import StoreLayout from '@/layouts/StoreLayout';
import { dateTime, money } from '@/lib/format';
import type { BadgeTone } from '@/components/ui/Misc';
import type { Paginated } from '@/types';

interface OrderRow {
    id: number;
    number: string;
    status: string;
    status_label: string;
    total: number;
    placed_at: string;
}

interface OrdersProps {
    orders: Paginated<OrderRow>;
}

const TONES: Record<string, BadgeTone> = {
    new: 'info',
    picking: 'warn',
    ready: 'info',
    on_route: 'warn',
    delivered: 'accent',
    cancelled: 'danger',
    returned: 'danger',
};

export default function StorefrontOrders({ orders }: OrdersProps) {
    return (
        <StoreLayout title="Mis pedidos" current="orders">
            <h1 className="mb-3 text-[20px]">Mis pedidos</h1>

            {orders.data.length === 0 ? (
                <div className="flex flex-col items-center gap-3 py-16 text-center">
                    <ClipboardTextIcon size={36} className="text-[var(--muted)]" />
                    <p className="text-[14px] text-[var(--muted)]">Todavía no has hecho ningún pedido.</p>
                    <Link
                        href={route('storefront.home')}
                        className="flex h-11 items-center rounded-[12px] bg-[var(--accent)] px-5 text-[14px] font-semibold text-[var(--accent-ink)]"
                    >
                        Ir a la tienda
                    </Link>
                </div>
            ) : (
                <>
                    <ul className="flex flex-col gap-2.5 md:grid md:grid-cols-2 xl:grid-cols-3">
                        {orders.data.map((order) => (
                            <li key={order.id}>
                                <Link
                                    href={route('storefront.orders.show', order.id)}
                                    className="flex items-center justify-between gap-3 rounded-[14px] border border-[var(--line)] bg-[var(--surface)] p-3.5"
                                >
                                    <span className="min-w-0">
                                        <span className="block text-[14px] font-semibold">#{order.number}</span>
                                        <span className="block text-[12px] text-[var(--muted)]">{dateTime(order.placed_at)}</span>
                                    </span>
                                    <span className="shrink-0 text-right">
                                        <Badge tone={TONES[order.status] ?? 'neutral'}>{order.status_label}</Badge>
                                        <span className="mt-1 block text-[14px] font-semibold tabular-nums">{money(order.total)}</span>
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>

                    <Pagination meta={orders} />
                </>
            )}
        </StoreLayout>
    );
}

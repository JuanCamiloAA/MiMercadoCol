import { Link } from '@inertiajs/react';
import { ClipboardTextIcon, MotorcycleIcon, PlusIcon } from '@phosphor-icons/react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader, StatCard } from '@/components/ui/Card';
import { Select } from '@/components/ui/Field';
import { Badge, PageHeader, SearchInput } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { dateTime, money, number, percent, time } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { BadgeTone } from '@/components/ui/Misc';
import type { Paginated } from '@/types';

interface OrderRow {
    id: number;
    number: string;
    status: string;
    status_label: string;
    channel: string;
    channel_label: string;
    payment_method: string;
    payment_label: string;
    payment_status: string;
    customer: string | null;
    customer_phone: string | null;
    courier: string | null;
    items_count: number;
    total: number;
    delivery_slot_start: string | null;
    delivery_slot_end: string | null;
    placed_at: string;
}

interface OrdersIndexProps {
    orders: Paginated<OrderRow>;
    filters: { search: string; status: string; channel: string };
    statusCounts: { status: string; label: string; count: number }[];
    couriers: { value: number; label: string }[];
    fulfillment: { total: number; delivered: number; cancelled: number; rate: number };
}

export const STATUS_TONES: Record<string, BadgeTone> = {
    new: 'info',
    picking: 'warn',
    ready: 'info',
    on_route: 'warn',
    delivered: 'accent',
    cancelled: 'danger',
    returned: 'danger',
};

export default function OrdersIndex({ orders, filters: initialFilters, statusCounts, couriers, fulfillment }: OrdersIndexProps) {
    const { can } = usePermission();
    const { filters, update, reset, hasActive } = useFilters('orders.index', initialFilters);

    return (
        <AppLayout title="Pedidos">
            <PageHeader
                title="Pedidos"
                description="Pedidos de la tienda en línea, WhatsApp y mostrador de la sede activa."
                actions={
                    can('orders.index.create') ? (
                        <ButtonLink href={route('orders.create')} icon={<PlusIcon size={16} weight="bold" />}>
                            Nuevo pedido
                        </ButtonLink>
                    ) : undefined
                }
            />

            {can('sales.index.view') && (
                <p className="mb-4 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 py-2 text-[0.78125rem] text-[var(--muted)]">
                    Aquí van los encargos y domicilios, que se alistan y se entregan. Lo que se cobró en la caja está en{' '}
                    <Link href={route('sales.index')} className="font-medium text-[var(--accent)] hover:underline">
                        Ventas
                    </Link>
                    .
                </p>
            )}

            <div className="mb-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Pedidos (30 días)" value={number(fulfillment.total)} tone="neutral" icon={<ClipboardTextIcon size={16} weight="bold" />} />
                <StatCard label="Entregados" value={number(fulfillment.delivered)} />
                <StatCard label="Cancelados" value={number(fulfillment.cancelled)} tone={fulfillment.cancelled > 0 ? 'warn' : 'neutral'} />
                <StatCard label="Cumplimiento" value={percent(fulfillment.rate)} />
            </div>

            <div className="mb-4 flex flex-wrap gap-1.5">
                <button
                    type="button"
                    onClick={() => update('status', '')}
                    className={`h-9 rounded-control border px-3 text-[0.8125rem] font-medium transition-colors ${
                        filters.status === ''
                            ? 'border-[var(--accent)] bg-[var(--accent)] text-[var(--accent-ink)]'
                            : 'border-[var(--line)] bg-[var(--surface)] hover:bg-[var(--surface-2)]'
                    }`}
                >
                    Todos
                </button>
                {statusCounts.map((item) => (
                    <button
                        key={item.status}
                        type="button"
                        onClick={() => update('status', item.status)}
                        className={`flex h-9 items-center gap-2 rounded-control border px-3 text-[0.8125rem] font-medium transition-colors ${
                            filters.status === item.status
                                ? 'border-[var(--accent)] bg-[var(--accent)] text-[var(--accent-ink)]'
                                : 'border-[var(--line)] bg-[var(--surface)] hover:bg-[var(--surface-2)]'
                        }`}
                    >
                        {item.label}
                        <span className="font-mono text-[0.71875rem] tabular opacity-70">{item.count}</span>
                    </button>
                ))}
            </div>

            <div className="grid gap-4 xl:grid-cols-[1fr_300px]">
                <div>
                    <Card className="mb-4">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <SearchInput
                                placeholder="Número de pedido o cliente"
                                value={filters.search}
                                onChange={(event) => update('search', event.target.value)}
                            />
                            <div className="flex items-start gap-2">
                                <div className="flex-1">
                                    <Select
                                        options={[
                                            { value: 'app', label: 'Tienda en línea' },
                                            { value: 'whatsapp', label: 'WhatsApp' },
                                            { value: 'counter', label: 'Mostrador' },
                                        ]}
                                        placeholder="Todos los canales"
                                        value={filters.channel}
                                        onChange={(event) => update('channel', event.target.value)}
                                    />
                                </div>
                                {hasActive && (
                                    <Button variant="ghost" onClick={reset}>
                                        Limpiar
                                    </Button>
                                )}
                            </div>
                        </div>
                    </Card>

                    {orders.data.length === 0 ? (
                        <EmptyState title="Ningún pedido coincide" description="Cambia los filtros o crea uno desde el mostrador." icon={<ClipboardTextIcon size={24} />} />
                    ) : (
                        <>
                            <Table>
                                <THead>
                                    <TR>
                                        <TH>Pedido</TH>
                                        <TH>Cliente</TH>
                                        <TH>Entrega</TH>
                                        <TH>Domiciliario</TH>
                                        <TH align="right">Total</TH>
                                        <TH align="center">Pago</TH>
                                        <TH align="center">Estado</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {orders.data.map((order) => (
                                        <TR key={order.id}>
                                            <TD>
                                                {can('orders.show.view') ? (
                                                    <Link
                                                        href={route('orders.show', order.id)}
                                                        className="font-mono text-[0.84375rem] font-medium hover:text-[var(--accent)]"
                                                    >
                                                        #{order.number}
                                                    </Link>
                                                ) : (
                                                    <span className="font-mono text-[0.84375rem] font-medium">#{order.number}</span>
                                                )}
                                                <p className="text-[0.71875rem] text-[var(--muted)]">
                                                    {order.channel_label} · {order.items_count} artículos
                                                </p>
                                            </TD>
                                            <TD>
                                                <p className="text-[0.8125rem]">{order.customer ?? '—'}</p>
                                                <p className="font-mono text-[0.71875rem] text-[var(--muted)] tabular">{order.customer_phone ?? ''}</p>
                                            </TD>
                                            <TD className="text-[0.78125rem] text-[var(--muted)]">
                                                {order.delivery_slot_start ? (
                                                    <>
                                                        {dateTime(order.delivery_slot_start)}
                                                        {order.delivery_slot_end ? ` – ${time(order.delivery_slot_end)}` : ''}
                                                    </>
                                                ) : (
                                                    'Sin franja'
                                                )}
                                            </TD>
                                            <TD className="text-[0.78125rem] text-[var(--muted)]">{order.courier ?? '—'}</TD>
                                            <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                                {money(order.total)}
                                            </TD>
                                            <TD align="center">
                                                <Badge tone={order.payment_status === 'paid' ? 'accent' : 'neutral'}>
                                                    {order.payment_status === 'paid' ? 'Pagado' : order.payment_label}
                                                </Badge>
                                            </TD>
                                            <TD align="center">
                                                <Badge tone={STATUS_TONES[order.status] ?? 'neutral'}>{order.status_label}</Badge>
                                            </TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>

                            <Pagination meta={orders} />
                        </>
                    )}
                </div>

                <Card className="h-fit">
                    <CardHeader title="Domiciliarios" description="Quien puede recibir pedidos en esta sede." />
                    {couriers.length === 0 ? (
                        <p className="text-[0.8125rem] text-[var(--muted)]">No hay domiciliarios habilitados en esta sede.</p>
                    ) : (
                        <ul className="flex flex-col gap-2">
                            {couriers.map((courier) => (
                                <li key={courier.value} className="flex items-center gap-2.5 rounded-control border border-[var(--line)] px-3 py-2">
                                    <MotorcycleIcon size={18} className="text-[var(--muted)]" />
                                    <span className="text-[0.8125rem]">{courier.label}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}

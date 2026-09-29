import { Link } from '@inertiajs/react';
import {
    CashRegisterIcon,
    CreditCardIcon,
    ReceiptIcon,
    TrendUpIcon,
    WarningIcon,
} from '@phosphor-icons/react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader, StatCard } from '@/components/ui/Card';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { EmptyState } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { money, number, time } from '@/lib/format';
import { usePermission } from '@/lib/permissions';

interface Metric {
    value: number;
    previous: number;
    change: number | null;
    extra: number | null;
}

interface DashboardData {
    metrics: {
        sales_total: Metric;
        tickets: Metric;
        average_ticket: Metric;
        receivable: Metric;
    };
    sales_by_hour: { hour: string; total: number }[];
    registers: {
        id: number;
        name: string;
        code: string;
        is_open: boolean;
        cashier: string | null;
        opened_at: string | null;
        expected_amount: number | null;
    }[];
    recent_orders: {
        id: number;
        number: string;
        customer: string | null;
        status: string;
        status_label: string;
        total: number;
        placed_at: string;
    }[];
    top_products: { product_id: number; name: string; quantity: number; total: number }[];
    stock_alerts: { product_id: number; name: string; sku: string; quantity: number; min_stock: number }[];
}

interface DashboardProps {
    data: DashboardData | null;
    canSeeTotals: boolean;
}

const ORDER_TONES: Record<string, 'accent' | 'warn' | 'danger' | 'neutral' | 'info'> = {
    new: 'info',
    picking: 'warn',
    ready: 'info',
    on_route: 'warn',
    delivered: 'accent',
    cancelled: 'danger',
    returned: 'danger',
};

export default function DashboardIndex({ data, canSeeTotals }: DashboardProps) {
    const { can } = usePermission();

    if (!data) {
        return (
            <AppLayout title="Inicio">
                <EmptyState
                    title="Todavia no tienes una sede asignada"
                    description="Pidele a un administrador que te habilite una sede para empezar a operar."
                />
            </AppLayout>
        );
    }

    const hasSales = data.sales_by_hour.some((point) => point.total > 0);

    return (
        <AppLayout title="Inicio">
            <PageHeader title="Buen dia" description="Resumen de hoy en la sede activa" />

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {canSeeTotals && (
                    <StatCard
                        label="Ventas de hoy"
                        value={money(data.metrics.sales_total.value)}
                        change={data.metrics.sales_total.change}
                        caption="frente a ayer"
                        icon={<TrendUpIcon size={16} weight="bold" />}
                    />
                )}
                <StatCard
                    label="Tiquetes"
                    value={number(data.metrics.tickets.value)}
                    change={data.metrics.tickets.change}
                    caption="frente a ayer"
                    icon={<ReceiptIcon size={16} weight="bold" />}
                    tone="neutral"
                />
                {canSeeTotals && (
                    <StatCard
                        label="Tiquete promedio"
                        value={money(data.metrics.average_ticket.value)}
                        change={data.metrics.average_ticket.change}
                        caption="frente a ayer"
                        icon={<CashRegisterIcon size={16} weight="bold" />}
                        tone="neutral"
                    />
                )}
                {canSeeTotals && (
                    <StatCard
                        label="Cartera por cobrar"
                        value={money(data.metrics.receivable.value)}
                        caption={
                            data.metrics.receivable.extra ? (
                                <span className="text-[var(--danger)]">{money(data.metrics.receivable.extra)} en mora</span>
                            ) : (
                                'toda al dia'
                            )
                        }
                        icon={<CreditCardIcon size={16} weight="bold" />}
                        tone={data.metrics.receivable.extra ? 'danger' : 'accent'}
                    />
                )}
                {!canSeeTotals && (
                    <StatCard
                        label="Alertas de inventario"
                        value={number(data.stock_alerts.length)}
                        caption="articulos bajo el minimo"
                        icon={<WarningIcon size={16} weight="bold" />}
                        tone={data.stock_alerts.length > 0 ? 'warn' : 'neutral'}
                    />
                )}
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader title="Ventas por hora" description="Total cobrado en cada franja de hoy" />
                    {hasSales ? (
                        <div className="h-[16.25rem] w-full">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={data.sales_by_hour} margin={{ top: 4, right: 4, bottom: 0, left: -12 }}>
                                    <CartesianGrid strokeDasharray="3 3" stroke="var(--line)" vertical={false} />
                                    <XAxis
                                        dataKey="hour"
                                        tickLine={false}
                                        axisLine={{ stroke: 'var(--line)' }}
                                        tick={{ fill: 'var(--muted)', fontSize: 11, fontFamily: 'IBM Plex Mono' }}
                                        interval={1}
                                    />
                                    <YAxis
                                        tickLine={false}
                                        axisLine={false}
                                        width={62}
                                        tick={{ fill: 'var(--muted)', fontSize: 11, fontFamily: 'IBM Plex Mono' }}
                                        tickFormatter={(value: number) => (value >= 1000 ? `${Math.round(value / 1000)}k` : String(value))}
                                    />
                                    <Tooltip
                                        cursor={{ fill: 'var(--surface-2)' }}
                                        contentStyle={{
                                            background: 'var(--surface)',
                                            border: '1px solid var(--line)',
                                            borderRadius: 10,
                                            fontSize: 12,
                                        }}
                                        labelStyle={{ color: 'var(--muted)' }}
                                        formatter={(value: number) => [money(value), 'Ventas']}
                                    />
                                    <Bar dataKey="total" fill="var(--accent)" radius={[5, 5, 0, 0]} maxBarSize={28} />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    ) : (
                        <EmptyState title="Aun no hay ventas hoy" description="La grafica se llena a medida que se cobra en las cajas." />
                    )}
                </Card>

                <Card>
                    <CardHeader title="Cajas" description="Estado de los turnos de la sede" />
                    <ul className="flex flex-col gap-2">
                        {data.registers.length === 0 && (
                            <li className="text-[0.8125rem] text-[var(--muted)]">Esta sede no tiene cajas registradas.</li>
                        )}
                        {data.registers.map((register) => (
                            <li
                                key={register.id}
                                className="flex items-center justify-between gap-3 rounded-control border border-[var(--line)] px-3 py-2.5"
                            >
                                <div className="min-w-0">
                                    <p className="truncate text-[0.84375rem] font-medium text-[var(--text)]">{register.name}</p>
                                    {register.is_open ? (
                                        <p className="truncate text-[0.75rem] text-[var(--muted)]">
                                            {register.cashier} · desde {time(register.opened_at)}
                                        </p>
                                    ) : (
                                        <p className="text-[0.75rem] text-[var(--muted)]">Sin turno abierto</p>
                                    )}
                                </div>
                                {register.is_open ? (
                                    <span className="shrink-0 text-right">
                                        <Badge tone="accent">Abierta</Badge>
                                        <span className="mt-1 block font-mono text-[0.75rem] text-[var(--muted)] tabular">
                                            {money(register.expected_amount ?? 0)}
                                        </span>
                                    </span>
                                ) : can('registers.open.view') ? (
                                    <ButtonLink href={route('registers.opening.create')} size="sm" variant="secondary">
                                        Abrir
                                    </ButtonLink>
                                ) : (
                                    <Badge tone="neutral">Cerrada</Badge>
                                )}
                            </li>
                        ))}
                    </ul>
                </Card>
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <Card>
                    <CardHeader
                        title="Ultimos pedidos"
                        action={
                            can('orders.index.view') ? (
                                <Link href={route('orders.index')} className="text-[0.78125rem] font-medium text-[var(--accent)] hover:underline">
                                    Ver todos
                                </Link>
                            ) : undefined
                        }
                    />
                    {data.recent_orders.length === 0 ? (
                        <p className="text-[0.8125rem] text-[var(--muted)]">Todavia no hay pedidos.</p>
                    ) : (
                        <ul className="flex flex-col gap-2">
                            {data.recent_orders.map((order) => (
                                <li key={order.id} className="flex items-center justify-between gap-3">
                                    <div className="min-w-0">
                                        {can('orders.show.view') ? (
                                            <Link
                                                href={route('orders.show', order.id)}
                                                className="truncate text-[0.84375rem] font-medium text-[var(--text)] hover:text-[var(--accent)]"
                                            >
                                                #{order.number}
                                            </Link>
                                        ) : (
                                            <span className="truncate text-[0.84375rem] font-medium text-[var(--text)]">#{order.number}</span>
                                        )}
                                        <p className="truncate text-[0.75rem] text-[var(--muted)]">{order.customer ?? 'Sin cliente'}</p>
                                    </div>
                                    <div className="shrink-0 text-right">
                                        <Badge tone={ORDER_TONES[order.status] ?? 'neutral'}>{order.status_label}</Badge>
                                        <span className="mt-1 block font-mono text-[0.75rem] text-[var(--muted)] tabular">{money(order.total)}</span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <Card>
                    <CardHeader title="Mas vendidos hoy" />
                    {data.top_products.length === 0 ? (
                        <p className="text-[0.8125rem] text-[var(--muted)]">Aun no se ha vendido nada hoy.</p>
                    ) : (
                        <ul className="flex flex-col gap-2.5">
                            {data.top_products.map((product) => (
                                <li key={product.product_id} className="flex items-center justify-between gap-3">
                                    <span className="min-w-0 truncate text-[0.84375rem] text-[var(--text)]">{product.name}</span>
                                    <span className="shrink-0 text-right">
                                        <span className="block font-mono text-[0.8125rem] text-[var(--text)] tabular">{money(product.total)}</span>
                                        <span className="block font-mono text-[0.71875rem] text-[var(--muted)] tabular">
                                            {number(product.quantity)} und
                                        </span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <Card>
                    <CardHeader
                        title="Alertas de inventario"
                        action={
                            can('inventory.index.view') ? (
                                <Link href={route('inventory.index')} className="text-[0.78125rem] font-medium text-[var(--accent)] hover:underline">
                                    Ver inventario
                                </Link>
                            ) : undefined
                        }
                    />
                    {data.stock_alerts.length === 0 ? (
                        <p className="text-[0.8125rem] text-[var(--muted)]">Ningun articulo esta bajo el minimo.</p>
                    ) : (
                        <ul className="flex flex-col gap-2.5">
                            {data.stock_alerts.map((alert) => (
                                <li key={alert.product_id} className="flex items-center justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="truncate text-[0.84375rem] text-[var(--text)]">{alert.name}</p>
                                        <p className="truncate font-mono text-[0.71875rem] text-[var(--muted)]">{alert.sku}</p>
                                    </div>
                                    <Badge tone={alert.quantity <= 0 ? 'danger' : 'warn'}>
                                        {number(alert.quantity)} / {alert.min_stock}
                                    </Badge>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}

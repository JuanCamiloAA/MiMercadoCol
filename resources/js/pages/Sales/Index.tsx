import { Link } from '@inertiajs/react';
import { ReceiptIcon } from '@phosphor-icons/react';
import { Button } from '@/components/ui/Button';
import { Card, StatCard } from '@/components/ui/Card';
import { Input, Select } from '@/components/ui/Field';
import { Badge, PageHeader, SearchInput } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { dateTime, money, number } from '@/lib/format';
import type { BadgeTone } from '@/components/ui/Misc';
import type { Paginated, SelectOption } from '@/types';

interface SaleRow {
    id: number;
    number: string;
    status: string;
    status_label: string;
    channel: string;
    channel_label: string;
    customer: string | null;
    cashier: string | null;
    seller: string | null;
    register: string | null;
    items_count: number;
    total: number;
    due_total: number;
    sold_at: string;
}

interface SalesIndexProps {
    sales: Paginated<SaleRow>;
    filters: {
        search: string;
        status: string;
        channel: string;
        register: string;
        user: string;
        from: string;
        to: string;
    };
    metrics: { count: number; total: number; average: number; due: number };
    registers: SelectOption[];
    cashiers: SelectOption[];
}

const STATUS_TONES: Record<string, BadgeTone> = {
    paid: 'accent',
    credit: 'warn',
    cancelled: 'danger',
    refunded: 'info',
};

export default function SalesIndex({ sales, filters: initialFilters, metrics, registers, cashiers }: SalesIndexProps) {
    const { filters, update } = useFilters('sales.index', initialFilters);

    // El rango de fechas no se limpia: siempre hay uno, y dejarlo vacio mostraria datos que
    // no corresponden con lo que dicen las casillas.
    const narrowed = ['search', 'status', 'channel', 'register', 'user'] as const;
    const hasActive = narrowed.some((key) => filters[key] !== '');
    const clear = () => narrowed.forEach((key) => update(key, ''));

    return (
        <AppLayout title="Ventas">
            <PageHeader
                title="Ventas"
                description="Lo que se cobró en el mostrador y en la tienda en línea. Los domicilios y encargos van en Pedidos."
            />

            <div className="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Ventas del rango" value={number(metrics.count)} tone="neutral" />
                <StatCard label="Total cobrado" value={money(metrics.total)} icon={<ReceiptIcon size={16} weight="bold" />} />
                <StatCard label="Tiquete promedio" value={money(metrics.average)} tone="neutral" />
                <StatCard label="Pendiente de cobro" value={money(metrics.due)} tone="warn" />
            </div>

            <Card className="mb-4">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <SearchInput
                        placeholder="Número o cliente"
                        value={filters.search}
                        onChange={(event) => update('search', event.target.value)}
                    />
                    <Input type="date" aria-label="Desde" value={filters.from} onChange={(event) => update('from', event.target.value)} />
                    <Input type="date" aria-label="Hasta" value={filters.to} onChange={(event) => update('to', event.target.value)} />
                    <Select
                        options={[
                            { value: 'paid', label: 'Pagada' },
                            { value: 'credit', label: 'A crédito' },
                            { value: 'refunded', label: 'Devuelta' },
                            { value: 'cancelled', label: 'Anulada' },
                        ]}
                        placeholder="Cualquier estado"
                        value={filters.status}
                        onChange={(event) => update('status', event.target.value)}
                    />
                    <Select
                        options={[
                            { value: 'pos', label: 'Mostrador' },
                            { value: 'online', label: 'Tienda en línea' },
                        ]}
                        placeholder="Cualquier origen"
                        value={filters.channel}
                        onChange={(event) => update('channel', event.target.value)}
                    />
                    {registers.length > 0 && (
                        <Select
                            options={registers}
                            placeholder="Cualquier caja"
                            value={filters.register}
                            onChange={(event) => update('register', event.target.value)}
                        />
                    )}
                    {cashiers.length > 0 && (
                        <Select
                            options={cashiers}
                            placeholder="Cualquier cajero"
                            value={filters.user}
                            onChange={(event) => update('user', event.target.value)}
                        />
                    )}
                    {hasActive && (
                        <div className="flex items-start">
                            <Button variant="ghost" onClick={clear}>
                                Limpiar
                            </Button>
                        </div>
                    )}
                </div>
            </Card>

            {sales.data.length === 0 ? (
                <EmptyState
                    title="Ninguna venta en ese rango"
                    description="Cambia las fechas o quita los filtros. Las ventas aparecen aquí apenas se cobran en la caja."
                    icon={<ReceiptIcon size={24} />}
                />
            ) : (
                <>
                    <Table>
                        <THead>
                            <TR>
                                <TH>Venta</TH>
                                <TH>Cliente</TH>
                                <TH>Vendedor</TH>
                                <TH>Caja</TH>
                                <TH align="center">Artículos</TH>
                                <TH align="center">Origen</TH>
                                <TH align="right">Total</TH>
                                <TH align="center">Estado</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {sales.data.map((sale) => (
                                <TR key={sale.id}>
                                    <TD>
                                        <Link
                                            href={route('sales.show', sale.id)}
                                            className="font-mono text-[0.84375rem] font-medium text-[var(--text)] hover:text-[var(--accent)]"
                                        >
                                            {sale.number}
                                        </Link>
                                        <p className="text-[0.71875rem] text-[var(--muted)]">{dateTime(sale.sold_at)}</p>
                                    </TD>
                                    <TD className="text-[0.8125rem]">{sale.customer ?? 'Consumidor final'}</TD>
                                    <TD className="text-[0.78125rem] text-[var(--muted)]">
                                        {sale.seller ?? sale.cashier ?? '—'}
                                        {sale.seller && sale.cashier && sale.seller !== sale.cashier && (
                                            <span className="block text-[0.6875rem]">cobró {sale.cashier}</span>
                                        )}
                                    </TD>
                                    <TD className="text-[0.78125rem] text-[var(--muted)]">{sale.register ?? '—'}</TD>
                                    <TD align="center" className="font-mono text-[0.8125rem] tabular">
                                        {sale.items_count}
                                    </TD>
                                    <TD align="center" className="text-[0.75rem] text-[var(--muted)]">
                                        {sale.channel_label}
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                        {money(sale.total)}
                                        {sale.due_total > 0 && (
                                            <span className="block text-[0.6875rem] text-[var(--warn)]">Debe {money(sale.due_total)}</span>
                                        )}
                                    </TD>
                                    <TD align="center">
                                        <Badge tone={STATUS_TONES[sale.status] ?? 'neutral'}>{sale.status_label}</Badge>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>

                    <Pagination meta={sales} />
                </>
            )}
        </AppLayout>
    );
}

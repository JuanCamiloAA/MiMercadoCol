import { router } from '@inertiajs/react';
import { DownloadSimpleIcon, FilePdfIcon } from '@phosphor-icons/react';
import { useState } from 'react';
import { Bar, BarChart, CartesianGrid, Cell, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { Button } from '@/components/ui/Button';
import { Card, CardHeader, StatCard } from '@/components/ui/Card';
import { Checkbox, Input, Select } from '@/components/ui/Field';
import { Badge, PageHeader, Tabs } from '@/components/ui/Misc';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { money, number, percent } from '@/lib/format';
import { usePermission } from '@/lib/permissions';

interface SalesData {
    summary: {
        total: number;
        tax: number;
        discount: number;
        tickets: number;
        average_ticket: number;
        cost: number;
        margin: number;
        margin_percent: number;
    };
    by_day: { date: string; label: string; total: number; tickets: number }[];
    by_category: { name: string; total: number; quantity: number }[];
    by_method: { method: string; label: string; total: number; count: number }[];
    /** Por medio configurado; `method` es el tipo. La comisión es estimada (Medios de pago). */
    by_payment_method: { payment_method_id: number | null; method: string; label: string; kind_label: string; total: number; count: number; fee: number; net: number }[];
    /** Conciliación de pasarelas: lo aprobado, lo que quedó en ventas o pedidos y lo que falta. */
    gateways: {
        provider: string;
        environment: string;
        payment_method_id: number;
        label: string;
        attempts: number;
        approved_count: number;
        approved_total: number;
        applied_total: number;
        to_reconcile_count: number;
        to_reconcile_total: number;
        failed_count: number;
        expired_count: number;
        refunded_total: number;
        fee: number;
        net: number;
    }[];
    by_employee: { name: string; total: number; tickets: number }[];
    top_products: { name: string; is_service: boolean; total: number; quantity: number; variants: number }[];
    services: {
        by_service: { service_id: number; name: string; quantity: number; times: number; adjusted: number; revenue: number; margin: number; margin_percent: number }[];
        by_product: { product_id: number; name: string; quantity: number; cost: number }[];
    };
}

interface ReportsIndexProps {
    filters: { from: string; to: string; store: string; channel: string; separate: string };
    stores: { value: number; label: string }[];
    sales: SalesData;
    inventory: {
        value: number;
        retail_value: number;
        references: number;
        low: number;
        out: number;
        by_category: { name: string; total: number }[];
    };
    credit: { total: number; overdue: number; customers: number; blocked: number };
    employees: { active: number; inactive: number; with_access: number };
    canExport: { sales: boolean; inventory: boolean; credit: boolean };
}

export default function ReportsIndex({ filters: initialFilters, stores, sales, inventory, credit, employees, canExport }: ReportsIndexProps) {
    const [filters, setFilters] = useState(initialFilters);
    const [tab, setTab] = useState<'sales' | 'inventory' | 'credit' | 'employees'>('sales');
    const { can } = usePermission();

    const apply = (next: Partial<typeof initialFilters>) => {
        const merged = { ...filters, ...next };
        setFilters(merged);
        router.get(route('reports.index'), merged, { preserveState: true, preserveScroll: true, replace: true });
    };

    const average = sales.by_day.length > 0 ? sales.by_day.reduce((sum, day) => sum + day.total, 0) / sales.by_day.length : 0;
    const exportQuery = new URLSearchParams(filters as Record<string, string>).toString();

    return (
        <AppLayout title="Informes">
            <PageHeader
                title="Informes"
                description="Ventas, inventario, cartera y equipo en el rango que elijas."
                actions={
                    tab === 'sales' && canExport.sales ? (
                        <>
                            <a
                                href={`${route('reports.sales.export')}?${exportQuery}`}
                                className="inline-flex h-10 items-center gap-2 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-4 text-sm font-medium hover:bg-[var(--surface-3)]"
                            >
                                <DownloadSimpleIcon size={16} />
                                CSV
                            </a>
                            <a
                                href={`${route('reports.sales.pdf')}?${exportQuery}`}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex h-10 items-center gap-2 rounded-control bg-[var(--accent)] px-4 text-sm font-medium text-[var(--accent-ink)] hover:brightness-110"
                            >
                                <FilePdfIcon size={16} />
                                PDF
                            </a>
                        </>
                    ) : undefined
                }
            />

            <Card className="mb-4">
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <Input label="Desde" type="date" value={filters.from} onChange={(event) => apply({ from: event.target.value })} />
                    <Input label="Hasta" type="date" value={filters.to} onChange={(event) => apply({ to: event.target.value })} />
                    <Select
                        label="Sede"
                        options={stores}
                        placeholder="Todas las sedes"
                        value={filters.store}
                        onChange={(event) => apply({ store: event.target.value })}
                    />
                    <div className="flex items-end gap-2">
                        <div className="flex-1">
                            <Select
                                label="Canal"
                                options={[
                                    { value: 'pos', label: 'Punto de venta' },
                                    { value: 'online', label: 'Tienda en línea' },
                                ]}
                                placeholder="Todos los canales"
                                value={filters.channel}
                                onChange={(event) => apply({ channel: event.target.value })}
                            />
                        </div>
                        <Button
                            variant="ghost"
                            onClick={() =>
                                apply({
                                    from: new Date(Date.now() - 29 * 86400000).toISOString().slice(0, 10),
                                    to: new Date().toISOString().slice(0, 10),
                                    store: '',
                                    channel: '',
                                })
                            }
                        >
                            30 días
                        </Button>
                    </div>
                </div>
            </Card>

            <Tabs
                className="mb-4"
                tabs={[
                    { value: 'sales', label: 'Ventas' },
                    { value: 'inventory', label: 'Inventario' },
                    { value: 'credit', label: 'Cartera' },
                    { value: 'employees', label: 'Equipo' },
                ]}
                active={tab}
                onChange={setTab}
            />

            {tab === 'sales' && (
                <div className="flex flex-col gap-4">
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <StatCard label="Ventas" value={money(sales.summary.total)} />
                        <StatCard label="Tiquetes" value={number(sales.summary.tickets)} tone="neutral" />
                        <StatCard label="Tiquete promedio" value={money(sales.summary.average_ticket)} tone="neutral" />
                        <StatCard label="Margen" value={money(sales.summary.margin)} caption={percent(sales.summary.margin_percent)} />
                    </div>

                    <Card>
                        <CardHeader title="Ventas por día" description="La línea punteada es el promedio del rango." />
                        <div className="h-[17.5rem] w-full">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={sales.by_day} margin={{ top: 4, right: 4, bottom: 0, left: -6 }}>
                                    <CartesianGrid strokeDasharray="3 3" stroke="var(--line)" vertical={false} />
                                    <XAxis
                                        dataKey="label"
                                        tickLine={false}
                                        axisLine={{ stroke: 'var(--line)' }}
                                        tick={{ fill: 'var(--muted)', fontSize: 11, fontFamily: 'IBM Plex Mono' }}
                                        interval="preserveStartEnd"
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
                                        contentStyle={{ background: 'var(--surface)', border: '1px solid var(--line)', borderRadius: 10, fontSize: 12 }}
                                        formatter={(value: number) => [money(value), 'Ventas']}
                                    />
                                    <ReferenceLine y={average} stroke="var(--muted)" strokeDasharray="4 4" />
                                    <Bar dataKey="total" fill="var(--accent)" radius={[5, 5, 0, 0]} maxBarSize={26} />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    </Card>

                    <div className="grid gap-4 lg:grid-cols-2">
                        <Card>
                            <CardHeader title="Ventas por categoría" />
                            <HorizontalBars data={sales.by_category.map((row) => ({ name: row.name, value: row.total }))} />
                        </Card>

                        <Card>
                            <CardHeader title="Ventas por tipo de medio" description="Lo que entró, ya sin el cambio del efectivo." />
                            <HorizontalBars data={sales.by_method.map((row) => ({ name: row.label, value: row.total }))} />
                        </Card>
                    </div>

                    {sales.by_payment_method.length > 0 && (
                        <Card padded={false}>
                            <div className="px-5 pt-5">
                                <CardHeader title="Por medio de pago" description="Por cada medio configurado. La comisión es estimada con lo que anotaste en Medios de pago." />
                            </div>
                            <Table className="rounded-none border-x-0 border-b-0">
                                <THead>
                                    <TR>
                                        <TH>Medio</TH>
                                        <TH align="right">Cobros</TH>
                                        <TH align="right">Total</TH>
                                        <TH align="right">Comisión est.</TH>
                                        <TH align="right">Neto</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {sales.by_method.map((kind) => {
                                        const rows = sales.by_payment_method.filter((row) => row.method === kind.method);
                                        const fee = rows.reduce((sum, row) => sum + row.fee, 0);

                                        return [
                                            ...rows.map((row) => (
                                                <TR key={`${row.method}-${row.payment_method_id ?? 'x'}`}>
                                                    <TD>
                                                        {row.label}
                                                        <span className="block text-[0.75rem] text-[var(--muted)]">{row.kind_label}</span>
                                                    </TD>
                                                    <TD align="right" className="font-mono tabular">
                                                        {number(row.count)}
                                                    </TD>
                                                    <TD align="right" className="font-mono tabular">
                                                        {money(row.total)}
                                                    </TD>
                                                    <TD align="right" className="font-mono tabular text-[var(--muted)]">
                                                        {row.fee > 0 ? money(row.fee) : '—'}
                                                    </TD>
                                                    <TD align="right" className="font-mono tabular">
                                                        {money(row.net)}
                                                    </TD>
                                                </TR>
                                            )),
                                            rows.length > 1 ? (
                                                <TR key={`${kind.method}-subtotal`} className="bg-[var(--surface-2)]">
                                                    <TD className="font-semibold">Subtotal {kind.label.toLowerCase()}</TD>
                                                    <TD align="right" className="font-mono tabular font-semibold">
                                                        {number(kind.count)}
                                                    </TD>
                                                    <TD align="right" className="font-mono tabular font-semibold">
                                                        {money(kind.total)}
                                                    </TD>
                                                    <TD align="right" className="font-mono tabular font-semibold">
                                                        {fee > 0 ? money(fee) : '—'}
                                                    </TD>
                                                    <TD align="right" className="font-mono tabular font-semibold">
                                                        {money(kind.total - fee)}
                                                    </TD>
                                                </TR>
                                            ) : null,
                                        ];
                                    })}
                                </TBody>
                            </Table>
                        </Card>
                    )}

                    {sales.gateways.length > 0 && (
                        <Card padded={false}>
                            <div className="px-5 pt-5">
                                <CardHeader
                                    title="Conciliación de pasarelas"
                                    description="Lo que aprobó cada pasarela frente a lo que quedó en ventas o pedidos. Lo que falta por conciliar entró y todavía no está en ninguna venta."
                                    action={
                                        can('payment_methods.transactions.view') && sales.gateways.some((row) => row.to_reconcile_count > 0) ? (
                                            <Button size="sm" variant="secondary" onClick={() => router.visit(route('payment_methods.transactions.index', { estado: 'conciliar' }))}>
                                                Ver por conciliar
                                            </Button>
                                        ) : undefined
                                    }
                                />
                            </div>
                            <Table className="rounded-none border-x-0 border-b-0">
                                <THead>
                                    <TR>
                                        <TH>Medio</TH>
                                        <TH align="right">Intentos</TH>
                                        <TH align="right">Aprobado</TH>
                                        <TH align="right">En ventas o pedidos</TH>
                                        <TH align="right">Por conciliar</TH>
                                        <TH align="right">Reembolsado</TH>
                                        <TH align="right">Comisión est.</TH>
                                        <TH align="right">Neto</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {sales.gateways.map((row) => (
                                        <TR key={`${row.provider}-${row.environment}-${row.payment_method_id}`}>
                                            <TD>
                                                {row.label}
                                                <span className="block text-[0.75rem] text-[var(--muted)]">
                                                    {row.provider}
                                                    {row.environment === 'sandbox' ? ' · pruebas' : ''} · {number(row.failed_count)} rechazados, {number(row.expired_count)} vencidos
                                                </span>
                                            </TD>
                                            <TD align="right" className="font-mono tabular">
                                                {number(row.attempts)}
                                            </TD>
                                            <TD align="right" className="font-mono tabular">
                                                {money(row.approved_total)}
                                            </TD>
                                            <TD align="right" className="font-mono tabular">
                                                {money(row.applied_total)}
                                            </TD>
                                            <TD align="right" className="font-mono tabular">
                                                {row.to_reconcile_count > 0 ? <Badge tone="warn">{money(row.to_reconcile_total)}</Badge> : '—'}
                                            </TD>
                                            <TD align="right" className="font-mono tabular">
                                                {row.refunded_total > 0 ? money(row.refunded_total) : '—'}
                                            </TD>
                                            <TD align="right" className="font-mono tabular text-[var(--muted)]">
                                                {row.fee > 0 ? money(row.fee) : '—'}
                                            </TD>
                                            <TD align="right" className="font-mono tabular">
                                                {money(row.net)}
                                            </TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        </Card>
                    )}

                    <div className="grid gap-4 lg:grid-cols-2">
                        <Card padded={false}>
                            <div className="px-5 pt-5">
                                <CardHeader title="Ventas por empleado" />
                            </div>
                            <Table className="rounded-none border-x-0 border-b-0">
                                <THead>
                                    <TR>
                                        <TH>Empleado</TH>
                                        <TH align="right">Tiquetes</TH>
                                        <TH align="right">Total</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {sales.by_employee.map((row) => (
                                        <TR key={row.name}>
                                            <TD className="text-[0.8125rem]">{row.name}</TD>
                                            <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                                {row.tickets}
                                            </TD>
                                            <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                                {money(row.total)}
                                            </TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        </Card>

                        <Card padded={false}>
                            <div className="px-5 pt-5">
                                <CardHeader
                                    title="Artículos más vendidos"
                                    action={
                                        <Checkbox
                                            label="Agrupar subartículos"
                                            checked={filters.separate !== '1'}
                                            onChange={(event) => apply({ separate: event.target.checked ? '' : '1' })}
                                        />
                                    }
                                />
                            </div>
                            <Table className="rounded-none border-x-0 border-b-0">
                                <THead>
                                    <TR>
                                        <TH>Artículo</TH>
                                        <TH align="right">Unidades</TH>
                                        <TH align="right">Total</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {sales.top_products.map((row) => (
                                        <TR key={`${row.is_service ? 's' : 'p'}-${row.name}`}>
                                            <TD className="text-[0.8125rem]">
                                                {row.name}
                                                {row.is_service && <Badge tone="info" className="ml-1.5">Servicio</Badge>}
                                                {row.variants > 1 && <Badge className="ml-1.5">{row.variants} subartículos</Badge>}
                                            </TD>
                                            <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                                {number(row.quantity)}
                                            </TD>
                                            <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                                {money(row.total)}
                                            </TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        </Card>
                    </div>

                    {(sales.services.by_service.length > 0 || sales.services.by_product.length > 0) && (
                        <div className="grid gap-4 lg:grid-cols-2">
                            <Card padded={false}>
                                <div className="px-5 pt-5">
                                    <CardHeader title="Servicios vendidos" description="Con sus artículos: el margen incluye lo que consumen." />
                                </div>
                                <Table className="rounded-none border-x-0 border-b-0">
                                    <THead>
                                        <TR>
                                            <TH>Servicio</TH>
                                            <TH align="right">Veces</TH>
                                            <TH align="right">Con ajustes</TH>
                                            <TH align="right">Ingreso</TH>
                                            <TH align="right">Margen</TH>
                                        </TR>
                                    </THead>
                                    <TBody>
                                        {sales.services.by_service.map((row) => (
                                            <TR key={row.service_id}>
                                                <TD className="text-[0.8125rem]">{row.name}</TD>
                                                <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                                    {number(row.times)}
                                                </TD>
                                                <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                                    {number(row.adjusted)}
                                                </TD>
                                                <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                                    {money(row.revenue)}
                                                </TD>
                                                <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                                    {money(row.margin)}
                                                    <span className="block text-[0.6875rem] text-[var(--muted)]">{percent(row.margin_percent)}</span>
                                                </TD>
                                            </TR>
                                        ))}
                                    </TBody>
                                </Table>
                            </Card>

                            <Card padded={false}>
                                <div className="px-5 pt-5">
                                    <CardHeader title="Consumo en servicios" description="Artículos que salieron del inventario a través de servicios." />
                                </div>
                                <Table className="rounded-none border-x-0 border-b-0">
                                    <THead>
                                        <TR>
                                            <TH>Artículo</TH>
                                            <TH align="right">Cantidad</TH>
                                            <TH align="right">Costo</TH>
                                        </TR>
                                    </THead>
                                    <TBody>
                                        {sales.services.by_product.map((row) => (
                                            <TR key={row.product_id}>
                                                <TD className="text-[0.8125rem]">{row.name}</TD>
                                                <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                                    {number(row.quantity)}
                                                </TD>
                                                <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                                    {money(row.cost)}
                                                </TD>
                                            </TR>
                                        ))}
                                    </TBody>
                                </Table>
                            </Card>
                        </div>
                    )}
                </div>
            )}

            {tab === 'inventory' && (
                <div className="flex flex-col gap-4">
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <StatCard label="Valor al costo" value={money(inventory.value)} />
                        <StatCard label="Valor al público" value={money(inventory.retail_value)} tone="neutral" />
                        <StatCard label="Bajo el mínimo" value={number(inventory.low)} tone={inventory.low > 0 ? 'warn' : 'neutral'} />
                        <StatCard label="Agotados" value={number(inventory.out)} tone={inventory.out > 0 ? 'danger' : 'neutral'} />
                    </div>

                    <Card>
                        <CardHeader title="Valor del inventario por categoría" description="Al costo, en la sede seleccionada." />
                        <HorizontalBars data={inventory.by_category.map((row) => ({ name: row.name, value: row.total }))} />
                    </Card>
                </div>
            )}

            {tab === 'credit' && (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard label="Cartera total" value={money(credit.total)} />
                    <StatCard label="En mora" value={money(credit.overdue)} tone={credit.overdue > 0 ? 'danger' : 'neutral'} />
                    <StatCard label="Clientes con deuda" value={number(credit.customers)} tone="neutral" />
                    <StatCard label="Crédito bloqueado" value={number(credit.blocked)} tone={credit.blocked > 0 ? 'warn' : 'neutral'} />
                </div>
            )}

            {tab === 'employees' && (
                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard label="Empleados activos" value={number(employees.active)} />
                    <StatCard label="Con cuenta de acceso" value={number(employees.with_access)} tone="neutral" />
                    <StatCard label="Inactivos" value={number(employees.inactive)} tone="neutral" />
                </div>
            )}
        </AppLayout>
    );
}

function HorizontalBars({ data }: { data: { name: string; value: number }[] }) {
    if (data.length === 0) {
        return <p className="text-[0.8125rem] text-[var(--muted)]">No hay datos en el rango seleccionado.</p>;
    }

    const max = Math.max(...data.map((row) => row.value), 1);

    return (
        <div className="h-[16.25rem] w-full">
            <ResponsiveContainer width="100%" height="100%">
                <BarChart data={data} layout="vertical" margin={{ top: 0, right: 12, bottom: 0, left: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--line)" horizontal={false} />
                    <XAxis
                        type="number"
                        tickLine={false}
                        axisLine={false}
                        tick={{ fill: 'var(--muted)', fontSize: 11, fontFamily: 'IBM Plex Mono' }}
                        tickFormatter={(value: number) => (value >= 1000 ? `${Math.round(value / 1000)}k` : String(value))}
                    />
                    <YAxis
                        type="category"
                        dataKey="name"
                        width={118}
                        tickLine={false}
                        axisLine={false}
                        tick={{ fill: 'var(--muted)', fontSize: 11 }}
                    />
                    <Tooltip
                        cursor={{ fill: 'var(--surface-2)' }}
                        contentStyle={{ background: 'var(--surface)', border: '1px solid var(--line)', borderRadius: 10, fontSize: 12 }}
                        formatter={(value: number) => [money(value), 'Total']}
                    />
                    <Bar dataKey="value" radius={[0, 5, 5, 0]} maxBarSize={18}>
                        {data.map((row) => (
                            <Cell key={row.name} fill={row.value === max ? 'var(--accent)' : 'var(--accent)'} fillOpacity={0.35 + (row.value / max) * 0.65} />
                        ))}
                    </Bar>
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
}

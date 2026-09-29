import { Link } from '@inertiajs/react';
import { ArrowUUpLeftIcon, CheckCircleIcon, DownloadSimpleIcon, EnvelopeSimpleIcon, PackageIcon, PlusIcon } from '@phosphor-icons/react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, StatCard } from '@/components/ui/Card';
import { Input, Select } from '@/components/ui/Field';
import { Badge, PageHeader, SearchInput } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { CUSTOMER_RETURN_TONES } from '@/lib/customer-returns';
import { dateTime, money, number } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { Paginated } from '@/types';
import type { CustomerReturnRow, Option } from '@/types/customer-returns';

interface Filters {
    search: string;
    status: string;
    channel: string;
    resolution: string;
    store: string;
    from: string;
    to: string;
    [key: string]: string;
}

interface CustomerReturnsIndexProps {
    returns: Paginated<CustomerReturnRow>;
    filters: Filters;
    cards: { requested: number; approved: number; received: number; month_value: number };
    statuses: Option[];
    channels: Option[];
    resolutions: Option[];
    stores: Option[];
}

/**
 * Devoluciones de venta: las de caja (resueltas en el acto) y las que piden los clientes de la
 * tienda en línea. Arriba, lo que está esperando a alguien del equipo.
 */
export default function CustomerReturnsIndex({ returns, filters: initial, cards, statuses, channels, resolutions, stores }: CustomerReturnsIndexProps) {
    const { can } = usePermission();
    const { filters, update, reset, hasActive } = useFilters<Filters>('customer_returns.index', initial);

    const exportUrl = () => {
        const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => value !== ''));

        return `${route('customer_returns.export')}?${query.toString()}`;
    };

    return (
        <AppLayout title="Devoluciones de venta">
            <PageHeader
                title="Devoluciones de venta"
                description="Lo que los clientes devuelven en caja o piden devolver desde la tienda en línea, y cómo se les respondió."
                actions={
                    <>
                        <a
                            href={exportUrl()}
                            className="inline-flex h-[calc(2.5rem*var(--density))] items-center gap-2 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-4 text-sm font-medium text-[var(--text)] hover:bg-[var(--surface-3)]"
                        >
                            <DownloadSimpleIcon size={16} />
                            Exportar CSV
                        </a>
                        {can('sales.returns.create') && (
                            <ButtonLink href={route('customer_returns.create')} icon={<PlusIcon size={16} />}>
                                Registrar devolución
                            </ButtonLink>
                        )}
                    </>
                }
            />

            <div className="mb-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Solicitudes por revisar"
                    value={number(cards.requested)}
                    caption="Las pidieron desde la tienda en línea"
                    tone="warn"
                    icon={<EnvelopeSimpleIcon size={16} weight="bold" />}
                />
                <StatCard label="Por recibir" value={number(cards.approved)} caption="Aprobadas, la mercancía no ha llegado" tone="neutral" icon={<PackageIcon size={16} weight="bold" />} />
                <StatCard
                    label="Por resolver"
                    value={number(cards.received)}
                    caption="Recibidas: falta devolver la plata o el saldo"
                    tone="warn"
                    icon={<CheckCircleIcon size={16} weight="bold" />}
                />
                <StatCard label="Devuelto este mes" value={money(cards.month_value)} caption="Valor de lo resuelto" tone="accent" icon={<ArrowUUpLeftIcon size={16} weight="bold" />} />
            </div>

            <Card className="mb-4">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8">
                    <SearchInput
                        containerClassName="sm:col-span-2"
                        placeholder="DV, venta o cliente"
                        value={filters.search}
                        onChange={(event) => update('search', event.target.value)}
                    />
                    <Select options={statuses} placeholder="Cualquier estado" value={filters.status} onChange={(event) => update('status', event.target.value)} />
                    <Select options={channels} placeholder="Caja o tienda" value={filters.channel} onChange={(event) => update('channel', event.target.value)} />
                    <Select options={resolutions} placeholder="Cualquier respuesta" value={filters.resolution} onChange={(event) => update('resolution', event.target.value)} />
                    <Select options={stores} placeholder="Cualquier sede" value={filters.store} onChange={(event) => update('store', event.target.value)} />
                    <Input type="date" aria-label="Desde" value={filters.from} onChange={(event) => update('from', event.target.value)} />
                    <Input type="date" aria-label="Hasta" value={filters.to} onChange={(event) => update('to', event.target.value)} />
                    {hasActive && (
                        <Button variant="ghost" onClick={reset}>
                            Limpiar
                        </Button>
                    )}
                </div>
            </Card>

            {returns.data.length === 0 ? (
                <EmptyState
                    title={hasActive ? 'Nada con esos filtros' : 'Todavía no hay devoluciones de venta'}
                    description="Las devoluciones de caja y las solicitudes de la tienda en línea quedan aquí, con su documento DV."
                    icon={<ArrowUUpLeftIcon size={24} />}
                    action={
                        can('sales.returns.create') && !hasActive ? (
                            <ButtonLink href={route('customer_returns.create')}>Registrar la primera</ButtonLink>
                        ) : undefined
                    }
                />
            ) : (
                <Card padded={false}>
                    <Table>
                        <THead>
                            <TR>
                                <TH>Número</TH>
                                <TH>Cliente</TH>
                                <TH>Venta</TH>
                                <TH>Origen</TH>
                                <TH align="right">Valor</TH>
                                <TH>Respuesta</TH>
                                <TH>Estado</TH>
                                <TH align="right" />
                            </TR>
                        </THead>
                        <TBody>
                            {returns.data.map((row) => (
                                <TR key={row.id}>
                                    <TD>
                                        <p className="font-mono text-[0.8125rem] font-medium">{row.number}</p>
                                        <p className="text-[0.71875rem] text-[var(--muted)]">{dateTime(row.created_at)}</p>
                                    </TD>
                                    <TD className="text-[0.8125rem]">{row.customer ?? <span className="text-[var(--muted)]">Consumidor final</span>}</TD>
                                    <TD className="text-[0.78125rem]">
                                        {row.sale &&
                                            (can('sales.index.view') ? (
                                                <Link href={route('sales.show', row.sale.id)} className="font-mono font-medium text-[var(--accent)] hover:underline">
                                                    {row.sale.number}
                                                </Link>
                                            ) : (
                                                <span className="font-mono">{row.sale.number}</span>
                                            ))}
                                        {row.order && <span className="block text-[var(--muted)]">Pedido #{row.order.number}</span>}
                                    </TD>
                                    <TD className="text-[0.78125rem]">
                                        <span className="block">{row.origin_label}</span>
                                        <span className="block text-[var(--muted)]">{row.store ?? '—'}</span>
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                        {money(row.total)}
                                    </TD>
                                    <TD className="text-[0.78125rem]">{row.resolution_label ?? '—'}</TD>
                                    <TD>
                                        <Badge tone={CUSTOMER_RETURN_TONES[row.status]}>{row.status_label}</Badge>
                                    </TD>
                                    <TD align="right">
                                        <ButtonLink href={route('customer_returns.show', row.id)} size="sm" variant="ghost">
                                            Ver
                                        </ButtonLink>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                    <Pagination meta={returns} />
                </Card>
            )}
        </AppLayout>
    );
}

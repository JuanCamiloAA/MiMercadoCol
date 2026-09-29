import { ArrowUUpLeftIcon, DownloadSimpleIcon, HourglassMediumIcon, NotePencilIcon, PlusIcon } from '@phosphor-icons/react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, StatCard } from '@/components/ui/Card';
import { Input, Select } from '@/components/ui/Field';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { date, money, number } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { Paginated } from '@/types';
import { RETURN_TONES } from '@/lib/supplier-returns';
import type { Option, SupplierReturnRow } from '@/types/supplier-returns';

interface Filters {
    status: string;
    supplier: string;
    store: string;
    from: string;
    to: string;
    kind: string;
    reason: string;
    [key: string]: string;
}

interface SupplierReturnsIndexProps {
    returns: Paginated<SupplierReturnRow>;
    filters: Filters;
    cards: { pending_count: number; pending_value: number; drafts: number; month_value: number };
    statuses: Option[];
    reasons: Option[];
    suppliers: Option[];
    stores: Option[];
    kinds: Option[];
}

/**
 * Devoluciones a proveedor.
 *
 * Lo que importa arriba es lo pendiente: mercancía que ya salió y por la que el proveedor todavía
 * no ha respondido. Es plata a favor que, si nadie la persigue, se pierde.
 */
export default function SupplierReturnsIndex({ returns, filters: initial, cards, statuses, reasons, suppliers, stores, kinds }: SupplierReturnsIndexProps) {
    const { can } = usePermission();
    const { filters, update, reset, hasActive } = useFilters<Filters>('supplier_returns.index', initial);

    const exportUrl = () => {
        const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => value !== ''));

        return `${route('supplier_returns.export')}?${query.toString()}`;
    };

    return (
        <AppLayout title="Devoluciones a proveedor">
            <PageHeader
                title="Devoluciones a proveedor"
                description="Mercancía que vuelve al proveedor, con o sin orden de compra, y lo que él responde."
                actions={
                    <>
                        <a
                            href={exportUrl()}
                            className="inline-flex h-[calc(2.5rem*var(--density))] items-center gap-2 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-4 text-sm font-medium text-[var(--text)] hover:bg-[var(--surface-3)]"
                        >
                            <DownloadSimpleIcon size={16} />
                            Exportar CSV
                        </a>
                        {can('purchases.returns.create') && (
                            <ButtonLink href={route('supplier_returns.create')} icon={<PlusIcon size={16} />}>
                                Nueva devolución
                            </ButtonLink>
                        )}
                    </>
                }
            />

            <div className="mb-4 grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Pendientes de respuesta"
                    value={money(cards.pending_value)}
                    caption={`${number(cards.pending_count)} devoluciones a favor`}
                    tone="warn"
                    icon={<HourglassMediumIcon size={16} weight="bold" />}
                />
                <StatCard
                    label="Borradores"
                    value={number(cards.drafts)}
                    caption="Todavía no ha salido nada"
                    tone="neutral"
                    icon={<NotePencilIcon size={16} weight="bold" />}
                />
                <StatCard
                    label="Devuelto este mes"
                    value={money(cards.month_value)}
                    caption="Despachado, sin contar lo rechazado"
                    tone="accent"
                    icon={<ArrowUUpLeftIcon size={16} weight="bold" />}
                />
            </div>

            <Card className="mb-4">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8">
                    <Select options={statuses} placeholder="Cualquier estado" value={filters.status} onChange={(event) => update('status', event.target.value)} />
                    <Select options={suppliers} placeholder="Cualquier proveedor" value={filters.supplier} onChange={(event) => update('supplier', event.target.value)} />
                    <Select options={stores} placeholder="Cualquier sede" value={filters.store} onChange={(event) => update('store', event.target.value)} />
                    <Select
                        options={kinds}
                        placeholder="Con orden o suelta"
                        value={filters.kind}
                        onChange={(event) => update('kind', event.target.value)}
                    />
                    <Select options={reasons} placeholder="Cualquier motivo" value={filters.reason} onChange={(event) => update('reason', event.target.value)} />
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
                    title={hasActive ? 'Nada con esos filtros' : 'Todavía no hay devoluciones a proveedor'}
                    description="Cuando devuelvas mercancía vencida, averiada o que no correspondía, quedará aquí con lo que responda el proveedor."
                    icon={<ArrowUUpLeftIcon size={24} />}
                    action={
                        can('purchases.returns.create') && !hasActive ? (
                            <ButtonLink href={route('supplier_returns.create')}>Crear la primera</ButtonLink>
                        ) : undefined
                    }
                />
            ) : (
                <Card padded={false}>
                    <Table>
                        <THead>
                            <TR>
                                <TH>Número</TH>
                                <TH>Proveedor</TH>
                                <TH>Orden</TH>
                                <TH>Sede y bodega</TH>
                                <TH align="right">Unidades</TH>
                                <TH align="right">Valor</TH>
                                <TH align="right">Pendiente a favor</TH>
                                <TH>Estado</TH>
                                <TH align="right" />
                            </TR>
                        </THead>
                        <TBody>
                            {returns.data.map((row) => (
                                <TR key={row.id}>
                                    <TD>
                                        <p className="font-mono text-[0.8125rem] font-medium">{row.number}</p>
                                        <p className="text-[0.71875rem] text-[var(--muted)]">{date(row.dispatched_at ?? row.created_at)}</p>
                                    </TD>
                                    <TD className="text-[0.8125rem]">{row.supplier_name ?? '—'}</TD>
                                    <TD className="font-mono text-[0.78125rem]">{row.order_code ?? <span className="font-sans text-[var(--muted)]">Suelta</span>}</TD>
                                    <TD className="text-[0.78125rem]">
                                        <span className="block">{row.store ?? '—'}</span>
                                        <span className="block text-[var(--muted)]">{row.warehouse ?? '—'}</span>
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                        {number(row.units)}
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                        {money(row.total)}
                                    </TD>
                                    <TD
                                        align="right"
                                        className="font-mono text-[0.8125rem] font-medium tabular"
                                        style={{ color: row.pending_total > 0 ? 'var(--warn)' : undefined }}
                                    >
                                        {row.pending_total > 0 ? money(row.pending_total) : '—'}
                                    </TD>
                                    <TD>
                                        <Badge tone={RETURN_TONES[row.status]}>{row.status_label}</Badge>
                                    </TD>
                                    <TD align="right">
                                        <ButtonLink href={route('supplier_returns.show', row.id)} size="sm" variant="ghost">
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

import { Link, router } from '@inertiajs/react';
import { CaretLeftIcon, DownloadSimpleIcon, InfoIcon, StarIcon } from '@phosphor-icons/react';
import { useEffect, useMemo, useState } from 'react';
import { StoreStockSelect } from '@/components/storefront-manage/StoreStockSelect';
import { Button } from '@/components/ui/Button';
import { Card, StatCard } from '@/components/ui/Card';
import { Checkbox, Select } from '@/components/ui/Field';
import { Badge, PageHeader, SearchInput } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import { date, number } from '@/lib/format';
import type { ManageTab, OutOfStockProps, OutOfStockRow } from '@/types/storefront-manage';

const TABS: { value: ManageTab; label: string }[] = [
    { value: 'products', label: 'Artículos' },
    { value: 'families', label: 'Con subartículos' },
    { value: 'services', label: 'Servicios' },
];

/** «hoy», «ayer», «hace 3 días»; null es que no se sabe desde cuándo. */
function since(value: string | null | undefined): { text: string; days: number | null } {
    if (!value) {
        return { text: '—', days: null };
    }

    const start = new Date(value.replace(' ', 'T'));
    const today = new Date();
    const days = Math.max(0, Math.floor((new Date(today.toDateString()).getTime() - new Date(start.toDateString()).getTime()) / 86_400_000));

    return { text: days === 0 ? 'hoy' : days === 1 ? 'ayer' : `hace ${days} días`, days };
}

/**
 * Lo que sigue publicado pero la tienda esconde: sin disponible en la bodega que la respalda no
 * se ofrece. Vuelve solo cuando entra mercancía; aquí está lo necesario para que entre.
 */
export default function StorefrontOutOfStock({ tab, stores, store_id, warehouse, rows, filters: initialFilters, options, metrics, tab_counts, can }: OutOfStockProps) {
    const { filters, update } = useFilters('storefront.catalog.out_of_stock', initialFilters);
    const [selected, setSelected] = useState<number[]>([]);
    const isProducts = tab === 'products';

    useEffect(() => setSelected([]), [rows.data]);

    // Una sola solicitud sale de una sola bodega: solo se juntan los que se trasladan desde la misma.
    const transferable = rows.data.filter((row) => selected.includes(row.id) && row.replenishment_kind === 'transferable' && row.top_other_warehouse);
    const origins = new Set(transferable.map((row) => row.top_other_warehouse?.id));
    const bundle = transferable.length > 0 && origins.size === 1 && transferable.length === selected.length ? transferable : [];
    const bundleOrigin = bundle[0]?.top_other_warehouse ?? null;

    const unpublish = (ids: number[], type: 'product' | 'family' | 'service') =>
        router.post(route('storefront.catalog.update'), { type, product_ids: ids, field: 'is_online_visible', value: false, store_id }, { preserveScroll: true });

    const exportHref = route(
        'storefront.catalog.export',
        Object.fromEntries(Object.entries({ store: String(store_id), view: 'out_of_stock', search: filters.search, category: filters.category, replenishment: filters.replenishment }).filter(([, value]) => value !== '')),
    );

    const categoryOptions = useMemo(
        () => options.categories.map((category) => ({ value: category.value, label: `${' '.repeat(category.depth)}${category.label}` })),
        [options.categories],
    );

    return (
        <AppLayout title="Publicados sin stock">
            <Link
                href={route('storefront.catalog.index', { store: store_id })}
                className="mb-3 inline-flex items-center gap-1 text-[0.8125rem] font-medium text-[var(--muted)] hover:text-[var(--text)]"
            >
                <CaretLeftIcon size={14} weight="bold" />
                Catálogo en línea
            </Link>

            <PageHeader
                title="Artículos publicados sin stock"
                description="Siguen publicados, pero el cliente no los ve: la tienda no muestra lo que no puede entregar. Cuando entre mercancía a la bodega de la tienda vuelven a aparecer solos, sin tocar nada aquí."
                actions={
                    <>
                        <StoreStockSelect stores={stores} value={store_id} onChange={(id) => update('store', String(id))} />
                        {isProducts && (
                            <a
                                href={exportHref}
                                className="inline-flex h-10 items-center gap-2 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-4 text-sm font-medium text-[var(--text)] hover:bg-[var(--surface-3)]"
                            >
                                <DownloadSimpleIcon size={16} />
                                Exportar CSV
                            </a>
                        )}
                        {can.transfer_requests_create && bundle.length > 0 && bundleOrigin && (
                            <Link
                                href={route('transfer_requests.create', { to_warehouse: warehouse.id, from_warehouse: bundleOrigin.id, products: bundle.map((row) => row.id) })}
                                className="inline-flex h-10 items-center rounded-control bg-[var(--accent)] px-4 text-sm font-medium text-[var(--accent-ink)] hover:brightness-110"
                            >
                                Crear solicitud de traslado ({number(bundle.length)})
                            </Link>
                        )}
                    </>
                }
            />

            <div className="-mt-2 mb-4">
                <Badge tone="warn">
                    {number(metrics.hidden)} {metrics.hidden === 1 ? 'oculto' : 'ocultos'} al cliente
                </Badge>
            </div>

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard
                    label="Ocultos al cliente"
                    value={number(metrics.hidden)}
                    valueClassName="text-[var(--warn)]"
                    className="border-[var(--warn)]/40 bg-[var(--warn-soft)]"
                    caption={metrics.featured > 0 ? `${number(metrics.featured)} de ellos ${metrics.featured === 1 ? 'está destacado' : 'están destacados'}` : 'Ninguno destacado'}
                />
                <StatCard
                    label="Reposición en camino"
                    value={number(metrics.replenishing)}
                    caption={`${number(metrics.replenishing_purchases)} ${metrics.replenishing_purchases === 1 ? 'compra' : 'compras'} y ${number(metrics.replenishing_transfers)} ${metrics.replenishing_transfers === 1 ? 'traslado' : 'traslados'}`}
                />
                <StatCard label="Se pueden trasladar" value={number(metrics.transferable)} caption="Otra bodega de la sede tiene" />
                <StatCard label="Sin reposición prevista" value={number(metrics.no_replenishment)} valueClassName="text-[var(--danger)]" caption="Ni compra, ni traslado, ni otra bodega" />
            </div>

            <Card className="mb-4">
                <h2 className="mb-3 text-[0.9375rem] font-semibold">Cómo decide la tienda</h2>
                <ol className="grid gap-3 md:grid-cols-3">
                    {[
                        ['Mira una sola bodega', `Disponible es existencia menos apartado en ${warehouse.name}, la que respalda la tienda. Otras bodegas de la sede no cuentan: el alistador no encuentra en el estante lo que está atrás.`],
                        ['Sin disponible, no se muestra', 'El artículo sigue publicado, pero sale de listados, búsqueda, destacados y estantes. Quien ya lo tenía en el carrito ve «Se agotó» y no puede pagarlo.'],
                        ['Vuelve solo', `Cuando entra mercancía a ${warehouse.name} (compra, traslado o ajuste) aparece otra vez, sin tocar nada aquí.`],
                    ].map(([title, text], index) => (
                        <li key={title} className="flex gap-3">
                            <span className="grid size-7 shrink-0 place-items-center rounded-full bg-[var(--accent-soft)] font-mono text-[0.8125rem] font-semibold text-[var(--accent)]" aria-hidden="true">
                                {index + 1}
                            </span>
                            <div>
                                <p className="text-[0.8125rem] font-medium">{title}</p>
                                <p className="text-[0.78125rem] text-[var(--muted)]">{text}</p>
                            </div>
                        </li>
                    ))}
                </ol>
            </Card>

            <nav aria-label="Tipo de artículo" className="mb-4 flex items-center gap-1 overflow-x-auto border-b border-[var(--line)]">
                {TABS.map((item) => (
                    <Link
                        key={item.value}
                        href={route('storefront.catalog.out_of_stock', item.value === 'products' ? { store: store_id } : { store: store_id, tab: item.value })}
                        aria-current={tab === item.value ? 'page' : undefined}
                        className={cn(
                            '-mb-px flex items-center gap-2 border-b-2 px-3 py-2.5 text-[0.8125rem] font-medium whitespace-nowrap transition-colors',
                            tab === item.value ? 'border-[var(--accent)] text-[var(--accent)]' : 'border-transparent text-[var(--muted)] hover:text-[var(--text)]',
                        )}
                    >
                        {item.label}
                        <span className="rounded-full bg-[var(--warn-soft)] px-1.5 py-0.5 font-mono text-[0.6875rem] text-[var(--warn)] tabular">{number(tab_counts[item.value])}</span>
                    </Link>
                ))}
            </nav>

            <Card className="mb-4">
                <div className={cn('grid gap-3', isProducts ? 'md:grid-cols-3' : 'md:grid-cols-2')}>
                    <SearchInput placeholder="Nombre, SKU, código de barras o marca" aria-label="Buscar" value={filters.search} onChange={(event) => update('search', event.target.value)} />
                    <Select aria-label="Categoría" options={categoryOptions} placeholder="Todas (incluye subcategorías)" value={filters.category} onChange={(event) => update('category', event.target.value)} />
                    {isProducts && (
                        <Select aria-label="Reposición" options={options.replenishment} placeholder="Cualquier reposición" value={filters.replenishment} onChange={(event) => update('replenishment', event.target.value)} />
                    )}
                </div>
            </Card>

            {isProducts && (
                <p className="mb-3 flex items-start gap-2 rounded-control border border-[var(--line)] bg-[var(--surface)] px-4 py-2.5 text-[0.78125rem] text-[var(--muted)]">
                    <InfoIcon size={16} className="mt-0.5 shrink-0" aria-hidden="true" />
                    Una presentación sin disponible sale del selector de la ficha. La tarjeta del artículo solo se oculta cuando ninguna de sus presentaciones publicadas tiene disponible.
                </p>
            )}

            {rows.data.length === 0 ? (
                <Card padded={false}>
                    <EmptyState title="Todo lo publicado tiene existencias" description="Nada publicado está oculto por falta de stock en la bodega de la tienda." />
                </Card>
            ) : (
                <>
                    {isProducts ? (
                        <ProductsTable rows={rows.data} selected={selected} setSelected={setSelected} onUnpublish={(id) => unpublish([id], 'product')} />
                    ) : (
                        <GroupedTable tab={tab} rows={rows.data} storeId={store_id} onUnpublish={(id) => unpublish([id], tab === 'families' ? 'family' : 'service')} />
                    )}

                    {selected.length > 0 && (
                        <p className="mt-2 text-[0.8125rem] text-[var(--muted)]">
                            <span className="font-medium text-[var(--text)]">
                                {number(selected.length)} {selected.length === 1 ? 'seleccionado' : 'seleccionados'}
                            </span>
                            {bundleOrigin ? ` · se pueden pedir en una sola solicitud a ${bundleOrigin.name}` : ' · para pedirlos juntos tienen que trasladarse desde la misma bodega'}
                        </p>
                    )}
                    <Pagination meta={rows} />
                </>
            )}
        </AppLayout>
    );
}

interface ProductsTableProps {
    rows: OutOfStockRow[];
    selected: number[];
    setSelected: (update: (current: number[]) => number[]) => void;
    onUnpublish: (id: number) => void;
}

function ProductsTable({ rows, selected, setSelected, onUnpublish }: ProductsTableProps) {
    const all = rows.length > 0 && rows.every((row) => selected.includes(row.id));

    return (
        <Table>
            <THead>
                <TR>
                    <TH className="w-10">
                        <Checkbox label="" aria-label="Seleccionar todos los de esta página" checked={all} onChange={() => setSelected(() => (all ? [] : rows.map((row) => row.id)))} />
                    </TH>
                    <TH>Artículo</TH>
                    <TH>Categoría</TH>
                    <TH align="right">Disponible tienda</TH>
                    <TH>Otras bodegas de la sede</TH>
                    <TH>Reposición</TH>
                    <TH>Sin stock desde</TH>
                    <TH align="right">Vendido en línea 30 d</TH>
                    <TH>Acciones</TH>
                </TR>
            </THead>
            <TBody>
                {rows.map((row) => {
                    const out = since(row.out_of_stock_since);
                    const next = row.replenishment;

                    return (
                        <TR key={row.id} className={cn(selected.includes(row.id) && 'bg-[var(--accent-soft)]/50')}>
                            <TD>
                                <Checkbox
                                    label=""
                                    aria-label={`Seleccionar ${row.name}`}
                                    checked={selected.includes(row.id)}
                                    onChange={() => setSelected((current) => (current.includes(row.id) ? current.filter((id) => id !== row.id) : [...current, row.id]))}
                                />
                            </TD>
                            <TD>
                                <p className="text-[0.84375rem] font-medium leading-snug">{row.name}</p>
                                <p className="flex flex-wrap items-center gap-1.5 font-mono text-[0.71875rem] text-[var(--muted)]">
                                    {row.sku}
                                    {row.is_featured && (
                                        <span className="inline-flex items-center gap-0.5 font-sans font-medium text-[var(--warn)]">
                                            <StarIcon size={12} weight="fill" aria-hidden="true" />
                                            Destacado
                                        </span>
                                    )}
                                </p>
                                {row.family && <p className="text-[0.71875rem] text-[var(--muted)]">Subartículo de {row.family}{row.variant_label ? ` · ${row.variant_label}` : ''}</p>}
                            </TD>
                            <TD className="text-[0.78125rem] text-[var(--muted)]">{row.category ?? '—'}</TD>
                            <TD align="right">
                                <span className="font-mono text-[0.8125rem] font-semibold text-[var(--danger)] tabular">
                                    0 <span className="font-sans text-[0.6875rem] font-normal text-[var(--muted)]">{row.stock_unit}</span>
                                </span>
                                {(row.reserved_quantity ?? 0) > 0 && (
                                    <span className="block text-[0.6875rem] text-[var(--muted)]">
                                        {number(row.stock_quantity)} en bodega · {number(row.reserved_quantity)} apartados
                                    </span>
                                )}
                            </TD>
                            <TD>
                                {row.top_other_warehouse ? (
                                    <span className="text-[0.8125rem]">
                                        <span className="font-mono tabular">{number(row.other_available)}</span>{' '}
                                        <span className="text-[var(--muted)]">· {row.top_other_warehouse.name} tiene {number(row.top_other_warehouse.available)}</span>
                                    </span>
                                ) : (
                                    <span className="text-[0.8125rem] text-[var(--muted)]">Ninguna tiene</span>
                                )}
                            </TD>
                            <TD>
                                {next ? (
                                    <span className="text-[0.8125rem]">
                                        <span className="font-mono">{next.code}</span>{' '}
                                        <span className="text-[var(--muted)]">
                                            · {next.kind === 'transfer' ? 'en camino' : next.expected_at ? `llega ${date(next.expected_at, 'd MMM')}` : 'compra pendiente'}
                                        </span>
                                    </span>
                                ) : (
                                    <span className="text-[0.8125rem] text-[var(--muted)]">Ninguna</span>
                                )}
                            </TD>
                            <TD>
                                <span className={cn('text-[0.8125rem]', out.days !== null && out.days > 3 ? 'font-medium text-[var(--danger)]' : 'text-[var(--muted)]')}>{out.text}</span>
                            </TD>
                            <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                {number(row.sold_online_30d)}
                            </TD>
                            <TD>
                                <div className="flex flex-wrap gap-1.5">
                                    {row.transfer_href && (
                                        <Link href={row.transfer_href} className="inline-flex h-8 items-center rounded-chip bg-[var(--accent)] px-3 text-[0.8125rem] font-medium text-[var(--accent-ink)] hover:brightness-110">
                                            Solicitar traslado
                                        </Link>
                                    )}
                                    {row.purchase_href && (
                                        <Link href={row.purchase_href} className="inline-flex h-8 items-center rounded-chip bg-[var(--accent)] px-3 text-[0.8125rem] font-medium text-[var(--accent-ink)] hover:brightness-110">
                                            Crear compra
                                        </Link>
                                    )}
                                    {next?.href && (
                                        <Link href={next.href} className="inline-flex h-8 items-center rounded-chip border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.8125rem] font-medium text-[var(--text)] hover:bg-[var(--surface-3)]">
                                            {next.kind === 'purchase' ? 'Ver compra' : 'Ver traslado'}
                                        </Link>
                                    )}
                                    <Button size="sm" variant="ghost" onClick={() => onUnpublish(row.id)} aria-label={`Despublicar ${row.name}`}>
                                        Despublicar
                                    </Button>
                                </div>
                            </TD>
                        </TR>
                    );
                })}
            </TBody>
        </Table>
    );
}

const KIND_LABELS: Record<OutOfStockRow['replenishment_kind'], string> = {
    purchase: 'Compra en camino',
    transfer: 'Traslado en camino',
    transferable: 'Otra bodega tiene',
    none: 'Ninguna',
};

function GroupedTable({ tab, rows, storeId, onUnpublish }: { tab: ManageTab; rows: OutOfStockRow[]; storeId: number; onUnpublish: (id: number) => void }) {
    const families = tab === 'families';

    return (
        <Table name={tab}>
            <THead>
                <TR>
                    <TH>{families ? 'Artículo' : 'Servicio'}</TH>
                    <TH>Categoría</TH>
                    <TH>{families ? 'Presentaciones' : 'Lo limitan'}</TH>
                    {families && <TH>Reposición</TH>}
                    <TH align="right">Vendido en línea 30 d</TH>
                    <TH>Acciones</TH>
                </TR>
            </THead>
            <TBody>
                {rows.map((row) => (
                    <TR key={row.id}>
                        <TD>
                            <p className="text-[0.84375rem] font-medium leading-snug">{row.name}</p>
                            {row.sku && <p className="font-mono text-[0.71875rem] text-[var(--muted)]">{row.sku}</p>}
                        </TD>
                        <TD className="text-[0.78125rem] text-[var(--muted)]">{row.category ?? '—'}</TD>
                        <TD className="text-[0.8125rem]">
                            {families
                                ? `${row.variants_published ?? 0} publicadas · ninguna con disponible`
                                : row.components && row.components.length > 0
                                  ? row.components.join(', ')
                                  : row.status_note}
                        </TD>
                        {families && <TD className="text-[0.8125rem] text-[var(--muted)]">{KIND_LABELS[row.replenishment_kind]}</TD>}
                        <TD align="right" className="font-mono text-[0.8125rem] tabular">
                            {number(row.sold_online_30d)}
                        </TD>
                        <TD>
                            <div className="flex flex-wrap gap-1.5">
                                {families && (
                                    <Link
                                        href={route('storefront.catalog.index', { store: storeId, family: row.id, view: 'out_of_stock' })}
                                        className="inline-flex h-8 items-center rounded-chip border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.8125rem] font-medium text-[var(--text)] hover:bg-[var(--surface-3)]"
                                    >
                                        Ver presentaciones
                                    </Link>
                                )}
                                <Button size="sm" variant="ghost" onClick={() => onUnpublish(row.id)} aria-label={`Despublicar ${row.name}`}>
                                    Despublicar
                                </Button>
                            </div>
                        </TD>
                    </TR>
                ))}
            </TBody>
        </Table>
    );
}

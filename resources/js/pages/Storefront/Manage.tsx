import { Link, router } from '@inertiajs/react';
import { ArrowSquareOutIcon, DownloadSimpleIcon, ImageIcon, StarIcon, WarningIcon, XIcon } from '@phosphor-icons/react';
import { type ReactNode, useEffect, useMemo, useState } from 'react';
import { CoverageBars } from '@/components/storefront-manage/CoverageBars';
import { ManageTabs } from '@/components/storefront-manage/ManageTabs';
import { QuickViews } from '@/components/storefront-manage/QuickViews';
import { RowFlags } from '@/components/storefront-manage/RowFlags';
import { StatusPill } from '@/components/storefront-manage/StatusPill';
import { StoreStockSelect } from '@/components/storefront-manage/StoreStockSelect';
import { SuggestionCard } from '@/components/storefront-manage/SuggestionCard';
import { Button } from '@/components/ui/Button';
import { Card, CardHeader, StatCard } from '@/components/ui/Card';
import { Checkbox, Input, Select } from '@/components/ui/Field';
import { Chip, PageHeader, SearchInput } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import { money, number } from '@/lib/format';
import type { CatalogFilters, CatalogRow, ManageProps } from '@/types/storefront-manage';

type Flag = 'is_online_visible' | 'is_featured';

/** Lo que no es sede, pestaña, vista ni orden: lo que «Limpiar filtros» deja en blanco. */
const FILTER_KEYS: (keyof CatalogFilters)[] = [
    'search',
    'category',
    'brand',
    'supplier',
    'stock',
    'status',
    'sheet',
    'price_min',
    'price_max',
    'promo',
    'expiring_days',
    'no_online_sales',
    'price_differs',
    'margin_below',
    'other_warehouse',
    'family',
];

/** Los órdenes que entiende cada pestaña: familias y servicios se ordenan por grupo o en PHP. */
const SORTS_BY_TAB: Record<ManageProps['tab'], string[]> = {
    products: ['name', 'sold_pos_desc', 'sold_online_desc', 'available_desc', 'available_asc', 'price_asc', 'price_desc', 'recent', 'out_since'],
    families: ['name', 'sold_pos_desc', 'sold_online_desc', 'available_desc', 'available_asc', 'price_asc', 'price_desc', 'recent'],
    services: ['name', 'sold_pos_desc', 'sold_online_desc', 'price_asc', 'price_desc'],
};

const NOUNS = {
    products: ['artículo', 'artículos'],
    families: ['artículo con subartículos', 'artículos con subartículos'],
    services: ['servicio', 'servicios'],
} as const;

/**
 * «Tienda en línea»: qué ve el cliente, qué está publicado pero no se muestra y qué conviene
 * publicar. Publicado no es visible: sin disponible en la bodega de la tienda, el artículo sigue
 * publicado pero oculto, y vuelve solo cuando entra mercancía.
 */
export default function StorefrontManage({
    tab,
    stores,
    store_id,
    warehouse,
    storefront_url,
    rows,
    filters: initialFilters,
    options,
    thresholds,
    metrics,
    suggestions_preview,
    suggestions_total,
    coverage,
    can,
}: ManageProps) {
    const { filters, update } = useFilters<CatalogFilters>('storefront.catalog.index', initialFilters);
    const [selected, setSelected] = useState<number[]>([]);
    const [busy, setBusy] = useState(false);
    const isProducts = tab === 'products';
    const type = tab === 'services' ? 'service' : tab === 'families' ? 'family' : 'product';
    const [singular, plural] = NOUNS[tab];

    // Otra página u otro filtro: la selección de antes ya no está a la vista.
    useEffect(() => setSelected([]), [rows.data]);

    const hasActive = FILTER_KEYS.some((key) => filters[key] !== '');
    const clearFilters = () => FILTER_KEYS.forEach((key) => update(key, ''));
    const toggle = (key: keyof CatalogFilters, value: string) => update(key, filters[key] === value ? '' : value);

    const allSelected = rows.data.length > 0 && rows.data.every((row) => selected.includes(row.id));
    const maxAvailable = useMemo(() => Math.max(1, ...rows.data.map((row) => Math.max(0, row.available ?? 0))), [rows.data]);

    const post = (ids: number[], fields: Partial<Record<Flag, boolean>>, onFinish?: () => void) =>
        router.post(route('storefront.catalog.update'), { type, product_ids: ids, fields, store_id }, { preserveScroll: true, onFinish });

    const bulk = (fields: Partial<Record<Flag, boolean>>) => {
        setBusy(true);
        post(selected, fields, () => {
            setBusy(false);
            setSelected([]);
        });
    };

    const exportQuery = Object.fromEntries(Object.entries({ ...filters, tab: '' }).filter(([, value]) => value !== ''));
    const goToOutOfStock = () => router.get(route('storefront.catalog.out_of_stock', isProducts ? { store: store_id } : { store: store_id, tab }));

    const categoryOptions = options.categories.map((category) => ({ value: category.value, label: `${' '.repeat(category.depth)}${category.label}` }));
    const chips: { key: keyof CatalogFilters; value: string; label: string }[] = [
        { key: 'promo', value: '1', label: 'Con promoción activa' },
        { key: 'expiring_days', value: String(thresholds.expiring_days), label: `Lote vence en ≤ ${thresholds.expiring_days} días` },
        { key: 'no_online_sales', value: '1', label: 'Sin ventas en línea 30 d' },
        { key: 'price_differs', value: '1', label: 'Precio tienda ≠ mostrador' },
        { key: 'margin_below', value: String(thresholds.low_margin), label: `Margen bajo ${thresholds.low_margin} %` },
        { key: 'other_warehouse', value: '1', label: 'Con stock en otra bodega' },
    ];

    return (
        <AppLayout title="Tienda en línea">
            <PageHeader
                title="Tienda en línea"
                description="Qué ve el cliente, qué está publicado pero no se muestra y qué conviene publicar."
                actions={
                    <>
                        <StoreStockSelect stores={stores} value={store_id} onChange={(id) => update('store', String(id))} />
                        {isProducts && (
                            <a
                                href={route('storefront.catalog.export', exportQuery)}
                                className="inline-flex h-10 items-center gap-2 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-4 text-sm font-medium text-[var(--text)] hover:bg-[var(--surface-3)]"
                            >
                                <DownloadSimpleIcon size={16} />
                                Exportar CSV
                            </a>
                        )}
                        {storefront_url && (
                            <a
                                href={storefront_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex h-10 items-center gap-2 rounded-control border border-[var(--line-2)] px-4 text-sm font-medium text-[var(--text)] hover:bg-[var(--surface-2)]"
                            >
                                <ArrowSquareOutIcon size={16} />
                                Ver tienda
                            </a>
                        )}
                    </>
                }
            />

            <ManageTabs active={tab} storeId={store_id} suggestionsTotal={suggestions_total} className="mb-4" />

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                <MetricButton onClick={() => update('view', 'visible')} pressed={filters.view === 'visible'}>
                    <StatCard label="Visibles al cliente" value={number(metrics.visible)} valueClassName="text-[var(--accent)]" caption="Publicados con existencia" />
                </MetricButton>
                <MetricButton onClick={goToOutOfStock}>
                    <StatCard
                        label="Publicados sin stock"
                        value={number(metrics.out_of_stock)}
                        valueClassName="text-[var(--warn)]"
                        className="border-[var(--warn)]/40 bg-[var(--warn-soft)]"
                        caption="Ocultos al cliente hoy"
                    />
                </MetricButton>
                <MetricButton onClick={() => update('view', 'stock_unpublished')} pressed={filters.view === 'stock_unpublished'}>
                    <StatCard label="Con stock sin publicar" value={number(metrics.stock_unpublished)} caption="Oportunidad de venta" />
                </MetricButton>
                {/* Publicados no tiene vista propia: son visibles + sin stock + sin precio. */}
                <StatCard label="Publicados" value={number(metrics.published)} caption={`de ${number(metrics.active)} activos`} />
                <MetricButton onClick={() => update('view', 'featured')} pressed={filters.view === 'featured'}>
                    <StatCard
                        label="Destacados"
                        value={number(metrics.featured)}
                        caption={metrics.featured_out_of_stock > 0 ? <span className="text-[var(--warn)]">{number(metrics.featured_out_of_stock)} sin stock</span> : undefined}
                    />
                </MetricButton>
                <MetricButton onClick={() => update('view', 'incomplete')} pressed={filters.view === 'incomplete'}>
                    <StatCard label="Ficha incompleta" value={number(metrics.incomplete)} caption="Sin foto o descripción" />
                </MetricButton>
            </div>

            {metrics.out_of_stock > 0 && (
                <div className="mb-4 flex flex-col gap-3 rounded-card border border-[var(--warn)]/40 bg-[var(--warn-soft)] px-4 py-3 sm:flex-row sm:items-center" role="status">
                    <WarningIcon size={20} weight="fill" className="shrink-0 text-[var(--warn)]" aria-hidden="true" />
                    <p className="text-[0.8125rem] text-[var(--text)]">
                        <strong>
                            {number(metrics.out_of_stock)} {metrics.out_of_stock === 1 ? `${singular} publicado no se le está mostrando` : `${plural} publicados no se le están mostrando`} al
                            cliente
                        </strong>{' '}
                        porque no tienen disponible en la bodega de la tienda. Vuelven solos cuando entre mercancía.
                        {metrics.out_of_stock_transferable > 0 && (
                            <span className="text-[var(--muted)]">
                                {' '}
                                {number(metrics.out_of_stock_transferable)} se {metrics.out_of_stock_transferable === 1 ? 'puede' : 'pueden'} trasladar desde otra bodega de la sede.
                            </span>
                        )}
                    </p>
                    <Button size="sm" variant="warn" className="sm:ml-auto" onClick={goToOutOfStock}>
                        Ver publicados sin stock
                    </Button>
                </div>
            )}

            <div className="mb-4">
                <QuickViews views={options.quick_views} active={filters.view} onChange={(view) => update('view', view)} />
            </div>

            <Card className="mb-4">
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <SearchInput
                        placeholder={isProducts ? 'Nombre, SKU, código de barras o marca' : 'Nombre o código'}
                        aria-label="Buscar"
                        value={filters.search}
                        onChange={(event) => update('search', event.target.value)}
                    />
                    <Select
                        aria-label="Categoría"
                        options={categoryOptions}
                        placeholder="Todas (incluye subcategorías)"
                        value={filters.category}
                        onChange={(event) => update('category', event.target.value)}
                    />
                    {tab !== 'services' && (
                        <Select aria-label="Marca" options={options.brands} placeholder="Todas las marcas" value={filters.brand} onChange={(event) => update('brand', event.target.value)} />
                    )}
                    {isProducts && (
                        <Select
                            aria-label="Proveedor"
                            options={options.suppliers}
                            placeholder="Todos los proveedores"
                            value={filters.supplier}
                            onChange={(event) => update('supplier', event.target.value)}
                        />
                    )}
                </div>

                <div className={cn('mt-3 grid gap-3', isProducts ? 'md:grid-cols-2 xl:grid-cols-[auto_repeat(4,minmax(0,1fr))]' : 'md:grid-cols-2')}>
                    {isProducts && (
                        <div>
                            <p id="stock-segment" className="mb-1.5 text-[0.75rem] font-medium text-[var(--muted)]">
                                Existencias en la bodega de la tienda
                            </p>
                            <div role="radiogroup" aria-labelledby="stock-segment" className="inline-flex flex-wrap gap-0.5 rounded-control border border-[var(--line)] bg-[var(--surface-2)] p-0.5">
                                {options.stock.map((option) => {
                                    const value = option.value === 'all' ? '' : String(option.value);
                                    const active = filters.stock === value;

                                    return (
                                        <button
                                            key={option.value}
                                            type="button"
                                            role="radio"
                                            aria-checked={active}
                                            onClick={() => update('stock', value)}
                                            className={cn(
                                                'h-8 rounded-chip px-3 text-[0.8125rem] font-medium whitespace-nowrap transition-colors',
                                                active ? 'bg-[var(--surface)] text-[var(--text)] shadow-sm' : 'text-[var(--muted)] hover:text-[var(--text)]',
                                            )}
                                        >
                                            {option.label}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                    <Select label="Estado en la tienda" options={options.statuses} placeholder="Todos" value={filters.status} onChange={(event) => update('status', event.target.value)} />
                    {isProducts && (
                        <>
                            <Select label="Ficha" options={options.sheet} placeholder="Todas" value={filters.sheet} onChange={(event) => update('sheet', event.target.value)} />
                            <div className="grid grid-cols-2 gap-2">
                                <Input
                                    label="Precio tienda desde"
                                    inputMode="numeric"
                                    value={filters.price_min}
                                    onChange={(event) => update('price_min', event.target.value.replace(/\D/g, ''))}
                                    placeholder="$"
                                />
                                <Input
                                    label="Hasta"
                                    inputMode="numeric"
                                    value={filters.price_max}
                                    onChange={(event) => update('price_max', event.target.value.replace(/\D/g, ''))}
                                    placeholder="$"
                                />
                            </div>
                        </>
                    )}
                    <Select
                        label="Ordenar por"
                        options={options.sorts.filter((sort) => SORTS_BY_TAB[tab].includes(String(sort.value)))}
                        value={filters.sort === '' ? 'name' : filters.sort}
                        onChange={(event) => update('sort', event.target.value === 'name' ? '' : event.target.value)}
                    />
                </div>

                {isProducts && (
                    <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-[var(--line)] pt-3">
                        <span className="text-[0.75rem] font-medium text-[var(--muted)]">Más filtros</span>
                        {chips.map((chip) => (
                            <Chip key={chip.key} active={filters[chip.key] !== ''} onClick={() => toggle(chip.key, chip.value)}>
                                {chip.label}
                            </Chip>
                        ))}
                        {hasActive && (
                            <Button size="sm" variant="ghost" className="ml-auto" onClick={clearFilters}>
                                Limpiar filtros
                            </Button>
                        )}
                    </div>
                )}
                {!isProducts && hasActive && (
                    <div className="mt-3 flex justify-end">
                        <Button size="sm" variant="ghost" onClick={clearFilters}>
                            Limpiar filtros
                        </Button>
                    </div>
                )}
            </Card>

            {filters.family !== '' && isProducts && (
                <div className="mb-4 flex flex-wrap items-center gap-2 rounded-control border border-[var(--line)] bg-[var(--surface)] px-4 py-2.5 text-[0.8125rem]">
                    <span>Viendo los subartículos de un artículo. Ocultar uno quita esa presentación de la tienda; la tarjeta sigue publicada.</span>
                    <Button size="sm" variant="ghost" className="ml-auto" onClick={() => update('family', '')}>
                        <XIcon size={14} weight="bold" />
                        Ver todos
                    </Button>
                </div>
            )}

            <div className="grid items-start gap-4 min-[1100px]:grid-cols-[minmax(0,1fr)_340px]">
                <div className="min-w-0">
                    {selected.length > 0 && (
                        <div className="mb-3 flex flex-wrap items-center gap-2 rounded-card border border-[var(--accent)]/30 bg-[var(--accent-soft)] px-4 py-2.5" role="region" aria-label="Acciones sobre la selección">
                            <span className="text-[0.8125rem] font-medium text-[var(--accent)]">
                                {number(selected.length)} {selected.length === 1 ? 'seleccionado' : 'seleccionados'}
                            </span>
                            <div className="ml-auto flex flex-wrap gap-2">
                                <Button size="sm" loading={busy} onClick={() => bulk({ is_online_visible: true })}>
                                    Publicar
                                </Button>
                                <Button size="sm" variant="secondary" disabled={busy} onClick={() => bulk({ is_online_visible: false })}>
                                    Ocultar
                                </Button>
                                <Button size="sm" variant="secondary" disabled={busy} onClick={() => bulk({ is_featured: true })}>
                                    Destacar
                                </Button>
                                <Button size="sm" variant="secondary" disabled={busy} onClick={() => bulk({ is_featured: false })}>
                                    Quitar destacado
                                </Button>
                                <Button size="sm" variant="ghost" onClick={() => setSelected([])}>
                                    Quitar selección
                                </Button>
                            </div>
                        </div>
                    )}

                    <p className="mb-2 text-[0.8125rem] text-[var(--muted)]">
                        <span className="font-mono text-[var(--text)] tabular">{number(rows.total)}</span> {rows.total === 1 ? singular : plural} · existencias de{' '}
                        {warehouse.name}
                    </p>

                    {rows.data.length === 0 ? (
                        <Card padded={false}>
                            {filters.view === 'out_of_stock' && !hasActive ? (
                                <EmptyState title="Todo lo publicado tiene existencias" description="Ningún artículo publicado está oculto por falta de stock en la bodega de la tienda." />
                            ) : (
                                <EmptyState title={`Ningún ${singular} coincide`} description="Cambia los filtros." />
                            )}
                        </Card>
                    ) : (
                        <>
                            <Table>
                                <THead>
                                    <TR>
                                        <TH className="w-10">
                                            <Checkbox
                                                label=""
                                                aria-label="Seleccionar todos los de esta página"
                                                checked={allSelected}
                                                onChange={() => setSelected(allSelected ? [] : rows.data.map((row) => row.id))}
                                            />
                                        </TH>
                                        <TH>{tab === 'services' ? 'Servicio' : 'Artículo'}</TH>
                                        <TH>Categoría</TH>
                                        <TH align="right">Disponible</TH>
                                        <TH align="right">Vendido caja 30 d</TH>
                                        <TH align="right">Precio tienda</TH>
                                        <TH>Estado en tienda</TH>
                                        <TH align="center">Publicado</TH>
                                        <TH align="center">Destacado</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {rows.data.map((row) => (
                                        <CatalogTableRow
                                            key={row.id}
                                            row={row}
                                            tab={tab}
                                            storeId={store_id}
                                            maxAvailable={maxAvailable}
                                            selected={selected.includes(row.id)}
                                            onSelect={() =>
                                                setSelected((current) => (current.includes(row.id) ? current.filter((id) => id !== row.id) : [...current, row.id]))
                                            }
                                            onToggle={(field) => post([row.id], { [field]: !row[field] })}
                                        />
                                    ))}
                                </TBody>
                            </Table>
                            <Pagination meta={rows} />
                        </>
                    )}
                </div>

                <aside className="flex flex-col gap-4" aria-label="Sugerencias y cobertura">
                    <Card>
                        <CardHeader
                            title={
                                <span className="flex items-center gap-2">
                                    Sugerencias para publicar
                                    <span className="rounded-full bg-[var(--accent-soft)] px-1.5 py-0.5 font-mono text-[0.6875rem] text-[var(--accent)] tabular">{number(suggestions_total)}</span>
                                </span>
                            }
                            description="Con disponible en la bodega de la tienda y todavía sin publicar."
                        />
                        {suggestions_preview.length === 0 ? (
                            <p className="text-[0.8125rem] text-[var(--muted)]">No hay artículos con stock por publicar.</p>
                        ) : (
                            <div className="flex flex-col gap-2">
                                {suggestions_preview.map((item) => (
                                    <SuggestionCard key={`${item.group}-${item.id}`} item={item} storeId={store_id} can={can} />
                                ))}
                            </div>
                        )}
                        {suggestions_total > 0 && (
                            <Link
                                href={route('storefront.catalog.suggestions', { store: store_id })}
                                className="mt-3 inline-block text-[0.8125rem] font-medium text-[var(--accent)] hover:underline"
                            >
                                Ver las {number(suggestions_total)} sugerencias
                            </Link>
                        )}
                    </Card>

                    <Card>
                        <CardHeader title="Cobertura por categoría" description="Publicados sobre activos, en las cinco categorías con más artículos." />
                        <CoverageBars coverage={coverage} storeId={store_id} />
                    </Card>
                </aside>
            </div>
        </AppLayout>
    );
}

function MetricButton({ children, onClick, pressed }: { children: ReactNode; onClick: () => void; pressed?: boolean }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={pressed}
            className={cn(
                'h-full rounded-card text-left transition-shadow hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--accent)]',
                pressed && 'ring-2 ring-[var(--accent)]/40',
            )}
        >
            {children}
        </button>
    );
}

interface CatalogTableRowProps {
    row: CatalogRow;
    tab: ManageProps['tab'];
    storeId: number;
    maxAvailable: number;
    selected: boolean;
    onSelect: () => void;
    onToggle: (field: Flag) => void;
}

function CatalogTableRow({ row, tab, storeId, maxAvailable, selected, onSelect, onToggle }: CatalogTableRowProps) {
    const available = row.available;
    const low = available !== null && available > 0 && (row.min_stock ?? 0) > 0 && available < (row.min_stock ?? 0);
    const empty = available !== null && available <= 0 && row.is_online_visible;
    const counterDiffers = tab === 'products' && row.price > 0 && row.price !== row.current_price;

    return (
        <TR className={cn(selected && 'bg-[var(--accent-soft)]/50')}>
            <TD>
                <Checkbox label="" aria-label={`Seleccionar ${row.name}`} checked={selected} onChange={onSelect} />
            </TD>
            <TD>
                <div className="flex items-start gap-3">
                    {row.thumb_url ? (
                        <img src={row.thumb_url} alt="" className="size-10 shrink-0 rounded-control object-cover" loading="lazy" />
                    ) : (
                        <span
                            className="grid size-10 shrink-0 place-items-center rounded-control border border-dashed border-[var(--line-2)] text-[var(--muted)]"
                            title="Sin foto"
                            aria-hidden="true"
                        >
                            <ImageIcon size={16} />
                        </span>
                    )}
                    <div className="min-w-0">
                        <p className="text-[0.84375rem] font-medium leading-snug">{row.name}</p>
                        {row.sku && <p className="font-mono text-[0.71875rem] text-[var(--muted)]">{row.sku}</p>}
                        {row.family && (
                            <p className="text-[0.71875rem] text-[var(--muted)]">
                                Subartículo de {row.family}
                                {row.variant_label ? ` · ${row.variant_label}` : ''}
                            </p>
                        )}
                        {tab === 'families' && row.variants_total !== undefined && (
                            <Link
                                href={route('storefront.catalog.index', { store: storeId, family: row.id })}
                                className="text-[0.71875rem] font-medium text-[var(--accent)] hover:underline"
                            >
                                {row.variants_published} de {row.variants_total} presentaciones publicadas · {row.variants_available} con disponible
                            </Link>
                        )}
                        {tab === 'services' && row.status === 'out_of_stock' && row.components && row.components.length > 0 && (
                            <p className="text-[0.71875rem] text-[var(--muted)]">Lo limitan: {row.components.join(', ')}</p>
                        )}
                        <RowFlags flags={row.flags} />
                    </div>
                </div>
            </TD>
            <TD className="text-[0.78125rem] text-[var(--muted)]">{row.category ?? '—'}</TD>
            <TD align="right">
                {available === null ? (
                    <span className="text-[0.75rem] text-[var(--muted)]">Sin límite</span>
                ) : (
                    <div className="flex flex-col items-end gap-1">
                        <span
                            className={cn(
                                'font-mono text-[0.8125rem] tabular',
                                empty ? 'font-semibold text-[var(--danger)]' : low ? 'text-[var(--warn)]' : 'text-[var(--text)]',
                            )}
                        >
                            {number(Math.max(0, available))} <span className="font-sans text-[0.6875rem] text-[var(--muted)]">{row.stock_unit}</span>
                        </span>
                        <span className="h-1 w-16 overflow-hidden rounded-full bg-[var(--surface-3)]" aria-hidden="true">
                            <span
                                className="block h-full rounded-full"
                                style={{
                                    width: `${Math.min(100, (Math.max(0, available) / maxAvailable) * 100)}%`,
                                    backgroundColor: empty ? 'var(--danger)' : low ? 'var(--warn)' : 'var(--accent)',
                                }}
                            />
                        </span>
                        {low && <span className="text-[0.6875rem] text-[var(--warn)]">mínimo {number(row.min_stock)}</span>}
                    </div>
                )}
            </TD>
            <TD align="right" className="font-mono text-[0.8125rem] tabular">
                {number(row.sold_pos_30d)}
            </TD>
            <TD align="right" className="font-mono text-[0.8125rem] tabular">
                {row.open_price ? (
                    <span className="font-sans text-[var(--muted)]">Variable</span>
                ) : (
                    <>
                        {tab === 'families' && <span className="mr-1 font-sans text-[0.6875rem] text-[var(--muted)]">desde</span>}
                        {row.current_price > 0 ? money(row.current_price) : <span className="font-sans text-[var(--danger)]">Sin precio</span>}
                        {counterDiffers && <span className="block text-[0.6875rem] text-[var(--muted)]">mostrador {money(row.price)}</span>}
                    </>
                )}
            </TD>
            <TD>
                <StatusPill status={row.status} label={row.status_label} note={row.status_note} />
            </TD>
            <TD align="center">
                {row.open_price ? (
                    <span className="text-[0.71875rem] text-[var(--muted)]">Precio variable</span>
                ) : (
                    <button
                        type="button"
                        onClick={() => onToggle('is_online_visible')}
                        aria-pressed={row.is_online_visible}
                        aria-label={row.is_online_visible ? `Ocultar ${row.name}` : `Publicar ${row.name}`}
                        className="inline-grid h-9 w-12 place-items-center rounded-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[var(--accent)]"
                    >
                        <span
                            className={cn(
                                'relative block h-[1.375rem] w-[2.375rem] rounded-full border transition-colors',
                                row.is_online_visible ? 'border-[var(--accent)] bg-[var(--accent)]' : 'border-[var(--line-2)] bg-[var(--surface-3)]',
                            )}
                        >
                            <span
                                className={cn(
                                    'absolute top-1/2 size-[1rem] -translate-y-1/2 rounded-full bg-white shadow-sm transition-[left]',
                                    row.is_online_visible ? 'left-[1.1875rem]' : 'left-[0.125rem]',
                                )}
                            />
                        </span>
                    </button>
                )}
            </TD>
            <TD align="center">
                <button
                    type="button"
                    onClick={() => onToggle('is_featured')}
                    aria-pressed={row.is_featured}
                    aria-label={row.is_featured ? `Quitar ${row.name} de destacados` : `Destacar ${row.name}`}
                    className="inline-grid size-9 place-items-center rounded-control transition-colors hover:bg-[var(--surface-2)] focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[var(--accent)]"
                >
                    <StarIcon size={18} weight={row.is_featured ? 'fill' : 'regular'} className={row.is_featured ? 'text-[var(--warn)]' : 'text-[var(--muted)]'} />
                </button>
            </TD>
        </TR>
    );
}

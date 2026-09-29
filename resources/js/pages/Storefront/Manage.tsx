import { Link, router } from '@inertiajs/react';
import { EyeIcon, EyeSlashIcon, StarIcon, StorefrontIcon, XIcon } from '@phosphor-icons/react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Card, StatCard } from '@/components/ui/Card';
import { Checkbox, Select } from '@/components/ui/Field';
import { PageHeader, SearchInput, Tabs } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { money, number } from '@/lib/format';
import type { Paginated } from '@/types';

interface ProductRow {
    id: number;
    name: string;
    sku: string;
    category: string | null;
    price: number;
    online_price: number | null;
    is_online_visible: boolean;
    is_featured: boolean;
    /** Un servicio de precio variable: no se publica. */
    open_price?: boolean;
    /** Subarticulo: el articulo al que pertenece y su presentacion. */
    family?: string | null;
    variant_label?: string | null;
    /** Articulo con subarticulos: cuantas presentaciones se ofrecen. */
    variants_total?: number;
    variants_published?: number;
}

interface ManageProps {
    products: Paginated<ProductRow>;
    filters: { search: string; category: string; state: string; type: string; family: string };
    types: { value: string; label: string }[];
    states: { value: string; label: string }[];
    categories: { value: number; label: string }[];
    metrics: { published: number; featured: number; total: number };
}

export default function StorefrontManage({ products, filters: initialFilters, types, states, categories, metrics }: ManageProps) {
    const { filters, update, reset, hasActive } = useFilters('storefront.catalog.index', initialFilters);
    const isServices = filters.type === 'services';
    const isFamilies = filters.type === 'families';
    const type = isServices ? 'service' : isFamilies ? 'family' : 'product';
    const [selected, setSelected] = useState<number[]>([]);
    const [busy, setBusy] = useState(false);

    const allSelected = useMemo(
        () => products.data.length > 0 && products.data.every((product) => selected.includes(product.id)),
        [products.data, selected],
    );

    const apply = (field: 'is_online_visible' | 'is_featured', value: boolean) => {
        setBusy(true);
        router.post(
            route('storefront.catalog.update'),
            { type, product_ids: selected, field, value },
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(false);
                    setSelected([]);
                },
            },
        );
    };

    const toggleOne = (product: ProductRow, field: 'is_online_visible' | 'is_featured') => {
        router.post(
            route('storefront.catalog.update'),
            { type, product_ids: [product.id], field, value: !product[field] },
            { preserveScroll: true },
        );
    };

    return (
        <AppLayout title="Tienda en linea">
            <PageHeader
                title="Tienda en linea"
                description={
                    isFamilies
                        ? 'Un articulo con subarticulos es una sola tarjeta en la tienda. Aqui se publica la tarjeta; que presentaciones se ofrecen se decide en sus subarticulos.'
                        : 'Decide que articulos y servicios ve el cliente y cuales van destacados.'
                }
            />

            <Tabs
                className="mb-4"
                tabs={types}
                active={filters.type}
                onChange={(value) => {
                    setSelected([]);
                    // Cambiar de pestaña suelta el filtro por articulo: los ids de una tabla no sirven en otra.
                    const { family: _family, ...rest } = filters;
                    const query = Object.fromEntries(Object.entries({ ...rest, type: value }).filter(([, current]) => current !== ''));
                    router.get(route('storefront.catalog.index'), query, { preserveState: false });
                }}
            />

            {filters.family && !isFamilies && (
                <div className="mb-4 flex flex-wrap items-center gap-2 rounded-control border border-[var(--line)] bg-[var(--surface)] px-4 py-2.5 text-[0.8125rem]">
                    <span>Viendo los subarticulos de un articulo. Ocultar uno quita esa presentacion de la tienda; la tarjeta sigue publicada.</span>
                    <Button size="sm" variant="ghost" className="ml-auto" onClick={() => update('family', '')}>
                        <XIcon size={14} weight="bold" />
                        Ver todos
                    </Button>
                </div>
            )}

            <div className="mb-4 grid gap-4 sm:grid-cols-3">
                <StatCard label="Publicados" value={number(metrics.published)} icon={<StorefrontIcon size={16} weight="bold" />} />
                <StatCard label="Destacados" value={number(metrics.featured)} tone="warn" />
                <StatCard
                    label={isServices ? 'Servicios activos' : isFamilies ? 'Articulos con subarticulos' : 'Articulos activos'}
                    value={number(metrics.total)}
                    tone="neutral"
                />
            </div>

            <Card className="mb-4">
                <div className="grid gap-3 sm:grid-cols-3">
                    <SearchInput placeholder="Nombre o SKU" value={filters.search} onChange={(event) => update('search', event.target.value)} />
                    <Select
                        options={categories}
                        placeholder="Todas las categorias"
                        value={filters.category}
                        onChange={(event) => update('category', event.target.value)}
                    />
                    <div className="flex items-start gap-2">
                        <div className="flex-1">
                            <Select
                                options={states}
                                placeholder="Todos"
                                value={filters.state}
                                onChange={(event) => update('state', event.target.value)}
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

            {selected.length > 0 && (
                <div className="mb-3 flex flex-wrap items-center gap-2 rounded-[12px] border border-[var(--accent)]/30 bg-[var(--accent-soft)] px-4 py-2.5">
                    <span className="text-[13px] font-medium text-[var(--accent)]">{selected.length} seleccionados</span>
                    <div className="ml-auto flex flex-wrap gap-2">
                        <Button size="sm" variant="secondary" loading={busy} onClick={() => apply('is_online_visible', true)}>
                            Publicar
                        </Button>
                        <Button size="sm" variant="secondary" loading={busy} onClick={() => apply('is_online_visible', false)}>
                            Ocultar
                        </Button>
                        <Button size="sm" variant="secondary" loading={busy} onClick={() => apply('is_featured', true)}>
                            Destacar
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => setSelected([])}>
                            Quitar seleccion
                        </Button>
                    </div>
                </div>
            )}

            {products.data.length === 0 ? (
                <EmptyState
                    title={isServices ? 'Ningun servicio coincide' : isFamilies ? 'Ningun articulo con subarticulos coincide' : 'Ningun articulo coincide'}
                    description={isFamilies && !hasActive ? 'Se crean en Articulos con «Nuevo articulo con subarticulos».' : 'Cambia los filtros para ver otra parte del catalogo.'}
                />
            ) : (
                <>
                    <Table>
                        <THead>
                            <TR>
                                <TH className="w-10">
                                    <Checkbox
                                        label=""
                                        checked={allSelected}
                                        onChange={() => setSelected(allSelected ? [] : products.data.map((product) => product.id))}
                                    />
                                </TH>
                                <TH>{isServices ? 'Servicio' : 'Articulo'}</TH>
                                <TH>Categoria</TH>
                                <TH align="right">Precio tienda</TH>
                                <TH align="center">Publicado</TH>
                                <TH align="center">Destacado</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {products.data.map((product) => (
                                <TR key={product.id}>
                                    <TD>
                                        <Checkbox
                                            label=""
                                            checked={selected.includes(product.id)}
                                            onChange={() =>
                                                setSelected((current) =>
                                                    current.includes(product.id) ? current.filter((id) => id !== product.id) : [...current, product.id],
                                                )
                                            }
                                        />
                                    </TD>
                                    <TD>
                                        <p className="text-[13.5px] font-medium">{product.name}</p>
                                        {product.sku && <p className="font-mono text-[11.5px] text-[var(--muted)]">{product.sku}</p>}
                                        {product.family && (
                                            <p className="text-[0.71875rem] text-[var(--muted)]">
                                                Subarticulo de {product.family}
                                                {product.variant_label ? ` · ${product.variant_label}` : ''}
                                            </p>
                                        )}
                                        {isFamilies && product.variants_total !== undefined && (
                                            <Link
                                                href={route('storefront.catalog.index', { type: 'products', family: product.id })}
                                                className="text-[0.71875rem] font-medium text-[var(--accent)] hover:underline"
                                            >
                                                {product.variants_published} de {product.variants_total} presentaciones publicadas
                                            </Link>
                                        )}
                                    </TD>
                                    <TD className="text-[12.5px] text-[var(--muted)]">{product.category ?? '—'}</TD>
                                    <TD align="right" className="font-mono text-[13px] tabular">
                                        {isFamilies && <span className="mr-1 font-sans text-[0.6875rem] text-[var(--muted)]">desde</span>}
                                        {product.open_price ? <span className="font-sans text-[var(--muted)]">Variable</span> : money(product.online_price ?? product.price)}
                                        {!isFamilies && !product.open_price && product.online_price && product.online_price !== product.price && (
                                            <span className="block text-[11px] text-[var(--muted)]">mostrador {money(product.price)}</span>
                                        )}
                                    </TD>
                                    <TD align="center">
                                        {product.open_price ? (
                                            <span className="text-[0.71875rem] text-[var(--muted)]">Precio variable</span>
                                        ) : (
                                        <button
                                            type="button"
                                            onClick={() => toggleOne(product, 'is_online_visible')}
                                            className="grid size-9 place-items-center rounded-[9px] transition-colors hover:bg-[var(--surface-2)]"
                                            aria-label={product.is_online_visible ? 'Ocultar' : 'Publicar'}
                                        >
                                            {product.is_online_visible ? (
                                                <EyeIcon size={18} className="text-[var(--accent)]" />
                                            ) : (
                                                <EyeSlashIcon size={18} className="text-[var(--muted)]" />
                                            )}
                                        </button>
                                        )}
                                    </TD>
                                    <TD align="center">
                                        <button
                                            type="button"
                                            onClick={() => toggleOne(product, 'is_featured')}
                                            className="grid size-9 place-items-center rounded-[9px] transition-colors hover:bg-[var(--surface-2)]"
                                            aria-label={product.is_featured ? 'Quitar de destacados' : 'Destacar'}
                                        >
                                            <StarIcon
                                                size={18}
                                                weight={product.is_featured ? 'fill' : 'regular'}
                                                className={product.is_featured ? 'text-[var(--warn)]' : 'text-[var(--muted)]'}
                                            />
                                        </button>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>

                    <Pagination meta={products} />
                </>
            )}
        </AppLayout>
    );
}

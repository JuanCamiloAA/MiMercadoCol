import { router, usePage } from '@inertiajs/react';
import { CaretLeftIcon, CaretRightIcon, FadersHorizontalIcon, MagnifyingGlassIcon, SealPercentIcon, XIcon } from '@phosphor-icons/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { StoreIcon } from '@/components/storefront/icons';
import { ProductCard } from '@/components/storefront/ProductCard';
import { Sheet } from '@/components/storefront/Sheet';
import { StoreLink, useCurrentShell, useIsPreview } from '@/components/storefront/store-context';
import StoreLayout from '@/layouts/StoreLayout';
import { cn } from '@/lib/cn';
import { money, number } from '@/lib/format';
import { StoreBlocks } from '@/pages/Storefront/blocks/StoreBlocks';
import type { CardProduct, CategoryNode, Listing, StoreBlock, StoreShell } from '@/types/storefront';

interface Filters {
    sub: number | null;
    brands: string[];
    /** Valores de característica elegidos (talla 12, azul…). */
    attr: number[];
    min: number | null;
    max: number | null;
    deals: boolean;
    sort: string;
    page: number;
}

interface CatalogProps {
    mode: 'category' | 'deals' | 'search';
    title: string;
    query: string | null;
    category: (CategoryNode & { children: CategoryNode[]; parent: { name: string; href: string } | null }) | null;
    results: Listing;
    filters: Filters;
    sorts: { value: string; label: string }[];
    blocks: StoreBlock[];
}

type Params = Record<string, string | number | boolean | string[] | undefined>;

function toParams(filters: Filters, query: string | null): Params {
    return {
        q: query || undefined,
        sub: filters.sub ?? undefined,
        brands: filters.brands.length > 0 ? filters.brands : undefined,
        attr: filters.attr.length > 0 ? filters.attr.map(String) : undefined,
        min: filters.min ?? undefined,
        max: filters.max ?? undefined,
        deals: filters.deals ? 1 : undefined,
        sort: filters.sort,
    } as Params;
}

/**
 * Categoría, ofertas y búsqueda: la misma página.
 *
 * Filtros a la izquierda en computador y en una hoja en celular (subcategoría, marca, precio,
 * solo ofertas, solo disponibles), orden, contador y la rejilla con «Cargar más» o páginas.
 * El precio que se filtra y se ordena es el vigente, con promociones: lo calcula el servidor.
 */
export default function StorefrontCatalog({ mode, title, query, category, results, filters, sorts, blocks }: CatalogProps) {
    const shell = useCurrentShell();
    const preview = useIsPreview();
    const page = shell?.settings.category_page;
    const allowed = page?.filters ?? [];
    const sidebar = (page?.layout ?? 'sidebar') === 'sidebar';
    const loadMore = (page?.pagination ?? 'load_more') === 'load_more';
    const [items, setItems] = useState<CardProduct[]>(results.items);
    const [sheet, setSheet] = useState(false);
    const [loading, setLoading] = useState(false);
    const appending = useRef(false);
    const path = usePage().url.split('?')[0];

    // Una página nueva de «Cargar más» se suma; cualquier otro cambio (filtro, orden) reemplaza.
    useEffect(() => {
        setItems((current) => (appending.current && results.page > 1 ? [...current, ...results.items] : results.items));
        appending.current = false;
    }, [results]);

    const apply = (next: Partial<Filters>) => {
        // En la vista previa del editor los filtros no navegan.
        if (preview) {
            return;
        }

        const merged = { ...filters, ...next, page: 1 };

        router.get(path, toParams(merged, query), { preserveScroll: true, preserveState: true, replace: true, only: ['results', 'filters'] });
    };

    const more = () => {
        if (preview) {
            return;
        }

        appending.current = true;
        setLoading(true);
        router.get(path, { ...toParams(filters, query), page: results.page + 1 }, {
            preserveScroll: true,
            preserveState: true,
            preserveUrl: true,
            only: ['results'],
            onFinish: () => setLoading(false),
        });
    };

    const goTo = (target: number) => {
        if (preview) {
            return;
        }

        router.get(path, { ...toParams(filters, query), page: target }, { preserveState: true, only: ['results', 'filters'] });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const active = (filters.sub ? 1 : 0) + filters.brands.length + filters.attr.length + (filters.min || filters.max ? 1 : 0) + (filters.deals ? 1 : 0);
    const chips = page?.show_subcategory_chips ?? true;

    const filterPanel = <FilterPanel results={results} filters={filters} allowed={allowed} onApply={apply} mode={mode} />;

    return (
        <StoreLayout title={title} current="catalog" searchInitial={query ?? ''}>
            <nav aria-label="Ruta" className="mb-3 flex flex-wrap items-center gap-1 text-[13px] text-[var(--muted)]">
                <StoreLink href="/tienda" className="hover:text-[var(--text)] hover:underline">
                    Inicio
                </StoreLink>
                {category?.parent && (
                    <>
                        <CaretRightIcon size={11} />
                        <StoreLink href={category.parent.href} className="hover:text-[var(--text)] hover:underline">
                            {category.parent.name}
                        </StoreLink>
                    </>
                )}
                <CaretRightIcon size={11} />
                <span className="font-semibold text-[var(--text)]" aria-current="page">
                    {mode === 'search' ? 'Búsqueda' : title}
                </span>
            </nav>

            <header className="mb-4 flex items-center gap-3">
                {mode === 'category' && category && (
                    <span className="grid size-14 shrink-0 place-items-center overflow-hidden rounded-full bg-[var(--accent-soft)] text-[var(--accent)]">
                        {category.image ? <img src={category.image} alt="" className="h-full w-full object-cover" /> : <StoreIcon name={category.icon} size={28} weight="duotone" />}
                    </span>
                )}
                {mode === 'deals' && (
                    <span className="grid size-14 shrink-0 place-items-center rounded-full bg-[var(--deal)] text-[var(--deal-ink)]">
                        <SealPercentIcon size={30} weight="fill" />
                    </span>
                )}
                <div className="min-w-0">
                    <h1 className="text-[24px] leading-tight md:text-[32px]">{title}</h1>
                    <p className="text-[13.5px] text-[var(--muted)]">
                        {number(results.total)} {results.total === 1 ? 'producto' : 'productos'}
                    </p>
                </div>
            </header>

            {blocks.length > 0 && <StoreBlocks blocks={blocks} className="mb-6" />}

            {chips && mode === 'category' && category && category.children.length > 0 && (
                <div className="scrollbar-none -mx-4 mb-4 flex gap-2 overflow-x-auto px-4 md:mx-0 md:flex-wrap md:px-0">
                    {category.children.map((child) => (
                        <StoreLink
                            key={child.id}
                            href={child.href}
                            className="flex h-10 shrink-0 items-center gap-2 rounded-full border border-[var(--line)] bg-[var(--surface)] px-3.5 text-[13.5px] font-semibold hover:border-[var(--accent)] hover:text-[var(--accent)]"
                        >
                            <StoreIcon name={child.icon} size={16} weight="fill" className="text-[var(--accent)]" />
                            {child.name}
                        </StoreLink>
                    ))}
                </div>
            )}

            <div className={cn('grid gap-6', sidebar && allowed.length > 0 && 'lg:grid-cols-[260px_minmax(0,1fr)]')}>
                {sidebar && allowed.length > 0 && (
                    <aside className="hidden lg:block" aria-label="Filtros">
                        <div className="sticky top-[180px] rounded-[var(--store-radius)] border border-[var(--line)] bg-[var(--surface)] p-4">
                            <p className="mb-2 text-[15px] font-extrabold">Filtrar</p>
                            {filterPanel}
                        </div>
                    </aside>
                )}

                <div className="min-w-0">
                    <div className="mb-3 flex flex-wrap items-center gap-2">
                        {allowed.length > 0 && (
                            <button
                                type="button"
                                onClick={() => setSheet(true)}
                                className={cn(
                                    'flex h-10 items-center gap-2 rounded-full border border-[var(--line)] bg-[var(--surface)] px-4 text-[13.5px] font-bold',
                                    sidebar && 'lg:hidden',
                                )}
                            >
                                <FadersHorizontalIcon size={18} weight="bold" />
                                Filtros
                                {active > 0 && (
                                    <span className="grid min-w-[20px] place-items-center rounded-full bg-[var(--accent)] px-1 text-[11px] leading-5 text-[var(--accent-ink)]">{active}</span>
                                )}
                            </button>
                        )}

                        <ActiveChips filters={filters} onApply={apply} results={results} />

                        {sorts.length > 1 && (
                            <label className="ml-auto flex items-center gap-2 text-[13px] text-[var(--muted)]">
                                <span className="hidden sm:inline">Ordenar por</span>
                                <select
                                    value={filters.sort}
                                    onChange={(event) => apply({ sort: event.target.value })}
                                    className="h-10 rounded-full border border-[var(--line)] bg-[var(--surface)] px-3 text-[13.5px] font-semibold text-[var(--text)] focus:border-[var(--accent)] focus:outline-none"
                                >
                                    {sorts.map((sort) => (
                                        <option key={sort.value} value={sort.value}>
                                            {sort.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                    </div>

                    {items.length === 0 ? (
                        <EmptyResults shell={shell} mode={mode} hasFilters={active > 0} onClear={() => apply({ sub: null, brands: [], attr: [], min: null, max: null, deals: false })} />
                    ) : (
                        <>
                            <div className={cn('grid grid-cols-2 gap-2.5 sm:grid-cols-3 md:gap-3', sidebar && allowed.length > 0 ? 'lg:grid-cols-4' : 'lg:grid-cols-5')}>
                                {items.map((product) => (
                                    <ProductCard key={product.key} product={product} />
                                ))}
                            </div>

                            {loadMore ? (
                                results.page < results.last_page && (
                                    <div className="mt-6 flex flex-col items-center gap-2">
                                        <p className="text-[13px] text-[var(--muted)]">
                                            Viste {number(items.length)} de {number(results.total)}
                                        </p>
                                        <button
                                            type="button"
                                            onClick={more}
                                            disabled={loading}
                                            className="h-12 rounded-full border-2 border-[var(--accent)] px-8 text-[14.5px] font-bold text-[var(--accent)] hover:bg-[var(--accent-soft)] disabled:opacity-60"
                                        >
                                            {loading ? 'Cargando…' : 'Cargar más'}
                                        </button>
                                    </div>
                                )
                            ) : (
                                results.last_page > 1 && (
                                    <nav aria-label="Páginas" className="mt-6 flex items-center justify-center gap-2">
                                        <button
                                            type="button"
                                            disabled={results.page <= 1}
                                            onClick={() => goTo(results.page - 1)}
                                            aria-label="Página anterior"
                                            className="grid size-11 place-items-center rounded-full border border-[var(--line)] bg-[var(--surface)] disabled:opacity-40"
                                        >
                                            <CaretLeftIcon size={17} weight="bold" />
                                        </button>
                                        <span className="px-3 text-[14px] font-semibold tabular-nums">
                                            Página {results.page} de {results.last_page}
                                        </span>
                                        <button
                                            type="button"
                                            disabled={results.page >= results.last_page}
                                            onClick={() => goTo(results.page + 1)}
                                            aria-label="Página siguiente"
                                            className="grid size-11 place-items-center rounded-full border border-[var(--line)] bg-[var(--surface)] disabled:opacity-40"
                                        >
                                            <CaretRightIcon size={17} weight="bold" />
                                        </button>
                                    </nav>
                                )
                            )}
                        </>
                    )}
                </div>
            </div>

            <Sheet
                open={sheet}
                onClose={() => setSheet(false)}
                title="Filtros"
                footer={
                    <button
                        type="button"
                        onClick={() => setSheet(false)}
                        className="h-12 w-full rounded-full bg-[var(--accent)] text-[15px] font-bold text-[var(--accent-ink)]"
                    >
                        Ver {number(results.total)} {results.total === 1 ? 'producto' : 'productos'}
                    </button>
                }
            >
                <div className="p-4">{filterPanel}</div>
            </Sheet>
        </StoreLayout>
    );
}

function ActiveChips({ filters, onApply, results }: { filters: Filters; onApply: (next: Partial<Filters>) => void; results: Listing }) {
    const sub = results.facets.subcategories.find((item) => item.id === filters.sub);
    const chip = 'flex h-9 items-center gap-1.5 rounded-full bg-[var(--accent-soft)] px-3 text-[12.5px] font-semibold text-[var(--accent)]';

    return (
        <>
            {sub && (
                <button type="button" className={chip} onClick={() => onApply({ sub: null })}>
                    {sub.name} <XIcon size={12} weight="bold" />
                </button>
            )}
            {filters.brands.map((brand) => (
                <button key={brand} type="button" className={chip} onClick={() => onApply({ brands: filters.brands.filter((item) => item !== brand) })}>
                    {brand} <XIcon size={12} weight="bold" />
                </button>
            ))}
            {filters.attr.map((id) => {
                const found = (results.facets.attributes ?? []).flatMap((attribute) => attribute.values.map((value) => ({ ...value, attribute: attribute.name }))).find((value) => value.id === id);

                return (
                    <button key={id} type="button" className={chip} onClick={() => onApply({ attr: filters.attr.filter((item) => item !== id) })}>
                        {found ? `${found.attribute}: ${found.value}` : 'Característica'} <XIcon size={12} weight="bold" />
                    </button>
                );
            })}
            {(filters.min || filters.max) && (
                <button type="button" className={chip} onClick={() => onApply({ min: null, max: null })}>
                    {filters.min ? money(filters.min) : '$ 0'} – {filters.max ? money(filters.max) : 'más'} <XIcon size={12} weight="bold" />
                </button>
            )}
            {filters.deals && (
                <button type="button" className={chip} onClick={() => onApply({ deals: false })}>
                    Solo ofertas <XIcon size={12} weight="bold" />
                </button>
            )}
        </>
    );
}

function FilterPanel({
    results,
    filters,
    allowed,
    onApply,
    mode,
}: {
    results: Listing;
    filters: Filters;
    allowed: string[];
    onApply: (next: Partial<Filters>) => void;
    mode: CatalogProps['mode'];
}) {
    const [min, setMin] = useState(filters.min ? String(filters.min) : '');
    const [max, setMax] = useState(filters.max ? String(filters.max) : '');
    const [allBrands, setAllBrands] = useState(false);
    const brands = useMemo(() => (allBrands ? results.facets.brands : results.facets.brands.slice(0, 8)), [allBrands, results.facets.brands]);

    useEffect(() => {
        setMin(filters.min ? String(filters.min) : '');
        setMax(filters.max ? String(filters.max) : '');
    }, [filters.min, filters.max]);

    const section = 'border-t border-[var(--line)] py-3 first:border-t-0 first:pt-0';
    const heading = 'mb-2 text-[13px] font-extrabold uppercase tracking-wide text-[var(--muted)]';

    return (
        <div className="flex flex-col text-[14px]">
            {allowed.includes('subcategories') && results.facets.subcategories.length > 0 && (
                <fieldset className={section}>
                    <legend className={heading}>Subcategoría</legend>
                    <ul className="flex flex-col gap-0.5">
                        {results.facets.subcategories.map((sub) => (
                            <li key={sub.id}>
                                <label className="flex min-h-[40px] cursor-pointer items-center gap-2.5">
                                    <input
                                        type="radio"
                                        name="sub"
                                        checked={filters.sub === sub.id}
                                        onChange={() => onApply({ sub: sub.id })}
                                        className="size-4 accent-[var(--accent)]"
                                    />
                                    <span className="flex-1">{sub.name}</span>
                                    <span className="text-[12px] text-[var(--muted)] tabular-nums">{sub.count}</span>
                                </label>
                            </li>
                        ))}
                        {filters.sub && (
                            <li>
                                <button type="button" onClick={() => onApply({ sub: null })} className="min-h-[36px] text-[13px] font-semibold text-[var(--accent)] hover:underline">
                                    Ver todas
                                </button>
                            </li>
                        )}
                    </ul>
                </fieldset>
            )}

            {allowed.includes('brand') && results.facets.brands.length > 0 && (
                <fieldset className={section}>
                    <legend className={heading}>Marca</legend>
                    <ul className="flex flex-col gap-0.5">
                        {brands.map((brand) => (
                            <li key={brand.value}>
                                <label className="flex min-h-[40px] cursor-pointer items-center gap-2.5">
                                    <input
                                        type="checkbox"
                                        checked={filters.brands.includes(brand.value)}
                                        onChange={(event) =>
                                            onApply({
                                                brands: event.target.checked ? [...filters.brands, brand.value] : filters.brands.filter((item) => item !== brand.value),
                                            })
                                        }
                                        className="size-4 rounded accent-[var(--accent)]"
                                    />
                                    <span className="flex-1">{brand.value}</span>
                                    <span className="text-[12px] text-[var(--muted)] tabular-nums">{brand.count}</span>
                                </label>
                            </li>
                        ))}
                    </ul>
                    {results.facets.brands.length > 8 && (
                        <button type="button" onClick={() => setAllBrands((current) => !current)} className="mt-1 min-h-[36px] text-[13px] font-semibold text-[var(--accent)] hover:underline">
                            {allBrands ? 'Ver menos' : `Ver las ${results.facets.brands.length} marcas`}
                        </button>
                    )}
                </fieldset>
            )}

            {allowed.includes('attributes') &&
                (results.facets.attributes ?? []).map((attribute) => (
                    <fieldset key={attribute.id} className={section}>
                        <legend className={heading}>{attribute.name}</legend>
                        <div className="flex flex-wrap gap-1.5">
                            {attribute.values.map((value) => {
                                const on = filters.attr.includes(value.id);

                                return (
                                    <button
                                        key={value.id}
                                        type="button"
                                        aria-pressed={on}
                                        onClick={() => onApply({ attr: on ? filters.attr.filter((item) => item !== value.id) : [...filters.attr, value.id] })}
                                        className={
                                            on
                                                ? 'flex min-h-[36px] items-center gap-1.5 rounded-full border-2 border-[var(--accent)] bg-[var(--accent-soft)] px-3 text-[13px] font-bold text-[var(--accent)]'
                                                : 'flex min-h-[36px] items-center gap-1.5 rounded-full border border-[var(--line)] px-3 text-[13px] font-semibold hover:border-[var(--accent)]'
                                        }
                                    >
                                        {value.color_hex && <span className="size-3.5 rounded-full border border-black/10" style={{ backgroundColor: value.color_hex }} aria-hidden />}
                                        {value.value}
                                        <span className="text-[11.5px] font-normal text-[var(--muted)] tabular-nums">{value.count}</span>
                                    </button>
                                );
                            })}
                        </div>
                    </fieldset>
                ))}

            {allowed.includes('price') && results.facets.price && (
                <fieldset className={section}>
                    <legend className={heading}>Precio</legend>
                    <form
                        className="flex items-end gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            onApply({ min: min ? Number(min) : null, max: max ? Number(max) : null });
                        }}
                    >
                        <label className="min-w-0 flex-1">
                            <span className="mb-1 block text-[12px] text-[var(--muted)]">Desde</span>
                            <input
                                type="number"
                                inputMode="numeric"
                                min={0}
                                value={min}
                                placeholder={String(results.facets.price.min)}
                                onChange={(event) => setMin(event.target.value)}
                                className="h-10 w-full rounded-[10px] border border-[var(--line)] bg-[var(--surface-2)] px-2.5 text-[14px] tabular-nums focus:border-[var(--accent)] focus:outline-none"
                            />
                        </label>
                        <label className="min-w-0 flex-1">
                            <span className="mb-1 block text-[12px] text-[var(--muted)]">Hasta</span>
                            <input
                                type="number"
                                inputMode="numeric"
                                min={0}
                                value={max}
                                placeholder={String(results.facets.price.max)}
                                onChange={(event) => setMax(event.target.value)}
                                className="h-10 w-full rounded-[10px] border border-[var(--line)] bg-[var(--surface-2)] px-2.5 text-[14px] tabular-nums focus:border-[var(--accent)] focus:outline-none"
                            />
                        </label>
                        <button type="submit" aria-label="Aplicar el rango de precio" className="grid size-10 shrink-0 place-items-center rounded-[10px] bg-[var(--accent)] text-[var(--accent-ink)]">
                            <CaretRightIcon size={16} weight="bold" />
                        </button>
                    </form>
                    <p className="mt-1.5 text-[12px] text-[var(--muted)]">
                        Entre {money(results.facets.price.min)} y {money(results.facets.price.max)}, con las ofertas de hoy.
                    </p>
                </fieldset>
            )}

            {allowed.includes('deals') && mode !== 'deals' && (
                <div className={section}>
                    <label className="flex min-h-[40px] cursor-pointer items-center gap-2.5 font-semibold">
                        <input type="checkbox" checked={filters.deals} onChange={(event) => onApply({ deals: event.target.checked })} className="size-4 accent-[var(--deal)]" />
                        <SealPercentIcon size={18} weight="fill" className="text-[var(--deal)]" />
                        Solo ofertas
                    </label>
                </div>
            )}

        </div>
    );
}

function EmptyResults({ shell, mode, hasFilters, onClear }: { shell: StoreShell | null; mode: CatalogProps['mode']; hasFilters: boolean; onClear: () => void }) {
    const search = shell?.settings.search;

    return (
        <div className="flex flex-col items-center gap-3 rounded-[var(--store-radius)] border border-dashed border-[var(--line-2)] px-4 py-12 text-center">
            <MagnifyingGlassIcon size={40} className="text-[var(--muted)]" />
            <h2 className="text-[20px]">{hasFilters ? 'Nada con esos filtros' : mode === 'deals' ? 'Hoy no hay ofertas' : (search?.empty_title ?? 'No encontramos lo que buscas')}</h2>
            <p className="max-w-[420px] text-[14px] text-[var(--muted)]">
                {hasFilters ? 'Quita alguno de los filtros para ver más productos.' : mode === 'deals' ? 'Vuelve pronto: las ofertas cambian cada semana.' : search?.empty_text}
            </p>
            {hasFilters ? (
                <button type="button" onClick={onClear} className="h-11 rounded-full bg-[var(--accent)] px-6 text-[14px] font-bold text-[var(--accent-ink)]">
                    Quitar los filtros
                </button>
            ) : (
                (search?.popular_terms ?? []).length > 0 && (
                    <div className="flex flex-wrap justify-center gap-2">
                        {(search?.popular_terms ?? []).map((term) => (
                            <StoreLink
                                key={term}
                                href={`/tienda/buscar?q=${encodeURIComponent(term)}`}
                                className="flex h-10 items-center rounded-full border border-[var(--line)] bg-[var(--surface)] px-4 text-[13.5px] font-semibold hover:border-[var(--accent)] hover:text-[var(--accent)]"
                            >
                                {term}
                            </StoreLink>
                        ))}
                    </div>
                )
            )}
        </div>
    );
}

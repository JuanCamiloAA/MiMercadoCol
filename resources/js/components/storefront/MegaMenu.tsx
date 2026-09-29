import { Link } from '@inertiajs/react';
import { CaretRightIcon, MagnifyingGlassIcon, XIcon } from '@phosphor-icons/react';
import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/cn';
import type { CardProduct, CategoryNode } from '@/types/storefront';
import { StoreIcon } from './icons';
import { ProductShelf } from './ProductShelf';

interface MenuResult {
    products: CardProduct[];
    total: number;
    by_sales: boolean;
    search_href: string | null;
}

/**
 * Lo que ya se pidió, por categoría y búsqueda: pasar el mouse dos veces por la misma no vuelve
 * a pedirla. Dura cinco minutos, para que un precio que cambió no se quede pegado.
 */
const CACHE_MS = 5 * 60 * 1000;
const cache = new Map<string, { at: number; promise: Promise<MenuResult> }>();

function loadMenu(categoryId: number, search: string): Promise<MenuResult> {
    const key = `${categoryId}|${search}`;
    const hit = cache.get(key);

    if (hit && Date.now() - hit.at < CACHE_MS) {
        return hit.promise;
    }

    const promise = fetch(route('storefront.menu.category', { category: categoryId, ...(search ? { q: search } : {}) }), {
        headers: { Accept: 'application/json' },
    }).then((response) => (response.ok ? (response.json() as Promise<MenuResult>) : Promise.reject(new Error(String(response.status)))));

    cache.set(key, { at: Date.now(), promise });
    promise.catch(() => cache.delete(key));

    return promise;
}

export function CategoryThumb({ category, size = 'md' }: { category: Pick<CategoryNode, 'icon' | 'image'>; size?: 'sm' | 'md' }) {
    const box = size === 'sm' ? 'size-7' : 'size-10';

    if (category.image) {
        return <img src={category.image} alt="" className={cn(box, 'shrink-0 rounded-full object-cover')} />;
    }

    return (
        <span className={cn(box, 'grid shrink-0 place-items-center rounded-full bg-[var(--accent-soft)] text-[var(--accent)]')}>
            <StoreIcon name={category.icon} size={size === 'sm' ? 15 : 20} weight="fill" />
        </span>
    );
}

/**
 * El menú grande: las categorías principales a la izquierda y, de la que se señala, sus
 * subcategorías y lo más vendido a la derecha, con un buscador para filtrar dentro de ella.
 * Se abre con «Todas las categorías» y se maneja con el teclado: flechas entre categorías,
 * Esc para cerrar (o para borrar la búsqueda, si hay una).
 */
export function MegaMenu({ categories, onClose }: { categories: CategoryNode[]; onClose: () => void }) {
    const [active, setActive] = useState(0);
    const [query, setQuery] = useState('');
    const [search, setSearch] = useState('');
    const [result, setResult] = useState<{ key: string; data: MenuResult | null } | null>(null);
    const list = useRef<HTMLUListElement>(null);
    const current = categories[active];
    const key = current ? `${current.id}|${search}` : '';

    useEffect(() => {
        list.current?.querySelector<HTMLElement>('a')?.focus();
    }, []);

    // Lo que se escribe se busca cuando el cliente para de escribir.
    useEffect(() => {
        const timer = setTimeout(() => setSearch(query.trim()), 300);

        return () => clearTimeout(timer);
    }, [query]);

    // Al bajar el mouse por la lista no se pide cada categoría por la que pasa: solo aquella en
    // la que se detiene.
    useEffect(() => {
        if (!current) {
            return;
        }

        let alive = true;
        const timer = setTimeout(() => {
            loadMenu(current.id, search)
                .then((data) => alive && setResult({ key: `${current.id}|${search}`, data }))
                .catch(() => alive && setResult({ key: `${current.id}|${search}`, data: null }));
        }, 120);

        return () => {
            alive = false;
            clearTimeout(timer);
        };
    }, [current, search]);

    if (!current) {
        return null;
    }

    const loading = result?.key !== key || search !== query.trim();
    const data = result?.data ?? null;
    const children = current.children ?? [];
    const title = search
        ? `${data?.total ?? 0} ${data?.total === 1 ? 'resultado' : 'resultados'} para «${search}»`
        : data?.by_sales
          ? `Lo más vendido en ${current.name}`
          : `Destacados de ${current.name}`;

    return (
        <div
            className="absolute inset-x-0 top-full z-40 border-b border-[var(--line)] bg-[var(--surface)] text-[var(--text)] shadow-2xl"
            onKeyDown={(event) => {
                if (event.key === 'Escape') {
                    onClose();
                }
            }}
            // Tocar un enlace (un artículo, una subcategoría) navega: el menú se cierra. Los
            // botones de agregar no son enlaces y lo dejan abierto.
            onClickCapture={(event) => {
                if ((event.target as HTMLElement).closest('a')) {
                    onClose();
                }
            }}
        >
            <div className="mx-auto grid max-h-[min(80vh,660px)] w-full max-w-[1280px] grid-cols-[280px_minmax(0,1fr)] gap-6 px-4 py-4 lg:px-6">
                <ul ref={list} className="overflow-y-auto border-r border-[var(--line)] pr-2" aria-label="Categorías">
                    {categories.map((category, position) => (
                        <li key={category.id}>
                            <Link
                                href={category.href}
                                onMouseEnter={() => setActive(position)}
                                onFocus={() => setActive(position)}
                                onKeyDown={(event) => {
                                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                                        event.preventDefault();
                                        const next = Math.max(0, Math.min(categories.length - 1, position + (event.key === 'ArrowDown' ? 1 : -1)));
                                        setActive(next);
                                        list.current?.querySelectorAll<HTMLElement>('a')[next]?.focus();
                                    }
                                }}
                                className={cn(
                                    'flex min-h-[44px] items-center gap-3 rounded-[12px] px-2.5 text-[14px] font-semibold outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent)]',
                                    position === active ? 'bg-[var(--accent-soft)] text-[var(--accent)]' : 'hover:bg-[var(--surface-2)]',
                                )}
                            >
                                <CategoryThumb category={category} size="sm" />
                                <span className="min-w-0 flex-1 truncate">{category.name}</span>
                                <CaretRightIcon size={14} weight="bold" className={cn('shrink-0', position === active ? 'opacity-100' : 'opacity-40')} />
                            </Link>
                        </li>
                    ))}
                </ul>

                <div className="min-w-0 overflow-y-auto py-1 pr-1">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex min-w-0 items-baseline gap-3">
                            <h2 className="truncate text-[21px] leading-tight">{current.name}</h2>
                            <Link href={current.href} className="flex shrink-0 items-center gap-0.5 text-[13.5px] font-bold text-[var(--accent)] hover:underline">
                                Ver todo
                                <CaretRightIcon size={13} weight="bold" />
                            </Link>
                        </div>

                        <form
                            role="search"
                            onSubmit={(event) => {
                                event.preventDefault();
                                setSearch(query.trim());
                            }}
                            className="relative w-full max-w-[280px]"
                        >
                            <MagnifyingGlassIcon size={17} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[var(--muted)]" />
                            <input
                                type="search"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                onKeyDown={(event) => {
                                    // Esc borra primero lo escrito; con el campo vacío, cierra el menú.
                                    if (event.key === 'Escape' && query !== '') {
                                        event.stopPropagation();
                                        setQuery('');
                                    }
                                }}
                                placeholder={`Buscar en ${current.name}`}
                                aria-label={`Buscar en ${current.name}`}
                                className="h-10 w-full rounded-full border border-[var(--line)] bg-[var(--surface-2)] pl-9 pr-9 text-[14px] outline-none transition-colors placeholder:text-[var(--muted)] focus:border-[var(--accent)] focus:bg-[var(--surface)] [&::-webkit-search-cancel-button]:hidden"
                            />
                            {query !== '' && (
                                <button
                                    type="button"
                                    onClick={() => setQuery('')}
                                    aria-label="Borrar la búsqueda"
                                    className="absolute right-1.5 top-1/2 grid size-7 -translate-y-1/2 place-items-center rounded-full text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]"
                                >
                                    <XIcon size={14} weight="bold" />
                                </button>
                            )}
                        </form>
                    </div>

                    {children.length > 0 && !search && (
                        <ul className="mt-3 flex flex-wrap gap-1.5" aria-label={`Subcategorías de ${current.name}`}>
                            {children.map((child) => (
                                <li key={child.id}>
                                    <Link
                                        href={child.href}
                                        className="flex h-9 items-center gap-2 rounded-full border border-[var(--line)] pl-1 pr-3 text-[13px] font-semibold hover:border-[var(--accent)] hover:text-[var(--accent)]"
                                    >
                                        <CategoryThumb category={child} size="sm" />
                                        {child.name}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}

                    <div className="mt-4" aria-busy={loading} aria-live="polite">
                        {data === null && !loading ? (
                            <p className="rounded-[12px] bg-[var(--surface-2)] px-4 py-6 text-center text-[13.5px] text-[var(--muted)]">
                                No pudimos traer los artículos. <Link href={current.href} className="font-semibold text-[var(--accent)]">Ver {current.name}</Link>
                            </p>
                        ) : data === null ? (
                            <ShelfSkeleton />
                        ) : (
                            <div className={cn('transition-opacity', loading && 'opacity-50')}>
                                <ProductShelf
                                    title={title}
                                    products={data.products}
                                    layout="carousel"
                                    columnsDesktop={4}
                                    empty={
                                        <p className="rounded-[12px] bg-[var(--surface-2)] px-4 py-6 text-center text-[13.5px] text-[var(--muted)]">
                                            {search ? (
                                                <>
                                                    No encontramos «{search}» en {current.name}.{' '}
                                                    {data.search_href && (
                                                        <Link href={data.search_href} className="font-semibold text-[var(--accent)]">
                                                            Buscarlo en toda la tienda
                                                        </Link>
                                                    )}
                                                </>
                                            ) : (
                                                <>Todavía no hay artículos en {current.name}.</>
                                            )}
                                        </p>
                                    }
                                />
                                {search && data.total > data.products.length && data.search_href && (
                                    <Link href={data.search_href} className="mt-3 inline-flex items-center gap-0.5 text-[13.5px] font-bold text-[var(--accent)] hover:underline">
                                        Ver los {data.total} resultados
                                        <CaretRightIcon size={13} weight="bold" />
                                    </Link>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

/** Mientras llegan los artículos: la forma del estante, para que el panel no salte. */
function ShelfSkeleton() {
    return (
        <div aria-hidden>
            <div className="mb-3 h-6 w-56 animate-pulse rounded-full bg-[var(--surface-2)]" />
            <div className="grid grid-cols-4 gap-3">
                {[0, 1, 2, 3].map((index) => (
                    <div key={index} className="overflow-hidden rounded-[var(--store-radius)] border border-[var(--line)]">
                        <div className="aspect-square animate-pulse bg-[var(--surface-2)]" />
                        <div className="flex flex-col gap-2 p-3">
                            <div className="h-3.5 w-4/5 animate-pulse rounded-full bg-[var(--surface-2)]" />
                            <div className="h-3.5 w-1/2 animate-pulse rounded-full bg-[var(--surface-2)]" />
                            <div className="mt-2 h-10 animate-pulse rounded-[var(--store-radius-sm)] bg-[var(--surface-2)]" />
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

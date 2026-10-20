import { router } from '@inertiajs/react';
import { ClockCounterClockwiseIcon, MagnifyingGlassIcon, XIcon } from '@phosphor-icons/react';
import { useEffect, useId, useRef, useState } from 'react';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';
import { StoreIcon } from './icons';
import { usePreviewMode } from './store-context';

interface Suggestions {
    products: { name: string; brand: string | null; content: string | null; image: string | null; category_icon: string; price: number; compare_at: number | null; href: string }[];
    categories: { name: string; href: string; icon: string }[];
    popular: string[];
}

type Option = { kind: 'product' | 'category'; href: string; label: string } | { kind: 'term'; label: string };

/**
 * El buscador grande del encabezado, con sugerencias mientras se escribe.
 *
 * Las sugerencias las da el servidor sin tildes y con lo que suena igual: «arros» trae
 * «Arroz». Con el campo vacío ofrece las búsquedas populares que eligió la sede. Se maneja
 * con el teclado como un combobox: flechas, Enter y Esc.
 */
export function SearchBox({
    placeholder,
    suggestions: enabled,
    initial = '',
    tone = 'light',
    variant = 'pill',
    autoFocus = false,
    className,
}: {
    placeholder: string;
    suggestions: boolean;
    initial?: string;
    tone?: 'light' | 'onColor';
    /** `underline`: una línea debajo, sin caja ni botón (el de las tiendas de moda). */
    variant?: 'pill' | 'underline';
    autoFocus?: boolean;
    className?: string;
}) {
    const underline = variant === 'underline';
    const { interactive } = usePreviewMode();
    const [query, setQuery] = useState(initial);
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(-1);
    const [data, setData] = useState<Suggestions | null>(null);
    const listId = useId();
    const box = useRef<HTMLDivElement>(null);
    const input = useRef<HTMLInputElement>(null);

    useEffect(() => setQuery(initial), [initial]);

    useEffect(() => {
        if (!enabled || !interactive || !open) {
            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(`${route('storefront.search.suggestions')}?q=${encodeURIComponent(query.trim())}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((body: Suggestions | null) => {
                    setData(body);
                    setActive(-1);
                })
                .catch(() => undefined);
        }, 180);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [enabled, interactive, open, query]);

    useEffect(() => {
        const close = (event: MouseEvent) => {
            if (box.current && !box.current.contains(event.target as Node)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', close);

        return () => document.removeEventListener('mousedown', close);
    }, []);

    const options: Option[] = [
        ...(data?.products ?? []).map((product) => ({ kind: 'product' as const, href: product.href, label: product.name })),
        ...(data?.categories ?? []).map((category) => ({ kind: 'category' as const, href: category.href, label: category.name })),
        ...(data?.popular ?? []).map((term) => ({ kind: 'term' as const, label: term })),
    ];

    const search = (term: string) => {
        setOpen(false);

        if (interactive) {
            router.get(route('storefront.search'), term.trim() !== '' ? { q: term.trim() } : {});
        }
    };

    const choose = (option: Option) => {
        if (option.kind === 'term') {
            setQuery(option.label);
            search(option.label);

            return;
        }

        setOpen(false);
        router.visit(option.href);
    };

    const showList = open && enabled && options.length > 0;

    return (
        <div ref={box} className={cn('relative w-full', className)}>
            <form
                role="search"
                onSubmit={(event) => {
                    event.preventDefault();

                    if (active >= 0 && options[active]) {
                        choose(options[active]);
                    } else {
                        search(query);
                    }
                }}
                className="relative"
            >
                <label htmlFor={`${listId}-input`} className="sr-only">
                    Buscar en la tienda
                </label>
                <MagnifyingGlassIcon
                    size={19}
                    weight="bold"
                    className={cn(
                        'pointer-events-none absolute top-1/2 -translate-y-1/2',
                        underline ? 'left-0' : 'left-3.5',
                        underline && tone === 'onColor' ? 'opacity-80' : 'text-[var(--muted)]',
                    )}
                />
                <input
                    ref={input}
                    id={`${listId}-input`}
                    type="search"
                    role="combobox"
                    aria-expanded={showList}
                    aria-controls={listId}
                    aria-autocomplete="list"
                    aria-activedescendant={active >= 0 ? `${listId}-${active}` : undefined}
                    autoComplete="off"
                    autoFocus={autoFocus}
                    value={query}
                    placeholder={placeholder}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        setOpen(true);
                    }}
                    onFocus={() => setOpen(true)}
                    onKeyDown={(event) => {
                        if (event.key === 'ArrowDown') {
                            event.preventDefault();
                            setOpen(true);
                            setActive((current) => Math.min(options.length - 1, current + 1));
                        } else if (event.key === 'ArrowUp') {
                            event.preventDefault();
                            setActive((current) => Math.max(-1, current - 1));
                        } else if (event.key === 'Escape') {
                            setOpen(false);
                        }
                    }}
                    className={cn(
                        'w-full text-[15px] font-medium outline-none transition-colors placeholder:font-normal [&::-webkit-search-cancel-button]:hidden',
                        underline
                            ? cn(
                                  'h-10 border-b bg-transparent pl-7 pr-9',
                                  tone === 'onColor'
                                      ? 'border-current text-current placeholder:text-current placeholder:opacity-70'
                                      : 'border-[var(--line-2)] text-[var(--text)] placeholder:text-[var(--muted)] focus:border-[var(--text)]',
                              )
                            : cn(
                                  'h-12 rounded-full border-2 pl-11 pr-24 placeholder:text-[var(--muted)]',
                                  tone === 'onColor'
                                      ? 'border-transparent bg-[var(--surface)] text-[var(--text)] focus:border-[var(--surface)]'
                                      : 'border-[var(--line)] bg-[var(--surface-2)] text-[var(--text)] focus:border-[var(--accent)] focus:bg-[var(--surface)]',
                              ),
                    )}
                />
                {query !== '' && (
                    <button
                        type="button"
                        onClick={() => {
                            setQuery('');
                            input.current?.focus();
                        }}
                        aria-label="Borrar la búsqueda"
                        className={cn(
                            'absolute top-1/2 grid size-8 -translate-y-1/2 place-items-center rounded-full hover:bg-black/5',
                            underline ? 'right-0 opacity-70' : 'right-[4.6rem] text-[var(--muted)]',
                        )}
                    >
                        <XIcon size={15} weight="bold" />
                    </button>
                )}
                {!underline && (
                    <button
                        type="submit"
                        className="absolute right-1.5 top-1/2 h-9 -translate-y-1/2 rounded-full bg-[var(--accent)] px-4 text-[13.5px] font-bold text-[var(--accent-ink)] hover:brightness-110"
                    >
                        Buscar
                    </button>
                )}
            </form>

            {showList && (
                <ul
                    id={listId}
                    role="listbox"
                    aria-label="Sugerencias"
                    className="absolute inset-x-0 top-[calc(100%+6px)] z-50 max-h-[70vh] overflow-y-auto rounded-[16px] border border-[var(--line)] bg-[var(--surface)] p-1.5 text-[var(--text)] shadow-xl"
                >
                    {data?.popular && data.popular.length > 0 && (
                        <li role="presentation" className="px-3 pb-1 pt-2 text-[11.5px] font-bold uppercase tracking-wide text-[var(--muted)]">
                            Lo que más se busca
                        </li>
                    )}
                    {options.map((option, position) => {
                        const product = option.kind === 'product' ? data?.products[position] : null;
                        const category = option.kind === 'category' ? data?.categories[position - (data?.products.length ?? 0)] : null;

                        return (
                            <li
                                key={`${option.kind}-${option.label}-${position}`}
                                id={`${listId}-${position}`}
                                role="option"
                                aria-selected={position === active}
                                onMouseDown={(event) => {
                                    event.preventDefault();
                                    choose(option);
                                }}
                                onMouseEnter={() => setActive(position)}
                                className={cn(
                                    'flex min-h-[48px] cursor-pointer items-center gap-3 rounded-[11px] px-2.5 py-1.5',
                                    position === active ? 'bg-[var(--surface-2)]' : '',
                                )}
                            >
                                {product ? (
                                    <>
                                        <span className="grid size-11 shrink-0 place-items-center overflow-hidden rounded-[9px] bg-white">
                                            {product.image ? (
                                                <img src={product.image} alt="" className="h-full w-full object-contain p-1" />
                                            ) : (
                                                <StoreIcon name={product.category_icon} size={22} className="text-[var(--muted)]" />
                                            )}
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-[14px] font-semibold">{product.name}</span>
                                            <span className="block truncate text-[12px] text-[var(--muted)]">
                                                {[product.brand, product.content].filter(Boolean).join(' · ')}
                                            </span>
                                        </span>
                                        <span
                                            className={cn(
                                                'shrink-0 text-[14px] font-extrabold tabular-nums',
                                                product.compare_at && product.compare_at > product.price ? 'text-[var(--deal)]' : '',
                                            )}
                                        >
                                            {money(product.price)}
                                        </span>
                                    </>
                                ) : category ? (
                                    <>
                                        <span className="grid size-11 shrink-0 place-items-center rounded-full bg-[var(--accent-soft)] text-[var(--accent)]">
                                            <StoreIcon name={category.icon} size={20} weight="fill" />
                                        </span>
                                        <span className="text-[14px]">
                                            Ver la categoría <strong>{category.name}</strong>
                                        </span>
                                    </>
                                ) : (
                                    <>
                                        <span className="grid size-8 shrink-0 place-items-center text-[var(--muted)]">
                                            <ClockCounterClockwiseIcon size={17} />
                                        </span>
                                        <span className="text-[14px]">{option.label}</span>
                                    </>
                                )}
                            </li>
                        );
                    })}
                    {query.trim() !== '' && (
                        <li role="presentation" className="border-t border-[var(--line)] px-1 pt-1.5">
                            <button
                                type="button"
                                onMouseDown={(event) => {
                                    event.preventDefault();
                                    search(query);
                                }}
                                className="flex min-h-[44px] w-full items-center gap-2 rounded-[11px] px-2.5 text-left text-[14px] font-semibold text-[var(--accent)] hover:bg-[var(--surface-2)]"
                            >
                                <MagnifyingGlassIcon size={17} weight="bold" />
                                Ver todos los resultados de «{query.trim()}»
                            </button>
                        </li>
                    )}
                </ul>
            )}
        </div>
    );
}

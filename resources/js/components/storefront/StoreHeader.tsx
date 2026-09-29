import { Link, router, usePage } from '@inertiajs/react';
import { CaretDownIcon, CheckIcon, MapPinIcon, ShoppingCartIcon, SignOutIcon, StorefrontIcon, UserCircleIcon, UserIcon } from '@phosphor-icons/react';
import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';
import type { PageProps } from '@/types';
import type { StoreShell } from '@/types/storefront';
import { countOf, isSyncing, useCart } from './cart-store';
import { openLogin } from './login-dialog';
import { SearchBox } from './SearchBox';
import { Sheet } from './Sheet';
import { StoreLink, usePreviewMode } from './store-context';

/** El logo, el isotipo con el nombre, o solo el nombre: lo que eligió la sede. */
export function StoreBrand({ shell, onColor }: { shell: StoreShell; onColor: boolean }) {
    const { theme, settings } = shell;
    const name = theme.display_name || settings.company.name || 'Tienda en línea';
    const mode = settings.header.logo_mode;
    const logo = theme.logo;
    const mark = theme.mark;
    const initials = name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0]?.toUpperCase())
        .join('');

    const badge = mark ? (
        <img src={mark.variants?.square ?? mark.url} alt="" className="size-10 shrink-0 rounded-[10px] object-cover md:size-11" />
    ) : (
        <span
            className={cn(
                'grid size-10 shrink-0 place-items-center rounded-[10px] text-[16px] font-black md:size-11',
                onColor ? 'bg-[var(--surface)] text-[var(--accent)]' : 'bg-[var(--accent)] text-[var(--accent-ink)]',
            )}
            aria-hidden
        >
            {initials}
        </span>
    );

    return (
        <StoreLink href="/tienda" className="flex min-w-0 shrink-0 items-center gap-2.5" ariaLabel={`${name}, ir a la portada`}>
            {mode === 'logo' && logo ? (
                <img src={logo.url} alt={name} className="h-9 w-auto max-w-[150px] object-contain md:h-11 md:max-w-[200px]" />
            ) : (
                <>
                    {mode !== 'name' && badge}
                    <span className="min-w-0">
                        <span className="block truncate text-[17px] font-extrabold leading-tight tracking-tight md:text-[20px]" style={{ fontFamily: 'var(--store-display)' }}>
                            {name}
                        </span>
                        {settings.header.show_tagline && theme.tagline && (
                            <span className={cn('hidden truncate text-[12px] leading-tight lg:block', onColor ? 'opacity-80' : 'text-[var(--muted)]')}>
                                {theme.tagline}
                            </span>
                        )}
                    </span>
                </>
            )}
        </StoreLink>
    );
}

/**
 * «Recibir en»: la sede que atiende y cómo llega el pedido. Abre una hoja para cambiar de sede
 * de la misma empresa o volver al buscador de tiendas de la landing.
 */
export function LocationPill({ shell, onColor, compact = false }: { shell: StoreShell; onColor: boolean; compact?: boolean }) {
    const [open, setOpen] = useState(false);
    const { interactive } = usePreviewMode();
    const location = shell.settings.header.location;
    const stores = shell.stores ?? [];

    return (
        <>
            <button
                type="button"
                onClick={() => interactive && setOpen(true)}
                className={cn(
                    'flex min-w-0 items-center gap-2 text-left',
                    compact ? 'w-full py-2 text-[12.5px]' : 'max-w-[220px] shrink-0 rounded-full px-3 py-1.5 hover:bg-black/5',
                )}
            >
                <MapPinIcon size={compact ? 16 : 20} weight="fill" className={cn('shrink-0', onColor ? '' : 'text-[var(--accent)]')} />
                <span className="min-w-0">
                    {!compact && <span className={cn('block text-[11px] leading-tight', onColor ? 'opacity-80' : 'text-[var(--muted)]')}>Recibir en</span>}
                    <span className="block truncate text-[13px] font-bold leading-tight">
                        {compact && <span className="font-medium opacity-80">Recibir en </span>}
                        {location.store}
                        {location.mode ? <span className="font-medium opacity-80"> · {location.mode}</span> : null}
                    </span>
                </span>
                <CaretDownIcon size={13} weight="bold" className="shrink-0 opacity-70" />
            </button>

            <Sheet open={open} onClose={() => setOpen(false)} title="¿Dónde recibes tu pedido?">
                <div className="flex flex-col gap-2 p-4">
                    <p className="text-[13.5px] text-[var(--muted)]">
                        Los precios, las ofertas y lo que hay disponible dependen de la sede que te atiende.
                    </p>
                    <ul className="flex flex-col gap-1.5">
                        {stores.map((store) => {
                            const current = store.id === shell.store?.id;

                            return (
                                <li key={store.id}>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setOpen(false);

                                            if (!current) {
                                                router.post(route('storefront.store.switch'), { store_id: store.id }, { preserveScroll: true });
                                            }
                                        }}
                                        className={cn(
                                            'flex min-h-[52px] w-full items-center gap-3 rounded-[14px] border px-3.5 text-left transition-colors',
                                            current ? 'border-[var(--accent)] bg-[var(--accent-soft)]' : 'border-[var(--line)] hover:bg-[var(--surface-2)]',
                                        )}
                                    >
                                        <StorefrontIcon size={20} className="shrink-0 text-[var(--accent)]" />
                                        <span className="min-w-0 flex-1 text-[14.5px] font-semibold">Sede {store.name}</span>
                                        {current && <CheckIcon size={18} weight="bold" className="text-[var(--accent)]" />}
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                    <a
                        href="/#tiendas"
                        className="mt-2 flex min-h-[48px] items-center justify-center gap-2 rounded-[14px] border border-dashed border-[var(--line-2)] text-[14px] font-semibold text-[var(--accent)] hover:bg-[var(--surface-2)]"
                    >
                        <MapPinIcon size={18} />
                        Buscar otra tienda cerca de mí
                    </a>
                </div>
            </Sheet>
        </>
    );
}

/** El carrito del encabezado: cuántos y cuánto. En computador puede abrir el cajón. */
export function CartButton({ shell, onColor, onOpenDrawer }: { shell: StoreShell; onColor: boolean; onOpenDrawer: () => void }) {
    const cart = useCart();
    const count = countOf(cart);
    const total = cart.brief?.total ?? 0;
    const { interactive } = usePreviewMode();
    const drawer = shell.settings.cart_bar.desktop_behavior === 'drawer';

    const content = (
        <>
            <span className="relative">
                <ShoppingCartIcon size={26} weight={count > 0 ? 'fill' : 'regular'} />
                {count > 0 && (
                    <span className="absolute -right-2 -top-1.5 grid min-w-[20px] place-items-center rounded-full bg-[var(--deal)] px-1 text-[11px] font-extrabold leading-5 text-[var(--deal-ink)] tabular-nums">
                        {Math.round(count) > 99 ? '99+' : Math.round(count * 10) / 10}
                    </span>
                )}
            </span>
            {shell.settings.header.cart_shows_total && (
                <span className="hidden text-left leading-tight lg:block">
                    <span className={cn('block text-[11px]', onColor ? 'opacity-80' : 'text-[var(--muted)]')}>Tu carrito</span>
                    <span className={cn('block text-[14px] font-extrabold tabular-nums', isSyncing(cart) && 'opacity-60')}>{money(total)}</span>
                </span>
            )}
        </>
    );

    const className = cn(
        'flex h-12 shrink-0 items-center gap-2.5 rounded-full px-3 transition-colors',
        onColor ? 'hover:bg-white/10' : 'hover:bg-[var(--surface-2)]',
    );
    const label = count > 0 ? `Carrito: ${Math.round(count * 10) / 10} artículos, ${money(total)}` : 'Carrito vacío';

    if (!interactive) {
        return (
            <span className={className} aria-label={label}>
                {content}
            </span>
        );
    }

    return (
        <>
            {/* En computador, el cajón (si la sede lo eligió); en celular siempre la página. */}
            {drawer && (
                <button type="button" onClick={onOpenDrawer} aria-label={label} className={cn(className, 'hidden md:flex')}>
                    {content}
                </button>
            )}
            <Link href={route('storefront.cart.index')} aria-label={label} className={cn(className, drawer && 'md:hidden')}>
                {content}
            </Link>
        </>
    );
}

/** «Entrar» o «Mi cuenta», con sus pedidos y la salida. */
export function AccountButton({ onColor }: { onColor: boolean }) {
    const { customer } = usePage<PageProps>().props;
    const { interactive } = usePreviewMode();
    const [open, setOpen] = useState(false);
    const box = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const close = (event: MouseEvent) => {
            if (box.current && !box.current.contains(event.target as Node)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', close);

        return () => document.removeEventListener('mousedown', close);
    }, []);

    const className = cn(
        'flex h-12 shrink-0 items-center gap-2 rounded-full px-3 text-left transition-colors',
        onColor ? 'hover:bg-white/10' : 'hover:bg-[var(--surface-2)]',
    );

    if (!customer || !interactive) {
        const content = (
            <>
                <UserIcon size={24} />
                <span className="hidden leading-tight lg:block">
                    <span className={cn('block text-[11px]', onColor ? 'opacity-80' : 'text-[var(--muted)]')}>Hola</span>
                    <span className="block text-[14px] font-bold">Entrar</span>
                </span>
            </>
        );

        // Entrar no saca al cliente de donde está: abre el ingreso encima de la página.
        return interactive ? (
            <button type="button" onClick={() => openLogin()} aria-haspopup="dialog" aria-label="Entrar a tu cuenta" className={className}>
                {content}
            </button>
        ) : (
            <span className={className}>{content}</span>
        );
    }

    return (
        <div ref={box} className="relative">
            <button type="button" onClick={() => setOpen((current) => !current)} aria-expanded={open} aria-haspopup="menu" className={className}>
                <UserCircleIcon size={26} weight="fill" />
                <span className="hidden leading-tight lg:block">
                    <span className={cn('block text-[11px]', onColor ? 'opacity-80' : 'text-[var(--muted)]')}>Hola, {customer.name.split(' ')[0]}</span>
                    <span className="block text-[14px] font-bold">Mi cuenta</span>
                </span>
            </button>
            {open && (
                <div role="menu" className="absolute right-0 top-[calc(100%+6px)] z-50 w-56 rounded-[14px] border border-[var(--line)] bg-[var(--surface)] p-1.5 text-[var(--text)] shadow-xl">
                    <Link role="menuitem" href={route('storefront.orders.index')} className="flex min-h-[44px] items-center rounded-[10px] px-3 text-[14px] font-medium hover:bg-[var(--surface-2)]">
                        Mis pedidos
                    </Link>
                    <Link
                        role="menuitem"
                        href={route('storefront.logout')}
                        method="post"
                        as="button"
                        className="flex min-h-[44px] w-full items-center gap-2 rounded-[10px] px-3 text-left text-[14px] font-medium hover:bg-[var(--surface-2)]"
                    >
                        <SignOutIcon size={17} />
                        Salir
                    </Link>
                </div>
            )}
        </div>
    );
}

/**
 * El encabezado de compra: marca, «Recibir en», el buscador grande, la cuenta y el carrito.
 * Fijo al desplazar si la sede lo pidió.
 */
export function StoreHeader({ shell, onOpenDrawer, searchInitial = '' }: { shell: StoreShell; onOpenDrawer: () => void; searchInitial?: string }) {
    const header = shell.settings.header;
    const onColor = header.style !== 'light';

    return (
        <header
            className={cn(
                header.style === 'light' && 'border-b border-[var(--line)] bg-[var(--surface)] text-[var(--text)]',
                header.style === 'brand' && 'bg-[var(--accent)] text-[var(--accent-ink)]',
                header.style === 'dark' && 'bg-[var(--brand-dark)] text-white',
            )}
        >
            <div className="mx-auto flex w-full max-w-[1280px] items-center gap-2 px-4 py-2 md:gap-3 md:py-3 lg:px-6">
                <StoreBrand shell={shell} onColor={onColor} />

                {header.show_location && (
                    <div className="hidden lg:block">
                        <LocationPill shell={shell} onColor={onColor} />
                    </div>
                )}

                <SearchBox
                    className="mx-1 hidden flex-1 md:block"
                    placeholder={header.search_placeholder}
                    suggestions={shell.settings.search.suggestions}
                    initial={searchInitial}
                    tone={onColor ? 'onColor' : 'light'}
                />

                <div className="ml-auto flex items-center gap-0.5 md:ml-0">
                    {header.show_account && <AccountButton onColor={onColor} />}
                    <CartButton shell={shell} onColor={onColor} onOpenDrawer={onOpenDrawer} />
                </div>
            </div>

            {/* En celular el buscador va en su propia fila, a todo lo ancho. */}
            <div className="px-4 pb-2.5 md:hidden">
                <SearchBox
                    placeholder={header.search_placeholder}
                    suggestions={shell.settings.search.suggestions}
                    initial={searchInitial}
                    tone={onColor ? 'onColor' : 'light'}
                />
            </div>
        </header>
    );
}

import { ClipboardTextIcon, HouseIcon, MagnifyingGlassIcon, ShoppingCartIcon, SquaresFourIcon, UserIcon, type Icon } from '@phosphor-icons/react';
import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/cn';
import type { PageProps } from '@/types';
import type { StoreShell } from '@/types/storefront';
import { countOf, useCart } from './cart-store';
import { openLogin } from './login-dialog';
import { StoreLink, usePreviewMode } from './store-context';

type Item = StoreShell['settings']['mobile_nav']['items'][number];

/** Cómo se pinta cada opción de la lista cerrada. */
const ITEMS: Record<Item, { label: string; icon: Icon; href?: string }> = {
    home: { label: 'Inicio', icon: HouseIcon, href: '/tienda' },
    categories: { label: 'Categorías', icon: SquaresFourIcon },
    search: { label: 'Buscar', icon: MagnifyingGlassIcon, href: '/tienda/buscar' },
    orders: { label: 'Pedidos', icon: ClipboardTextIcon, href: '/tienda/pedidos' },
    account: { label: 'Cuenta', icon: UserIcon, href: '/tienda/pedidos' },
    cart: { label: 'Carrito', icon: ShoppingCartIcon, href: '/tienda/carrito' },
};

/**
 * La navegación de abajo en el celular, al alcance del pulgar. Qué lleva y en qué orden lo
 * decide la sede (de 3 a 5 de una lista cerrada).
 */
export function MobileNav({ shell, currentPath, onOpenCategories }: { shell: StoreShell; currentPath: string; onOpenCategories: () => void }) {
    const { customer } = usePage<PageProps>().props;
    const { interactive } = usePreviewMode();
    const cart = useCart();
    const count = countOf(cart);
    const items = shell.settings.mobile_nav.items;

    if (items.length === 0) {
        return null;
    }

    return (
        <nav aria-label="Navegación" className="fixed inset-x-0 bottom-0 z-40 border-t border-[var(--line)] bg-[var(--surface)] pb-[env(safe-area-inset-bottom)] md:hidden">
            <ul className="flex h-[66px] items-stretch">
                {items.map((key) => {
                    const item = ITEMS[key];
                    const ItemIcon = item.icon;
                    // Sin sesión, «Cuenta» y «Pedidos» abren el ingreso aquí mismo.
                    const needsLogin = !customer && (key === 'account' || key === 'orders');
                    const href = needsLogin ? undefined : item.href;
                    const active = href !== undefined && (href === '/tienda' ? currentPath === '/tienda' : currentPath.startsWith(href));
                    const label = key === 'account' && !customer ? 'Entrar' : item.label;

                    const content = (
                        <>
                            <span className="relative">
                                <ItemIcon size={24} weight={active ? 'fill' : 'regular'} />
                                {key === 'cart' && count > 0 && (
                                    <span className="absolute -right-2.5 -top-1.5 grid min-w-[18px] place-items-center rounded-full bg-[var(--deal)] px-1 text-[10.5px] font-extrabold leading-[18px] text-[var(--deal-ink)] tabular-nums">
                                        {Math.round(count) > 99 ? '99+' : Math.round(count * 10) / 10}
                                    </span>
                                )}
                            </span>
                            {label}
                        </>
                    );

                    const className = cn(
                        'flex h-full w-full flex-col items-center justify-center gap-0.5 text-[11.5px] font-semibold',
                        active ? 'text-[var(--accent)]' : 'text-[var(--muted)]',
                    );

                    return (
                        <li key={key} className="flex-1">
                            {key === 'categories' ? (
                                <button type="button" onClick={() => interactive && onOpenCategories()} className={className}>
                                    {content}
                                </button>
                            ) : needsLogin ? (
                                <button
                                    type="button"
                                    onClick={() => interactive && openLogin(key === 'orders' ? { returnTo: route('storefront.orders.index') } : {})}
                                    aria-haspopup="dialog"
                                    className={className}
                                >
                                    {content}
                                </button>
                            ) : (
                                <StoreLink href={href} className={className}>
                                    {content}
                                </StoreLink>
                            )}
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}

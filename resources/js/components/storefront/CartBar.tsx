import { Link } from '@inertiajs/react';
import { CaretRightIcon, ShoppingCartIcon } from '@phosphor-icons/react';
import { cn } from '@/lib/cn';
import { money, number } from '@/lib/format';
import type { StoreShell } from '@/types/storefront';
import { countOf, isSyncing, useCart } from './cart-store';
import { FreeDeliveryProgress } from './FreeDeliveryProgress';
import { usePreviewMode } from './store-context';

/**
 * La barra flotante del carrito: aparece con el primer artículo y dice cuántos van, cuánto
 * suman (lo que dijo el servidor) y cuánto falta para el domicilio gratis.
 *
 * En celular va encima de la navegación de abajo; en computador flota abajo al centro y, si
 * la sede lo eligió, abre el cajón en vez de ir a la página del carrito.
 */
export function CartBar({ shell, onOpenDrawer, withMobileNav }: { shell: StoreShell; onOpenDrawer: () => void; withMobileNav: boolean }) {
    const cart = useCart();
    const { interactive } = usePreviewMode();
    const settings = shell.settings.cart_bar;
    const count = countOf(cart);

    if (!settings.enabled || count <= 0) {
        return null;
    }

    const drawer = settings.desktop_behavior === 'drawer';
    const syncing = isSyncing(cart);

    const button = (
        <span className="flex h-11 shrink-0 items-center gap-1 rounded-full bg-[var(--accent-ink)] px-4 text-[14px] font-extrabold text-[var(--accent)]">
            Ver carrito
            <CaretRightIcon size={15} weight="bold" />
        </span>
    );

    return (
        <div
            className={cn(
                'pointer-events-none fixed inset-x-0 z-40 px-3 md:bottom-4 md:px-4',
                withMobileNav ? 'bottom-[76px]' : 'bottom-3',
            )}
        >
            <div className="pointer-events-auto mx-auto flex max-w-[720px] items-center gap-3 rounded-[18px] bg-[var(--accent)] px-3.5 py-2.5 text-[var(--accent-ink)] shadow-[0_12px_32px_-12px_rgba(0,0,0,0.45)]">
                <span className="relative grid size-10 shrink-0 place-items-center rounded-full bg-[color-mix(in_srgb,var(--accent-ink)_15%,transparent)]">
                    <ShoppingCartIcon size={22} weight="fill" />
                </span>

                <div className="min-w-0 flex-1">
                    <p className="flex items-baseline gap-2 leading-tight">
                        <span className={cn('text-[17px] font-extrabold tabular-nums', syncing && 'opacity-70')}>{money(cart.brief?.total ?? 0)}</span>
                        <span className="truncate text-[12.5px] opacity-85">
                            {number(Math.round(count * 10) / 10)} {count === 1 ? 'artículo' : 'artículos'}
                        </span>
                    </p>
                    {settings.show_free_delivery_progress && (
                        <FreeDeliveryProgress freeDelivery={cart.brief?.free_delivery ?? null} texts={settings.texts} tone="onColor" className="mt-1" />
                    )}
                </div>

                {!interactive ? (
                    button
                ) : (
                    <>
                        {drawer && (
                            <button type="button" onClick={onOpenDrawer} className="hidden md:block" aria-label="Ver el carrito">
                                {button}
                            </button>
                        )}
                        <Link href={route('storefront.cart.index')} className={cn(drawer && 'md:hidden')} aria-label="Ver el carrito">
                            {button}
                        </Link>
                    </>
                )}
            </div>
        </div>
    );
}

import { Link, usePage } from '@inertiajs/react';
import { ShoppingCartIcon } from '@phosphor-icons/react';
import { useEffect, useState } from 'react';
import { money } from '@/lib/format';
import type { PageProps } from '@/types';
import type { StoreShell } from '@/types/storefront';
import { AddToCartControl } from './AddToCartControl';
import { useCart } from './cart-store';
import { openLogin } from './login-dialog';
import { FreeDeliveryProgress } from './FreeDeliveryProgress';
import { StoreIcon } from './icons';
import { Sheet } from './Sheet';

export interface CartDetailLine {
    id: number;
    key: string;
    kind: 'product' | 'service';
    product_id: number | null;
    service_id: number | null;
    name: string;
    brand: string | null;
    image: string | null;
    href: string;
    price: number;
    compare_at: number | null;
    quantity: number;
    line_total: number;
    step: number;
    sold_by_weight: boolean;
}

/**
 * El carrito en un cajón al lado, en computador: las líneas con su contador, el avance al
 * domicilio gratis, el mínimo y el total. Todo sale del servidor cada vez que algo cambia.
 */
export function CartDrawer({ open, onClose, shell }: { open: boolean; onClose: () => void; shell: StoreShell }) {
    const cart = useCart();
    const { customer } = usePage<PageProps>().props;
    const [lines, setLines] = useState<CartDetailLine[] | null>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();

        fetch(route('storefront.cart.detail'), { headers: { Accept: 'application/json' }, signal: controller.signal })
            .then((response) => (response.ok ? response.json() : null))
            .then((body: { items: CartDetailLine[] } | null) => setLines(body?.items ?? []))
            .catch(() => undefined);

        return () => controller.abort();
    }, [open, cart.revision]);

    const brief = cart.brief;
    const missing = brief?.min_order_missing ?? 0;

    return (
        <Sheet
            open={open}
            onClose={onClose}
            side="right"
            title="Tu carrito"
            footer={
                <div className="flex flex-col gap-3">
                    <FreeDeliveryProgress freeDelivery={brief?.free_delivery ?? null} texts={shell.settings.cart_bar.texts} />
                    <div className="flex items-baseline justify-between">
                        <span className="text-[14px] text-[var(--muted)]">Subtotal</span>
                        <span className="text-[22px] font-extrabold tabular-nums">{money(brief?.total ?? 0)}</span>
                    </div>
                    {missing > 0 && (
                        <p className="rounded-[12px] bg-[var(--warn-soft)] px-3 py-2 text-[12.5px] font-semibold text-[var(--warn)]">
                            Te faltan {money(missing)} para el pedido mínimo de {money(brief?.min_order_total ?? 0)}.
                        </p>
                    )}
                    <div className="grid grid-cols-2 gap-2">
                        <Link
                            href={route('storefront.cart.index')}
                            onClick={onClose}
                            className="flex h-12 items-center justify-center rounded-[var(--store-radius-sm)] border-2 border-[var(--accent)] text-[14.5px] font-bold text-[var(--accent)] hover:bg-[var(--accent-soft)]"
                        >
                            Ver carrito
                        </Link>
                        {missing > 0 ? (
                            <span className="flex h-12 cursor-not-allowed items-center justify-center rounded-[var(--store-radius-sm)] bg-[var(--surface-2)] text-[14.5px] font-bold text-[var(--muted)]">
                                Pagar
                            </span>
                        ) : customer ? (
                            <Link
                                href={route('storefront.checkout.create')}
                                onClick={onClose}
                                className="flex h-12 items-center justify-center rounded-[var(--store-radius-sm)] bg-[var(--accent)] text-[14.5px] font-bold text-[var(--accent-ink)] hover:brightness-110"
                            >
                                Pagar
                            </Link>
                        ) : (
                            <button
                                type="button"
                                onClick={() => {
                                    onClose();
                                    openLogin({ returnTo: route('storefront.checkout.create') });
                                }}
                                aria-haspopup="dialog"
                                className="flex h-12 items-center justify-center rounded-[var(--store-radius-sm)] bg-[var(--accent)] text-[14.5px] font-bold text-[var(--accent-ink)] hover:brightness-110"
                            >
                                Entrar y pagar
                            </button>
                        )}
                    </div>
                </div>
            }
        >
            {lines === null ? (
                <p className="p-6 text-center text-[13.5px] text-[var(--muted)]">Cargando tu carrito…</p>
            ) : lines.length === 0 ? (
                <div className="flex flex-col items-center gap-2 p-10 text-center text-[var(--muted)]">
                    <ShoppingCartIcon size={40} />
                    <p className="text-[14px]">Tu carrito está vacío.</p>
                </div>
            ) : (
                <ul className="divide-y divide-[var(--line)]">
                    {lines.map((line) => (
                        <li key={line.key} className="flex gap-3 px-4 py-3">
                            <Link href={line.href} onClick={onClose} className="grid size-16 shrink-0 place-items-center overflow-hidden rounded-[12px] border border-[var(--line)] bg-white">
                                {line.image ? (
                                    <img src={line.image} alt="" className="h-full w-full object-contain p-1" />
                                ) : (
                                    <StoreIcon name="Package" size={26} className="text-[var(--muted)]" />
                                )}
                            </Link>
                            <div className="min-w-0 flex-1">
                                <Link href={line.href} onClick={onClose} className="line-clamp-2 text-[13.5px] font-semibold leading-snug hover:underline">
                                    {line.name}
                                </Link>
                                <p className="mt-0.5 text-[12px] text-[var(--muted)] tabular-nums">
                                    {money(line.price)} c/u
                                    {line.compare_at && line.compare_at > line.price && <span className="ml-1 line-through">{money(line.compare_at)}</span>}
                                </p>
                                <div className="mt-2 flex items-center gap-3">
                                    <AddToCartControl
                                        item={{ kind: line.kind, id: (line.product_id ?? line.service_id) as number, key: line.key, name: line.name, step: line.step, sold_by_weight: line.sold_by_weight }}
                                        className="h-9 w-[124px]"
                                    />
                                    <span className="ml-auto text-[15px] font-extrabold tabular-nums">{money(line.line_total)}</span>
                                </div>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </Sheet>
    );
}

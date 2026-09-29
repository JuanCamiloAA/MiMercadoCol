import { MinusIcon, PlusIcon } from '@phosphor-icons/react';
import { cn } from '@/lib/cn';
import { number } from '@/lib/format';
import type { CardProduct } from '@/types/storefront';
import { quantityOf, setCartQuantity, useCart } from './cart-store';
import { usePreviewMode } from './store-context';

/** Cada botón del contador es una cajita con borde: se ve que se puede tocar. */
const STEP_BUTTON =
    'grid size-8 shrink-0 place-items-center rounded-[calc(var(--store-radius-sm)*0.7)] border border-[color-mix(in_srgb,var(--accent-ink)_40%,transparent)] transition-colors hover:bg-[color-mix(in_srgb,var(--accent-ink)_16%,transparent)] focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[var(--accent-ink)]';

/** Lo que va al carrito es un artículo o un servicio: de un artículo con subartículos, la presentación elegida. */
type Item = Pick<CardProduct, 'id' | 'key' | 'name' | 'step' | 'sold_by_weight'> & {
    kind: 'product' | 'service';
    stock?: CardProduct['stock'];
    choices?: Record<number, number>;
};

/**
 * «Agregar» que se vuelve contador (− n +) sin recargar la página.
 *
 * El cambio se ve al instante y el carrito se guarda solo, con la cantidad final. Si el
 * servidor dice que no hay tanto, el contador vuelve a lo que haya y sale el aviso.
 */
export function AddToCartControl({
    item,
    variant = 'solid',
    size = 'md',
    className,
}: {
    item: Item;
    variant?: 'solid' | 'outline';
    size?: 'md' | 'lg';
    className?: string;
}) {
    const cart = useCart();
    const { interactive } = usePreviewMode();
    const quantity = quantityOf(cart, item.key);
    const step = item.step || 1;
    const out = item.stock?.out ?? false;
    const unit = item.sold_by_weight ? ' kg' : '';

    const set = (next: number) => {
        if (interactive) {
            setCartQuantity(item, next);
        }
    };

    const height = size === 'lg' ? 'h-12' : 'h-11';

    if (out) {
        return (
            <button
                type="button"
                disabled
                className={cn(height, 'flex w-full cursor-not-allowed items-center justify-center rounded-[var(--store-radius-sm)] bg-[var(--surface-2)] text-[13.5px] font-bold text-[var(--muted)]', className)}
            >
                Agotado
            </button>
        );
    }

    if (quantity <= 0) {
        return (
            <button
                type="button"
                onClick={() => set(step)}
                aria-label={`Agregar ${item.name} al carrito`}
                className={cn(
                    height,
                    'flex w-full items-center justify-center gap-1.5 rounded-[var(--store-radius-sm)] text-[14px] font-bold transition-[filter,background-color] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--accent)]',
                    variant === 'solid'
                        ? 'bg-[var(--accent)] text-[var(--accent-ink)] hover:brightness-110 active:brightness-95'
                        : 'border-2 border-[var(--accent)] bg-transparent text-[var(--accent)] hover:bg-[var(--accent-soft)]',
                    className,
                )}
            >
                <PlusIcon size={16} weight="bold" />
                Agregar
            </button>
        );
    }

    return (
        <div
            className={cn(
                height,
                'flex w-full items-center justify-between overflow-hidden rounded-[var(--store-radius-sm)] bg-[var(--accent)] px-1.5 text-[var(--accent-ink)]',
                className,
            )}
        >
            <button
                type="button"
                onClick={() => set(quantity - step)}
                aria-label={quantity - step <= 0 ? `Quitar ${item.name} del carrito` : `Quitar uno de ${item.name}`}
                className={STEP_BUTTON}
            >
                <MinusIcon size={15} weight="bold" />
            </button>
            <span className="min-w-0 truncate px-1 text-[15px] font-extrabold tabular-nums" aria-hidden>
                {number(quantity)}
                {unit}
            </span>
            <button type="button" onClick={() => set(quantity + step)} aria-label={`Agregar uno más de ${item.name}`} className={STEP_BUTTON}>
                <PlusIcon size={15} weight="bold" />
            </button>
        </div>
    );
}

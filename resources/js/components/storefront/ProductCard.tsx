import { SlidersHorizontalIcon } from '@phosphor-icons/react';
import { useState } from 'react';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';
import type { CardProduct, CardSettings } from '@/types/storefront';
import { AddToCartControl } from './AddToCartControl';
import { FamilyQuickPick } from './FamilyQuickPick';
import { StoreIcon } from './icons';
import { Price } from './Price';
import { StockNote } from './StockNote';
import { StoreLink, useExternalImage, useStoreShell } from './store-context';

const DEFAULT_CARD: CardSettings = {
    image_fit: 'contain',
    image_bg: 'white',
    show_brand: true,
    show_content: true,
    show_unit_price: true,
    deal_badge: 'percent',
    add_button: 'solid',
    stock_display: 'low_only',
    low_stock_threshold: 5,
    price_size: 'lg',
};

/**
 * La tarjeta de producto. Es una sola en toda la tienda y en la vista previa: portada,
 * estantes, categoría, búsqueda, relacionados y venta cruzada.
 *
 * Foto cuadrada sobre fondo claro, etiqueta de oferta, nombre en dos líneas, marca y
 * presentación, precio grande (rojo de oferta con el anterior tachado), precio por unidad de
 * medida y «Agregar» que se vuelve contador. Cómo se ve cada cosa lo decide la sede (`card`).
 */
export function ProductCard({ product, settings, className }: { product: CardProduct; settings?: CardSettings; className?: string }) {
    const shell = useStoreShell();
    const card = settings ?? shell?.settings.card ?? DEFAULT_CARD;

    useExternalImage(product.image_external && product.image !== null);

    const [choosing, setChoosing] = useState(false);
    const family = product.kind === 'family';

    // «Hasta -20%»: en un artículo con subartículos, no todas sus presentaciones tienen la misma rebaja.
    const badge =
        product.deal && card.deal_badge !== 'none'
            ? card.deal_badge === 'savings' && !product.deal.upto
                ? `Ahorra ${money(product.deal.savings)}`
                : `${product.deal.upto ? 'Hasta ' : ''}-${product.deal.percent}%`
            : null;

    return (
        <article
            className={cn(
                'group relative flex h-full min-w-0 flex-col overflow-hidden rounded-[var(--store-radius)] border border-[var(--line)] bg-[var(--surface)] transition-shadow hover:shadow-[0_6px_24px_-12px_rgba(0,0,0,0.25)]',
                className,
            )}
        >
            <StoreLink
                href={product.href}
                ariaLabel={product.name}
                className={cn(
                    'relative m-2 mb-0 block aspect-square overflow-hidden rounded-[calc(var(--store-radius)*0.7)]',
                    card.image_bg === 'white' ? 'bg-white' : 'bg-[var(--surface-2)]',
                )}
            >
                {product.image ? (
                    <img
                        src={product.image}
                        alt=""
                        loading="lazy"
                        decoding="async"
                        className={cn(
                            'h-full w-full transition-transform duration-300 group-hover:scale-[1.03]',
                            card.image_fit === 'cover' ? 'object-cover' : 'object-contain p-3 md:p-4',
                        )}
                    />
                ) : (
                    <span className="grid h-full w-full place-items-center bg-[var(--surface-2)] text-[var(--muted)]">
                        <StoreIcon name={product.kind === 'service' ? 'Package' : product.category_icon} size={46} weight="regular" />
                    </span>
                )}

                <span className="absolute left-2 top-2 flex flex-col items-start gap-1">
                    {badge && (
                        <span className="rounded-[6px] bg-[var(--deal)] px-1.5 py-0.5 text-[12px] font-extrabold text-[var(--deal-ink)] tabular-nums shadow-sm">
                            {badge}
                        </span>
                    )}
                    {product.kind === 'service' && (
                        <span className="rounded-[6px] bg-[var(--accent)] px-1.5 py-0.5 text-[11.5px] font-bold text-[var(--accent-ink)]">Servicio</span>
                    )}
                </span>
            </StoreLink>

            <div className="flex flex-1 flex-col gap-1 px-2.5 pb-2.5 pt-2 md:px-3 md:pb-3">
                {card.show_brand && product.brand && (
                    <p className="truncate text-[11px] font-bold uppercase tracking-wide text-[var(--muted)]">{product.brand}</p>
                )}

                <StoreLink href={product.href} className="line-clamp-2 min-h-[2.6em] text-[13.5px] font-semibold leading-[1.3] text-[var(--text)] hover:underline">
                    {product.name}
                </StoreLink>

                {card.show_content && product.content && <p className="text-[12px] text-[var(--muted)]">{product.content}</p>}

                {family && product.options && (
                    <div className="flex flex-wrap items-center gap-1.5 text-[12px] text-[var(--muted)]">
                        {product.options.swatches.length > 0 && (
                            <span className="flex -space-x-1" aria-hidden>
                                {product.options.swatches.slice(0, 5).map((swatch) =>
                                    swatch.image ? (
                                        <img key={swatch.value} src={swatch.image} alt="" className="size-4 rounded-full border border-white object-cover" />
                                    ) : (
                                        <span key={swatch.value} className="size-4 rounded-full border border-white shadow-sm" style={{ backgroundColor: swatch.color_hex ?? '#ccc' }} />
                                    ),
                                )}
                            </span>
                        )}
                        <span>{product.options.summary}</span>
                    </div>
                )}

                {product.kind === 'service' && (product.includes ?? []).length > 0 && (
                    <p className="text-[12px] text-[var(--muted)]">
                        Incluye {(product.includes ?? []).length} {(product.includes ?? []).length === 1 ? 'artículo' : 'artículos'}
                    </p>
                )}

                <div className="mt-auto flex flex-col gap-2 pt-1.5">
                    <Price
                        price={product.price}
                        compareAt={product.compare_at}
                        unitPrice={card.show_unit_price ? product.unit_price : null}
                        size={card.price_size === 'lg' ? 'lg' : 'md'}
                        from={product.price_from}
                    />

                    <StockNote stock={product.stock} />

                    {!family ? (
                        <AddToCartControl item={{ ...product, kind: product.kind === 'service' ? 'service' : 'product' }} variant={card.add_button} />
                    ) : product.single ? (
                        // Una sola presentación a la venta: se agrega directo.
                        <AddToCartControl item={{ ...product.single, kind: 'product' }} variant={card.add_button} />
                    ) : product.stock.out ? (
                        <AddToCartControl item={{ ...product, kind: 'product' }} variant={card.add_button} />
                    ) : (
                        <>
                            <button
                                type="button"
                                onClick={() => setChoosing(true)}
                                aria-haspopup="dialog"
                                className={cn(
                                    'flex h-11 w-full items-center justify-center gap-1.5 rounded-[var(--store-radius-sm)] text-[14px] font-bold transition-[filter,background-color] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--accent)]',
                                    card.add_button === 'solid'
                                        ? 'bg-[var(--accent)] text-[var(--accent-ink)] hover:brightness-110'
                                        : 'border-2 border-[var(--accent)] text-[var(--accent)] hover:bg-[var(--accent-soft)]',
                                )}
                            >
                                <SlidersHorizontalIcon size={16} weight="bold" />
                                {product.choose_label ?? 'Elegir'}
                            </button>
                            {choosing && <FamilyQuickPick product={product} open={choosing} onClose={() => setChoosing(false)} />}
                        </>
                    )}
                </div>
            </div>
        </article>
    );
}

import { CheckIcon, ShoppingBagIcon } from '@phosphor-icons/react';
import { useState } from 'react';
import { BannerImage, bannerMedia } from '@/components/storefront/BannerImage';
import { isSyncing, quantityOf, setCartQuantity, useCart } from '@/components/storefront/cart-store';
import { Price } from '@/components/storefront/Price';
import { StoreLink, usePreviewMode } from '@/components/storefront/store-context';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';
import type { CardProduct } from '@/types/storefront';
import { text, type BlockProps, type MediaPayload } from './shared';

interface LookItem {
    product: CardProduct;
    /** De un artículo con subartículos: las presentaciones (tallas) que se pueden comprar. */
    options: { key: string; id: number; label: string; price: number; step: number }[];
}

/**
 * «Compra el look»: la foto con un número sobre cada prenda y, al lado, la lista con su precio
 * vigente y su talla. «Agregar el look» suma una de cada una al carrito (la misma cantidad
 * absoluta de siempre, `PUT /tienda/carrito/cantidad`) y el total lo dice el servidor.
 */
export function LookBlock({ data, priority = false }: BlockProps) {
    const media = (data.media as MediaPayload | null) ?? null;
    const items = (data.items as LookItem[] | undefined) ?? [];
    const hotspots = ((data.hotspots as { x: number | null; y: number | null }[] | undefined) ?? []).slice(0, items.length);
    const cart = useCart();
    const { interactive } = usePreviewMode();
    const [chosen, setChosen] = useState<Record<number, number>>(() =>
        Object.fromEntries(items.map((item, index) => [index, item.options[0]?.id ?? item.product.single?.id ?? item.product.id])),
    );
    const [added, setAdded] = useState(false);

    if (!media || items.length === 0) {
        return null;
    }

    const addAll = () => {
        if (!interactive) {
            return;
        }

        items.forEach((item, index) => {
            const option = item.options.find((candidate) => candidate.id === chosen[index]);
            const product = item.product.kind === 'family' ? (item.product.single ?? null) : item.product;
            const ref = option
                ? { kind: 'product' as const, id: option.id, key: option.key, name: `${item.product.name} · ${option.label}` }
                : product
                  ? { kind: product.kind === 'service' ? ('service' as const) : ('product' as const), id: product.id, key: product.key, name: product.name, sold_by_weight: product.sold_by_weight }
                  : null;

            if (ref) {
                const step = option?.step ?? product?.step ?? 1;
                setCartQuantity(ref, quantityOf(cart, ref.key) + step);
            }
        });

        setAdded(true);
    };

    return (
        <div className="grid gap-6 md:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)] md:items-center md:gap-10">
            <div className="relative aspect-[4/5] overflow-hidden rounded-[var(--store-radius)] bg-[var(--surface-2)]">
                <BannerImage {...bannerMedia(data)} priority={priority} className="absolute inset-0" sizes="(max-width: 767px) 100vw, 55vw" />
                {hotspots.map((spot, index) => (
                    <span
                        key={index}
                        className="absolute grid size-8 -translate-x-1/2 -translate-y-1/2 place-items-center rounded-full bg-white text-[13px] font-extrabold text-[#14171A] shadow-lg ring-4 ring-white/40"
                        style={{ left: `${spot.x ?? 50}%`, top: `${spot.y ?? 50}%` }}
                        aria-hidden
                    >
                        {index + 1}
                    </span>
                ))}
            </div>

            <div className="flex flex-col gap-4">
                {text(data.title) && <h2 className="text-[26px] leading-tight md:text-[36px]">{text(data.title)}</h2>}
                {text(data.text) && <p className="text-[15px] text-[var(--muted)]">{text(data.text)}</p>}

                <ol className="flex flex-col divide-y divide-[var(--line)] border-y border-[var(--line)]">
                    {items.map((item, index) => {
                        const option = item.options.find((candidate) => candidate.id === chosen[index]);

                        return (
                            <li key={item.product.key} className="flex items-center gap-3 py-3">
                                <span className="grid size-7 shrink-0 place-items-center rounded-full bg-[var(--text)] text-[12px] font-extrabold text-[var(--bg)]">{index + 1}</span>
                                {item.product.image && <img src={item.product.image} alt="" loading="lazy" className="size-14 shrink-0 rounded-[var(--store-radius-sm)] bg-[var(--surface-2)] object-cover" />}
                                <span className="min-w-0 flex-1">
                                    <StoreLink href={item.product.href} className="line-clamp-2 text-[14px] font-semibold hover:underline">
                                        {item.product.name}
                                    </StoreLink>
                                    {option ? (
                                        <span className="mt-0.5 block text-[14px] font-extrabold tabular-nums" data-store-price="">
                                            {money(option.price)}
                                        </span>
                                    ) : (
                                        <Price price={item.product.price} compareAt={item.product.compare_at} size="sm" from={item.product.price_from && !item.product.single} />
                                    )}
                                </span>
                                {item.options.length > 1 && (
                                    <label className="shrink-0">
                                        <span className="sr-only">Presentación de {item.product.name}</span>
                                        <select
                                            value={chosen[index]}
                                            onChange={(event) => setChosen((current) => ({ ...current, [index]: Number(event.target.value) }))}
                                            className="h-10 rounded-[var(--store-radius-sm)] border border-[var(--line-2)] bg-[var(--surface)] px-2 text-[13px] text-[var(--text)]"
                                        >
                                            {item.options.map((candidate) => (
                                                <option key={candidate.id} value={candidate.id}>
                                                    {candidate.label}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                )}
                            </li>
                        );
                    })}
                </ol>

                <button
                    type="button"
                    onClick={addAll}
                    className={cn(
                        'inline-flex h-12 items-center justify-center gap-2 rounded-[var(--store-btn-radius,var(--store-radius-sm))] px-6 text-[15px] font-bold transition-[filter]',
                        'bg-[var(--accent)] text-[var(--accent-ink)] hover:brightness-110',
                    )}
                >
                    {added ? <CheckIcon size={18} weight="bold" /> : <ShoppingBagIcon size={18} weight="bold" />}
                    {text(data.button_label) || 'Agregar el look completo'}
                </button>

                {/* El total sale del servidor, que es el que sabe cuánto vale hoy cada prenda. */}
                {added && cart.brief && (
                    <p className={cn('text-center text-[13.5px] text-[var(--muted)]', isSyncing(cart) && 'opacity-60')} role="status">
                        Tu carrito va en <strong className="text-[var(--text)]">{money(cart.brief.total)}</strong>
                    </p>
                )}
            </div>
        </div>
    );
}

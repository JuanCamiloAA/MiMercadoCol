import { CaretRightIcon } from '@phosphor-icons/react';
import { useEffect, useState } from 'react';
import type { CardProduct, FamilyDetail, FamilyVariant } from '@/types/storefront';
import { AddToCartControl } from './AddToCartControl';
import { Price } from './Price';
import { StockNote } from './StockNote';
import { StoreDialog } from './StoreDialog';
import { StoreLink } from './store-context';
import { VariantPicker } from './VariantPicker';

/** Lo que ya se pidió, por artículo: la ventana se abre sin volver a esperar al servidor. */
const cache = new Map<string, Promise<FamilyDetail>>();

function loadOptions(slug: string): Promise<FamilyDetail> {
    if (!cache.has(slug)) {
        const promise = fetch(route('storefront.family.options', slug), { headers: { Accept: 'application/json' } }).then((response) =>
            response.ok ? (response.json() as Promise<FamilyDetail>) : Promise.reject(new Error(String(response.status))),
        );
        cache.set(slug, promise);
        promise.catch(() => cache.delete(slug));
    }

    return cache.get(slug) as Promise<FamilyDetail>;
}

/**
 * «Elegir talla» desde la tarjeta: la misma elección de la página del artículo, en una hoja que
 * sube en el celular o una ventana en el computador, sin salir del listado. Lo que va al carrito
 * es la presentación elegida.
 */
export function FamilyQuickPick({ product, open, onClose }: { product: CardProduct; open: boolean; onClose: () => void }) {
    const [detail, setDetail] = useState<FamilyDetail | null>(null);
    const [failed, setFailed] = useState(false);
    const [selectedId, setSelectedId] = useState<number | null>(null);

    useEffect(() => {
        if (!open || !product.slug) {
            return;
        }

        let alive = true;
        setFailed(false);
        loadOptions(product.slug)
            .then((data) => {
                if (alive) {
                    setDetail(data);
                    setSelectedId((current) => current ?? data.default_id);
                }
            })
            .catch(() => alive && setFailed(true));

        return () => {
            alive = false;
        };
    }, [open, product.slug]);

    const selected: FamilyVariant | null = detail?.variants.find((variant) => variant.id === selectedId) ?? null;

    return (
        <StoreDialog open={open} onClose={onClose} title={product.name} description={product.options?.summary}>
            {failed ? (
                <p className="py-6 text-center text-[14px] text-[var(--muted)]">No pudimos traer las opciones. Intenta de nuevo.</p>
            ) : !detail || !selected ? (
                <div className="flex flex-col gap-3 py-2" aria-busy>
                    <div className="h-5 w-32 animate-pulse rounded-full bg-[var(--surface-2)]" />
                    <div className="flex gap-2">
                        {[0, 1, 2, 3].map((index) => (
                            <div key={index} className="h-11 w-14 animate-pulse rounded-[var(--store-radius-sm)] bg-[var(--surface-2)]" />
                        ))}
                    </div>
                </div>
            ) : (
                <div className="flex flex-col gap-4">
                    <VariantPicker attributes={detail.attributes} variants={detail.variants} selectedId={selected.id} onSelect={(variant) => setSelectedId(variant.id)} compact />

                    <div className="flex items-end justify-between gap-3 rounded-[var(--store-radius-sm)] bg-[var(--surface-2)] p-3">
                        <div className="min-w-0">
                            <p className="truncate text-[13px] font-semibold">{selected.label ?? selected.name}</p>
                            <Price price={selected.price} compareAt={selected.compare_at} unitPrice={selected.unit_price} size="md" />
                        </div>
                        <StockNote stock={selected.stock} />
                    </div>

                    <AddToCartControl item={{ ...selected, kind: 'product' }} size="lg" />

                    <StoreLink href={`${detail.family.href}?variante=${selected.id}`} onClick={onClose} className="flex items-center justify-center gap-1 text-[13.5px] font-semibold text-[var(--accent)] hover:underline">
                        Ver el artículo
                        <CaretRightIcon size={13} weight="bold" />
                    </StoreLink>
                </div>
            )}
        </StoreDialog>
    );
}

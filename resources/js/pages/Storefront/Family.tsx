import { CaretRightIcon, ShieldCheckIcon, TruckIcon } from '@phosphor-icons/react';
import { useMemo, useState } from 'react';
import { AddToCartControl } from '@/components/storefront/AddToCartControl';
import { ImageCarousel } from '@/components/storefront/ImageCarousel';
import { StoreIcon } from '@/components/storefront/icons';
import { Price } from '@/components/storefront/Price';
import { ProductShelf } from '@/components/storefront/ProductShelf';
import { StockNote } from '@/components/storefront/StockNote';
import { StoreLink, useCurrentShell } from '@/components/storefront/store-context';
import { VariantPicker } from '@/components/storefront/VariantPicker';
import StoreLayout from '@/layouts/StoreLayout';
import { money } from '@/lib/format';
import { StoreBlocks } from '@/pages/Storefront/blocks/StoreBlocks';
import type { CardProduct, FamilyDetail, StoreBlock } from '@/types/storefront';

interface FamilyProps extends FamilyDetail {
    selected_id: number | null;
    show_description: boolean;
    breadcrumbs: { label: string; href: string }[];
    related: CardProduct[];
    blocks: StoreBlock[];
}

/**
 * La página de un artículo con subartículos: un solo artículo con sus presentaciones (o tallas,
 * o sabores) para escoger. El precio, el precio por unidad y la existencia son los de la
 * presentación elegida, y es esa la que va al carrito. `?variante=` guarda la elegida: es el
 * enlace que se comparte.
 */
export default function StorefrontFamily({ family, attributes, variants, images, selected_id, show_description, breadcrumbs, related, blocks }: FamilyProps) {
    const shell = useCurrentShell();
    const card = shell?.settings.card;
    const freeFrom = shell?.settings.delivery.free_from ?? 0;
    const [selectedId, setSelectedId] = useState<number | null>(selected_id);
    const selected = variants.find((variant) => variant.id === selectedId) ?? variants[0];

    // La galería: las fotos propias de la presentación; si no tiene, las comunes de su color; si
    // no, las comunes.
    const gallery = useMemo(() => {
        if (selected.images.length > 0) {
            return selected.images;
        }

        const values = [selected.value_1_id, selected.value_2_id].filter(Boolean);
        const byValue = images.filter((image) => image.value_id !== null && values.includes(image.value_id));
        const common = images.filter((image) => image.value_id === null);

        return (byValue.length > 0 ? byValue : common.length > 0 ? common : images).map((image) => image.url);
    }, [selected, images]);

    const choose = (id: number) => {
        setSelectedId(id);

        // El enlace queda con la presentación elegida, sin recargar ni llenar el historial.
        try {
            const url = new URL(window.location.href);
            url.searchParams.set('variante', String(id));
            window.history.replaceState(window.history.state, '', url.toString());
        } catch {
            // Sin `history` (una vista previa, por ejemplo) no pasa nada.
        }
    };

    const badge =
        selected.deal && card?.deal_badge !== 'none'
            ? card?.deal_badge === 'savings'
                ? `Ahorra ${money(selected.deal.savings)}`
                : `-${selected.deal.percent}%`
            : null;

    return (
        <StoreLayout title={family.name} current="catalog">
            <nav aria-label="Ruta" className="mb-3 flex flex-wrap items-center gap-1 text-[13px] text-[var(--muted)]">
                <StoreLink href="/tienda" className="hover:text-[var(--text)] hover:underline">
                    Inicio
                </StoreLink>
                {breadcrumbs.map((crumb) => (
                    <span key={crumb.href} className="flex items-center gap-1">
                        <CaretRightIcon size={11} />
                        <StoreLink href={crumb.href} className="hover:text-[var(--text)] hover:underline">
                            {crumb.label}
                        </StoreLink>
                    </span>
                ))}
            </nav>

            <article className="grid gap-5 rounded-[var(--store-radius)] border border-[var(--line)] bg-[var(--surface)] p-3 md:grid-cols-2 md:gap-8 md:p-6">
                <ImageCarousel
                    images={gallery.length > 0 ? gallery : selected.image ? [selected.image] : []}
                    alt={`${family.name}${selected.label ? ` · ${selected.label}` : ''}`}
                    // Otra presentación vuelve a la primera de sus fotos.
                    resetKey={selected.id}
                    fallback={
                        <span className="grid h-full w-full place-items-center bg-[var(--surface-2)] text-[var(--muted)]">
                            <StoreIcon name={selected.category_icon} size={120} weight="regular" />
                        </span>
                    }
                    overlay={
                        badge ? (
                            <span className="absolute left-3 top-3 rounded-[8px] bg-[var(--deal)] px-2.5 py-1 text-[15px] font-extrabold text-[var(--deal-ink)] shadow">{badge}</span>
                        ) : undefined
                    }
                />

                <div className="flex flex-col gap-3">
                    {family.brand && <p className="text-[12.5px] font-bold uppercase tracking-wide text-[var(--muted)]">{family.brand}</p>}
                    <h1 className="text-[24px] leading-tight md:text-[32px]">{family.name}</h1>

                    <Price price={selected.price} compareAt={selected.compare_at} unitPrice={selected.unit_price} size="xl" />
                    <p className="text-[12.5px] text-[var(--muted)]">
                        {selected.content ? `${selected.content} · ` : ''}IVA {family.tax_rate}% incluido
                    </p>

                    <VariantPicker attributes={attributes} variants={variants} selectedId={selected.id} onSelect={(variant) => choose(variant.id)} />

                    <StockNote stock={selected.stock} className="text-[13px]" />

                    <div className="mt-1 max-w-[360px]">
                        <AddToCartControl item={{ ...selected, kind: 'product' }} size="lg" variant="solid" />
                    </div>

                    <ul className="mt-2 flex flex-col gap-2 rounded-[var(--store-radius)] bg-[var(--surface-2)] p-3 text-[13px]">
                        <li className="flex items-center gap-2">
                            <TruckIcon size={18} weight="fill" className="shrink-0 text-[var(--accent)]" />
                            {freeFrom > 0 ? `Domicilio gratis desde ${money(freeFrom)}` : 'Te lo llevamos a domicilio'}
                        </li>
                        <li className="flex items-center gap-2">
                            <ShieldCheckIcon size={18} weight="fill" className="shrink-0 text-[var(--accent)]" />
                            Si algo no llega bien, lo devuelves desde «Mis pedidos».
                        </li>
                    </ul>

                    {show_description && selected.description && (
                        <section className="mt-2">
                            <h2 className="mb-1 text-[17px]">Descripción</h2>
                            <p className="whitespace-pre-line text-[14px] leading-relaxed text-[var(--muted)]">{selected.description}</p>
                        </section>
                    )}
                </div>
            </article>

            {related.length > 0 && (
                <div className="mt-8">
                    <ProductShelf title="También te puede interesar" products={related} />
                </div>
            )}

            {blocks.length > 0 && <StoreBlocks blocks={blocks} className="mt-8" />}
        </StoreLayout>
    );
}

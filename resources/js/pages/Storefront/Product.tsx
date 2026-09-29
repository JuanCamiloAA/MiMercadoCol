import { CaretRightIcon, ShieldCheckIcon, TruckIcon } from '@phosphor-icons/react';
import { AddToCartControl } from '@/components/storefront/AddToCartControl';
import { StoreIcon } from '@/components/storefront/icons';
import { Price } from '@/components/storefront/Price';
import { ProductShelf } from '@/components/storefront/ProductShelf';
import { StockNote } from '@/components/storefront/StockNote';
import { StoreLink, useCurrentShell, useExternalImage } from '@/components/storefront/store-context';
import StoreLayout from '@/layouts/StoreLayout';
import { money } from '@/lib/format';
import { StoreBlocks } from '@/pages/Storefront/blocks/StoreBlocks';
import type { CardProduct, StoreBlock } from '@/types/storefront';

interface ProductProps {
    product: CardProduct & { description: string | null; tax_rate: number };
    breadcrumbs: { label: string; href: string }[];
    related: CardProduct[];
    blocks: StoreBlock[];
}

/**
 * La ficha de un artículo: foto grande, precio, precio por unidad, contador, descripción,
 * «También te puede interesar» y, debajo, los bloques que la sede puso en la página de
 * producto.
 */
export default function StorefrontProduct({ product, breadcrumbs, related, blocks }: ProductProps) {
    const shell = useCurrentShell();
    const card = shell?.settings.card;
    const freeFrom = shell?.settings.delivery.free_from ?? 0;

    useExternalImage(product.image_external && product.image !== null);

    const badge =
        product.deal && card?.deal_badge !== 'none'
            ? card?.deal_badge === 'savings'
                ? `Ahorra ${money(product.deal.savings)}`
                : `-${product.deal.percent}%`
            : null;

    return (
        <StoreLayout title={product.name} current="catalog">
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
                <div className="relative aspect-square overflow-hidden rounded-[var(--store-radius)] bg-white">
                    {product.image ? (
                        <img src={product.image} alt={product.name} className="h-full w-full object-contain p-6" />
                    ) : (
                        <span className="grid h-full w-full place-items-center text-[var(--muted)] opacity-50">
                            <StoreIcon name={product.category_icon} size={120} weight="duotone" />
                        </span>
                    )}
                    {badge && (
                        <span className="absolute left-3 top-3 rounded-[8px] bg-[var(--deal)] px-2.5 py-1 text-[15px] font-extrabold text-[var(--deal-ink)] shadow">{badge}</span>
                    )}
                </div>

                <div className="flex flex-col gap-3">
                    {product.brand && <p className="text-[12.5px] font-bold uppercase tracking-wide text-[var(--muted)]">{product.brand}</p>}
                    <h1 className="text-[24px] leading-tight md:text-[32px]">{product.name}</h1>
                    {product.content && <p className="text-[14px] text-[var(--muted)]">{product.content}</p>}

                    <Price price={product.price} compareAt={product.compare_at} unitPrice={product.unit_price} size="xl" />
                    <p className="text-[12.5px] text-[var(--muted)]">
                        {product.sold_by_weight ? 'Precio por kilo' : 'Precio por unidad'} · IVA {product.tax_rate}% incluido
                    </p>

                    <StockNote stock={product.stock} className="text-[13px]" />

                    <div className="mt-1 max-w-[360px]">
                        <AddToCartControl item={{ ...product, kind: 'product' }} size="lg" variant="solid" />
                        {product.sold_by_weight && (
                            <p className="mt-1.5 text-[12px] text-[var(--muted)]">Se vende por peso: cada toque suma medio kilo. Lo que se cobra es lo que pese al alistarlo.</p>
                        )}
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

                    {product.description && (
                        <section className="mt-2">
                            <h2 className="mb-1 text-[17px]">Descripción</h2>
                            <p className="whitespace-pre-line text-[14px] leading-relaxed text-[var(--muted)]">{product.description}</p>
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

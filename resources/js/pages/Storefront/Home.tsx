import { StorefrontIcon } from '@phosphor-icons/react';
import { ProductShelf } from '@/components/storefront/ProductShelf';
import { StoreLink } from '@/components/storefront/store-context';
import StoreLayout from '@/layouts/StoreLayout';
import { StoreBlocks } from '@/pages/Storefront/blocks/StoreBlocks';
import type { CardProduct, StoreBlock } from '@/types/storefront';

interface HomeProps {
    /** La portada publicada de esta sede, ya resuelta. */
    blocks: StoreBlock[];
    /** Sin bloques publicados: las ofertas y los destacados, para que la tienda no quede en blanco. */
    fallback: { deals: CardProduct[]; featured: CardProduct[] } | null;
    /** El encabezado va transparente sobre el banner de borde a borde (`StorefrontRenderer::headerOverlay`). */
    headerOverlay?: boolean;
}

export default function StorefrontHome({ blocks, fallback, headerOverlay = false }: HomeProps) {
    return (
        <StoreLayout title="Tienda" current="home" headerOverlay={headerOverlay}>
            <h1 className="sr-only">Portada de la tienda</h1>

            {blocks.length > 0 ? (
                <StoreBlocks blocks={blocks} />
            ) : (
                <div className="flex flex-col gap-8">
                    {fallback && fallback.deals.length > 0 && <ProductShelf title="Ofertas de la semana" products={fallback.deals.slice(0, 12)} seeAllHref="/tienda/ofertas" />}
                    {fallback && fallback.featured.length > 0 && <ProductShelf title="Destacados de hoy" products={fallback.featured} seeAllHref="/tienda/buscar" />}
                    <StoreLink
                        href="/tienda/buscar"
                        className="flex min-h-[64px] items-center justify-center gap-2 rounded-[var(--store-radius)] border border-dashed border-[var(--line-2)] text-[15px] font-bold text-[var(--accent)] hover:bg-[var(--surface)]"
                    >
                        <StorefrontIcon size={20} />
                        Ver todo el surtido
                    </StoreLink>
                </div>
            )}
        </StoreLayout>
    );
}

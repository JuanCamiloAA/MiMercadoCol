import { ProductShelf } from '@/components/storefront/ProductShelf';
import type { CardProduct } from '@/types/storefront';
import { text, type BlockProps, type Target } from './shared';

/**
 * Estante de productos: su regla (ofertas, más vendidos, lo nuevo, «vuelve a comprar»...) la
 * resolvió el servidor. «Vuelve a comprar» sin sesión o sin compras ni llega: en la vista
 * previa se ve con un aviso para que quien edita sepa dónde queda.
 */
export function ProductGridBlock({ data }: BlockProps) {
    const products = (data.products as CardProduct[] | undefined) ?? [];
    const seeAll = (data.see_all_target as Target | null)?.href ?? (data.see_all_href as string | null) ?? null;

    return (
        <ProductShelf
            title={text(data.title) || null}
            products={products}
            seeAllHref={seeAll}
            seeAllLabel={text(data.see_all_label) || null}
            layout={text(data.layout) === 'grid' ? 'grid' : 'carousel'}
            columnsDesktop={Number(data.columns_desktop ?? 5)}
            columnsMobile={String(data.columns_mobile ?? 'row') === '2' ? '2' : 'row'}
            titleAlign={text(data.title_align) === 'center' ? 'center' : 'left'}
            arrows={text(data.arrows) === 'top' ? 'top' : 'side'}
            scrollbar={data.scrollbar === true}
            empty={
                data.placeholder ? (
                    <p className="rounded-[var(--store-radius)] border border-dashed border-[var(--line-2)] px-4 py-6 text-center text-[13px] text-[var(--muted)]">
                        Aquí salen los artículos que el cliente ya compró. Sin sesión o sin compras, el estante no aparece.
                    </p>
                ) : undefined
            }
        />
    );
}

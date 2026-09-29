import { CaretRightIcon } from '@phosphor-icons/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import type { CardProduct } from '@/types/storefront';
import { Carousel } from './Carousel';
import { ProductCard } from './ProductCard';
import { StoreLink } from './store-context';

const DESKTOP_COLUMNS: Record<number, string> = {
    4: 'md:grid-cols-3 lg:grid-cols-4',
    5: 'md:grid-cols-4 lg:grid-cols-5',
    6: 'md:grid-cols-4 lg:grid-cols-6',
};

const CAROUSEL_WIDTH: Record<number, string> = {
    4: 'w-[46%] sm:w-[32%] md:w-[calc((100%-3*0.75rem)/4)]',
    5: 'w-[46%] sm:w-[32%] md:w-[calc((100%-3*0.75rem)/4)] lg:w-[calc((100%-4*0.75rem)/5)]',
    6: 'w-[46%] sm:w-[32%] md:w-[calc((100%-3*0.75rem)/4)] lg:w-[calc((100%-5*0.75rem)/6)]',
};

/**
 * Un estante: título, «Ver todo» y las tarjetas en rejilla o en fila que se desliza.
 */
export function ProductShelf({
    title,
    products,
    seeAllHref,
    seeAllLabel,
    layout = 'carousel',
    columnsDesktop = 5,
    columnsMobile = 'row',
    background = 'none',
    heading,
    empty,
}: {
    title?: string | null;
    products: CardProduct[];
    seeAllHref?: string | null;
    /** El texto del enlace; sin él, «Ver todo». */
    seeAllLabel?: string | null;
    layout?: 'grid' | 'carousel';
    columnsDesktop?: number;
    columnsMobile?: '2' | 'row';
    background?: 'none' | 'soft' | 'brand';
    /** Algo más al lado del título (la cuenta regresiva de una oferta relámpago). */
    heading?: ReactNode;
    /** Qué decir si no hay artículos; sin esto, el estante vacío no se pinta. */
    empty?: ReactNode;
}) {
    if (products.length === 0 && !empty) {
        return null;
    }

    const columns = [4, 5, 6].includes(columnsDesktop) ? columnsDesktop : 5;

    return (
        <div
            className={cn(
                background === 'soft' && '-mx-4 bg-[var(--accent-soft)] px-4 py-5 md:mx-0 md:rounded-[var(--store-radius)] md:px-5',
                background === 'brand' && '-mx-4 bg-[var(--accent)] px-4 py-5 md:mx-0 md:rounded-[var(--store-radius)] md:px-5',
            )}
        >
            {(title || seeAllHref || heading) && (
                <div className="mb-3 flex flex-wrap items-end justify-between gap-x-3 gap-y-1">
                    <div className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                        {title && (
                            <h2 className={cn('text-[19px] leading-tight md:text-[23px]', background === 'brand' && 'text-[var(--accent-ink)]')}>
                                {title}
                            </h2>
                        )}
                        {heading}
                    </div>
                    {seeAllHref && (
                        <StoreLink
                            href={seeAllHref}
                            className={cn(
                                'flex shrink-0 items-center gap-0.5 text-[13.5px] font-bold hover:underline',
                                background === 'brand' ? 'text-[var(--accent-ink)]' : 'text-[var(--accent)]',
                            )}
                        >
                            {seeAllLabel || 'Ver todo'}
                            <CaretRightIcon size={14} weight="bold" />
                        </StoreLink>
                    )}
                </div>
            )}

            {products.length === 0 ? (
                empty
            ) : layout === 'carousel' ? (
                <Carousel
                    label={title ?? 'Productos'}
                    perView="cards"
                    dots={false}
                    trackClassName="-mx-4 gap-3 px-4 pb-1 md:mx-0 md:px-0"
                    slideClassName={CAROUSEL_WIDTH[columns]}
                >
                    {products.map((product) => (
                        <ProductCard key={product.key} product={product} />
                    ))}
                </Carousel>
            ) : (
                <div
                    className={cn(
                        'gap-2.5 md:grid md:gap-3',
                        DESKTOP_COLUMNS[columns],
                        columnsMobile === 'row'
                            ? 'scrollbar-none -mx-4 flex snap-x overflow-x-auto px-4 pb-1 md:mx-0 md:overflow-visible md:px-0'
                            : 'grid grid-cols-2 sm:grid-cols-3',
                    )}
                >
                    {products.map((product) => (
                        <div key={product.key} className={cn(columnsMobile === 'row' && 'w-[46%] shrink-0 snap-start sm:w-[32%] md:w-auto')}>
                            <ProductCard product={product} />
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

import { CaretRightIcon } from '@phosphor-icons/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import type { CardProduct } from '@/types/storefront';
import { Carousel } from './Carousel';
import { ProductCard } from './ProductCard';
import { StoreLink } from './store-context';

const DESKTOP_COLUMNS: Record<number, string> = {
    2: 'md:grid-cols-2',
    3: 'md:grid-cols-3',
    4: 'md:grid-cols-3 lg:grid-cols-4',
    5: 'md:grid-cols-4 lg:grid-cols-5',
    6: 'md:grid-cols-4 lg:grid-cols-6',
};

const CAROUSEL_WIDTH: Record<number, string> = {
    2: 'w-[78%] sm:w-[60%] md:w-[calc((100%-0.75rem)/2)]',
    3: 'w-[66%] sm:w-[46%] md:w-[calc((100%-2*0.75rem)/3)]',
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
    titleAlign = 'left',
    arrows = 'side',
    scrollbar = false,
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
    titleAlign?: 'left' | 'center';
    /** `top`: las flechas redondas van a la altura del título. */
    arrows?: 'side' | 'top';
    /** La barra de avance debajo de la fila. */
    scrollbar?: boolean;
    /** Algo más al lado del título (la cuenta regresiva de una oferta relámpago). */
    heading?: ReactNode;
    /** Qué decir si no hay artículos; sin esto, el estante vacío no se pinta. */
    empty?: ReactNode;
}) {
    if (products.length === 0 && !empty) {
        return null;
    }

    const columns = [2, 3, 4, 5, 6].includes(columnsDesktop) ? columnsDesktop : 5;
    const centered = titleAlign === 'center';
    const topArrows = layout === 'carousel' && arrows === 'top';

    // El fondo del estante lo pone la sección (pestaña «Diseño»); sobre el color de la marca,
    // `--sec-accent` es la letra que se lee ahí.
    return (
        <div>
            {(title || seeAllHref || heading) && (
                <div
                    className={cn(
                        'mb-3 flex flex-wrap gap-x-3 gap-y-1',
                        centered ? 'flex-col items-center text-center' : 'items-end justify-between',
                        // Las flechas de arriba van a la derecha del título: se les deja su lugar.
                        topArrows && !centered && 'md:pr-24',
                    )}
                >
                    <div className={cn('flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1', centered && 'justify-center')}>
                        {title && <h2 className="text-[19px] leading-tight md:text-[23px]">{title}</h2>}
                        {heading}
                    </div>
                    {seeAllHref && (
                        <StoreLink
                            href={seeAllHref}
                            className="flex shrink-0 items-center gap-0.5 text-[13.5px] font-bold text-[var(--sec-accent,var(--accent))] hover:underline"
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
                    arrowPlacement={topArrows && !centered ? 'top' : 'side'}
                    progress={scrollbar}
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

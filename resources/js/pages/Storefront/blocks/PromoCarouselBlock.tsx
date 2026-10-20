import { BannerImage, bannerMedia } from '@/components/storefront/BannerImage';
import { Carousel } from '@/components/storefront/Carousel';
import { cn } from '@/lib/cn';
import { BlockTitle, TargetLink, text, type BlockProps, type MediaPayload, type Target } from './shared';

interface Slide {
    media: MediaPayload | null;
    media_mobile: MediaPayload | null;
    title?: string;
    target?: Target | null;
    is_live?: boolean;
}

const HEIGHTS: Record<string, string> = {
    small: 'h-[150px] md:h-[180px]',
    medium: 'h-[190px] md:h-[240px]',
    large: 'h-[240px] md:h-[320px]',
};

const WIDTHS: Record<number, string> = {
    1: 'w-[88%] md:w-full',
    2: 'w-[88%] md:w-[calc((100%-0.75rem)/2)]',
    3: 'w-[88%] md:w-[calc((100%-1.5rem)/3)]',
};

/** Varias imágenes que se pasan de lado, cada una con su destino y su vigencia. */
export function PromoCarouselBlock({ data, priority = false }: BlockProps) {
    const slides = (data.slides as Slide[] | undefined) ?? [];
    const perView = Math.max(1, Math.min(3, Number(data.per_view ?? 2)));
    const height = HEIGHTS[text(data.size)] ?? HEIGHTS.medium;

    if (slides.length === 0) {
        return null;
    }

    return (
        <div>
            {text(data.title) !== '' && <BlockTitle>{text(data.title)}</BlockTitle>}
            <Carousel
                label={text(data.title) || 'Promociones'}
                perView="cards"
                dots={false}
                autoplay={Number(data.autoplay ?? 0)}
                trackClassName="-mx-4 gap-3 px-4 md:mx-0 md:px-0"
                slideClassName={WIDTHS[perView]}
            >
                {slides.map((slide, index) => (
                    <TargetLink
                        key={index}
                        target={slide.target}
                        ariaLabel={slide.title || slide.media?.alt || undefined}
                        className={cn('relative block overflow-hidden rounded-[var(--store-radius)] bg-[var(--surface-2)]', height)}
                    >
                        <BannerImage
                            {...bannerMedia(slide)}
                            priority={priority && index === 0}
                            className="absolute inset-0"
                            sizes={`(max-width: 767px) 88vw, ${Math.round(1180 / perView)}px`}
                        />
                        {slide.title && (
                            <span className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent px-4 py-3 text-[15px] font-extrabold text-white">
                                {slide.title}
                            </span>
                        )}
                        {slide.is_live === false && (
                            <span className="absolute right-2 top-2 rounded-[8px] bg-black/70 px-2 py-1 text-[11px] font-bold text-white">Fuera de vigencia hoy</span>
                        )}
                    </TargetLink>
                ))}
            </Carousel>
        </div>
    );
}

import { CaretRightIcon } from '@phosphor-icons/react';
import { BannerImage } from '@/components/storefront/BannerImage';
import { Carousel } from '@/components/storefront/Carousel';
import { cn } from '@/lib/cn';
import { TargetLink, TONES, text, type BlockProps, type MediaPayload, type Target } from './shared';

interface Slide {
    media: MediaPayload | null;
    media_mobile: MediaPayload | null;
    title?: string;
    text?: string | null;
    button_label?: string | null;
    target?: Target | null;
    text_color?: 'light' | 'dark';
    overlay?: number;
    is_live?: boolean;
}

interface Side {
    kind: 'image' | 'color';
    media: MediaPayload | null;
    tone?: string;
    title?: string;
    text?: string;
    target?: Target | null;
}

const HEIGHTS: Record<string, string> = {
    low: 'h-[210px] md:h-[280px]',
    medium: 'h-[280px] md:h-[380px]',
    high: 'h-[360px] md:h-[480px]',
};

/** Los banners de al lado miden lo mismo que el principal en computador. */
const SIDE_HEIGHTS: Record<string, string> = {
    low: 'md:h-[280px]',
    medium: 'md:h-[380px]',
    high: 'md:h-[480px]',
};

function SlideView({ slide, height, priority }: { slide: Slide; height: string; priority: boolean }) {
    const light = slide.text_color !== 'dark';
    const hasText = Boolean(slide.title || slide.text || slide.button_label);
    const overlay = Math.max(0, Math.min(60, Number(slide.overlay ?? 30))) / 100;

    return (
        <TargetLink
            target={slide.target}
            ariaLabel={!hasText ? (slide.media?.alt ?? undefined) : undefined}
            className={cn('relative block w-full overflow-hidden bg-[var(--surface-2)]', height)}
        >
            <BannerImage media={slide.media} mobile={slide.media_mobile} priority={priority} className="absolute inset-0" />

            {hasText && (
                <>
                    {/* El oscurecido es un degradé desde la izquierda: el texto se lee sin tapar la foto. */}
                    <span
                        className="absolute inset-0"
                        style={{
                            background: light
                                ? `linear-gradient(90deg, rgba(0,0,0,${overlay + 0.15}) 0%, rgba(0,0,0,${overlay}) 45%, rgba(0,0,0,0) 80%)`
                                : `linear-gradient(90deg, rgba(255,255,255,${overlay + 0.2}) 0%, rgba(255,255,255,${overlay}) 45%, rgba(255,255,255,0) 80%)`,
                        }}
                        aria-hidden
                    />
                    <span className={cn('absolute inset-0 flex items-end md:items-center', light ? 'text-white' : 'text-[#111]')}>
                        <span className="flex max-w-[600px] flex-col gap-2 p-5 pb-9 md:px-16 md:py-10">
                            {slide.title && <span className="text-[26px] font-black leading-[1.05] tracking-tight md:text-[44px]" style={{ fontFamily: 'var(--store-display)' }}>{slide.title}</span>}
                            {slide.text && <span className={cn('text-[14px] md:text-[17px]', light ? 'text-white/90' : 'text-black/75')}>{slide.text}</span>}
                            {slide.button_label && (
                                <span className="mt-1.5 inline-flex w-fit items-center gap-1.5 rounded-full bg-[var(--accent)] px-5 py-2.5 text-[14px] font-extrabold text-[var(--accent-ink)] shadow-lg">
                                    {slide.button_label}
                                    <CaretRightIcon size={15} weight="bold" />
                                </span>
                            )}
                        </span>
                    </span>
                </>
            )}

            {slide.is_live === false && (
                <span className="absolute right-3 top-3 rounded-[8px] bg-black/70 px-2 py-1 text-[11.5px] font-bold text-white">Fuera de vigencia hoy</span>
            )}
        </TargetLink>
    );
}

function SideView({ side, priority }: { side: Side; priority: boolean }) {
    return (
        <TargetLink
            target={side.target}
            ariaLabel={side.kind === 'image' ? (side.media?.alt ?? side.title ?? undefined) : undefined}
            className={cn(
                'relative flex min-h-[140px] flex-1 overflow-hidden rounded-[var(--store-radius)]',
                side.kind === 'color' ? (TONES[side.tone ?? 'accent'] ?? TONES.accent) : 'bg-[var(--surface-2)]',
            )}
        >
            {side.kind === 'image' ? (
                <>
                    <BannerImage media={side.media} priority={priority} className="absolute inset-0" sizes="(max-width: 767px) 50vw, 400px" />
                    {(side.title || side.text) && (
                        <span className="absolute inset-x-0 bottom-0 flex flex-col gap-0.5 bg-gradient-to-t from-black/70 to-transparent p-3.5 text-white md:p-4">
                            {side.title && <span className="text-[17px] font-black leading-tight md:text-[20px]" style={{ fontFamily: 'var(--store-display)' }}>{side.title}</span>}
                            {side.text && <span className="text-[12.5px] opacity-90">{side.text}</span>}
                        </span>
                    )}
                </>
            ) : (
                <span className="flex flex-col justify-end gap-1 p-4">
                    {side.title && <span className="text-[20px] font-black leading-tight" style={{ fontFamily: 'var(--store-display)' }}>{side.title}</span>}
                    {side.text && <span className="text-[13px] opacity-90">{side.text}</span>}
                </span>
            )}
        </TargetLink>
    );
}

/**
 * Banner principal: una o varias láminas que pasan solas (o no), solo o con uno o dos banners
 * al lado. Cada lámina tiene su imagen de celular y su vigencia.
 */
export function HeroBlock({ data, priority = false }: BlockProps) {
    const slides = (data.slides as Slide[] | undefined) ?? [];
    const side = ((data.side as Side[] | undefined) ?? []).slice(0, 2);
    const withSide = text(data.layout) === 'main_side' && side.length > 0;
    const height = HEIGHTS[text(data.height)] ?? HEIGHTS.medium;

    if (slides.length === 0) {
        return null;
    }

    const main = (
        <div className="overflow-hidden rounded-[var(--store-radius)]">
            {slides.length === 1 ? (
                <SlideView slide={slides[0]} height={height} priority={priority} />
            ) : (
                <Carousel label="Banner principal" autoplay={Number(data.autoplay ?? 0)}>
                    {slides.map((slide, index) => (
                        <SlideView key={index} slide={slide} height={height} priority={priority && index === 0} />
                    ))}
                </Carousel>
            )}
        </div>
    );

    if (!withSide) {
        return main;
    }

    return (
        <div className="grid gap-3 md:grid-cols-[minmax(0,2.2fr)_minmax(0,1fr)]">
            {main}
            <div className={cn('grid grid-cols-2 gap-3 md:flex md:flex-col', SIDE_HEIGHTS[text(data.height)] ?? SIDE_HEIGHTS.medium)}>
                {side.map((item, index) => (
                    <SideView key={index} side={item} priority={false} />
                ))}
            </div>
        </div>
    );
}

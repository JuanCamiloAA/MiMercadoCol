import { CaretRightIcon } from '@phosphor-icons/react';
import { BannerImage, bannerMedia } from '@/components/storefront/BannerImage';
import { Carousel } from '@/components/storefront/Carousel';
import { useDocumentTheme } from '@/hooks/use-document-theme';
import { cn } from '@/lib/cn';
import { bannerStyle, buttonClasses, innerAlign, overlayStyle, positionClasses, RATIO_CLASSES, themedItem, titleClass } from './banner-style';
import { BannerText } from './BannerText';
import { useStoreShell } from '@/components/storefront/store-context';
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
    style?: unknown;
}

interface Side {
    kind: 'image' | 'color';
    media: MediaPayload | null;
    media_mobile?: MediaPayload | null;
    tone?: string;
    title?: string;
    text?: string;
    target?: Target | null;
    text_color?: 'light' | 'dark' | null;
    overlay?: number | null;
    style?: unknown;
}

const HEIGHTS: Record<string, string> = {
    low: 'h-[210px] md:h-[280px]',
    medium: 'h-[280px] md:h-[380px]',
    high: 'h-[360px] md:h-[480px]',
    full: 'h-[100svh]',
};

/** Los diseños de una sola foto, más altos: son la portada de la tienda. */
const COVER_HEIGHTS: Record<string, string> = {
    low: 'min-h-[320px] md:min-h-[420px]',
    medium: 'min-h-[420px] md:min-h-[560px]',
    high: 'min-h-[520px] md:min-h-[680px]',
    full: 'min-h-[100svh]',
};

/** Los banners de al lado miden lo mismo que el principal en computador. */
const SIDE_HEIGHTS: Record<string, string> = {
    low: 'md:h-[280px]',
    medium: 'md:h-[380px]',
    high: 'md:h-[480px]',
};

function SlideView({ slide: given, height, priority }: { slide: Slide; height: string; priority: boolean }) {
    // En modo oscuro, lo que la lámina cambia allá (color del texto, velo, diseño) encima de lo de claro.
    const slide = themedItem(given, useDocumentTheme());
    const light = slide.text_color !== 'dark';
    const hasText = Boolean(slide.title || slide.text || slide.button_label);
    const overlay = Math.max(0, Math.min(60, Number(slide.overlay ?? 30))) / 100;
    // «Diseño de esta lámina»: lo que no se eligió queda como siempre.
    const style = bannerStyle(slide.style);
    const ratio = style.ratio ? RATIO_CLASSES[style.ratio] : null;
    const position = positionClasses(style.text_position);
    const shape = overlayStyle(style.overlay_style, overlay, light);
    const button = buttonClasses(style.button_style, light);

    return (
        <TargetLink
            target={slide.target}
            ariaLabel={!hasText ? (slide.media?.alt ?? undefined) : undefined}
            // `heroFill`: si la sección tiene alto propio, la lámina lo llena (`sections.css`).
            className={cn('relative block w-full overflow-hidden bg-[var(--surface-2)]', ratio ?? height)}
            heroFill
        >
            <BannerImage {...bannerMedia(slide)} priority={priority} className="absolute inset-0" />

            {hasText && (
                <>
                    {/* El oscurecido de siempre es un degradé desde la izquierda: el texto se lee sin tapar la foto. */}
                    <span
                        className="absolute inset-0"
                        style={
                            shape ?? {
                                background: light
                                    ? `linear-gradient(90deg, rgba(0,0,0,${overlay + 0.15}) 0%, rgba(0,0,0,${overlay}) 45%, rgba(0,0,0,0) 80%)`
                                    : `linear-gradient(90deg, rgba(255,255,255,${overlay + 0.2}) 0%, rgba(255,255,255,${overlay}) 45%, rgba(255,255,255,0) 80%)`,
                            }
                        }
                        aria-hidden
                    />
                    <span className={cn('absolute inset-0', position ?? 'flex items-end md:items-center', light ? 'text-white' : 'text-[#111]')}>
                        <span className={cn('flex max-w-[600px] flex-col gap-2 p-5 pb-9 md:px-16 md:py-10', innerAlign(style.text_position))}>
                            {slide.title && (
                                <span
                                    className={cn('font-black tracking-tight', titleClass(style.title_size, 'text-[26px] md:text-[44px]', 'leading-[1.05]'))}
                                    style={{ fontFamily: 'var(--store-display)' }}
                                >
                                    {slide.title}
                                </span>
                            )}
                            {slide.text && <span className={cn('text-[14px] md:text-[17px]', light ? 'text-white/90' : 'text-black/75')}>{slide.text}</span>}
                            {slide.button_label && (
                                <span
                                    className={cn(
                                        'mt-1.5 inline-flex w-fit items-center gap-1.5 text-[14px] font-extrabold',
                                        button ?? 'rounded-full bg-[var(--accent)] px-5 py-2.5 text-[var(--accent-ink)] shadow-lg',
                                    )}
                                >
                                    {slide.button_label}
                                    {style.button_style !== 'link' && <CaretRightIcon size={15} weight="bold" />}
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

function SideView({ side: given, priority }: { side: Side; priority: boolean }) {
    const side = themedItem(given, useDocumentTheme());
    const style = bannerStyle(side.style);
    const light = side.text_color !== 'dark';
    const position = positionClasses(style.text_position);

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
                    <BannerImage {...bannerMedia(side)} priority={priority} className="absolute inset-0" sizes="(max-width: 767px) 50vw, 400px" />
                    {(side.title || side.text) && (
                        <BannerText light={light} overlay={side.overlay} shape={style.overlay_style} position={position} className="flex flex-col gap-0.5 p-3.5 md:p-4">
                            {side.title && <span className={cn('font-black', titleClass(style.title_size, 'text-[17px] md:text-[20px]', 'leading-tight'))} style={{ fontFamily: 'var(--store-display)' }}>{side.title}</span>}
                            {side.text && <span className="text-[12.5px] opacity-90">{side.text}</span>}
                        </BannerText>
                    )}
                </>
            ) : (
                <span className={cn('flex w-full flex-col justify-end gap-1 p-4', position)}>
                    {side.title && <span className={cn('font-black', titleClass(style.title_size, 'text-[20px]', 'leading-tight'))} style={{ fontFamily: 'var(--store-display)' }}>{side.title}</span>}
                    {side.text && <span className="text-[13px] opacity-90">{side.text}</span>}
                </span>
            )}
        </TargetLink>
    );
}


/**
 * Los diseños de una sola foto (la de la primera lámina), con el texto en el bloque:
 *
 * - `split`: el texto en un panel y la foto al lado (`image_side`).
 * - `editorial`: la foto grande y el texto abajo a la izquierda, con un botón tipo enlace.
 * - `cover_center`: la foto grande con el nombre de la tienda (o el título) gigante al centro.
 */
function SingleHero({ data, slide: given, layout, priority }: { data: Record<string, unknown>; slide: Slide; layout: 'split' | 'editorial' | 'cover_center'; priority: boolean }) {
    const shell = useStoreShell();
    const slide = themedItem(given, useDocumentTheme());
    const style = bannerStyle(slide.style);
    const light = slide.text_color !== 'dark';
    const overlay = Math.max(0, Math.min(60, Number(slide.overlay ?? 30))) / 100;
    const coverHeight = COVER_HEIGHTS[text(data.height)] ?? COVER_HEIGHTS.medium;
    const eyebrow = text(data.eyebrow);
    const subtitle = text(data.subtitle);
    const buttonLabel = text(data.button_label);
    const target = (data.target as Target | null | undefined) ?? null;
    const name = shell?.theme.display_name || shell?.settings.company.name || '';
    const title = layout === 'cover_center' && data.wordmark === true ? name : text(data.title);

    if (layout === 'split') {
        const left = text(data.image_side) === 'left';

        return (
            <div className={cn('grid overflow-hidden rounded-[var(--store-radius)] md:grid-cols-2', coverHeight)} data-hero-fill="">
                <div className={cn('relative min-h-[280px] bg-[var(--surface-2)]', left ? 'md:order-1' : 'md:order-2')}>
                    <BannerImage {...bannerMedia(slide)} priority={priority} className="absolute inset-0" sizes="(max-width: 767px) 100vw, 50vw" />
                </div>
                <div className={cn('flex flex-col justify-center gap-3 bg-[var(--accent-soft)] p-6 md:p-12', left ? 'md:order-2' : 'md:order-1')}>
                    {eyebrow && <p className="text-[12px] font-bold uppercase tracking-[0.14em] text-[var(--muted)]">{eyebrow}</p>}
                    {title && (
                        <h2 className={cn('tracking-tight', titleClass(style.title_size, 'text-[34px] md:text-[56px]', 'leading-[1.02]'))}>{title}</h2>
                    )}
                    {subtitle && <p className="max-w-[32rem] text-[15px] text-[var(--muted)] md:text-[17px]">{subtitle}</p>}
                    {buttonLabel && (
                        <TargetLink target={target} className={cn('mt-2 inline-flex w-fit items-center gap-1.5 text-[14px] font-extrabold', buttonClasses(style.button_style, false) ?? 'rounded-full bg-[var(--accent)] px-6 py-3 text-[var(--accent-ink)]')}>
                            {buttonLabel}
                            {style.button_style !== 'link' && <CaretRightIcon size={15} weight="bold" />}
                        </TargetLink>
                    )}
                </div>
            </div>
        );
    }

    const centered = layout === 'cover_center';
    const position = positionClasses(style.text_position) ?? (centered ? 'flex flex-col justify-center items-center text-center' : 'flex flex-col justify-end items-start text-left');

    return (
        <TargetLink
            target={buttonLabel ? null : target}
            className={cn('relative block w-full overflow-hidden bg-[var(--surface-2)]', style.ratio ? RATIO_CLASSES[style.ratio] : coverHeight)}
            heroFill
        >
            <BannerImage {...bannerMedia(slide)} priority={priority} className="absolute inset-0" />
            <span className="absolute inset-0" style={overlayStyle(style.overlay_style ?? (centered ? 'flat' : 'bottom'), overlay, light) ?? undefined} aria-hidden />
            <span className={cn('absolute inset-0 gap-3 p-6 md:p-14', position, light ? 'text-white' : 'text-[#111]')}>
                {eyebrow && !centered && <span className="text-[12px] font-bold uppercase tracking-[0.18em] opacity-90">{eyebrow}</span>}
                {title &&
                    (centered && data.wordmark === true ? (
                        // El nombre de la tienda con la letra que eligió para su nombre.
                        <span className="block max-w-full break-words text-[56px] leading-[0.95] md:text-[128px]" data-store-wordmark="">
                            {title}
                        </span>
                    ) : (
                        <span
                            className={cn('block max-w-[44rem] tracking-tight', titleClass(style.title_size, centered ? 'text-[40px] md:text-[80px]' : 'text-[36px] md:text-[64px]', 'leading-[1]'))}
                            style={{ fontFamily: 'var(--store-display)', fontWeight: 'var(--store-display-weight)' as unknown as number }}
                        >
                            {title}
                        </span>
                    ))}
                {subtitle && (
                    <span className={cn('max-w-[36rem]', centered ? 'text-[12px] font-semibold uppercase tracking-[0.3em] md:text-[14px]' : 'text-[15px] opacity-90 md:text-[18px]')}>{subtitle}</span>
                )}
                {buttonLabel && (
                    <TargetLink
                        target={target}
                        className={cn(
                            'mt-2 inline-flex w-fit items-center gap-1.5 text-[14px] font-extrabold',
                            buttonClasses(style.button_style ?? (centered ? 'pill_white' : 'link'), light) ?? 'rounded-full bg-[var(--accent)] px-6 py-3 text-[var(--accent-ink)]',
                        )}
                    >
                        {buttonLabel}
                        {(style.button_style ?? (centered ? 'pill_white' : 'link')) !== 'link' && <CaretRightIcon size={15} weight="bold" />}
                    </TargetLink>
                )}
            </span>
        </TargetLink>
    );
}

/**
 * Banner principal: una o varias láminas que pasan solas (o no), solo o con uno o dos banners
 * al lado. Cada lámina tiene su imagen de celular y su vigencia. También los diseños de una sola
 * foto (`SingleHero`).
 */
export function HeroBlock({ data, priority = false }: BlockProps) {
    const slides = (data.slides as Slide[] | undefined) ?? [];
    const side = ((data.side as Side[] | undefined) ?? []).slice(0, 2);
    const layout = text(data.layout);
    const withSide = layout === 'main_side' && side.length > 0;
    const height = HEIGHTS[text(data.height)] ?? HEIGHTS.medium;

    if (slides.length === 0) {
        return null;
    }

    if (layout === 'split' || layout === 'editorial' || layout === 'cover_center') {
        return <SingleHero data={data} slide={slides[0]} layout={layout} priority={priority} />;
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

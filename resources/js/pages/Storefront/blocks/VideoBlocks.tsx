import {
    prefersLessMotion,
    type RadiusClasses,
    RATIOS,
    type ShowcaseStep,
    useLoopingVideo,
    useVideoSteps,
    VideoFrame,
    type VideoOptions,
    videoOptions,
} from '@/components/media/looping-video';
import { BannerImage, bannerMedia } from '@/components/storefront/BannerImage';
import { StoreIcon } from '@/components/storefront/icons';
import { useMediaVariant } from '@/hooks/use-media-variant';
import { cn } from '@/lib/cn';
import { CaretLeftIcon, CaretRightIcon } from '@phosphor-icons/react';
import { type CSSProperties, useCallback, useEffect, useState } from 'react';
import { type BlockProps, BlockTitle, type MediaPayload, type Target, TargetLink, text } from './shared';

/**
 * Los bloques de video de la tienda: «Video», «Video con pasos» y el carrusel de imágenes y videos.
 * El video es el mismo de la landing (`components/media/looping-video.tsx`): sin sonido, sin barra
 * y solo, como un GIF. Lo que cambia es el aspecto: aquí mandan el tema de la sede y sus esquinas.
 */

/** Las esquinas del video con las del tema de la sede (`StoreTheme`). */
const STORE_RADII: RadiusClasses = {
    none: 'rounded-none',
    soft: 'rounded-[var(--store-radius-sm)]',
    round: 'rounded-[var(--store-radius)]',
};

/*
 * El fondo, el relleno y las esquinas de estos bloques los pone la sección (`SectionShell`, pestaña
 * «Diseño»): antes cada bloque los pintaba con su propio `background`.
 */

function media(value: unknown): MediaPayload | null {
    return value && typeof value === 'object' ? (value as MediaPayload) : null;
}

/** Solo el video, con un título y un texto opcionales encima. */
export function VideoBlock({ data }: BlockProps) {
    // La versión del modo de la página y del ancho de la pantalla (celular, modo oscuro).
    const video = media(useMediaVariant(data, 'video'));
    const poster = media(useMediaVariant(data, 'poster'));
    const options = videoOptions(data);
    const player = useLoopingVideo(video?.url ?? '', { autoplay: options.autoplay, speed: options.speed });

    if (!video) {
        return null;
    }

    return (
        <div>
            {text(data.title) !== '' && <BlockTitle>{text(data.title)}</BlockTitle>}
            {text(data.text) !== '' && <p className="-mt-1 mb-3 text-[14.5px] text-[var(--muted)]">{text(data.text)}</p>}
            <VideoFrame
                player={player}
                src={video.url}
                poster={poster?.url}
                options={options}
                radii={STORE_RADII}
                label={video.alt ?? undefined}
            />
        </div>
    );
}

/** Un video al lado de los pasos que muestra: los pasos se iluminan con el video y tocar uno lo lleva a esa parte. */
export function VideoShowcaseBlock({ data }: BlockProps) {
    const video = media(useMediaVariant(data, 'video'));
    const poster = media(useMediaVariant(data, 'poster'));
    const source = video?.url ?? '';
    const steps = ((data.steps as ShowcaseStep[] | undefined) ?? []).filter((step) => step.title || step.text);
    const options = videoOptions(data);
    const player = useLoopingVideo(source, { autoplay: options.autoplay, speed: options.speed });
    const sync = useVideoSteps(
        player,
        source,
        steps.map((step) => (typeof step.starts_at === 'number' ? step.starts_at : null)),
    );
    const highlight = text(data.title_highlight);

    if (!video) {
        return null;
    }

    return (
        <div className="grid items-center gap-6 md:grid-cols-[0.9fr_1fr] md:gap-8">
            <div className={cn(data.video_side === 'left' && 'md:order-2')}>
                <h2
                    className="text-[24px] leading-[1.1] md:text-[32px]"
                    style={{ fontFamily: 'var(--store-display)', fontWeight: 'var(--store-display-weight)' as CSSProperties['fontWeight'] }}
                >
                    {text(data.title)}
                    {highlight !== '' && (
                        // Sobre un fondo oscuro la sección aclara el acento (`--sec-accent`), para que se lea.
                        <span className="block" style={{ color: 'var(--sec-accent, var(--accent))' }}>
                            {highlight}
                        </span>
                    )}
                </h2>
                {text(data.text) !== '' && <p className="mt-2 text-[15px] text-[var(--muted)]">{text(data.text)}</p>}

                {steps.length > 0 && (
                    <ol className="mt-5 flex flex-col gap-1.5">
                        {steps.map((step, index) => {
                            const current = sync.active === index;

                            return (
                                <li key={index}>
                                    <button
                                        type="button"
                                        onClick={() => sync.seek(index)}
                                        aria-current={current ? 'step' : undefined}
                                        className={cn(
                                            'relative flex w-full items-start gap-3 rounded-[var(--store-radius-sm)] border p-3 text-left transition-[opacity,background-color,border-color]',
                                            current ? 'border-[var(--line)] bg-[var(--surface)]' : 'border-transparent opacity-70 hover:opacity-100',
                                        )}
                                    >
                                        <span className="grid size-9 shrink-0 place-items-center rounded-full bg-[var(--accent)] text-[var(--accent-ink)]">
                                            <StoreIcon name={step.icon || 'Sparkle'} size={18} weight={current ? 'fill' : 'regular'} />
                                        </span>
                                        <span className="min-w-0">
                                            <span className="block text-[15px] font-bold">
                                                {index + 1}. {step.title}
                                            </span>
                                            {step.text && <span className="mt-0.5 block text-[13.5px] text-[var(--muted)]">{step.text}</span>}
                                        </span>
                                        {current && (
                                            <span
                                                aria-hidden
                                                className="absolute inset-x-3 bottom-0 h-0.5 overflow-hidden rounded-full bg-[var(--line)]"
                                            >
                                                <span ref={sync.barRef} className="block h-full origin-left scale-x-0 bg-[var(--accent)]" />
                                            </span>
                                        )}
                                    </button>
                                </li>
                            );
                        })}
                    </ol>
                )}
            </div>

            <VideoFrame
                player={player}
                src={source}
                poster={poster?.url}
                options={options}
                radii={STORE_RADII}
                label={video.alt ?? undefined}
            />
        </div>
    );
}

interface Slide {
    media: MediaPayload | null;
    video: MediaPayload | null;
    caption?: string;
    target?: Target | null;
}

/**
 * Láminas con imagen o video que avanzan solas: la imagen se queda lo que diga el bloque y el
 * video, lo que dure. Con flechas y puntos para ir a mano; quien pidió menos movimiento, o la
 * sede que apagó «Avanza solo», lo deja quieto.
 */
export function MediaCarouselBlock({ data, priority = false }: BlockProps) {
    const slides = ((data.slides as Slide[] | undefined) ?? []).filter((slide) => slide.media || slide.video);
    const options = videoOptions(data);
    const [index, setIndex] = useState(0);
    const [lessMotion] = useState(prefersLessMotion);
    const still = lessMotion || !options.autoplay;
    const seconds = Math.max(2, Number(data.interval) || 6);
    const count = slides.length;
    const current = count > 0 ? index % count : 0;
    const currentIsVideo = Boolean(slides[current]?.video);
    const next = useCallback(() => setIndex((value) => value + 1), []);
    const go = (target: number) => setIndex(((target % count) + count) % count);

    // Las imágenes avanzan por tiempo; los videos, cuando terminan (`onEnded`).
    useEffect(() => {
        if (still || count < 2 || currentIsVideo) {
            return;
        }

        const timer = window.setTimeout(next, seconds * 1000);

        return () => window.clearTimeout(timer);
    }, [still, count, current, currentIsVideo, seconds, next]);

    if (count === 0) {
        return null;
    }

    return (
        <div>
            {text(data.title) !== '' && <BlockTitle>{text(data.title)}</BlockTitle>}
            <div className="relative" aria-roledescription="carrusel" aria-label={text(data.title) || 'Imágenes y videos'}>
                <div
                    className={cn(
                        'relative overflow-hidden',
                        STORE_RADII[options.radius],
                        options.shadow && 'shadow-xl',
                        options.fit === 'cover' && 'bg-[var(--surface-2)]',
                        RATIOS[text(data.ratio)] ?? 'aspect-video',
                    )}
                >
                    {slides.map((slide, position) => (
                        <CarouselSlide
                            key={position}
                            slide={slide}
                            active={position === current}
                            label={`${position + 1} de ${count}`}
                            options={options}
                            priority={priority && position === 0}
                            onEnded={count > 1 && !still ? next : undefined}
                        />
                    ))}
                </div>

                {count > 1 && (
                    <>
                        <CarouselArrow side="left" onClick={() => go(current - 1)} />
                        <CarouselArrow side="right" onClick={() => go(current + 1)} />
                        <div className="mt-3 flex justify-center gap-1.5">
                            {slides.map((_, position) => (
                                <button
                                    key={position}
                                    type="button"
                                    aria-label={`Ir a la lámina ${position + 1}`}
                                    aria-current={position === current ? 'true' : undefined}
                                    onClick={() => go(position)}
                                    className={cn(
                                        'h-2 rounded-full transition-all',
                                        position === current ? 'w-6 bg-[var(--accent)]' : 'w-2 bg-[var(--line-2)]',
                                    )}
                                />
                            ))}
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}

function CarouselSlide({
    slide,
    active,
    label,
    options,
    priority,
    onEnded,
}: {
    slide: Slide;
    active: boolean;
    label: string;
    options: VideoOptions;
    priority: boolean;
    onEnded?: () => void;
}) {
    // La versión del modo de la página y del ancho de la pantalla (celular, modo oscuro).
    const video = media(useMediaVariant(slide, 'video'));
    const image = media(useMediaVariant(slide, 'media'));
    const source = video?.url ?? '';
    const player = useLoopingVideo(source, { active, autoplay: options.autoplay, speed: options.speed });
    const href = slide.target?.href ?? null;

    // Al volver a una lámina con video, empieza desde el principio.
    useEffect(() => {
        if (active && player.videoRef.current) {
            player.videoRef.current.currentTime = 0;
        }
    }, [active, player.videoRef]);

    return (
        <div
            role="group"
            aria-roledescription="lámina"
            aria-label={label}
            aria-hidden={!active}
            className={cn('absolute inset-0 transition-opacity duration-500', active ? 'opacity-100' : 'pointer-events-none opacity-0')}
        >
            {video ? (
                <VideoFrame
                    player={player}
                    src={source}
                    poster={image?.url}
                    // Las esquinas y la sombra las lleva la caja del carrusel, no cada lámina.
                    options={{ ...options, radius: 'none', shadow: false, frame: 'none' }}
                    radii={STORE_RADII}
                    loop={!onEnded}
                    onEnded={onEnded}
                    className="h-full"
                    label={video.alt ?? undefined}
                    fill
                />
            ) : options.fit === 'contain' ? (
                <img src={image?.url} alt={image?.alt ?? ''} className="size-full object-contain" loading="lazy" />
            ) : (
                <BannerImage
                    {...bannerMedia(slide)}
                    priority={priority}
                    className="absolute inset-0"
                    sizes="(max-width: 767px) 100vw, 1180px"
                />
            )}

            {/* La leyenda lleva al destino: sobre el video, el toque es para pausarlo. */}
            {(slide.caption || href) && (
                <div className="pointer-events-none absolute inset-x-0 bottom-0 flex items-end justify-between gap-3 bg-gradient-to-t from-black/60 to-transparent px-4 pt-10 pb-3 text-white">
                    {slide.caption ? <p className="text-[14.5px] font-semibold">{slide.caption}</p> : <span />}
                    {href && (
                        <TargetLink
                            target={slide.target}
                            className="pointer-events-auto inline-flex h-9 shrink-0 items-center gap-1 rounded-full bg-white px-4 text-[13px] font-bold text-[#14171A]"
                        >
                            Ver más
                            <CaretRightIcon size={13} weight="bold" />
                        </TargetLink>
                    )}
                </div>
            )}
        </div>
    );
}

function CarouselArrow({ side, onClick }: { side: 'left' | 'right'; onClick: () => void }) {
    const ArrowIcon = side === 'left' ? CaretLeftIcon : CaretRightIcon;

    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={side === 'left' ? 'Lámina anterior' : 'Lámina siguiente'}
            className={cn(
                'absolute top-[calc(50%-1rem)] grid size-9 -translate-y-1/2 place-items-center rounded-full bg-white/90 text-[#14171A] shadow-md hover:bg-white',
                side === 'left' ? 'left-2' : 'right-2',
            )}
        >
            <ArrowIcon size={17} weight="bold" />
        </button>
    );
}

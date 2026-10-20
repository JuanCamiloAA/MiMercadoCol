import { LANDING_ICONS } from '@/components/landing/landing-icons';
import { list, PreviewNotice, SectionTitle, text } from '@/components/landing/landing-ui';
import {
    PANEL_RADII,
    prefersLessMotion,
    RATIOS,
    type ShowcaseStep,
    useLoopingVideo,
    useVideoSteps,
    VideoFrame,
    type VideoOptions,
    videoOptions,
} from '@/components/media/looping-video';
import { useUrlVariant } from '@/hooks/use-media-variant';
import { cn } from '@/lib/cn';
import { CaretLeftIcon, CaretRightIcon, SparkleIcon } from '@phosphor-icons/react';
import { useCallback, useEffect, useState } from 'react';

/**
 * Los bloques de la landing con video: «Video con pasos», «Video» y el carrusel de imágenes y
 * videos. Todos usan el mismo video: sin sonido, sin barra y solo, como un GIF.
 *
 * El fondo lo pone la sección (pestaña «Diseño»): estos bloques ya no lo eligen (`tone`). Pintan
 * `--bg` por si la sección cambió el tema, y nada si trae fondo propio (`--sec-own-bg`).
 */

type BlockProps = { data: Record<string, unknown>; isPreview: boolean };

/**
 * Una sección con un video al lado del título y los pasos que muestra. Los pasos se iluminan con
 * el video y tocar uno lo lleva a esa parte.
 */
export function VideoShowcaseBlock({ data, isPreview }: BlockProps) {
    // La versión del modo de la página y del ancho de la pantalla (celular, modo oscuro).
    const video = useUrlVariant(data, 'video') ?? '';
    const poster = useUrlVariant(data, 'poster') ?? undefined;
    const steps = list<ShowcaseStep>(data.steps).filter((step) => step.title || step.text);
    const tags = list<{ label: string }>(data.tags).filter((tag) => tag.label);
    const highlight = text(data.title_highlight);
    const options = videoOptions(data);
    const player = useLoopingVideo(video, { autoplay: options.autoplay, speed: options.speed });
    const sync = useVideoSteps(
        player,
        video,
        steps.map((step) => step.starts_at),
    );
    const synced = video !== '';

    return (
        <section className="relative overflow-hidden bg-[var(--sec-own-bg,var(--bg))] px-[var(--sec-px,1rem)] pt-[var(--sec-pt,4rem)] pb-[var(--sec-pb,4rem)] text-[var(--text)] md:pt-[var(--sec-pt,6rem)] md:pb-[var(--sec-pb,6rem)]">
            <div
                aria-hidden
                className="pointer-events-none absolute inset-0 opacity-50"
                style={{ background: 'radial-gradient(50% 60% at 75% 40%, var(--accent-soft), transparent 70%)' }}
            />
            <div
                className={cn(
                    'relative mx-auto grid w-full max-w-[var(--sec-max,70rem)] items-center gap-10',
                    (synced || isPreview) && 'lg:grid-cols-[0.85fr_1fr] lg:gap-14',
                )}
            >
                <div className={cn(data.video_side === 'left' && 'lg:order-2')}>
                    <h2 className="text-[2rem] leading-[1.08] tracking-tight md:text-[3rem]">
                        {text(data.title)}
                        {highlight && (
                            <span
                                className="block bg-clip-text text-transparent"
                                style={{
                                    backgroundImage: 'linear-gradient(90deg, var(--accent), color-mix(in oklab, var(--accent) 55%, var(--text)))',
                                }}
                            >
                                {highlight}
                            </span>
                        )}
                    </h2>
                    {text(data.text) && <p className="mt-4 max-w-[30rem] text-[1.0625rem] text-[var(--muted)]">{text(data.text)}</p>}

                    {steps.length > 0 && (
                        <ol className="mt-8 flex flex-col gap-2">
                            {steps.map((step, index) => {
                                const StepIcon = LANDING_ICONS[step.icon] ?? SparkleIcon;
                                const current = synced && sync.active === index;
                                const body = (
                                    <>
                                        <span className="rounded-control grid size-10 shrink-0 place-items-center bg-[var(--accent-soft)] text-[var(--accent)]">
                                            <StepIcon size={20} weight={current ? 'fill' : 'regular'} />
                                        </span>
                                        <span className="min-w-0">
                                            <span className="block text-[1rem] font-semibold">
                                                {index + 1}. {step.title}
                                            </span>
                                            {step.text && <span className="mt-0.5 block text-[0.9375rem] text-[var(--muted)]">{step.text}</span>}
                                        </span>
                                        {current && (
                                            <span
                                                aria-hidden
                                                className="absolute inset-x-4 bottom-0 h-0.5 overflow-hidden rounded-full bg-[var(--line)]"
                                            >
                                                <span ref={sync.barRef} className="block h-full origin-left scale-x-0 bg-[var(--accent)]" />
                                            </span>
                                        )}
                                    </>
                                );

                                return (
                                    <li key={index}>
                                        {synced ? (
                                            <button
                                                type="button"
                                                onClick={() => sync.seek(index)}
                                                aria-current={current ? 'step' : undefined}
                                                className={cn(
                                                    'rounded-card relative flex w-full items-start gap-4 border p-4 text-left transition-[opacity,background-color,border-color]',
                                                    current
                                                        ? 'border-[var(--line)] bg-[var(--surface)]'
                                                        : 'border-transparent opacity-70 hover:opacity-100',
                                                )}
                                            >
                                                {body}
                                            </button>
                                        ) : (
                                            <div className="relative flex items-start gap-4 p-4">{body}</div>
                                        )}
                                    </li>
                                );
                            })}
                        </ol>
                    )}

                    {tags.length > 0 && (
                        <ul className="mt-6 flex flex-wrap gap-2">
                            {tags.map((tag, index) => (
                                <li
                                    key={index}
                                    className="rounded-full border border-[var(--line)] px-3 py-1.5 text-[0.8125rem] font-medium text-[var(--muted)]"
                                >
                                    {tag.label}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                {synced ? (
                    <VideoFrame player={player} src={video} poster={poster} options={options} />
                ) : (
                    isPreview && (
                        <PreviewNotice className="aspect-video">
                            Sube un video en el bloque para que se vea aquí. Sin video, la sección sale solo con el texto.
                        </PreviewNotice>
                    )
                )}
            </div>
        </section>
    );
}

/** Solo el video, con un título opcional encima: al ancho del contenido o de lado a lado. */
export function VideoBlock({ data, isPreview }: BlockProps) {
    const video = useUrlVariant(data, 'video') ?? '';
    const poster = useUrlVariant(data, 'poster') ?? undefined;
    const full = data.width === 'full';
    const options = videoOptions(data);
    const player = useLoopingVideo(video, { autoplay: options.autoplay, speed: options.speed });

    if (!video && !isPreview) {
        return null;
    }

    return (
        <section
            className={cn(
                'bg-[var(--sec-own-bg,var(--bg))] pt-[var(--sec-pt,3.5rem)] pb-[var(--sec-pb,3.5rem)] text-[var(--text)] md:pt-[var(--sec-pt,5rem)] md:pb-[var(--sec-pb,5rem)]',
                !full && 'px-[var(--sec-px,1rem)]',
            )}
        >
            <div className={cn(!full && 'mx-auto w-full max-w-[var(--sec-max,70rem)]')}>
                <div className={cn(full && 'px-4')}>
                    <SectionTitle title={text(data.title)} body={text(data.text) || undefined} />
                </div>
                {video ? (
                    <VideoFrame player={player} src={video} poster={poster} options={options} />
                ) : (
                    <PreviewNotice className={cn('aspect-video', full && 'mx-4')}>
                        Sube el video en el bloque para que se vea aquí. Sin video, la sección no sale en la landing.
                    </PreviewNotice>
                )}
            </div>
        </section>
    );
}

interface Slide {
    image_url: string | null;
    video_url: string | null;
    caption: string;
}

/**
 * Un carrusel de imágenes y videos que avanza solo: la imagen se queda lo que diga el bloque y el
 * video, lo que dure. Con flechas y puntos para ir a mano; quien pidió menos movimiento lo
 * recibe quieto y avanza él.
 */
export function MediaCarouselBlock({ data, isPreview }: BlockProps) {
    const slides = list<Slide>(data.slides).filter((slide) => slide.image_url || slide.video_url);
    const [index, setIndex] = useState(0);
    const options = videoOptions(data);
    const [lessMotion] = useState(prefersLessMotion);
    // Quieto si pidieron menos movimiento o si quien arma la landing apagó «Avanza solo».
    const still = lessMotion || !options.autoplay;
    const seconds = Math.max(2, Number(data.interval) || 5);
    const current = slides.length > 0 ? index % slides.length : 0;
    const count = slides.length;
    const currentIsVideo = Boolean(slides[current]?.video_url);
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

    if (slides.length === 0) {
        return isPreview ? (
            <section className="px-4 py-14">
                <div className="mx-auto w-full max-w-[70rem]">
                    <PreviewNotice className="aspect-video">
                        Agrega láminas con una imagen o un video. Sin láminas, el carrusel no sale en la landing.
                    </PreviewNotice>
                </div>
            </section>
        ) : null;
    }

    return (
        <section className="bg-[var(--sec-own-bg,var(--bg))] px-[var(--sec-px,1rem)] pt-[var(--sec-pt,3.5rem)] pb-[var(--sec-pb,3.5rem)] text-[var(--text)] md:pt-[var(--sec-pt,5rem)] md:pb-[var(--sec-pb,5rem)]">
            <div className="mx-auto w-full max-w-[var(--sec-max,70rem)]">
                <SectionTitle title={text(data.title)} body={text(data.text) || undefined} />
                <div className="relative" aria-roledescription="carrusel">
                    <div
                        className={cn(
                            'relative overflow-hidden',
                            PANEL_RADII[options.radius],
                            options.shadow && 'shadow-xl',
                            // Con «se ve completo», alrededor queda el fondo de la sección.
                            options.fit === 'cover' && 'bg-[var(--surface-2)]',
                            RATIOS[text(data.ratio)] ?? 'aspect-video',
                        )}
                    >
                        {slides.map((slide, position) => (
                            <CarouselSlide
                                key={position}
                                slide={slide}
                                active={position === current}
                                label={`${position + 1} de ${slides.length}`}
                                options={options}
                                onEnded={slides.length > 1 && !still ? next : undefined}
                            />
                        ))}
                    </div>

                    {slides.length > 1 && (
                        <>
                            <CarouselArrow side="left" onClick={() => go(current - 1)} />
                            <CarouselArrow side="right" onClick={() => go(current + 1)} />
                            <div className="mt-4 flex justify-center gap-2">
                                {slides.map((_, position) => (
                                    <button
                                        key={position}
                                        type="button"
                                        aria-label={`Ir a la lámina ${position + 1}`}
                                        aria-current={position === current ? 'true' : undefined}
                                        onClick={() => go(position)}
                                        className={cn(
                                            'h-2 rounded-full transition-all',
                                            position === current ? 'w-6 bg-[var(--accent)]' : 'w-2 bg-[var(--line-2)] hover:bg-[var(--muted)]',
                                        )}
                                    />
                                ))}
                            </div>
                        </>
                    )}
                </div>
            </div>
        </section>
    );
}

function CarouselSlide({
    slide,
    active,
    label,
    options,
    onEnded,
}: {
    slide: Slide;
    active: boolean;
    label: string;
    options: VideoOptions;
    onEnded?: () => void;
}) {
    // La versión del modo de la página y del ancho de la pantalla (celular, modo oscuro).
    const video = useUrlVariant(slide, 'video') ?? '';
    const image = useUrlVariant(slide, 'image');
    const player = useLoopingVideo(video, { active, autoplay: options.autoplay, speed: options.speed });

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
                    src={video}
                    poster={image ?? undefined}
                    // Las esquinas y la sombra las lleva la caja del carrusel, no cada lámina.
                    options={{ ...options, radius: 'none', shadow: false, frame: 'none' }}
                    loop={!onEnded}
                    onEnded={onEnded}
                    className="h-full"
                    fill
                />
            ) : (
                <img
                    src={image ?? ''}
                    alt={slide.caption}
                    className={cn('size-full', options.fit === 'contain' ? 'object-contain' : 'object-cover')}
                    loading="lazy"
                />
            )}
            {slide.caption && (
                <p className="pointer-events-none absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/60 to-transparent px-5 pt-10 pb-4 text-[0.9375rem] font-medium text-white">
                    {slide.caption}
                </p>
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
                'absolute top-[calc(50%-1.5rem)] grid size-10 -translate-y-1/2 place-items-center rounded-full bg-white/90 text-[#14171A] shadow-md transition-opacity hover:bg-white',
                side === 'left' ? 'left-3' : 'right-3',
            )}
        >
            <ArrowIcon size={18} weight="bold" />
        </button>
    );
}

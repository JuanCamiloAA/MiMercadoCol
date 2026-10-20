import { cn } from '@/lib/cn';
import { PlayIcon } from '@phosphor-icons/react';
import { type RefObject, useCallback, useEffect, useRef, useState } from 'react';

/**
 * El video que se muestra solo, como un GIF: sin sonido, sin barra y en bucle. Lo comparten la
 * landing de la plataforma (`LandingMedia.tsx`) y la tienda en línea de cada empresa
 * (`Storefront/blocks/VideoBlocks.tsx`), con las mismas opciones (`App\Support\VideoOptions`).
 */

export const prefersLessMotion = () => typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** Las opciones de video del bloque (`VideoOptions` en el servidor). */
export interface VideoOptions {
    speed: number;
    autoplay: boolean;
    loop: boolean;
    fit: 'cover' | 'contain';
    radius: 'none' | 'soft' | 'round';
    shadow: boolean;
    frame: 'none' | 'browser';
}

/** Las esquinas de cada superficie: la landing usa las del panel; la tienda, las de su tema. */
export type RadiusClasses = Record<VideoOptions['radius'], string>;

export const PANEL_RADII: RadiusClasses = { none: 'rounded-none', soft: 'rounded-card', round: 'rounded-sheet' };

/** Lee las opciones del bloque; lo que no traiga (un bloque guardado antes) vale su defecto. */
export function videoOptions(data: Record<string, unknown>): VideoOptions {
    const speed = Number(data.video_speed);

    return {
        speed: Number.isFinite(speed) && speed > 0 ? speed : 1,
        autoplay: data.video_autoplay !== false,
        loop: data.video_loop !== false,
        fit: data.video_fit === 'contain' ? 'contain' : 'cover',
        radius: data.video_radius === 'none' || data.video_radius === 'soft' ? data.video_radius : 'round',
        shadow: data.video_shadow !== false,
        frame: data.video_frame === 'browser' ? 'browser' : 'none',
    };
}

export interface LoopingVideo {
    videoRef: RefObject<HTMLVideoElement | null>;
    /** La persona lo pausó (o pidió menos movimiento y nunca lo puso a andar). */
    paused: boolean;
    /** Anda de verdad: se ve en pantalla, nadie lo pausó y le toca. */
    playing: boolean;
    /** Ya puede bajar el archivo: estuvo cerca de la pantalla y le tocó al menos una vez. */
    load: boolean;
    toggle: () => void;
}

/**
 * Un video que anda solo mientras se ve en pantalla (fuera de ella no gasta batería), arranca
 * pausado si la persona pidió menos movimiento y se detiene con un toque: es la única forma de
 * parar algo que se mueve solo. `active` lo apaga sin pausarlo (la lámina que no se ve).
 *
 * El archivo no se pide hasta que el video está por aparecer y le toca: una página con varios
 * videos no los baja todos al abrir, y la lámina que nadie ha visto no gasta datos.
 *
 * Sin `autoplay` arranca pausado, con el botón de reproducir a la vista. `speed` es la velocidad.
 */
export function useLoopingVideo(
    source: string,
    { active = true, autoplay = true, speed = 1 }: { active?: boolean; autoplay?: boolean; speed?: number } = {},
): LoopingVideo {
    const videoRef = useRef<HTMLVideoElement>(null);
    const [visible, setVisible] = useState(false);
    const [near, setNear] = useState(false);
    const [paused, setPaused] = useState(() => prefersLessMotion() || !autoplay);
    const [wasActive, setWasActive] = useState(active);
    const [lastAutoplay, setLastAutoplay] = useState(autoplay);

    if (active && !wasActive) {
        setWasActive(true);
    }

    // Si quien edita cambia «Arranca solo» con la vista previa abierta, el video lo sigue.
    if (autoplay !== lastAutoplay) {
        setLastAutoplay(autoplay);
        setPaused(prefersLessMotion() || !autoplay);
    }

    // React no escribe `muted` como atributo y sin el algunos navegadores no dejan arrancar solo.
    // `source`: el video puede llegar despues (se sube con la vista previa abierta).
    useEffect(() => {
        const element = videoRef.current;

        if (!element) {
            return;
        }

        element.muted = true;

        // Un WebM grabado en el navegador (o con muchas grabadoras de pantalla) no trae su duracion:
        // se salta al final para que el navegador la calcule y se vuelve al principio.
        const measure = () => {
            if (element.duration === Infinity) {
                element.addEventListener('durationchange', () => (element.currentTime = 0), { once: true });
                element.currentTime = Number.MAX_SAFE_INTEGER;
            }
        };
        element.addEventListener('loadedmetadata', measure);

        const observer = new IntersectionObserver(([entry]) => setVisible(entry.isIntersecting), { threshold: 0.25 });
        observer.observe(element);

        // Cerca es una vez: lo que ya se bajó no se vuelve a soltar.
        const approach = new IntersectionObserver(([entry]) => entry.isIntersecting && setNear(true), { rootMargin: '400px 0px' });
        approach.observe(element);

        return () => {
            observer.disconnect();
            approach.disconnect();
            element.removeEventListener('loadedmetadata', measure);
        };
    }, [source]);

    const load = near && wasActive;
    const playing = load && visible && !paused && active;

    useEffect(() => {
        const element = videoRef.current;

        if (!element) {
            return;
        }

        if (playing) {
            // El navegador puede negarse (ahorro de batería): queda en el primer cuadro, que es lo mismo que una imagen.
            element.play().catch(() => undefined);
        } else {
            element.pause();
        }
    }, [source, playing]);

    // `defaultPlaybackRate` sobrevive a que el navegador cargue el archivo; `playbackRate`, no.
    useEffect(() => {
        const element = videoRef.current;

        if (element) {
            element.defaultPlaybackRate = speed;
            element.playbackRate = speed;
        }
    }, [source, load, speed]);

    return { videoRef, paused, playing, load, toggle: useCallback(() => setPaused((current) => !current), []) };
}

/**
 * El video con su toque para pausar, sus esquinas, su sombra y, si lo piden, un marco de ventana
 * de navegador. Sin `controls`: ni barra, ni volumen, ni pantalla completa.
 */
export function VideoFrame({
    player,
    src,
    poster,
    options,
    loop = options.loop,
    onEnded,
    className,
    fill = false,
    radii = PANEL_RADII,
    label,
}: {
    player: LoopingVideo;
    src: string;
    poster?: string;
    options: VideoOptions;
    loop?: boolean;
    onEnded?: () => void;
    className?: string;
    /** Ocupa todo el alto de su caja (la lámina de un carrusel) en vez del alto propio del video. */
    fill?: boolean;
    radii?: RadiusClasses;
    /** Lo que muestra el video, para quien no lo ve (la descripción de la biblioteca). */
    label?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-col overflow-hidden',
                radii[options.radius],
                options.shadow && 'shadow-2xl',
                options.frame === 'browser' && 'border border-[var(--line)] bg-[var(--surface)]',
                className,
            )}
        >
            {options.frame === 'browser' && (
                <span aria-hidden className="flex h-8 shrink-0 items-center gap-1.5 border-b border-[var(--line)] bg-[var(--surface-2)] px-3">
                    <span className="size-2.5 rounded-full bg-[#FF5F57]" />
                    <span className="size-2.5 rounded-full bg-[#FEBC2E]" />
                    <span className="size-2.5 rounded-full bg-[#28C840]" />
                </span>
            )}
            <button
                type="button"
                onClick={player.toggle}
                aria-label={`${player.paused ? 'Reproducir' : 'Pausar'} el video${label ? `: ${label}` : ''}`}
                className={cn('relative block w-full', fill && 'min-h-0 flex-1')}
            >
                <video
                    ref={player.videoRef}
                    src={player.load ? src : undefined}
                    poster={poster}
                    muted
                    loop={loop}
                    playsInline
                    preload="metadata"
                    onEnded={onEnded}
                    className={cn('block w-full', fill ? cn('h-full', options.fit === 'contain' ? 'object-contain' : 'object-cover') : 'h-auto')}
                />
                {player.paused && (
                    <span className="absolute inset-0 grid place-items-center bg-black/20">
                        <span className="grid size-14 place-items-center rounded-full bg-white/90 text-[#14171A] shadow-lg">
                            <PlayIcon size={26} weight="fill" />
                        </span>
                    </span>
                )}
            </button>
        </div>
    );
}

export interface ShowcaseStep {
    icon: string;
    title: string;
    text: string;
    starts_at: number | null;
}

/**
 * Qué paso va según el tiempo del video y cuánto le falta. El avance se escribe directo en la
 * barra, cuadro a cuadro, sin volver a pintar React.
 */
export function useVideoSteps(player: LoopingVideo, source: string, starts: (number | null)[]) {
    const barRef = useRef<HTMLSpanElement>(null);
    const startsRef = useRef(starts);
    startsRef.current = starts;
    const [active, setActive] = useState(0);

    // Donde empieza cada paso: lo que escribió quien armó la sección o, si falta alguno, el video repartido por igual.
    const times = useCallback((duration: number): number[] => {
        const given = startsRef.current;

        return given.every((value) => typeof value === 'number')
            ? (given as number[])
            : given.map((_, index) => (duration * index) / Math.max(1, given.length));
    }, []);

    const update = useCallback(() => {
        const element = player.videoRef.current;

        if (!element || !Number.isFinite(element.duration) || element.duration <= 0 || startsRef.current.length === 0) {
            return;
        }

        const bounds = times(element.duration);
        const now = element.currentTime;
        const index = bounds.reduce((found, start, position) => (now >= start ? position : found), 0);
        const end = bounds[index + 1] ?? element.duration;
        const share = (now - bounds[index]) / Math.max(0.001, end - bounds[index]);

        setActive((previous) => (previous === index ? previous : index));

        if (barRef.current) {
            barRef.current.style.transform = `scaleX(${Math.min(1, Math.max(0, share))})`;
        }
    }, [player.videoRef, times]);

    useEffect(() => {
        const element = player.videoRef.current;

        if (!element) {
            return;
        }

        element.addEventListener('loadedmetadata', update);
        element.addEventListener('seeked', update);

        return () => {
            element.removeEventListener('loadedmetadata', update);
            element.removeEventListener('seeked', update);
        };
    }, [player.videoRef, source, update]);

    useEffect(() => {
        if (!player.playing) {
            return;
        }

        let frame = 0;
        const tick = () => {
            update();
            frame = requestAnimationFrame(tick);
        };
        frame = requestAnimationFrame(tick);

        return () => cancelAnimationFrame(frame);
    }, [player.playing, update]);

    const seek = (index: number) => {
        const element = player.videoRef.current;

        if (!element || !Number.isFinite(element.duration)) {
            return;
        }

        element.currentTime = Math.min(element.duration, times(element.duration)[index] ?? 0);
        setActive(index);
    };

    return { barRef, active, seek };
}

/** Las clases de proporción que ofrecen los carruseles (`ratio` del bloque). */
export const RATIOS: Record<string, string> = { '16/9': 'aspect-video', '4/3': 'aspect-[4/3]', '1/1': 'aspect-square', '21/9': 'aspect-[21/9]' };

import { PauseIcon, PlayIcon } from '@phosphor-icons/react';
import { type CSSProperties, type HTMLAttributes, type ReactNode, useEffect, useRef, useState } from 'react';
import { useLoopingVideo } from '@/components/media/looping-video';
import { useDocumentTheme } from '@/hooks/use-document-theme';
import { MOBILE_QUERY, useMediaQuery } from '@/hooks/use-media-query';
import { cn } from '@/lib/cn';
import {
    animates,
    type Appearance,
    appearanceFor,
    baseColor,
    hasBackground,
    imageBackgroundStyle,
    imageLayerStyle,
    inkProps,
    overlayStyle,
    parallaxFactor,
    resolveAppearance,
    revealStyle,
    shellAttributes,
    type Surface,
} from './appearance';

/**
 * La envoltura común de las secciones de la tienda y de la landing.
 *
 * Los bloques solo dibujan su contenido; el alto, el ancho, el espacio, el fondo y la entrada los
 * pone esta envoltura a partir del diseño de la sección (`appearance`). Así cualquier bloque,
 * también los que se agreguen después, queda parametrizado sin tocar su propio código. Es la
 * misma idea de `BlockShell` de miTallerCol, con las dos superficies de este proyecto.
 */

interface SectionShellProps extends Omit<HTMLAttributes<HTMLElement>, 'children'> {
    surface: Surface;
    /** El diseño que manda el servidor, ya resuelto con los defectos del tipo. */
    appearance: unknown;
    /** La etiqueta de la envoltura: `section` en la tienda, `div` en la landing (el bloque trae la suya). */
    as?: 'section' | 'div';
    children: ReactNode;
    /** En la vista previa del editor el revelado se repite al cambiar un ajuste. */
    replayKey?: string;
}

/** El bloque se considera a la vista cuando entró un 15 % en la pantalla. */
const OBSERVER: IntersectionObserverInit = { threshold: 0.15, rootMargin: '0px 0px -8% 0px' };

/*
 * Marca que hay JavaScript. La regla que esconde las piezas antes de revelarlas cuelga de esta
 * clase: sin este módulo cargado, el contenido nunca queda invisible.
 */
if (typeof document !== 'undefined') {
    document.documentElement.classList.add('sec-js');
}

export function SectionShell({ surface, appearance, as: Tag = 'section', children, replayKey, className, style, ...rest }: SectionShellProps) {
    // En modo oscuro, el diseño de oscuro si la sección cambió algo allá (`appearance.dark`).
    const a = resolveAppearance(undefined, appearanceFor(appearance, useDocumentTheme()), surface);
    const reveal = animates(a);
    const ref = useRef<HTMLElement | null>(null);
    const layer = useRef<HTMLDivElement | null>(null);
    const [visible, setVisible] = useState(!reveal);
    const ink = inkProps(a, surface);
    const background = hasBackground(a);

    /* Revelado al entrar en pantalla. Sin IntersectionObserver se muestra de una vez: nunca puede
       quedar contenido invisible. */
    useEffect(() => {
        const element = ref.current;

        if (!reveal || !element || typeof IntersectionObserver === 'undefined') {
            setVisible(true);

            return;
        }

        setVisible(false);

        const observer = new IntersectionObserver(([entry]) => {
            if (entry.isIntersecting) {
                setVisible(true);

                // «Solo la primera vez» deja la sección quieta después de entrar.
                if (a.anim_once) {
                    observer.disconnect();
                }
            } else if (!a.anim_once) {
                setVisible(false);
            }
        }, OBSERVER);

        observer.observe(element);

        return () => observer.disconnect();
    }, [reveal, a.anim_once, replayKey]);

    /* Paralaje: la capa del fondo se desplaza según lo recorrido, en un cuadro de animación para no
       trabar el desplazamiento. */
    const factor = parallaxFactor(a);

    useEffect(() => {
        if (!factor || typeof window === 'undefined' || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        let frame = 0;

        const update = () => {
            frame = 0;
            const element = ref.current;
            const target = layer.current;

            if (!element || !target) {
                return;
            }

            const rect = element.getBoundingClientRect();
            const height = window.innerHeight || 1;
            // Dónde está el centro de la sección respecto al de la pantalla, acotado a -0,5…0,5: el
            // desplazamiento nunca pasa de la mitad del factor y el margen de la capa siempre alcanza.
            const progress = Math.max(-0.5, Math.min(0.5, (rect.top + rect.height / 2 - height / 2) / height));
            target.style.transform = `translate3d(0, ${(-progress * factor).toFixed(1)}px, 0)`;
        };

        const onScroll = () => {
            if (!frame) {
                frame = window.requestAnimationFrame(update);
            }
        };

        update();
        // En captura: `scroll` no burbujea, y en el editor la página vive en un panel con su propio desplazamiento.
        window.addEventListener('scroll', onScroll, { passive: true, capture: true });
        window.addEventListener('resize', onScroll, { passive: true });

        return () => {
            if (frame) {
                window.cancelAnimationFrame(frame);
            }

            window.removeEventListener('scroll', onScroll, true);
            window.removeEventListener('resize', onScroll);
        };
    }, [factor, a.image?.url]);

    const base = baseColor(a, surface);

    return (
        <Tag
            ref={(element: HTMLElement | null) => {
                ref.current = element;
            }}
            {...rest}
            {...shellAttributes(a, surface)}
            data-theme={ink.theme}
            data-visible={reveal ? (visible ? 'true' : 'false') : undefined}
            className={cn('sec', reveal && 'sec-reveal', className)}
            style={{ ...revealStyle(a), ...ink.style, ...style }}
        >
            {background && (
                <div className="sec-bg" aria-hidden="true">
                    {base && <div className="sec-bg-layer" style={{ backgroundColor: base }} />}
                    {a.bg_type === 'image' && a.image && <ImageLayer appearance={a} layerRef={layer} parallax={factor > 0} />}
                    {a.bg_type === 'texture' && <div className="sec-bg-layer sec-texture" data-texture={a.bg_texture} />}
                    {a.bg_type === 'gradient' && <div className="sec-bg-layer sec-gradient" data-gradient={a.bg_gradient} />}
                    {overlayStyle(a) && <div className="sec-bg-layer" style={overlayStyle(a) ?? undefined} />}
                </div>
            )}
            {a.bg_type === 'video' && (a.video || a.video_mobile) && <VideoLayer appearance={a} overlay={overlayStyle(a)} />}
            <div className="sec-inner">{children}</div>
        </Tag>
    );
}

/**
 * La imagen de fondo: un `<picture>` con la de celular si la hay. El mosaico y el fondo fijo no
 * caben en un `<img>` y van como fondo de CSS.
 */
function ImageLayer({ appearance: a, layerRef, parallax }: { appearance: Appearance; layerRef: React.RefObject<HTMLDivElement | null>; parallax: boolean }) {
    const image = a.image;

    if (!image) {
        return null;
    }

    const asCss = a.bg_fit === 'repeat' || (a.bg_fixed && !parallax);

    return (
        <div ref={layerRef} className="sec-bg-layer" data-parallax={parallax ? 'on' : undefined}>
            {asCss ? (
                <div className="sec-bg-fill" style={imageBackgroundStyle(a, image.url)} />
            ) : (
                <picture>
                    {a.image_mobile && <source media="(max-width: 767px)" srcSet={a.image_mobile.url} />}
                    <img src={image.url} srcSet={image.srcset ?? undefined} sizes="100vw" alt="" loading="lazy" decoding="async" className="sec-bg-fill" style={imageLayerStyle(a)} />
                </picture>
            )}
        </div>
    );
}

/**
 * El video de fondo: sin sonido, en bucle y solo mientras se ve (`useLoopingVideo`); quien pide
 * menos movimiento lo ve quieto en el primer cuadro. Como se mueve solo, lleva su botón para
 * pausarlo: es la única forma de parar algo que se mueve sin que nadie lo pida.
 */
function VideoLayer({ appearance: a, overlay }: { appearance: Appearance; overlay: CSSProperties | null }) {
    // En celular, el de celular si lo hay.
    const phone = useMediaQuery(MOBILE_QUERY);
    const source = ((phone ? a.video_mobile : null) ?? a.video ?? a.video_mobile)?.url ?? '';
    const player = useLoopingVideo(source);

    return (
        <>
            <div className="sec-bg" aria-hidden="true">
                <video
                    ref={player.videoRef}
                    src={player.load ? source : undefined}
                    poster={a.image?.url}
                    muted
                    loop
                    playsInline
                    preload="metadata"
                    className="sec-bg-fill"
                    style={imageLayerStyle(a)}
                />
                {overlay && <div className="sec-bg-layer" style={overlay} />}
            </div>
            <button
                type="button"
                onClick={(event) => {
                    event.stopPropagation();
                    player.toggle();
                }}
                aria-label={player.paused ? 'Reproducir el video de fondo' : 'Pausar el video de fondo'}
                className="sec-video-toggle"
            >
                {player.paused ? <PlayIcon size={14} weight="fill" /> : <PauseIcon size={14} weight="fill" />}
            </button>
        </>
    );
}

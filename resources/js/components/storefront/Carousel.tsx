import { CaretLeftIcon, CaretRightIcon, PauseIcon, PlayIcon } from '@phosphor-icons/react';
import { Children, useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

function prefersReducedMotion(): boolean {
    return typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * Carrusel accesible.
 *
 * Se desliza con el dedo (es una fila con `scroll-snap`), con flechas y con puntos. Si pasa
 * solo, se detiene al pasar el mouse, al enfocar algo adentro y con el botón de pausa; con
 * «menos movimiento» en el sistema no pasa solo nunca.
 */
export function Carousel({
    children,
    label,
    autoplay = 0,
    perView = 'full',
    dots = true,
    arrows = true,
    arrowPlacement = 'side',
    progress = false,
    className,
    trackClassName,
    slideClassName,
}: {
    children: ReactNode;
    label: string;
    /** Segundos entre lámina y lámina; 0 no pasa solo. */
    autoplay?: number;
    /** `full`: una lámina a la vez; `cards`: las que quepan (estantes). */
    perView?: 'full' | 'cards';
    dots?: boolean;
    arrows?: boolean;
    /** `top`: redondas arriba a la derecha, a la altura del título del estante. */
    arrowPlacement?: 'side' | 'top';
    /** Una barra debajo que dice cuánto se ha recorrido. */
    progress?: boolean;
    className?: string;
    trackClassName?: string;
    /** El ancho de cada lámina en una fila de tarjetas. */
    slideClassName?: string;
}) {
    const slides = Children.toArray(children);
    const track = useRef<HTMLDivElement>(null);
    const [index, setIndex] = useState(0);
    const [paused, setPaused] = useState(false);
    const [hovered, setHovered] = useState(false);
    const [focused, setFocused] = useState(false);
    const [edges, setEdges] = useState({ start: true, end: slides.length <= 1 });
    // La parte de la fila que se ve y dónde empieza, de 0 a 1, para la barra de avance.
    const [bar, setBar] = useState({ size: 1, start: 0 });
    const reduced = prefersReducedMotion();
    const playing = autoplay > 0 && !paused && !reduced && slides.length > 1;

    const measure = useCallback(() => {
        const element = track.current;

        if (!element) {
            return;
        }

        const width = element.clientWidth || 1;
        const total = element.scrollWidth || width;
        setIndex(Math.round(element.scrollLeft / width));
        setEdges({ start: element.scrollLeft <= 4, end: element.scrollLeft + width >= element.scrollWidth - 4 });
        setBar({ size: Math.min(1, width / total), start: total > 0 ? element.scrollLeft / total : 0 });
    }, []);

    const go = useCallback(
        (target: number) => {
            const element = track.current;

            if (!element) {
                return;
            }

            if (perView === 'cards') {
                element.scrollBy({ left: Math.sign(target - index) * element.clientWidth * 0.85, behavior: reduced ? 'auto' : 'smooth' });

                return;
            }

            const next = (target + slides.length) % slides.length;
            element.scrollTo({ left: next * element.clientWidth, behavior: reduced ? 'auto' : 'smooth' });
        },
        [index, perView, reduced, slides.length],
    );

    useEffect(() => {
        measure();
        const element = track.current;
        element?.addEventListener('scroll', measure, { passive: true });
        window.addEventListener('resize', measure);

        return () => {
            element?.removeEventListener('scroll', measure);
            window.removeEventListener('resize', measure);
        };
    }, [measure]);

    useEffect(() => {
        if (!playing || hovered || focused) {
            return;
        }

        const timer = setInterval(() => {
            if (!document.hidden) {
                go(index + 1);
            }
        }, autoplay * 1000);

        return () => clearInterval(timer);
    }, [autoplay, focused, go, hovered, index, playing]);

    if (slides.length === 0) {
        return null;
    }

    return (
        <section
            aria-roledescription="carrusel"
            aria-label={label}
            className={cn('relative', className)}
            onMouseEnter={() => setHovered(true)}
            onMouseLeave={() => setHovered(false)}
            onFocus={() => setFocused(true)}
            onBlur={(event) => {
                if (!event.currentTarget.contains(event.relatedTarget as Node | null)) {
                    setFocused(false);
                }
            }}
        >
            <div
                ref={track}
                aria-live={playing && !hovered && !focused ? 'off' : 'polite'}
                className={cn(
                    'scrollbar-none flex snap-x snap-mandatory overflow-x-auto overscroll-x-contain',
                    perView === 'cards' ? 'scroll-px-4 md:scroll-px-0' : '',
                    trackClassName,
                )}
            >
                {slides.map((slide, position) => (
                    <div
                        key={position}
                        role="group"
                        aria-roledescription="diapositiva"
                        aria-label={`${position + 1} de ${slides.length}`}
                        className={cn('snap-start', perView === 'full' ? 'w-full shrink-0' : 'shrink-0', slideClassName)}
                    >
                        {slide}
                    </div>
                ))}
            </div>

            {progress && slides.length > 1 && bar.size < 1 && (
                <div className="mt-3 h-[3px] w-full overflow-hidden rounded-full bg-[var(--line)]" aria-hidden>
                    <div className="h-full rounded-full bg-[var(--text)] transition-[margin] duration-150" style={{ width: `${bar.size * 100}%`, marginLeft: `${bar.start * 100}%` }} />
                </div>
            )}

            {arrows && slides.length > 1 && arrowPlacement === 'top' && (
                <div className="absolute -top-12 right-0 z-10 hidden gap-2 md:flex">
                    <button
                        type="button"
                        onClick={() => go(index - 1)}
                        disabled={perView === 'cards' && edges.start}
                        aria-label="Anterior"
                        className="grid size-10 place-items-center rounded-full border border-[var(--line-2)] text-[var(--text)] transition-colors hover:bg-[var(--surface-2)] disabled:opacity-30"
                    >
                        <CaretLeftIcon size={17} weight="bold" />
                    </button>
                    <button
                        type="button"
                        onClick={() => go(index + 1)}
                        disabled={perView === 'cards' && edges.end}
                        aria-label="Siguiente"
                        className="grid size-10 place-items-center rounded-full border border-[var(--line-2)] text-[var(--text)] transition-colors hover:bg-[var(--surface-2)] disabled:opacity-30"
                    >
                        <CaretRightIcon size={17} weight="bold" />
                    </button>
                </div>
            )}

            {arrows && slides.length > 1 && arrowPlacement === 'side' && (
                <>
                    <button
                        type="button"
                        onClick={() => go(index - 1)}
                        disabled={perView === 'cards' && edges.start}
                        aria-label="Anterior"
                        className="absolute left-2 top-1/2 z-10 hidden size-10 -translate-y-1/2 place-items-center rounded-full border border-[var(--line)] bg-[var(--surface)] text-[var(--text)] shadow-md transition-opacity hover:bg-[var(--surface-2)] disabled:pointer-events-none disabled:opacity-0 md:grid"
                    >
                        <CaretLeftIcon size={18} weight="bold" />
                    </button>
                    <button
                        type="button"
                        onClick={() => go(index + 1)}
                        disabled={perView === 'cards' && edges.end}
                        aria-label="Siguiente"
                        className="absolute right-2 top-1/2 z-10 hidden size-10 -translate-y-1/2 place-items-center rounded-full border border-[var(--line)] bg-[var(--surface)] text-[var(--text)] shadow-md transition-opacity hover:bg-[var(--surface-2)] disabled:pointer-events-none disabled:opacity-0 md:grid"
                    >
                        <CaretRightIcon size={18} weight="bold" />
                    </button>
                </>
            )}

            {perView === 'full' && slides.length > 1 && (dots || autoplay > 0) && (
                <div className="absolute inset-x-0 bottom-2.5 z-10 flex items-center justify-center gap-1.5">
                    {dots &&
                        slides.map((_, position) => (
                            <button
                                key={position}
                                type="button"
                                onClick={() => go(position)}
                                aria-label={`Ir a la lámina ${position + 1}`}
                                aria-current={position === index}
                                className={cn(
                                    'h-2 rounded-full bg-white/70 shadow transition-all',
                                    position === index ? 'w-6 bg-white' : 'w-2 hover:bg-white',
                                )}
                            />
                        ))}
                    {autoplay > 0 && !reduced && (
                        <button
                            type="button"
                            onClick={() => setPaused((current) => !current)}
                            aria-label={paused ? 'Reanudar el carrusel' : 'Pausar el carrusel'}
                            className="ml-1 grid size-6 place-items-center rounded-full bg-black/45 text-white"
                        >
                            {paused ? <PlayIcon size={11} weight="fill" /> : <PauseIcon size={11} weight="fill" />}
                        </button>
                    )}
                </div>
            )}
        </section>
    );
}

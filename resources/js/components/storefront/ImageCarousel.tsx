import { CaretLeftIcon, CaretRightIcon } from '@phosphor-icons/react';
import { type KeyboardEvent, type ReactNode, useCallback, useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/cn';

interface ImageCarouselProps {
    images: string[];
    /** El texto alternativo de cada foto: el nombre del artículo. */
    alt: string;
    /** Lo que se ve sin ninguna foto (el ícono de la categoría). */
    fallback: ReactNode;
    /** Lo que va encima de la foto, fijo (el distintivo de la oferta). */
    overlay?: ReactNode;
    /** Al cambiar (otra presentación elegida) vuelve a la primera foto. */
    resetKey?: string | number | null;
    /** `contain` para artículos sobre fondo blanco; `cover` para fotos que llenan el cuadro. */
    fit?: 'contain' | 'cover';
}

const prefersReducedMotion = () => typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

/**
 * Las fotos de una ficha de la tienda. En el celular se deslizan con el dedo (desplazamiento con
 * imán, sin librerías); en el computador también hay flechas y miniaturas. Con una sola foto es
 * solo la foto.
 */
export function ImageCarousel({ images, alt, fallback, overlay, resetKey, fit = 'contain' }: ImageCarouselProps) {
    const track = useRef<HTMLDivElement>(null);
    const [index, setIndex] = useState(0);
    const count = images.length;
    const many = count > 1;
    const signature = images.join('|');

    const goTo = useCallback(
        (target: number, smooth = true) => {
            const element = track.current;

            if (!element || count === 0) {
                return;
            }

            const next = (target + count) % count;
            element.scrollTo({ left: next * element.clientWidth, behavior: smooth && !prefersReducedMotion() ? 'smooth' : 'auto' });
            setIndex(next);
        },
        [count],
    );

    // Otra presentación u otras fotos: se arranca por la portada.
    useEffect(() => {
        setIndex(0);
        track.current?.scrollTo({ left: 0, behavior: 'auto' });
    }, [resetKey, signature]);

    const onScroll = () => {
        const element = track.current;

        if (element && element.clientWidth > 0) {
            setIndex(Math.min(count - 1, Math.max(0, Math.round(element.scrollLeft / element.clientWidth))));
        }
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (!many) {
            return;
        }

        if (event.key === 'ArrowRight') {
            event.preventDefault();
            goTo(index + 1);
        } else if (event.key === 'ArrowLeft') {
            event.preventDefault();
            goTo(index - 1);
        }
    };

    const imageClass = fit === 'cover' ? 'h-full w-full object-cover' : 'h-full w-full object-contain p-6';

    return (
        <div className="flex flex-col gap-2">
            <div
                className="relative aspect-square overflow-hidden rounded-[var(--store-radius)] bg-white"
                role={many ? 'region' : undefined}
                aria-roledescription={many ? 'carrusel' : undefined}
                aria-label={many ? `Fotos de ${alt}` : undefined}
            >
                {count === 0 ? (
                    fallback
                ) : (
                    <div
                        ref={track}
                        onScroll={many ? onScroll : undefined}
                        onKeyDown={onKeyDown}
                        tabIndex={many ? 0 : undefined}
                        className="flex h-full w-full snap-x snap-mandatory overflow-x-auto overscroll-x-contain [scrollbar-width:none] focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[var(--accent)] [&::-webkit-scrollbar]:hidden"
                    >
                        {images.map((url, position) => (
                            <div
                                key={`${url}-${position}`}
                                className="h-full w-full shrink-0 snap-center"
                                role={many ? 'group' : undefined}
                                aria-roledescription={many ? 'foto' : undefined}
                                aria-label={many ? `${position + 1} de ${count}` : undefined}
                            >
                                <img src={url} alt={position === 0 ? alt : `${alt} · foto ${position + 1}`} loading={position === 0 ? 'eager' : 'lazy'} draggable={false} className={imageClass} />
                            </div>
                        ))}
                    </div>
                )}

                {overlay}

                {many && (
                    <>
                        <button
                            type="button"
                            onClick={() => goTo(index - 1)}
                            aria-label="Foto anterior"
                            className="absolute left-2 top-1/2 hidden size-10 -translate-y-1/2 place-items-center rounded-full border border-[var(--line)] bg-[var(--surface)]/90 text-[var(--text)] shadow transition-colors hover:bg-[var(--surface)] md:grid"
                        >
                            <CaretLeftIcon size={18} weight="bold" />
                        </button>
                        <button
                            type="button"
                            onClick={() => goTo(index + 1)}
                            aria-label="Foto siguiente"
                            className="absolute right-2 top-1/2 hidden size-10 -translate-y-1/2 place-items-center rounded-full border border-[var(--line)] bg-[var(--surface)]/90 text-[var(--text)] shadow transition-colors hover:bg-[var(--surface)] md:grid"
                        >
                            <CaretRightIcon size={18} weight="bold" />
                        </button>

                        <div className="absolute inset-x-0 bottom-2 flex justify-center gap-1.5" aria-hidden="true">
                            {images.map((url, position) => (
                                <span
                                    key={`${url}-dot-${position}`}
                                    className={cn('h-1.5 rounded-full transition-all', position === index ? 'w-5 bg-[var(--accent)]' : 'w-1.5 bg-black/25')}
                                />
                            ))}
                        </div>
                        <p className="sr-only" aria-live="polite">
                            Foto {index + 1} de {count}
                        </p>
                    </>
                )}
            </div>

            {many && (
                <div className="flex gap-2 overflow-x-auto pb-1" role="list" aria-label="Elegir foto">
                    {images.map((url, position) => (
                        <button
                            key={`${url}-thumb-${position}`}
                            type="button"
                            role="listitem"
                            onClick={() => goTo(position)}
                            aria-label={`Ver la foto ${position + 1} de ${count}`}
                            aria-current={position === index || undefined}
                            className={cn(
                                'size-14 shrink-0 overflow-hidden rounded-[var(--store-radius-sm)] border-2 bg-white md:size-16',
                                position === index ? 'border-[var(--accent)]' : 'border-[var(--line)]',
                            )}
                        >
                            <img src={url} alt="" loading="lazy" className={fit === 'cover' ? 'h-full w-full object-cover' : 'h-full w-full object-contain p-1'} />
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

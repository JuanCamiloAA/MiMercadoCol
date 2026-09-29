import { XIcon } from '@phosphor-icons/react';
import { useEffect, useId, useRef, useState, type PointerEvent as ReactPointerEvent, type ReactNode, type RefObject } from 'react';
import { createPortal } from 'react-dom';
import { cn } from '@/lib/cn';
import { useDialog } from './use-dialog';

/** Lo que dura la salida; coincide con la transición de `.store-dialog` en `app.css`. */
const EXIT_MS = 280;
/** Cuánto hay que arrastrar hacia abajo (o qué tan rápido) para que la hoja se cierre. */
const DISMISS_DISTANCE = 110;
const DISMISS_VELOCITY = 0.55;

/**
 * Diálogo de la tienda que no saca al cliente de la página: en el celular es una hoja que sube
 * desde abajo y se cierra deslizándola; en el computador, una ventana centrada.
 *
 * Entra y sale con transición: al cerrar se queda montado lo que dura la salida. En el celular
 * se sube por encima del teclado (`visualViewport`), que en Android ya no encoge la página.
 */
export function StoreDialog({
    open,
    onClose,
    title,
    description,
    children,
    initialFocus,
    className,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    description?: ReactNode;
    children: ReactNode;
    /** El campo que toma el foco al abrir; sin él, el panel. */
    initialFocus?: RefObject<HTMLElement | null>;
    className?: string;
}) {
    const [mounted, setMounted] = useState(open);
    const [shown, setShown] = useState(false);
    const overlay = useRef<HTMLDivElement>(null);
    const panel = useRef<HTMLDivElement>(null);
    const drag = useRef<{ start: number; time: number; offset: number } | null>(null);
    const titleId = useId();
    const descriptionId = useId();

    // Entrar: se monta cerrado y en el cuadro siguiente se abre, para que la transición corra.
    // Salir: se cierra y se desmonta cuando terminó.
    useEffect(() => {
        if (open) {
            setMounted(true);
            let second = 0;
            const first = requestAnimationFrame(() => {
                second = requestAnimationFrame(() => setShown(true));
            });

            return () => {
                cancelAnimationFrame(first);
                cancelAnimationFrame(second);
            };
        }

        setShown(false);
        const timer = setTimeout(() => setMounted(false), EXIT_MS);

        return () => clearTimeout(timer);
    }, [open]);

    useDialog(panel, mounted, onClose, initialFocus);

    // El teclado del celular tapa lo que está pegado abajo: la hoja se sube lo que él ocupe.
    useEffect(() => {
        const viewport = window.visualViewport;

        if (!mounted || !viewport) {
            return;
        }

        const update = () => {
            const inset = Math.max(0, window.innerHeight - viewport.height - viewport.offsetTop);
            overlay.current?.style.setProperty('--keyboard', `${Math.round(inset)}px`);
        };

        update();
        viewport.addEventListener('resize', update);
        viewport.addEventListener('scroll', update);

        return () => {
            viewport.removeEventListener('resize', update);
            viewport.removeEventListener('scroll', update);
        };
    }, [mounted]);

    const isDesktop = () => window.matchMedia('(min-width: 48rem)').matches;

    const startDrag = (event: ReactPointerEvent<HTMLDivElement>) => {
        if (isDesktop() || event.button !== 0 || (event.target as HTMLElement).closest('button, a, input')) {
            return;
        }

        drag.current = { start: event.clientY, time: performance.now(), offset: 0 };
        event.currentTarget.setPointerCapture(event.pointerId);
        panel.current?.style.setProperty('transition', 'none');
    };

    const moveDrag = (event: ReactPointerEvent<HTMLDivElement>) => {
        if (!drag.current || !panel.current) {
            return;
        }

        // Hacia arriba no se estira: solo baja.
        drag.current.offset = Math.max(0, event.clientY - drag.current.start);
        panel.current.style.transform = `translateY(${drag.current.offset}px)`;
    };

    const endDrag = () => {
        const moved = drag.current;
        drag.current = null;

        if (!moved || !panel.current) {
            return;
        }

        // Se suelta el estilo en línea: la transición lleva la hoja desde donde quedó, hacia
        // abajo si se cierra o de vuelta a su sitio si no.
        panel.current.style.removeProperty('transition');
        panel.current.style.removeProperty('transform');

        const velocity = moved.offset / Math.max(1, performance.now() - moved.time);

        if (moved.offset > DISMISS_DISTANCE || (moved.offset > 24 && velocity > DISMISS_VELOCITY)) {
            onClose();
        }
    };

    if (!mounted || typeof document === 'undefined') {
        return null;
    }

    const state = shown ? 'open' : 'closed';

    return createPortal(
        <div
            ref={overlay}
            className="fixed inset-0 z-[75] flex items-end justify-center pb-[var(--keyboard,0px)] md:items-center md:p-6"
            data-store-overlay=""
        >
            <div className="store-dialog-backdrop absolute inset-0 bg-black/50" data-state={state} onClick={onClose} aria-hidden />
            <div
                ref={panel}
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                aria-describedby={description ? descriptionId : undefined}
                tabIndex={-1}
                data-state={state}
                className={cn(
                    'store-dialog relative flex max-h-[calc(100dvh-var(--keyboard,0px)-1.5rem)] w-full flex-col bg-[var(--surface)] text-[var(--text)] shadow-2xl outline-none',
                    'rounded-t-[24px] pb-[env(safe-area-inset-bottom)] md:max-h-[calc(100dvh-3rem)] md:max-w-[440px] md:rounded-[20px] md:pb-0',
                    className,
                )}
            >
                {/* La agarradera: de aquí se arrastra la hoja hacia abajo para cerrarla. */}
                <div className="shrink-0 touch-none select-none md:touch-auto" onPointerDown={startDrag} onPointerMove={moveDrag} onPointerUp={endDrag} onPointerCancel={endDrag}>
                    <div className="flex justify-center pt-2.5 md:hidden" aria-hidden>
                        <span className="h-1.5 w-11 rounded-full bg-[var(--line)]" />
                    </div>
                    <div className="flex items-start justify-between gap-3 px-5 pb-1 pt-3 md:px-7 md:pt-6">
                        <div className="min-w-0">
                            <h2 id={titleId} className="text-[21px] leading-tight md:text-[23px]">
                                {title}
                            </h2>
                            {description && (
                                <p id={descriptionId} className="mt-1 text-[13.5px] leading-snug text-[var(--muted)]">
                                    {description}
                                </p>
                            )}
                        </div>
                        <button
                            type="button"
                            onClick={onClose}
                            aria-label="Cerrar"
                            className="-mr-2 -mt-1 grid size-10 shrink-0 place-items-center rounded-full text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]"
                        >
                            <XIcon size={20} />
                        </button>
                    </div>
                </div>
                <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 pb-5 pt-3 md:px-7 md:pb-7">{children}</div>
            </div>
        </div>,
        document.querySelector('[data-store-shell]') ?? document.body,
    );
}

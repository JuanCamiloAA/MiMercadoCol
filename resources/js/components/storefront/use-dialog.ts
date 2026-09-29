import { useEffect, useRef, type RefObject } from 'react';

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';

/**
 * Lo que hace de un panel un diálogo de verdad: toma el foco al abrir y lo devuelve al cerrar,
 * se cierra con Esc, el foco no se sale con Tab y lo que queda detrás no se desplaza.
 *
 * `onClose` se lee de una referencia: si cambiara de identidad en cada render (una flecha en
 * línea), el efecto volvería a correr con cada tecla y le robaría el foco al campo.
 */
export function useDialog(panel: RefObject<HTMLElement | null>, active: boolean, onClose: () => void, initialFocus?: RefObject<HTMLElement | null>): void {
    const close = useRef(onClose);

    useEffect(() => {
        close.current = onClose;
    }, [onClose]);

    useEffect(() => {
        if (!active) {
            return;
        }

        const previous = document.activeElement as HTMLElement | null;
        const overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        (initialFocus?.current ?? panel.current)?.focus({ preventScroll: true });

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                close.current();
            }

            if (event.key === 'Tab' && panel.current) {
                const focusable = panel.current.querySelectorAll<HTMLElement>(FOCUSABLE);

                if (focusable.length === 0) {
                    return;
                }

                const first = focusable[0];
                const last = focusable[focusable.length - 1];

                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        };

        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = overflow;
            previous?.focus?.({ preventScroll: true });
        };
    }, [active, panel, initialFocus]);
}

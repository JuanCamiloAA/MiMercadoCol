import { XIcon } from '@phosphor-icons/react';
import { useId, useRef, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { cn } from '@/lib/cn';
import { useDialog } from './use-dialog';

/**
 * Hoja de la tienda: sube desde abajo en el celular y sale de un lado en el computador.
 *
 * Es un diálogo de verdad: toma el foco al abrir, lo devuelve al cerrar, se cierra con Esc o
 * tocando afuera, y lo que queda detrás no se desplaza.
 */
export function Sheet({
    open,
    onClose,
    title,
    children,
    footer,
    side = 'bottom',
    className,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    children: ReactNode;
    footer?: ReactNode;
    /** `bottom`: hoja en celular y panel lateral izquierdo en computador. `right`: cajón a la derecha. */
    side?: 'bottom' | 'right' | 'left';
    className?: string;
}) {
    const panel = useRef<HTMLDivElement>(null);
    const titleId = useId();

    useDialog(panel, open, onClose);

    if (!open || typeof document === 'undefined') {
        return null;
    }

    return createPortal(
        <div className="fixed inset-0 z-[70]" data-store-overlay="">
            <div className="absolute inset-0 bg-black/45 animate-fade-in" onClick={onClose} aria-hidden />
            <div
                ref={panel}
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                tabIndex={-1}
                className={cn(
                    'absolute flex max-h-[88vh] flex-col bg-[var(--surface)] text-[var(--text)] shadow-2xl outline-none',
                    side === 'bottom' &&
                        'inset-x-0 bottom-0 rounded-t-[20px] animate-sheet-in md:inset-y-0 md:left-0 md:right-auto md:max-h-none md:w-[420px] md:rounded-none md:rounded-r-[20px] md:animate-drawer-in',
                    side === 'right' && 'inset-x-0 bottom-0 rounded-t-[20px] animate-sheet-in md:inset-y-0 md:left-auto md:right-0 md:max-h-none md:w-[440px] md:rounded-none md:rounded-l-[20px] md:animate-drawer-in',
                    side === 'left' && 'inset-y-0 left-0 w-[88vw] max-w-[420px] rounded-r-[20px] animate-drawer-in',
                    className,
                )}
            >
                <div className="flex shrink-0 items-center justify-between gap-3 border-b border-[var(--line)] px-4 py-3">
                    <h2 id={titleId} className="text-[17px] leading-tight">
                        {title}
                    </h2>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Cerrar"
                        className="grid size-10 place-items-center rounded-full text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]"
                    >
                        <XIcon size={20} />
                    </button>
                </div>
                <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain">{children}</div>
                {footer && <div className="shrink-0 border-t border-[var(--line)] p-4">{footer}</div>}
            </div>
        </div>,
        document.querySelector('[data-store-shell]') ?? document.body,
    );
}

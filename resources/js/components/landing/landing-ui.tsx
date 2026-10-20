import { cn } from '@/lib/cn';
import type { ReactNode } from 'react';

/**
 * Lo que comparten los bloques de la landing, repartidos en varios archivos (`LandingBlocks`,
 * `LandingMedia`, `AppDemo`): leer los datos del bloque y el marco de una sección.
 */

// Cada bloque trae su forma como `Record<string, unknown>`; se lee con estos ayudantes.
export const text = (value: unknown): string => (typeof value === 'string' ? value : '');
export const bool = (value: unknown): boolean => value === true;
export const list = <T,>(value: unknown): T[] => (Array.isArray(value) ? (value as T[]) : []);

/*
 * El espacio, el ancho y el fondo de cada bloque los puede cambiar la pestaña «Diseño» de la
 * sección (`SectionShell`, `sections.css`): los bloques los leen de `--sec-pt`, `--sec-pb`,
 * `--sec-px`, `--sec-max` y `--sec-own-bg`, con su valor de siempre de respaldo. Sin tocar «Diseño»
 * esas variables no existen y cada bloque se ve como antes.
 */

/** La banda gris de algunas secciones: se apaga sola cuando la sección trae fondo propio. */
export const BAND_BG = 'bg-[var(--sec-own-bg,var(--surface-2))]';

export function Section({ id, children, className }: { id?: string; children: ReactNode; className?: string }) {
    return (
        <section
            id={id}
            className={cn(
                'scroll-mt-20 px-[var(--sec-px,1rem)] pt-[var(--sec-pt,3.5rem)] pb-[var(--sec-pb,3.5rem)] md:pt-[var(--sec-pt,5rem)] md:pb-[var(--sec-pb,5rem)]',
                className,
            )}
        >
            <div className="mx-auto w-full max-w-[var(--sec-max,70rem)]">{children}</div>
        </section>
    );
}

export function SectionTitle({ title, body, center = true }: { title: string; body?: string; center?: boolean }) {
    if (!title && !body) {
        return null;
    }

    return (
        <div className={cn('mb-8 max-w-[44rem]', center && 'mx-auto text-center')}>
            {title && <h2 className="text-[1.75rem] leading-tight md:text-[2.25rem]">{title}</h2>}
            {body && <p className="mt-3 text-[1rem] text-[var(--muted)]">{body}</p>}
        </div>
    );
}

/** Lo que pinta la vista previa del editor cuando un bloque todavía no tiene con qué mostrarse. */
export function PreviewNotice({ children, className }: { children: ReactNode; className?: string }) {
    return (
        <div
            className={cn(
                'rounded-sheet grid place-items-center border-2 border-dashed border-[var(--line-2)] p-6 text-center text-[0.9375rem] text-[var(--muted)]',
                className,
            )}
        >
            {children}
        </div>
    );
}

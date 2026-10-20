import type { ReactNode } from 'react';
import { StoreLink } from '@/components/storefront/store-context';
import type { MediaPayload, Target } from '@/types/storefront';

/** Lo que recibe cada bloque: sus datos ya resueltos por el servidor. */
export interface BlockProps {
    data: Record<string, unknown>;
    /** El primer banner de la página pide prioridad; los demás esperan. */
    priority?: boolean;
    /** En la vista previa (borrador) se ven también las láminas fuera de vigencia. */
    draft?: boolean;
}

export type { MediaPayload, Target };

export function text(value: unknown): string {
    return typeof value === 'string' ? value : '';
}

/** Un enlace a un destino ya resuelto; sin destino es un bloque quieto. */
export function TargetLink({
    target,
    className,
    children,
    ariaLabel,
    heroFill = false,
}: {
    target: Target | null | undefined;
    className?: string;
    children: ReactNode;
    ariaLabel?: string;
    heroFill?: boolean;
}) {
    return (
        <StoreLink href={target?.href ?? null} className={className} ariaLabel={ariaLabel} heroFill={heroFill}>
            {children}
        </StoreLink>
    );
}

/** Los tonos de color que ofrecen los bloques, con su tinta legible. */
export const TONES: Record<string, string> = {
    accent: 'bg-[var(--accent)] text-[var(--accent-ink)]',
    deal: 'bg-[var(--deal)] text-[var(--deal-ink)]',
    dark: 'bg-[var(--brand-dark)] text-white',
    soft: 'bg-[var(--accent-soft)] text-[var(--text)]',
    warn: 'bg-[var(--warn-soft)] text-[var(--warn)]',
};

/** El título de sección de los bloques. */
export function BlockTitle({ children }: { children: ReactNode }) {
    return <h2 className="mb-3 text-[19px] leading-tight md:text-[23px]">{children}</h2>;
}

import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/cn';
import type { PageProps } from '@/types';

interface BrandMarkProps {
    /** Tamano y esquina del cuadro, p. ej. `size-9 rounded-control`. */
    className?: string;
    /** Tamano del dibujo de la canasta dentro del cuadro. */
    iconClassName?: string;
    /**
     * Color del trazo de la canasta. De fabrica es blanco en el panel (`--brand-mark-ink`) y la
     * letra del acento en la caja.
     */
    ink?: string;
    strokeWidth?: number;
    /** Para la vista previa de Ajustes: la imagen del borrador, o null para ver la canasta. */
    src?: string | null;
}

/**
 * El isotipo de la empresa: la imagen que se subio en Ajustes o, sin ella, la canasta de siempre
 * sobre el color de la marca.
 */
export function BrandMark({ className, iconClassName = 'size-5', ink = 'var(--brand-mark-ink)', strokeWidth = 2.4, src }: BrandMarkProps) {
    const brand = usePage<PageProps>().props.brand ?? null;
    const image = src === undefined ? (brand?.mark_url ?? null) : src;

    if (image) {
        return <img src={image} alt="" className={cn('shrink-0 object-contain', className)} />;
    }

    return (
        <span className={cn('grid shrink-0 place-items-center bg-[var(--accent)]', className)}>
            <svg
                viewBox="0 0 32 32"
                className={iconClassName}
                fill="none"
                stroke={ink}
                strokeWidth={strokeWidth}
                strokeLinejoin="round"
                aria-hidden="true"
            >
                <path d="M7 11h18l-2.2 13H9.2L7 11Z" />
                <path d="M12 11a4 4 0 0 1 8 0" />
            </svg>
        </span>
    );
}

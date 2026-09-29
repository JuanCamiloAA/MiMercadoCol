import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import type { PageProps } from '@/types';
import { BrandMark } from './BrandMark';

interface BrandLogoProps {
    /** El fondo donde va: sobre oscuro se prefiere el logo para fondo oscuro. */
    onDark: boolean;
    /** Segundo renglon cuando no hay logo (la empresa activa, en el menu). */
    subtitle?: ReactNode;
    className?: string;
}

/**
 * El logo horizontal segun el fondo: el de fondo oscuro si el menu o el tema son oscuros, con
 * respaldo en el claro. Sin logo, isotipo + nombre + subtitulo, como siempre se vio.
 */
export function BrandLogo({ onDark, subtitle, className }: BrandLogoProps) {
    const brand = usePage<PageProps>().props.brand ?? null;
    const logo = onDark ? (brand?.logo_dark_url ?? brand?.logo_light_url) : (brand?.logo_light_url ?? brand?.logo_dark_url);
    const name = brand?.display_name ?? 'La Canasta';

    if (logo) {
        return (
            <div className={cn('flex min-w-0 items-center', className)} title={typeof subtitle === 'string' ? subtitle : undefined}>
                <img src={logo} alt={name} className="h-9 max-w-full object-contain object-left" />
            </div>
        );
    }

    return (
        <div className={cn('flex min-w-0 items-center gap-2.5', className)}>
            <BrandMark className="size-9 rounded-control" />
            <div className="min-w-0">
                <p className="truncate text-[0.875rem] font-semibold leading-tight text-[var(--text)]">{name}</p>
                {subtitle && <p className="truncate text-[0.6875rem] text-[var(--muted)]">{subtitle}</p>}
            </div>
        </div>
    );
}

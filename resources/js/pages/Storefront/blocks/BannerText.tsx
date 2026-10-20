import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { overlayStyle } from './banner-style';

/**
 * El texto sobre la foto de un banner chico (lateral del banner principal o del mosaico). Sin
 * diseño propio va abajo, con letra clara sobre el degradado oscuro de siempre. Con diseño, donde
 * y con el velo que se eligió en «Diseño de este banner».
 */
export function BannerText({
    light,
    overlay,
    shape,
    position,
    className,
    children,
}: {
    light: boolean;
    overlay?: number | null;
    shape?: string;
    position: string | null;
    /** El relleno de la caja del texto. */
    className?: string;
    children: ReactNode;
}) {
    const custom = typeof overlay === 'number' || Boolean(shape) || position !== null || !light;

    if (!custom) {
        return <span className={cn('absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent text-white', className)}>{children}</span>;
    }

    const opacity = Math.max(0, Math.min(60, typeof overlay === 'number' ? overlay : 40)) / 100;

    return (
        <>
            <span className="absolute inset-0" style={overlayStyle(shape ?? 'bottom', opacity, light) ?? undefined} aria-hidden />
            <span className={cn('absolute inset-0', position ?? 'flex flex-col justify-end', light ? 'text-white' : 'text-[#111]', className)}>{children}</span>
        </>
    );
}

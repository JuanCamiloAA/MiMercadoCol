import type { CSSProperties } from 'react';
import { cn } from '@/lib/cn';
import type { MediaPayload } from '@/types/storefront';

/**
 * Una imagen de banner con su versión de celular.
 *
 * Es un `<picture>`: en celular baja la imagen de celular si la hay; si no, la de computador
 * recortada por su punto focal. Solo el primer banner de la página pide prioridad al navegador;
 * los demás esperan a que se acerquen a la pantalla.
 */
export function BannerImage({
    media,
    mobile,
    alt,
    priority = false,
    className,
    sizes = '(max-width: 767px) 100vw, 1180px',
}: {
    media: MediaPayload | null | undefined;
    mobile?: MediaPayload | null;
    alt?: string | null;
    priority?: boolean;
    className?: string;
    sizes?: string;
}) {
    if (!media) {
        return null;
    }

    const position = (item: MediaPayload) => `${Math.round((item.focal_x ?? 0.5) * 100)}% ${Math.round((item.focal_y ?? 0.5) * 100)}%`;

    const style = {
        '--pos-d': position(media),
        '--pos-m': position(mobile ?? media),
    } as CSSProperties;

    return (
        <picture>
            {mobile && <source media="(max-width: 767px)" srcSet={mobile.srcset || mobile.url} sizes="100vw" />}
            <img
                src={media.url}
                srcSet={media.srcset || undefined}
                sizes={sizes}
                alt={alt ?? media.alt ?? ''}
                width={media.width ?? undefined}
                height={media.height ?? undefined}
                loading={priority ? 'eager' : 'lazy'}
                fetchPriority={priority ? 'high' : 'auto'}
                decoding={priority ? 'sync' : 'async'}
                style={style}
                className={cn('h-full w-full object-cover [object-position:var(--pos-m)] md:[object-position:var(--pos-d)]', className)}
            />
        </picture>
    );
}

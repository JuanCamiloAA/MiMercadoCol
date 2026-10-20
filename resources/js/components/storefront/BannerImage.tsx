import type { CSSProperties } from 'react';
import { useDocumentTheme } from '@/hooks/use-document-theme';
import { cn } from '@/lib/cn';
import { pickWith } from '@/lib/media-variants';
import type { MediaPayload } from '@/types/storefront';

/** Las cuatro versiones de una imagen de banner, como las manda el servidor. */
export interface BannerMedia {
    media: MediaPayload | null | undefined;
    mobile?: MediaPayload | null;
    dark?: MediaPayload | null;
    mobileDark?: MediaPayload | null;
}

/**
 * Las versiones de `base` de un elemento del servidor (`slide.media`, `slide.media_mobile`,
 * `slide.media_dark`, `slide.media_mobile_dark`), listas para `<BannerImage {...} />`.
 */
export function bannerMedia(item: object | null | undefined, base = 'media'): BannerMedia {
    const record = (item ?? {}) as Record<string, MediaPayload | null | undefined>;

    return {
        media: record[base] ?? null,
        mobile: record[`${base}_mobile`] ?? null,
        dark: record[`${base}_dark`] ?? null,
        mobileDark: record[`${base}_mobile_dark`] ?? null,
    };
}

/**
 * Una imagen de banner con su versión de celular y, si las hay, las de modo oscuro.
 *
 * Es un `<picture>`: en celular baja la imagen de celular si la hay; si no, la de computador
 * recortada por su punto focal. El modo lo dice la página (`data-theme`): en oscuro van las
 * oscuras y, si no hay, las claras (`media-variants.ts`). Solo el primer banner de la página pide
 * prioridad al navegador; los demás esperan a que se acerquen a la pantalla.
 */
export function BannerImage({
    media,
    mobile,
    dark,
    mobileDark,
    alt,
    priority = false,
    className,
    sizes = '(max-width: 767px) 100vw, 1180px',
}: BannerMedia & {
    alt?: string | null;
    priority?: boolean;
    className?: string;
    sizes?: string;
}) {
    const scheme = useDocumentTheme();
    const versions: Record<string, MediaPayload | null | undefined> = { '': media, _mobile: mobile, _dark: dark, _mobile_dark: mobileDark };
    const picked = pickWith<MediaPayload>((suffix) => versions[suffix], scheme);
    const desktop = picked.desktop;
    const phone = picked.mobile;

    if (!desktop) {
        return null;
    }

    const position = (item: MediaPayload) => `${Math.round((item.focal_x ?? 0.5) * 100)}% ${Math.round((item.focal_y ?? 0.5) * 100)}%`;

    const style = {
        '--pos-d': position(desktop),
        '--pos-m': position(phone ?? desktop),
    } as CSSProperties;

    return (
        // `key`: al cambiar de modo el `<picture>` se arma de nuevo; un `<source>` cambiado en sitio no siempre se vuelve a evaluar.
        <picture key={`${desktop.url}|${phone?.url ?? ''}`}>
            {phone && <source media="(max-width: 767px)" srcSet={phone.srcset || phone.url} sizes="100vw" />}
            <img
                src={desktop.url}
                srcSet={desktop.srcset || undefined}
                sizes={sizes}
                alt={alt ?? desktop.alt ?? ''}
                width={desktop.width ?? undefined}
                height={desktop.height ?? undefined}
                loading={priority ? 'eager' : 'lazy'}
                fetchPriority={priority ? 'high' : 'auto'}
                decoding={priority ? 'sync' : 'async'}
                style={style}
                className={cn('h-full w-full object-cover [object-position:var(--pos-m)] md:[object-position:var(--pos-d)]', className)}
            />
        </picture>
    );
}

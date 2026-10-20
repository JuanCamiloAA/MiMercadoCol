import { useDocumentTheme } from '@/hooks/use-document-theme';
import { MOBILE_QUERY, useMediaQuery } from '@/hooks/use-media-query';
import { pickUrls, pickVariants } from '@/lib/media-variants';

/**
 * La versión de una imagen o un video que toca ahora: la del modo de la página y la del ancho de
 * la pantalla (`media-variants.ts`). Es para lo que no se puede escoger con `<picture>`: los
 * videos y las imágenes que se pintan contenidas.
 */
export function useMediaVariant<T>(item: object | null | undefined, base: string): T | null {
    const scheme = useDocumentTheme();
    const mobile = useMediaQuery(MOBILE_QUERY);
    const picked = pickVariants<T>(item, base, scheme);

    return (mobile ? picked.mobile : null) ?? picked.desktop;
}

/** Lo mismo con las direcciones de la landing (`video_url`, `video_mobile_url`, `video_dark_url`…). */
export function useUrlVariant(item: object | null | undefined, base: string): string | null {
    const scheme = useDocumentTheme();
    const mobile = useMediaQuery(MOBILE_QUERY);
    const picked = pickUrls(item, base, scheme);

    return (mobile ? picked.mobile : null) ?? picked.desktop;
}

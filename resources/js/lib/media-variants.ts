/**
 * Las cuatro versiones de una imagen o un video de banner (`App\Support\MediaVariants`):
 * computador y celular, en modo claro y en modo oscuro.
 *
 * El servidor las manda con el nombre del campo y un sufijo: `media`, `media_mobile`,
 * `media_dark`, `media_mobile_dark` (o `video_*`, `poster_*`; en la landing `image_url`,
 * `image_mobile_url`…). Ninguna es obligatoria:
 *
 * - En claro va la de computador y, en celular, la de celular si la hay.
 * - En oscuro, si hay alguna oscura, manda el modo: la oscura de celular, si no la oscura de
 *   computador recortada. Si no hay ninguna oscura, las claras.
 */

export type Scheme = 'light' | 'dark';

export interface PickedVariants<T> {
    desktop: T | null;
    mobile: T | null;
}

type Getter<T> = (suffix: '' | '_mobile' | '_dark' | '_mobile_dark') => T | null | undefined;

/** Elige con una función que lee cada versión (sirve para objetos y para direcciones sueltas). */
export function pickWith<T>(get: Getter<T>, scheme: Scheme): PickedVariants<T> {
    const light = { desktop: get('') ?? null, mobile: get('_mobile') ?? null };
    const dark = { desktop: get('_dark') ?? null, mobile: get('_mobile_dark') ?? null };

    if (scheme === 'dark' && (dark.desktop || dark.mobile)) {
        return { desktop: dark.desktop ?? light.desktop, mobile: dark.mobile ?? (dark.desktop ? null : light.mobile) };
    }

    return { desktop: light.desktop ?? dark.desktop, mobile: light.mobile };
}

/** Las versiones de `base` dentro de un objeto del servidor (`slide.media`, `slide.media_dark`…). */
export function pickVariants<T>(item: object | null | undefined, base: string, scheme: Scheme): PickedVariants<T> {
    const record = (item ?? {}) as Record<string, unknown>;

    return pickWith<T>((suffix) => (record[`${base}${suffix}`] as T | null | undefined) ?? null, scheme);
}

/** Las versiones de la landing, que llegan como direcciones: `image_url`, `image_mobile_url`, `image_dark_url`… */
export function pickUrls(item: object | null | undefined, base: string, scheme: Scheme): PickedVariants<string> {
    const record = (item ?? {}) as Record<string, unknown>;

    return pickWith<string>((suffix) => {
        const value = record[`${base}${suffix}_url`];

        return typeof value === 'string' && value !== '' ? value : null;
    }, scheme);
}

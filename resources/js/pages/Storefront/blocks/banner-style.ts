import type { CSSProperties } from 'react';

/**
 * El diseño de cada banner (`item.style`, `App\Storefront\Blocks\BannerStyle`): proporción, dónde
 * va el texto, tamaño del título, forma del velo y estilo del botón. Una clave ausente es `auto`:
 * el bloque pinta lo que pintaba antes, así un banner que nadie tocó se ve igual.
 */

export interface BannerStyle {
    ratio?: 'square' | 'portrait' | 'tall' | 'landscape' | 'wide';
    text_position?: string;
    title_size?: 'sm' | 'md' | 'lg' | 'xl' | 'display';
    overlay_style?: 'flat' | 'bottom' | 'top';
    button_style?: 'solid' | 'outline' | 'pill_white' | 'link';
}

export function bannerStyle(value: unknown): BannerStyle {
    return value && typeof value === 'object' && !Array.isArray(value) ? (value as BannerStyle) : {};
}

/**
 * La lámina con el diseño del modo de la página: en oscuro, lo que cambia en `item.dark` (color
 * del texto, velo y `style`) encima de lo de claro. Lo que no cambió allá vale lo de claro.
 */
export function themedItem<T extends object>(item: T, scheme: 'light' | 'dark'): T {
    const dark = (item as { dark?: unknown }).dark;

    if (scheme !== 'dark' || !dark || typeof dark !== 'object' || Array.isArray(dark)) {
        return item;
    }

    const overrides = dark as Record<string, unknown>;
    const style = { ...bannerStyle((item as { style?: unknown }).style), ...bannerStyle(overrides.style) };

    return { ...item, ...overrides, style };
}

/** La proporción, o `null` para dejar la del bloque. */
export const RATIO_CLASSES: Record<string, string> = {
    square: 'aspect-square',
    portrait: 'aspect-[3/4]',
    tall: 'aspect-[30/42]',
    landscape: 'aspect-video',
    wide: 'aspect-[21/9]',
};

const VERTICAL: Record<string, string> = { top: 'justify-start', middle: 'justify-center', bottom: 'justify-end' };
const HORIZONTAL: Record<string, string> = { left: 'items-start text-left', center: 'items-center text-center', right: 'items-end text-right' };

/**
 * Las clases de la caja del texto (una columna que llena el banner) según la posición elegida.
 * `null` si no se eligió: el bloque usa su ubicación de siempre.
 */
export function positionClasses(position: string | undefined): string | null {
    if (!position || !position.includes('-')) {
        return null;
    }

    const [vertical, horizontal] = position.split('-');

    return `flex flex-col ${VERTICAL[vertical] ?? 'justify-end'} ${HORIZONTAL[horizontal] ?? HORIZONTAL.left}`;
}

const INNER: Record<string, string> = { left: 'items-start', center: 'items-center', right: 'items-end' };

/** La alineación de lo que va dentro de la caja del texto (el botón no se estira). */
export function innerAlign(position: string | undefined): string | null {
    return position && position.includes('-') ? (INNER[position.split('-')[1]] ?? null) : null;
}

/** El tamaño del título; `display` es el título gigante en mayúsculas. */
export const TITLE_CLASSES: Record<string, string> = {
    sm: 'text-[18px] md:text-[22px]',
    md: 'text-[22px] md:text-[30px]',
    lg: 'text-[26px] md:text-[44px]',
    xl: 'text-[32px] md:text-[58px]',
    display: 'text-[44px] uppercase leading-[0.9] md:text-[92px]',
};

/**
 * Las clases del título: el tamaño elegido o el del bloque, con el interlineado **después**.
 * `cn` (tailwind-merge) borra un `leading-*` que venga antes de un tamaño de letra; puesto al
 * final se conserva, y el título de siempre queda con su interlineado de siempre. «Gigante» trae
 * el suyo.
 */
export function titleClass(size: string | undefined, fallback: string, leading: string): string {
    if (size && TITLE_CLASSES[size]) {
        return size === 'display' ? TITLE_CLASSES[size] : `${TITLE_CLASSES[size]} ${leading}`;
    }

    return `${fallback} ${leading}`;
}

/**
 * El velo con la forma elegida. `opacity` de 0 a 0,6; la letra clara pide velo oscuro y la oscura,
 * claro. `null` si no se eligió forma: el bloque usa su degradado de siempre.
 */
export function overlayStyle(shape: string | undefined, opacity: number, light: boolean): CSSProperties | null {
    if (!shape) {
        return null;
    }

    const color = light ? '0,0,0' : '255,255,255';
    const strong = Math.min(0.85, opacity + 0.2);

    switch (shape) {
        case 'flat':
            return { background: `rgba(${color},${opacity})` };
        case 'top':
            return { background: `linear-gradient(180deg, rgba(${color},${strong}) 0%, rgba(${color},${opacity}) 35%, rgba(${color},0) 75%)` };
        default:
            return { background: `linear-gradient(0deg, rgba(${color},${strong}) 0%, rgba(${color},${opacity}) 35%, rgba(${color},0) 75%)` };
    }
}

/** El botón según su estilo; `null` deja el del bloque. */
export function buttonClasses(kind: string | undefined, light: boolean): string | null {
    switch (kind) {
        case 'solid':
            return 'rounded-full bg-[var(--accent)] px-5 py-2.5 text-[var(--accent-ink)] shadow-lg';
        case 'outline':
            return light ? 'rounded-full border-2 border-white px-5 py-2 text-white' : 'rounded-full border-2 border-[#111] px-5 py-2 text-[#111]';
        case 'pill_white':
            return 'rounded-full bg-white px-6 py-2.5 text-[#14171A] shadow-lg';
        case 'link':
            return 'border-b-2 border-current pb-0.5 text-[13px] uppercase tracking-[0.12em]';
        default:
            return null;
    }
}

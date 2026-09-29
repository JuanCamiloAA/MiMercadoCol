/**
 * Aritmetica de color de la marca.
 *
 * Repite, cuenta por cuenta, `App\Support\Color` y `BrandThemeService::resolveAccent` del
 * servidor. Existe para avisar mientras se arrastra el selector sin ir y volver por la red; el
 * servidor vuelve a calcular al guardar. Si una cambia, cambia la otra: si no, la vista previa
 * promete un color y el servidor guarda otro. `ColorParityTest` corre los mismos casos en los
 * dos lados.
 *
 * Sin alias ni dependencias: la prueba de paridad lo importa directo con Node.
 */

export const DARK_INK = '#10161C';

export const FACTORY_ACCENT = '#0E6E4E';

export const MIN_CONTRAST = 4.5;

export interface SurfacePalette {
    surface: string;
    bg: string;
}

export const LIGHT: SurfacePalette = { surface: '#FFFFFF', bg: '#F4F4F1' };

export const DARK: SurfacePalette = { surface: '#151A1D', bg: '#0C1012' };

export const SOFT_WEIGHT = { light: 0.12, dark: 0.2 } as const;

export interface ThemeTokens {
    accent: string;
    ink: string;
    soft: string;
    muted: string;
    /** El peor contraste del acento como texto sobre superficie, fondo y tinte. */
    contrast: number;
}

/** Redondeo a dos decimales igual al `round($x, 2)` de PHP (mitad hacia arriba). */
function round2(value: number): number {
    return Math.round((value + Number.EPSILON) * 100) / 100;
}

export function isHex(value: unknown): value is string {
    return typeof value === 'string' && /^#[0-9A-Fa-f]{6}$/.test(value);
}

/** `#abc` o `abc` a `#AABBCC`. Lo que no sea un color devuelve negro. */
export function normalize(hex: string): string {
    let clean = hex.trim().replace(/^#+/, '');

    if (clean.length === 3) {
        clean = clean
            .split('')
            .map((char) => char + char)
            .join('');
    }

    if (!/^[0-9A-Fa-f]{6}$/.test(clean)) {
        return '#000000';
    }

    return `#${clean.toUpperCase()}`;
}

function channels(hex: string): [number, number, number] {
    const clean = normalize(hex).slice(1);

    return [parseInt(clean.slice(0, 2), 16), parseInt(clean.slice(2, 4), 16), parseInt(clean.slice(4, 6), 16)];
}

/** Luminancia relativa segun WCAG. */
export function luminance(hex: string): number {
    const linear = (value: number) => {
        const channel = value / 255;

        return channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
    };

    const [r, g, b] = channels(hex);

    return 0.2126 * linear(r) + 0.7152 * linear(g) + 0.0722 * linear(b);
}

/** Contraste real entre dos colores. */
export function contrast(a: string, b: string): number {
    const first = luminance(a);
    const second = luminance(b);

    return round2((Math.max(first, second) + 0.05) / (Math.min(first, second) + 0.05));
}

/** Mezcla lineal en sRGB; t = 0 devuelve a, t = 1 devuelve b. */
export function mix(a: string, b: string, t: number): string {
    const weight = Math.max(0, Math.min(1, t));
    const from = channels(a);
    const to = channels(b);

    // `Math.round` de JS redondea -0,5 hacia arriba y el de PHP hacia abajo; con canales
    // positivos y el mismo orden de operaciones dan lo mismo.
    const mixed = from.map((value, index) => Math.round(value + (to[index] - value) * weight));

    return `#${mixed.map((value) => value.toString(16).padStart(2, '0').toUpperCase()).join('')}`;
}

/** Letra encima del color: blanca o #10161C, la que mejor se lea. */
export function ink(hex: string): string {
    const value = luminance(hex);

    return 1.05 / (value + 0.05) >= (value + 0.05) / 0.05 ? '#FFFFFF' : DARK_INK;
}

export function worstContrast(accent: string, palette: SurfacePalette, softWeight: number): number {
    const soft = mix(palette.surface, accent, softWeight);

    return Math.min(contrast(accent, palette.surface), contrast(accent, palette.bg), contrast(accent, soft));
}

/**
 * Acerca el color de marca a negro (tema claro) o a blanco (tema oscuro) en pasos de 5 % hasta
 * que se lea como texto sobre la superficie, el fondo y su propio tinte suave.
 */
export function resolveAccent(accent: string, palette: SurfacePalette, toward: string, softWeight: number): string {
    for (let step = 0; step <= 20; step++) {
        const candidate = mix(accent, toward, step / 20);

        if (worstContrast(candidate, palette, softWeight) >= MIN_CONTRAST) {
            return candidate;
        }
    }

    return normalize(toward);
}

/** El gris del menu del color de la marca; si no llega a 4,5:1 va la letra entera. */
export function sidebarMuted(accent: string, letter: string): string {
    const muted = mix(accent, letter, 0.8);

    return contrast(muted, accent) >= MIN_CONTRAST ? muted : letter;
}

function tokens(accent: string, palette: SurfacePalette, softWeight: number): ThemeTokens {
    const letter = ink(accent);

    return {
        accent,
        ink: letter,
        soft: mix(palette.surface, accent, softWeight),
        muted: sidebarMuted(accent, letter),
        contrast: worstContrast(accent, palette, softWeight),
    };
}

/** El acento de cada tema, ya ajustado, con su letra, su tinte y el gris del menu de marca. */
export function palette(accent: string, accentDark: string | null = null): { light: ThemeTokens; dark: ThemeTokens } {
    const base = normalize(accent);
    const light = resolveAccent(base, LIGHT, '#000000', SOFT_WEIGHT.light);
    const dark = accentDark !== null && isHex(accentDark) ? normalize(accentDark) : resolveAccent(base, DARK, '#FFFFFF', SOFT_WEIGHT.dark);

    return {
        light: tokens(light, LIGHT, SOFT_WEIGHT.light),
        dark: tokens(dark, DARK, SOFT_WEIGHT.dark),
    };
}

/** El contraste escrito como se lee en Colombia: «4,5:1». */
export function ratio(value: number): string {
    return `${value.toFixed(1).replace('.', ',')}:1`;
}

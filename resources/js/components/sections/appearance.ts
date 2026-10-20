import type { CSSProperties } from 'react';
import { ink, isHex, mix, normalize } from '@/lib/color';

/**
 * El diseño de una sección —tamaño, espacio, fondo y animación— traducido a lo que pinta
 * `SectionShell`. El catálogo vive en `App\Support\SectionAppearance` (el mismo para la tienda y
 * la landing) y el servidor manda cada bloque con su diseño ya resuelto: el defecto del tipo con
 * lo que alguien cambió encima.
 *
 * La regla que cuida lo ya publicado: un valor igual al neutro (`BASE`) no escribe nada. Así
 * «Espacio normal» es el espacio que cada bloque ya tenía y «Ancho normal» su ancho de siempre:
 * un bloque que nunca tocó la pestaña «Diseño» se ve exactamente como antes.
 */

export type Surface = 'store' | 'landing';

type Dict = Record<string, unknown>;

export interface MediaRef {
    url: string;
    srcset?: string | null;
    alt?: string | null;
    focal_x?: number;
    focal_y?: number;
}

export interface Appearance {
    height: string;
    height_mobile: string;
    align: string;
    frame: string;
    width: string;
    pad_top: string;
    pad_bottom: string;
    gap: string;
    radius: string;
    bg_type: string;
    bg_color: string;
    bg_gradient: string;
    bg_texture: string;
    bg_fit: string;
    bg_position: string;
    bg_fixed: boolean;
    bg_blur: number;
    overlay: number;
    overlay_color: string;
    parallax: string;
    ink: string;
    anim: string;
    anim_speed: string;
    anim_stagger: string;
    anim_delay: number;
    anim_once: boolean;
    /** Los archivos de fondo, ya con su dirección. */
    image: MediaRef | null;
    image_mobile: MediaRef | null;
    video: MediaRef | null;
    video_mobile: MediaRef | null;
}

/**
 * El diseño que manda el servidor para el modo de la página: en oscuro, el de `appearance.dark`
 * (ya resuelto allá con lo de claro debajo) si la sección cambió algo; si no, el de siempre.
 */
export function appearanceFor(appearance: unknown, scheme: 'light' | 'dark'): unknown {
    if (scheme !== 'dark' || !isDict(appearance) || !isDict(appearance.dark)) {
        return appearance;
    }

    return appearance.dark;
}

/** Lo neutro: el mismo `SectionAppearance::BASE` del servidor. */
export const BASE: Omit<Appearance, 'image' | 'image_mobile' | 'video' | 'video_mobile'> = {
    height: 'auto',
    height_mobile: 'same',
    align: 'start',
    frame: 'contained',
    width: 'normal',
    pad_top: 'md',
    pad_bottom: 'md',
    gap: 'md',
    radius: 'theme',
    bg_type: 'none',
    bg_color: 'soft',
    bg_gradient: 'brand',
    bg_texture: 'dots',
    bg_fit: 'cover',
    bg_position: 'center',
    bg_fixed: false,
    bg_blur: 0,
    overlay: 0,
    overlay_color: 'dark',
    parallax: 'none',
    ink: 'auto',
    anim: 'none',
    anim_speed: 'normal',
    anim_stagger: 'normal',
    anim_delay: 0,
    anim_once: true,
};

const str = (value: unknown, fallback: string): string => (typeof value === 'string' && value !== '' ? value : fallback);
const num = (value: unknown, fallback: number): number => {
    const n = typeof value === 'string' ? Number(value) : value;

    return typeof n === 'number' && Number.isFinite(n) ? n : fallback;
};
const bool = (value: unknown, fallback: boolean): boolean => (typeof value === 'boolean' ? value : fallback);
const media = (value: unknown): MediaRef | null =>
    value && typeof value === 'object' && typeof (value as MediaRef).url === 'string' && (value as MediaRef).url !== '' ? (value as MediaRef) : null;
const url = (value: unknown): MediaRef | null => (typeof value === 'string' && value !== '' ? { url: value } : null);

/**
 * Mezcla lo guardado con los defectos del tipo. Una clave vacía cae al defecto: así un campo que
 * se limpia vuelve al comportamiento de fábrica en vez de dejar la sección a medias.
 */
export function resolveAppearance(defaults: unknown, saved: unknown, surface: Surface): Appearance {
    const d = { ...BASE, ...(isDict(defaults) ? defaults : {}) } as Dict;
    const s = (isDict(saved) ? saved : {}) as Dict;
    const pick = (key: keyof typeof BASE): unknown => (s[key] === '' || s[key] == null ? d[key] : s[key]);

    return {
        height: str(pick('height'), BASE.height),
        height_mobile: str(pick('height_mobile'), BASE.height_mobile),
        align: str(pick('align'), BASE.align),
        frame: str(pick('frame'), BASE.frame),
        width: str(pick('width'), BASE.width),
        pad_top: str(pick('pad_top'), BASE.pad_top),
        pad_bottom: str(pick('pad_bottom'), BASE.pad_bottom),
        gap: str(pick('gap'), BASE.gap),
        radius: str(pick('radius'), BASE.radius),
        bg_type: str(pick('bg_type'), BASE.bg_type),
        bg_color: str(pick('bg_color'), BASE.bg_color),
        bg_gradient: str(pick('bg_gradient'), BASE.bg_gradient),
        bg_texture: str(pick('bg_texture'), BASE.bg_texture),
        bg_fit: str(pick('bg_fit'), BASE.bg_fit),
        bg_position: str(pick('bg_position'), BASE.bg_position),
        bg_fixed: bool(pick('bg_fixed'), BASE.bg_fixed),
        bg_blur: num(pick('bg_blur'), BASE.bg_blur),
        overlay: num(pick('overlay'), BASE.overlay),
        overlay_color: str(pick('overlay_color'), BASE.overlay_color),
        parallax: str(pick('parallax'), BASE.parallax),
        ink: str(pick('ink'), BASE.ink),
        anim: str(pick('anim'), BASE.anim),
        anim_speed: str(pick('anim_speed'), BASE.anim_speed),
        anim_stagger: str(pick('anim_stagger'), BASE.anim_stagger),
        anim_delay: num(pick('anim_delay'), BASE.anim_delay),
        anim_once: bool(pick('anim_once'), BASE.anim_once),
        // La tienda manda el medio resuelto (`bg_media`); la landing, la dirección (`bg_image_url`).
        image: surface === 'store' ? media(s.bg_media) : url(s.bg_image_url),
        image_mobile: surface === 'store' ? media(s.bg_media_mobile) : url(s.bg_image_mobile_url),
        video: surface === 'store' ? media(s.bg_video) : url(s.bg_video_url),
        video_mobile: surface === 'store' ? media(s.bg_video_mobile) : url(s.bg_video_mobile_url),
    };
}

function isDict(value: unknown): value is Dict {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/* ------------------------------------------------------------------ color */

/**
 * Los colores con nombre de cada superficie. Salen de su propio tema: en la tienda el de la sede
 * (`StoreTheme`), en la landing el de la plataforma. Nunca del panel.
 */
const TOKENS: Record<Surface, Record<string, string>> = {
    store: {
        page: 'var(--bg)',
        soft: 'var(--accent-soft)',
        brand: 'var(--accent)',
        brand_dark: 'var(--brand-dark)',
        deal: 'var(--deal)',
        dark: '#14171A',
        light: '#FFFFFF',
    },
    landing: {
        page: 'var(--bg)',
        band: 'var(--surface-2)',
        soft: 'var(--accent-soft)',
        brand: 'var(--accent)',
        dark: 'var(--bg)',
        light: '#FFFFFF',
    },
};

/** Qué letra va sobre cada color con nombre: `null` es la de la página. */
const TOKEN_INK: Record<Surface, Record<string, 'light' | 'dark' | 'accent' | 'deal' | null>> = {
    store: { page: null, soft: null, brand: 'accent', brand_dark: 'light', deal: 'deal', dark: 'light', light: 'dark' },
    landing: { page: null, band: null, soft: null, brand: 'accent', dark: 'light', light: 'dark' },
};

export function colorValue(value: string, surface: Surface): string | null {
    if (TOKENS[surface][value]) {
        return TOKENS[surface][value];
    }

    return isHex(value) ? normalize(value) : null;
}

/** La letra que toca: la elegida, o la que se lee sobre el fondo. `null` deja la de la página. */
function resolvedInk(a: Appearance, surface: Surface): 'light' | 'dark' | 'accent' | 'deal' | { hex: string; on: string } | null {
    if (a.bg_type === 'none') {
        return null;
    }

    if (a.ink === 'light' || a.ink === 'dark') {
        return a.ink;
    }

    if (a.bg_type === 'image' || a.bg_type === 'video') {
        // Sobre una foto solo se sabe con el velo: oscuro y marcado, letra clara.
        if (a.overlay >= 30) {
            return a.overlay_color === 'light' ? 'dark' : 'light';
        }

        return null;
    }

    if (a.bg_type === 'gradient' && a.bg_gradient === 'dark') {
        return 'light';
    }

    if (isHex(a.bg_color) && (a.bg_type === 'color' || a.bg_type === 'texture')) {
        const color = normalize(a.bg_color);

        return { hex: ink(color), on: color };
    }

    return a.bg_type === 'color' ? (TOKEN_INK[surface][a.bg_color] ?? null) : null;
}

/**
 * Lo que la sección le cambia al tema para que todo lo de adentro se lea sobre su fondo. En la
 * landing es su `data-theme` (como hacía `sectionSurface`); en la tienda, variables en línea,
 * porque el acento de la sede viaja así y un tema anidado le pondría encima el de fábrica.
 */
export function inkProps(a: Appearance, surface: Surface): { theme?: 'light' | 'dark'; style: CSSProperties } {
    const chosen = resolvedInk(a, surface);

    if (chosen === null) {
        return { style: {} };
    }

    if (typeof chosen === 'object') {
        const dark = chosen.hex === '#FFFFFF';
        const tint = {
            '--text': chosen.hex,
            '--muted': mix(chosen.hex, chosen.on, 0.3),
            '--line': mix(chosen.on, chosen.hex, 0.18),
            '--line-2': mix(chosen.on, chosen.hex, 0.28),
            '--surface': mix(chosen.on, chosen.hex, 0.06),
            '--surface-2': mix(chosen.on, chosen.hex, 0.1),
            // Sobre fondo oscuro el acento se aclara para que se lea (lo usan los títulos con resalte).
            ...(dark ? { '--sec-accent': 'color-mix(in srgb, var(--accent) 55%, white)' } : {}),
            color: chosen.hex,
        } as CSSProperties;

        return surface === 'landing' ? { theme: dark ? 'dark' : 'light', style: tint } : { style: tint };
    }

    if (surface === 'landing') {
        if (chosen === 'accent') {
            return { style: { color: 'var(--accent-ink)', '--text': 'var(--accent-ink)', '--muted': 'color-mix(in srgb, var(--accent-ink) 78%, transparent)' } as CSSProperties };
        }

        return { theme: chosen === 'light' ? 'dark' : 'light', style: {} };
    }

    if (chosen === 'accent' || chosen === 'deal') {
        const letter = chosen === 'accent' ? 'var(--accent-ink)' : 'var(--deal-ink)';

        return {
            style: {
                color: letter,
                '--text': letter,
                '--muted': `color-mix(in srgb, ${letter} 78%, transparent)`,
                '--line': `color-mix(in srgb, ${letter} 22%, transparent)`,
                '--line-2': `color-mix(in srgb, ${letter} 32%, transparent)`,
                '--surface': `color-mix(in srgb, ${letter} 10%, transparent)`,
                '--surface-2': `color-mix(in srgb, ${letter} 14%, transparent)`,
                // Un enlace del color de la marca sobre la marca no se ve: toma la letra.
                '--sec-accent': letter,
            } as CSSProperties,
        };
    }

    // Lo mismo que hacía el fondo oscuro de los bloques de video.
    return chosen === 'light'
        ? {
              style: {
                  color: '#FFFFFF',
                  '--text': '#FFFFFF',
                  '--muted': 'rgba(255, 255, 255, 0.74)',
                  '--line': 'rgba(255, 255, 255, 0.18)',
                  '--line-2': 'rgba(255, 255, 255, 0.28)',
                  '--surface': 'rgba(255, 255, 255, 0.08)',
                  '--surface-2': 'rgba(255, 255, 255, 0.12)',
                  '--sec-accent': 'color-mix(in srgb, var(--accent) 55%, white)',
              } as CSSProperties,
          }
        : {
              style: {
                  color: '#14171A',
                  '--text': '#14171A',
                  '--muted': '#565E64',
                  '--line': 'rgba(20, 23, 26, 0.14)',
                  '--line-2': 'rgba(20, 23, 26, 0.24)',
                  '--surface': 'rgba(20, 23, 26, 0.04)',
                  '--surface-2': 'rgba(20, 23, 26, 0.07)',
              } as CSSProperties,
          };
}

/* ------------------------------------------------------------------ fondo */

const POSITION: Record<string, string> = {
    center: 'center',
    top: 'center top',
    bottom: 'center bottom',
    left: 'left center',
    right: 'right center',
};

/** Cuánto se desplaza el fondo respecto al desplazamiento de la página, en px por pantalla. */
const PARALLAX: Record<string, number> = { none: 0, soft: 40, medium: 80, strong: 130 };

export const parallaxFactor = (a: Appearance): number => (a.bg_type === 'image' ? (PARALLAX[a.parallax] ?? 0) : 0);

/** Si la sección pinta un fondo propio (y por eso lleva relleno, esquinas y su capa). */
export const hasBackground = (a: Appearance): boolean => {
    switch (a.bg_type) {
        case 'color':
        case 'gradient':
        case 'texture':
            return true;
        case 'image':
            return a.image !== null;
        case 'video':
            return a.video !== null;
        default:
            return false;
    }
};

/** El color de base, debajo de la imagen, la textura o el degradado. */
export function baseColor(a: Appearance, surface: Surface): string | null {
    return a.bg_type === 'none' ? null : colorValue(a.bg_color, surface);
}

/** La imagen de fondo como capa. La de celular va aparte, en un `<picture>`. */
export function imageLayerStyle(a: Appearance): CSSProperties {
    return {
        objectFit: a.bg_fit === 'contain' ? 'contain' : 'cover',
        objectPosition: POSITION[a.bg_position] ?? 'center',
        filter: a.bg_blur > 0 ? `blur(${a.bg_blur}px)` : undefined,
        // El desenfoque aclara los bordes: se agranda un poco para taparlos.
        transform: a.bg_blur > 0 ? 'scale(1.08)' : undefined,
    };
}

/** El mosaico (`repeat`) y el fondo fijo no caben en un `<img>`: van como fondo de CSS. */
export function imageBackgroundStyle(a: Appearance, source: string): CSSProperties {
    return {
        backgroundImage: `url(${JSON.stringify(source)})`,
        backgroundSize: a.bg_fit === 'repeat' ? 'auto' : a.bg_fit === 'contain' ? 'contain' : 'cover',
        backgroundRepeat: a.bg_fit === 'repeat' ? 'repeat' : 'no-repeat',
        backgroundPosition: POSITION[a.bg_position] ?? 'center',
        // `fixed` deja el fondo quieto sin JavaScript; con paralaje no se combinan.
        backgroundAttachment: a.bg_fixed && !parallaxFactor(a) ? 'fixed' : undefined,
        filter: a.bg_blur > 0 ? `blur(${a.bg_blur}px)` : undefined,
        transform: a.bg_blur > 0 ? 'scale(1.08)' : undefined,
    };
}

/** El velo sobre el fondo, para que el texto se siga leyendo encima de una foto. */
export function overlayStyle(a: Appearance): CSSProperties | null {
    if (a.overlay <= 0 || !['image', 'video', 'texture', 'gradient'].includes(a.bg_type)) {
        return null;
    }

    // `var(--accent)` es el de cada superficie: el de la sede en la tienda, el de la plataforma en la landing.
    const color = a.overlay_color === 'light' ? '#FFFFFF' : a.overlay_color === 'brand' ? 'var(--accent)' : '#08101D';

    return { backgroundColor: color, opacity: Math.min(90, a.overlay) / 100 };
}

/* -------------------------------------------------------------- animación */

const ANIM_FROM: Record<string, string> = {
    none: 'none',
    fade: 'none',
    up: 'translate3d(0, 26px, 0)',
    down: 'translate3d(0, -26px, 0)',
    left: 'translate3d(34px, 0, 0)',
    right: 'translate3d(-34px, 0, 0)',
    zoom: 'scale(0.94)',
    zoom_out: 'scale(1.06)',
    blur: 'none',
    rise: 'translate3d(0, 34px, 0) scale(0.97)',
};

const SPEED: Record<string, string> = { fast: '0.35s', normal: '0.6s', slow: '0.95s' };

const STAGGER: Record<string, string> = { none: '0s', subtle: '0.05s', normal: '0.09s', wide: '0.16s' };

export const animates = (a: Appearance): boolean => a.anim !== 'none' && a.anim !== '' && ANIM_FROM[a.anim] !== undefined;

/* ------------------------------------------------------------------ salida */

/**
 * Los atributos de la envoltura. Cada uno se escribe solo si difiere de lo neutro: las reglas de
 * `sections.css` cuelgan de ellos, y sin atributo manda lo que el bloque ya tenía.
 */
export function shellAttributes(a: Appearance, surface: Surface): Record<string, string | undefined> {
    const differs = <K extends keyof typeof BASE>(key: K): string | undefined => (a[key] !== BASE[key] ? String(a[key]) : undefined);

    return {
        'data-surface': surface,
        'data-height': differs('height'),
        'data-height-m': differs('height_mobile'),
        'data-align': differs('align'),
        'data-frame': a.frame,
        'data-width': differs('width'),
        'data-pt': differs('pad_top'),
        'data-pb': differs('pad_bottom'),
        'data-gap': differs('gap'),
        'data-radius': differs('radius'),
        'data-bg': hasBackground(a) ? a.bg_type : undefined,
    };
}

/** Las variables de la animación, solo si la sección anima. */
export function revealStyle(a: Appearance): CSSProperties {
    if (!animates(a)) {
        return {};
    }

    return {
        '--sec-rev-from': ANIM_FROM[a.anim] ?? 'none',
        '--sec-rev-dur': SPEED[a.anim_speed] ?? SPEED.normal,
        '--sec-rev-step': STAGGER[a.anim_stagger] ?? STAGGER.normal,
        '--sec-rev-delay': `${Math.max(0, a.anim_delay) / 1000}s`,
        '--sec-rev-blur': a.anim === 'blur' ? '12px' : '0px',
    } as CSSProperties;
}

/**
 * Una pieza que entra escalonada dentro de una sección animada: `--sec-part` es su turno. Las
 * piezas no se anidan; si una envuelve a otra, las dos se moverían.
 */
export function part(index: number, style?: CSSProperties): { 'data-part': ''; style: CSSProperties } {
    return { 'data-part': '', style: { ...style, '--sec-part': index } as CSSProperties };
}

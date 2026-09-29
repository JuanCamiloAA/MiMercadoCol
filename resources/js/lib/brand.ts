import type { BrandProps, BrandSurface, PanelSurface } from '@/types';

/**
 * El nombre que lleva el titulo de la pestana.
 *
 * `createInertiaApp` arma el titulo con una funcion que no ve las props de la pagina, asi que
 * el nombre de la marca se deja aqui. Lo escribe `BrandStyle` mientras pinta el layout, que es
 * antes de que `<Head>` pida el titulo.
 */
let brandName: string | null = null;

export function setBrandName(name: string | null): void {
    brandName = name;
}

export function brandTitle(title: string, fallback: string): string {
    const name = brandName ?? fallback;

    return title ? `${title} · ${name}` : name;
}

/** Lo que el panel hacia antes de ser configurable. Solo se usa si la prop no llega. */
export const FACTORY_PANEL: PanelSurface = {
    default_theme: 'light',
    allow_theme_toggle: true,
    sidebar_style: 'surface',
    sidebar_mode: 'expanded',
};

export const FACTORY_POS: BrandSurface = { default_theme: 'dark', allow_theme_toggle: true };

export function panelSurface(brand: BrandProps | null): PanelSurface {
    return brand?.surfaces.panel ?? FACTORY_PANEL;
}

export function posSurface(brand: BrandProps | null): BrandSurface {
    return brand?.surfaces.pos ?? FACTORY_POS;
}

/**
 * Si el menu lateral queda sobre un fondo oscuro, para escoger el logo que se lee encima.
 *
 * `ink` es la letra que el servidor puso sobre el acento de cada tema: blanca quiere decir que
 * el acento es oscuro.
 */
export function sidebarOnDark(brand: BrandProps | null, style: PanelSurface['sidebar_style'], theme: 'light' | 'dark'): boolean {
    if (style === 'dark') {
        return true;
    }

    if (style === 'brand') {
        return (brand?.ink[theme] ?? '#FFFFFF').toUpperCase() === '#FFFFFF';
    }

    return theme === 'dark';
}

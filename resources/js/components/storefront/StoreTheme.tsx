import { Head } from '@inertiajs/react';
import type { CSSProperties, ReactNode } from 'react';
import type { StoreThemePayload } from '@/types/storefront';

export type StoreThemeRow = StoreThemePayload;

/**
 * Las parejas de fuentes que ofrece el editor: la del texto, la de los títulos y de dónde se
 * cargan. La tienda no usa las del panel: tiene su propia voz.
 */
const FONTS: Record<string, { body: string; display: string; weight: number; href: string; price?: string }> = {
    moderna: {
        body: "'Inter', system-ui, sans-serif",
        display: "'Inter', system-ui, sans-serif",
        weight: 800,
        href: 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap',
    },
    editorial: {
        body: "'Inter', system-ui, sans-serif",
        display: "'Fraunces', Georgia, serif",
        weight: 700,
        href: 'https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap',
    },
    cercana: {
        body: "'Nunito', 'Segoe UI', system-ui, sans-serif",
        display: "'Nunito', 'Segoe UI', system-ui, sans-serif",
        weight: 800,
        href: 'https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap',
    },
    mercado: {
        body: "'Nunito Sans', 'Segoe UI', system-ui, sans-serif",
        display: "'Nunito Sans', 'Segoe UI', system-ui, sans-serif",
        weight: 800,
        href: 'https://fonts.googleapis.com/css2?family=Nunito+Sans:opsz,wght@6..12,400;6..12,600;6..12,700;6..12,800;6..12,900&display=swap',
    },
    grotesca: {
        body: "'Archivo', system-ui, sans-serif",
        display: "'Archivo', system-ui, sans-serif",
        weight: 900,
        href: 'https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800;900&display=swap',
    },
    minimal: {
        body: "'Manrope', system-ui, sans-serif",
        display: "'Manrope', system-ui, sans-serif",
        weight: 500,
        href: 'https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap',
    },
    lujo: {
        body: "'Jost', system-ui, sans-serif",
        display: "'Cormorant Garamond', Georgia, serif",
        weight: 500,
        href: 'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Jost:wght@400;500;600;700&display=swap',
    },
    tecnica: {
        body: "'IBM Plex Sans', system-ui, sans-serif",
        display: "'IBM Plex Sans', system-ui, sans-serif",
        weight: 700,
        price: "'IBM Plex Mono', ui-monospace, monospace",
        href: 'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap',
    },
    vitrina: {
        body: "'Ubuntu', system-ui, sans-serif",
        display: "'Ubuntu', system-ui, sans-serif",
        weight: 700,
        href: 'https://fonts.googleapis.com/css2?family=Ubuntu:wght@400;500;700&display=swap',
    },
    urbano: {
        body: "'Archivo', system-ui, sans-serif",
        display: "'Anton', Impact, sans-serif",
        weight: 400,
        href: 'https://fonts.googleapis.com/css2?family=Anton&family=Archivo:wght@400;500;600;700;800&display=swap',
    },
    sastre: {
        body: "'Inter', system-ui, sans-serif",
        display: "'Playfair Display', Georgia, serif",
        weight: 600,
        href: 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap',
    },
};

/** La letra del nombre cuando la sede se presenta sin logo (`logo_mode = name`). */
const LOGO_FONTS: Record<string, { family: string; weight: number; href: string }> = {
    italiana: { family: "'Italiana', Georgia, serif", weight: 400, href: 'https://fonts.googleapis.com/css2?family=Italiana&display=swap' },
    cormorant: { family: "'Cormorant Garamond', Georgia, serif", weight: 600, href: 'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&display=swap' },
    playfair: { family: "'Playfair Display', Georgia, serif", weight: 700, href: 'https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&display=swap' },
    anton: { family: "'Anton', Impact, sans-serif", weight: 400, href: 'https://fonts.googleapis.com/css2?family=Anton&display=swap' },
};

/** El radio de las esquinas, en la escala que usan los bloques de la tienda. */
const RADIUS: Record<string, [string, string]> = {
    sharp: ['2px', '2px'],
    soft: ['12px', '8px'],
    round: ['20px', '14px'],
};

/** El ancho de la página. «Normal» es el de siempre. */
const CONTAINER: Record<string, string> = { normal: '1280px', wide: '1440px', full: '100%' };

/** La forma de los botones; «Con las esquinas del tema» es la de siempre. */
const BUTTON_RADIUS: Record<string, string | null> = { default: null, pill: '999px', square: '2px' };

/**
 * Aplica el aspecto que la sede publicó.
 *
 * Es el mismo componente en la tienda y en la vista previa a propósito: si el editor pintara
 * con unas variables y la tienda con otras, lo que se ve al editar no sería lo que ve el
 * cliente, y todo el editor perdería sentido.
 *
 * El color del texto sobre el acento (`--accent-ink`) y sobre el rojo de oferta (`--deal-ink`)
 * llegan resueltos desde el servidor. Dejarlos fijos en blanco haría ilegible un color claro,
 * que es justo lo que la verificación de contraste promete evitar.
 */
export function StoreTheme({ theme, children, className }: { theme?: StoreThemePayload | null; children: ReactNode; className?: string }) {
    const font = FONTS[theme?.font_pair ?? 'moderna'] ?? FONTS.moderna;
    const [radius, radiusSmall] = RADIUS[theme?.radius ?? 'soft'] ?? RADIUS.soft;
    const accent = theme?.accent ?? null;
    const deal = theme?.deal_color ?? '#D92D20';
    const logoFont = LOGO_FONTS[theme?.logo_font ?? 'none'] ?? null;
    const buttonRadius = BUTTON_RADIUS[theme?.button_shape ?? 'default'] ?? null;

    const style = {
        ...(accent
            ? {
                  '--accent': accent,
                  '--accent-ink': theme?.accent_ink ?? '#FFFFFF',
                  '--accent-soft': `color-mix(in srgb, ${accent} 12%, transparent)`,
              }
            : {}),
        '--deal': deal,
        '--deal-ink': theme?.deal_ink ?? '#FFFFFF',
        '--deal-soft': `color-mix(in srgb, ${deal} 10%, transparent)`,
        '--brand-dark': `color-mix(in srgb, var(--accent) 62%, #000)`,
        '--store-font': font.body,
        '--store-display': font.display,
        '--store-display-weight': String(font.weight),
        '--store-radius': radius,
        '--store-radius-sm': radiusSmall,
        '--store-gap': theme?.density === 'compact' ? '0.75rem' : '1.25rem',
        '--store-max': CONTAINER[theme?.container ?? 'normal'] ?? CONTAINER.normal,
        // El precio con su propia letra (la monoespaciada de «Técnica»); si no, la del texto.
        '--store-price-font': font.price ?? 'inherit',
        '--store-logo-font': logoFont?.family ?? 'var(--store-display)',
        '--store-logo-weight': String(logoFont?.weight ?? font.weight),
        ...(buttonRadius ? { '--store-btn-radius': buttonRadius } : {}),
        // Lo que dentro de la tienda use las fuentes del panel (títulos, cifras) toma las suyas.
        '--brand-font-body': font.body,
        '--brand-font-display': font.display,
        '--brand-font-numeric': font.body,
        fontFamily: 'var(--store-font)',
    } as CSSProperties;

    return (
        <div
            className={className}
            style={style}
            data-store-buttons={theme?.button_style ?? 'solid'}
            // El fondo, los títulos en mayúsculas y el ancho completo los pone `storefront.css`.
            data-store-surface={theme?.surface && theme.surface !== 'white' ? theme.surface : undefined}
            data-store-headings={theme?.heading_case === 'upper' ? 'upper' : undefined}
            data-store-container={theme?.container === 'full' ? 'full' : undefined}
            data-store-shell=""
        >
            <Head>
                <link rel="stylesheet" href={font.href} />
                {logoFont && <link rel="stylesheet" href={logoFont.href} />}
            </Head>
            {children}
        </div>
    );
}

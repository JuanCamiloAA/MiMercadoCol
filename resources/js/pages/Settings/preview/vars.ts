import { useEffect, type CSSProperties } from 'react';
import { isHex, normalize, palette } from '@/lib/color';
import type { PreviewTokens, SettingsValues, ThemeTokenSet } from '../types';

type Theme = 'light' | 'dark';

/**
 * Los tokens de color de un borrador, para ponerlos en linea en la vista previa.
 *
 * Replica `BrandThemeService::css()`: con el acento de fabrica manda `app.css`, y el oscuro
 * solo cambia si hay acento oscuro o acento propio. Van en linea porque las variables del
 * elemento le ganan a `#brand-theme`, que trae lo que la empresa tiene guardado.
 */
export function themeVars(accent: string, accentDark: string | null, theme: Theme, tokens: PreviewTokens): CSSProperties {
    const factoryAccent = normalize(accent) === normalize(tokens.factory_accent);
    const dark = isHex(accentDark) ? accentDark : null;
    let set: ThemeTokenSet = tokens.factory[theme];

    if (!(factoryAccent && (theme === 'light' || dark === null))) {
        const resolved = palette(accent, dark)[theme];

        set = { accent: resolved.accent, ink: resolved.ink, soft: resolved.soft, muted: resolved.muted, mark: resolved.ink };
    }

    return {
        '--accent': set.accent,
        '--accent-ink': set.ink,
        '--accent-soft': set.soft,
        '--brand-accent': set.accent,
        '--brand-ink': set.ink,
        '--brand-muted': set.muted,
        '--brand-mark-ink': set.mark,
    } as CSSProperties;
}

/** Tipografias, esquinas y densidad del borrador, como las pone `#brand-theme`. */
export function layoutVars(values: Pick<SettingsValues, 'appearance.font_body' | 'appearance.font_display' | 'appearance.font_numeric' | 'appearance.radius' | 'appearance.density'>, tokens: PreviewTokens): CSSProperties {
    const [chip, control, card, sheet] = tokens.radii[values['appearance.radius']] ?? tokens.radii.soft;

    return {
        '--brand-font-body': `'${values['appearance.font_body']}'`,
        '--brand-font-display': `'${values['appearance.font_display']}'`,
        '--brand-font-numeric': `'${values['appearance.font_numeric']}'`,
        '--r-chip': `${chip}px`,
        '--r-control': `${control}px`,
        '--r-card': `${card}px`,
        '--r-sheet': `${sheet}px`,
        '--density': String(tokens.densities[values['appearance.density']] ?? 1),
        fontFamily: 'var(--brand-font-body), ui-sans-serif, system-ui, sans-serif',
    } as CSSProperties;
}

/**
 * Carga en la pagina las tipografias que se estan probando. Las guardadas ya vienen en el blade;
 * estas solo existen mientras se elige, asi que se agregan al vuelo y se quedan (son livianas).
 */
export function usePreviewFonts(hrefs: (string | undefined)[]): void {
    const key = hrefs.filter(Boolean).join('|');

    useEffect(() => {
        for (const href of key.split('|').filter(Boolean)) {
            if (!document.querySelector(`link[data-preview-font="${CSS.escape(href)}"]`)) {
                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = href;
                link.dataset.previewFont = href;
                document.head.appendChild(link);
            }
        }
    }, [key]);
}

export function resolveTheme(choice: 'light' | 'dark' | 'system'): Theme {
    if (choice === 'system') {
        return typeof window !== 'undefined' && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    return choice;
}

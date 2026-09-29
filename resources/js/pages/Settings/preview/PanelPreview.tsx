import { DesktopIcon, MoonIcon, SunIcon } from '@phosphor-icons/react';
import { BrandMark } from '@/components/brand/BrandMark';
import { Button } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Misc';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';
import { applyNavigationOverrides, isNavGroup, NAVIGATION, PLATFORM_SECTIONS, type NavigationOverrides } from '@/lib/navigation';
import type { PosSample, PreviewTokens, SettingsValues } from '../types';
import { layoutVars, themeVars } from './vars';

export interface PanelDraft {
    displayName: string;
    subtitle: string;
    accent: string;
    accentDark: string | null;
    mark: string | null;
    logoLight: string | null;
    logoDark: string | null;
    layout: Pick<SettingsValues, 'appearance.font_body' | 'appearance.font_display' | 'appearance.font_numeric' | 'appearance.radius' | 'appearance.density'>;
    sidebarStyle: SettingsValues['panel.sidebar_style'];
    sidebarMode: SettingsValues['panel.sidebar_mode'];
    showToggle: boolean;
    scale: number;
    navigation: NavigationOverrides | null;
}

interface PanelPreviewProps {
    draft: PanelDraft;
    theme: 'light' | 'dark';
    tokens: PreviewTokens;
    sample: PosSample;
    /** Cuantas entradas del menu se muestran. */
    entries?: number;
}

/**
 * El panel en miniatura, pintado con el borrador y con los componentes de verdad (`Button`,
 * `Card`, `Table`), no con dibujos: si un componente no respeta un token, se ve aqui.
 *
 * No usa `iframe`: los tokens cuelgan de `data-theme` y de variables en linea, asi que un `div`
 * basta y se pueden ver claro y oscuro lado a lado. Todo va `inert`: es para mirar.
 */
export function PanelPreview({ draft, theme, tokens, sample, entries = 7 }: PanelPreviewProps) {
    const icons = draft.sidebarMode === 'icons';
    const colors = themeVars(draft.accent, draft.accentDark, theme, tokens) as Record<string, string>;
    // Sobre el menu del color de la marca, la letra blanca dice que el fondo es oscuro.
    const onDark = draft.sidebarStyle === 'dark' || (draft.sidebarStyle === 'brand' ? colors['--brand-ink'].toUpperCase() === '#FFFFFF' : theme === 'dark');
    const logo = onDark ? (draft.logoDark ?? draft.logoLight) : (draft.logoLight ?? draft.logoDark);

    const links = applyNavigationOverrides(NAVIGATION.filter((section) => !PLATFORM_SECTIONS.includes(section.key)), draft.navigation)
        .flatMap((section) => section.entries.map((entry) => ({ section: section.title, entry })))
        .slice(0, entries);

    const products = sample.products.slice(0, 3);

    return (
        <div
            data-theme={theme}
            inert
            aria-label={`Vista previa del panel en tema ${theme === 'dark' ? 'oscuro' : 'claro'}`}
            style={{ ...colors, ...layoutVars(draft.layout, tokens) }}
            className="overflow-hidden rounded-card border border-[var(--line)] bg-[var(--bg)] text-[var(--text)] select-none"
        >
            <div className="flex h-[20rem]" style={{ zoom: draft.scale / 100 }}>
                <div
                    data-theme={draft.sidebarStyle === 'dark' ? 'dark' : undefined}
                    data-sidebar={draft.sidebarStyle === 'brand' ? 'brand' : undefined}
                    style={draft.sidebarStyle === 'dark' ? themeVars(draft.accent, draft.accentDark, 'dark', tokens) : undefined}
                    className={cn('flex shrink-0 flex-col border-r border-[var(--line)] bg-[var(--surface)] text-[var(--text)]', icons ? 'w-12' : 'w-40')}
                >
                    <div className={cn('flex h-11 items-center border-b border-[var(--line)]', icons ? 'justify-center' : 'gap-2 px-2.5')}>
                        {!icons && logo ? (
                            <img src={logo} alt="" className="h-6 max-w-full object-contain object-left" />
                        ) : (
                            <>
                                <BrandMark src={draft.mark} className="size-6 rounded-chip" iconClassName="size-3.5" />
                                {!icons && (
                                    <span className="min-w-0">
                                        <span className="block truncate text-[0.6875rem] font-semibold leading-tight">{draft.displayName || 'Sin nombre'}</span>
                                        <span className="block truncate text-[0.5625rem] text-[var(--muted)]">{draft.subtitle}</span>
                                    </span>
                                )}
                            </>
                        )}
                    </div>

                    <ul className={cn('flex flex-1 flex-col gap-0.5 overflow-hidden py-2', icons ? 'items-center px-1' : 'px-2')}>
                        {links.map(({ section, entry }, index) => {
                            const Icon = entry.icon;
                            const active = index === 0;
                            const showSection = !icons && (index === 0 || links[index - 1].section !== section);

                            return (
                                <li key={isNavGroup(entry) ? entry.key : entry.routeName}>
                                    {showSection && (
                                        <p className="mt-1 px-1.5 pb-0.5 text-[0.53125rem] font-semibold tracking-wider text-[var(--muted)] uppercase">{section}</p>
                                    )}
                                    <span
                                        className={cn(
                                            'flex h-7 items-center gap-1.5 rounded-chip text-[0.65625rem] font-medium',
                                            icons ? 'w-8 justify-center' : 'px-1.5',
                                            active ? 'bg-[var(--accent-soft)] text-[var(--accent)]' : 'text-[var(--muted)]',
                                        )}
                                    >
                                        <Icon size={13} weight={active ? 'fill' : 'regular'} />
                                        {!icons && <span className="truncate">{entry.label}</span>}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                </div>

                <div className="flex min-w-0 flex-1 flex-col">
                    <div className="flex h-11 shrink-0 items-center gap-2 border-b border-[var(--line)] bg-[var(--surface)] px-3">
                        <span className="truncate text-[0.71875rem] font-semibold">Inicio</span>
                        {draft.showToggle && (
                            <span className="ml-auto inline-flex items-center gap-0.5 rounded-chip border border-[var(--line)] bg-[var(--surface-2)] p-0.5 text-[var(--muted)]">
                                {[SunIcon, MoonIcon, DesktopIcon].map((Icon, index) => (
                                    <span
                                        key={index}
                                        className={cn(
                                            'grid size-5 place-items-center rounded-sm',
                                            (index === 0 && theme === 'light') || (index === 1 && theme === 'dark') ? 'bg-[var(--surface)] text-[var(--text)]' : '',
                                        )}
                                    >
                                        <Icon size={10} />
                                    </span>
                                ))}
                            </span>
                        )}
                    </div>

                    <div className="min-h-0 flex-1 overflow-hidden p-3" style={{ zoom: 0.82 }}>
                        <h3 className="mb-2 text-[0.9375rem] leading-tight">Buenos días</h3>
                        <Card className="text-[0.75rem]">
                            <CardHeader title="Ventas de hoy" description="Lo cobrado en la caja y en línea." action={<Badge tone="accent">Al día</Badge>} />
                            <p className="font-mono text-[1.125rem] font-semibold tabular text-[var(--text)]">{money(1240500)}</p>
                            <div className="mt-3 flex gap-2">
                                <Button size="sm">Nueva venta</Button>
                                <Button size="sm" variant="secondary">
                                    Exportar
                                </Button>
                            </div>
                        </Card>

                        {products.length > 0 && (
                            <Table className="mt-3 text-[0.71875rem]">
                                <THead>
                                    <tr>
                                        <TH>Artículo</TH>
                                        <TH align="right">Precio</TH>
                                    </tr>
                                </THead>
                                <TBody>
                                    {products.map((product) => (
                                        <TR key={product.name}>
                                            <TD className="truncate">{product.name}</TD>
                                            <TD align="right" className="font-mono tabular">
                                                {money(product.price)}
                                            </TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

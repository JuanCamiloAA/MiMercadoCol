import { useState } from 'react';
import { cn } from '@/lib/cn';
import { CheckRow, FieldBlock, PreviewFrame, SaveBar, Segmented, TabHeader } from '../SettingField';
import { useSettingsForm } from '../form';
import { PanelPreview, type PanelDraft } from '../preview/PanelPreview';
import { resolveTheme, usePreviewFonts } from '../preview/vars';
import type { FontOption, Option, SettingKey, SettingsValues, TabProps } from '../types';

export const APPEARANCE_KEYS = [
    'appearance.font_body',
    'appearance.font_display',
    'appearance.font_numeric',
    'appearance.radius',
    'appearance.density',
    'panel.default_theme',
    'panel.allow_theme_toggle',
    'panel.sidebar_style',
    'panel.sidebar_mode',
    'panel.ui_scale',
    'panel.content_width',
] as const satisfies readonly SettingKey[];

/**
 * Ajustes > Apariencia: tipografia, esquinas y densidad (panel y caja), y lo propio del panel:
 * tema, escala, ancho y menu lateral. Todo con barandas: fuentes de una lista cerrada, tamanos
 * en escalones y esquinas en tres formas.
 */
export function AppearanceTab({ settings, defaults, readOnly, onDirtyChange, options, fontOptions, brandAssets, company, previewTokens, posSample }: TabProps) {
    const { form, data, errors, set, save, reset } = useSettingsForm(APPEARANCE_KEYS, settings, onDirtyChange);
    const [previewTheme, setPreviewTheme] = useState<'light' | 'dark'>(() => resolveTheme(settings['panel.default_theme']));

    const font = (group: keyof TabProps['fontOptions'], value: string): FontOption | undefined =>
        fontOptions[group].find((option) => option.value === value);

    usePreviewFonts([
        font('body', data['appearance.font_body'])?.href,
        font('display', data['appearance.font_display'])?.href,
        font('numeric', data['appearance.font_numeric'])?.href,
    ]);

    const draft: PanelDraft = {
        displayName: settings['brand.display_name'],
        subtitle: company?.name ?? '',
        accent: settings['brand.accent'],
        accentDark: settings['brand.accent_dark'],
        mark: brandAssets.mark,
        logoLight: brandAssets.logo_light,
        logoDark: brandAssets.logo_dark,
        layout: data,
        sidebarStyle: data['panel.sidebar_style'],
        sidebarMode: data['panel.sidebar_mode'],
        showToggle: data['panel.allow_theme_toggle'],
        scale: Number(data['panel.ui_scale']),
        navigation: Array.isArray(settings['panel.navigation']) ? null : settings['panel.navigation'],
    };

    const segmented = <Key extends keyof typeof data>(key: Key, label: string) => (
        <Segmented
            label={label}
            value={data[key] as string | number}
            options={options[key] as Option[]}
            onChange={(value) => set(key, value as SettingsValues[Key])}
            disabled={readOnly}
        />
    );

    const hintFor = (key: string, value: string | number) => (options[key] ?? []).find((option) => option.value === value)?.hint;

    return (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] xl:grid-cols-[minmax(0,27rem)_minmax(0,1fr)]">
            <div className="flex flex-col gap-5">
                <TabHeader scope="Empresa · el panel, y la letra y las esquinas de la caja" onFactory={readOnly ? undefined : () => reset(defaults)} />

                <FieldBlock label="Tipografía" hint="Se sirven desde Google Fonts, como las de fábrica.">
                    <div className="flex flex-col gap-3">
                        <FontSelect
                            label="Texto"
                            sample="Arroz blanco 1 kg · Leche entera"
                            options={fontOptions.body}
                            value={data['appearance.font_body']}
                            onChange={(value) => set('appearance.font_body', value)}
                            disabled={readOnly}
                            error={errors['appearance.font_body']}
                        />
                        <FontSelect
                            label="Títulos"
                            sample="Buenos días"
                            options={fontOptions.display}
                            value={data['appearance.font_display']}
                            onChange={(value) => set('appearance.font_display', value)}
                            disabled={readOnly}
                            error={errors['appearance.font_display']}
                            display
                        />
                        <FontSelect
                            label="Cifras"
                            sample="$ 1.240.500 · 0123456789"
                            options={fontOptions.numeric}
                            value={data['appearance.font_numeric']}
                            onChange={(value) => set('appearance.font_numeric', value)}
                            disabled={readOnly}
                            error={errors['appearance.font_numeric']}
                            numeric
                        />
                    </div>
                </FieldBlock>

                <FieldBlock label="Esquinas" error={errors['appearance.radius']} hint="Botones, tarjetas, campos y ventanas: todos a la vez.">
                    {segmented('appearance.radius', 'Esquinas')}
                </FieldBlock>

                <FieldBlock
                    label="Densidad"
                    error={errors['appearance.density']}
                    hint={`${hintFor('appearance.density', data['appearance.density']) ?? ''} La caja no cambia: lo que se toca no baja de 44 px.`}
                >
                    {segmented('appearance.density', 'Densidad')}
                </FieldBlock>

                <FieldBlock
                    label="Tamaño del panel"
                    error={errors['panel.ui_scale']}
                    hint="Agranda o achica todo el panel. Los cortes para celular y tableta no cambian."
                >
                    {segmented('panel.ui_scale', 'Tamaño del panel')}
                </FieldBlock>

                <FieldBlock label="Ancho del contenido" error={errors['panel.content_width']} hint="Centrado es el de siempre; en pantallas anchas, Amplio muestra más columnas.">
                    {segmented('panel.content_width', 'Ancho del contenido')}
                </FieldBlock>

                <FieldBlock
                    label="Tema inicial del panel"
                    error={errors['panel.default_theme']}
                    hint="Con el que arranca cada quien. La elección de cada persona se guarda en su navegador."
                >
                    {segmented('panel.default_theme', 'Tema inicial del panel')}
                </FieldBlock>

                <FieldBlock label="Interruptor de tema" error={errors['panel.allow_theme_toggle']}>
                    <CheckRow
                        checked={data['panel.allow_theme_toggle']}
                        onChange={(value) => set('panel.allow_theme_toggle', value)}
                        disabled={readOnly}
                        label="Mostrar el interruptor de tema"
                        hint="Apagado, todos ven el tema inicial. Lo que cada persona había escogido no se borra: vuelve si lo enciendes."
                    />
                </FieldBlock>

                <FieldBlock label="Menú lateral" error={errors['panel.sidebar_style'] ?? errors['panel.sidebar_mode']}>
                    {segmented('panel.sidebar_style', 'Estilo del menú')}
                    {segmented('panel.sidebar_mode', 'Modo del menú')}
                    <p className="text-[0.75rem] text-[var(--muted)]">
                        Solo íconos deja una columna angosta; cada grupo abre sus enlaces en un menú flotante. En el celular el menú siempre va completo.
                    </p>
                </FieldBlock>

                {!readOnly && (
                    <SaveBar dirty={form.isDirty} processing={form.processing} onSave={() => save(route('settings.appearance.update'))} label="Guardar la apariencia" />
                )}
            </div>

            <div className="flex flex-col gap-4 lg:sticky lg:top-20 lg:self-start">
                <div className="flex items-center justify-between gap-2">
                    <p className="text-[0.78125rem] text-[var(--muted)]">Vista previa · panel al {data['panel.ui_scale']} %</p>
                    <Segmented
                        label="Tema de la vista previa"
                        value={previewTheme}
                        options={(options['panel.default_theme'] as Option<'light' | 'dark' | 'system'>[]).filter(
                            (option): option is Option<'light' | 'dark'> => option.value !== 'system',
                        )}
                        onChange={setPreviewTheme}
                    />
                </div>
                <div className="rounded-card border border-[var(--line)] bg-[var(--surface)] p-4">
                    <PanelPreview draft={draft} theme={previewTheme} tokens={previewTokens} sample={posSample} />
                </div>
                <PreviewFrame title="Ancho del contenido en una pantalla de 1600 px">
                    <ContentWidth width={data['panel.content_width']} widths={previewTokens.content_widths} />
                </PreviewFrame>
            </div>
        </div>
    );
}

interface FontSelectProps {
    label: string;
    sample: string;
    options: FontOption[];
    value: string;
    onChange: (value: string) => void;
    disabled: boolean;
    error?: string;
    display?: boolean;
    numeric?: boolean;
}

function FontSelect({ label, sample, options, value, onChange, disabled, error, display, numeric }: FontSelectProps) {
    const current = options.find((option) => option.value === value);

    return (
        <div className="flex flex-col gap-1">
            <label className="flex items-center justify-between gap-3 text-[0.78125rem] text-[var(--muted)]">
                {label}
                <select
                    value={value}
                    disabled={disabled}
                    onChange={(event) => onChange(event.target.value)}
                    className="h-9 w-56 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2.5 text-[0.8125rem] text-[var(--text)] focus:border-[var(--accent)] focus:outline-none"
                >
                    {options.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
            </label>
            <p
                className={cn('truncate rounded-chip bg-[var(--surface-2)] px-2.5 py-1.5 text-[var(--text)]', display ? 'text-[1.125rem] font-bold' : 'text-[0.875rem]')}
                style={{
                    fontFamily: `'${value}', ${current?.generic ?? 'sans-serif'}`,
                    fontVariantNumeric: numeric ? 'tabular-nums' : undefined,
                }}
            >
                {sample}
            </p>
            {error && <p className="text-[0.75rem] font-medium text-[var(--danger)]">{error}</p>}
        </div>
    );
}

/** El ancho del contenido dibujado: la pantalla y el bloque que ocupa. */
function ContentWidth({ width, widths }: { width: string; widths: Record<string, string> }) {
    const value = widths[width] ?? widths.centered;
    // 1600 px de pantalla, menos el menu (238) y los margenes (48).
    const available = 1600 - 238 - 48;
    const pixels = value === 'none' ? available : Math.min(available, parseFloat(value) * 16);
    const percent = Math.round((pixels / available) * 100);

    return (
        <div className="flex flex-col gap-2" inert>
            <div className="flex h-16 overflow-hidden rounded-chip border border-[var(--line)] bg-[var(--bg)]">
                <span className="w-[15%] shrink-0 border-r border-[var(--line)] bg-[var(--surface)]" />
                <span className="flex flex-1 justify-center p-2">
                    <span className="rounded-sm bg-[var(--accent-soft)] ring-1 ring-[var(--accent)]/40" style={{ width: `${percent}%` }} />
                </span>
            </div>
            <p className="text-center text-[0.75rem] text-[var(--muted)]">
                {value === 'none' ? 'Ocupa todo el ancho disponible.' : `Hasta ${Math.round(parseFloat(value) * 16)} px, centrado.`}
            </p>
        </div>
    );
}

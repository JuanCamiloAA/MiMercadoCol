import { router } from '@inertiajs/react';
import { CheckIcon, LockSimpleIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Card, CardHeader } from '@/components/ui/Card';
import { Input, Select, Toggle } from '@/components/ui/Field';
import { cn } from '@/lib/cn';
import { contrast, ink } from '@/lib/color';
import { MediaField } from './BlockFields';
import { useAutosave } from './editor-api';
import { StructureSection } from './StructureForms';
import type { EditorContext, Endpoints, PaletteSuggestion, SettingsRow, StructureOptions, ThemeRow } from './types';

interface AppearanceTabProps {
    context: EditorContext;
    theme: ThemeRow;
    settings: SettingsRow;
    readOnly: boolean;
    /** Sigue la plantilla: la forma es de la plantilla; la marca y los colores, de la sede. */
    locked: boolean;
    onPersonalize: () => void;
    structureOptions: StructureOptions;
    endpoints: Endpoints;
    onSaved: () => void;
    preview: React.ReactNode;
    errors: Record<string, string>;
    /** Los colores que sugiere la plantilla de la sede, con su nombre. Nunca se aplican solos. */
    suggestion?: { template: string | null; palette: PaletteSuggestion } | null;
}

/** Colores sugeridos para la marca, todos con contraste suficiente para letra encima. */
const SWATCHES = ['#0E6E4E', '#1F7A5C', '#0F5F8A', '#1D4ED8', '#7A2E8E', '#A8301F', '#B3541E', '#3A4A5C'];

/** Rojos de oferta sugeridos: todos se leen como precio sobre blanco. */
const DEAL_SWATCHES = ['#D92D20', '#C8102E', '#B42318', '#E11D48', '#C2410C'];

/** La muestra de cada pareja de fuentes, pintada con su propia letra. */
const FONT_SAMPLES: Record<string, string> = {
    mercado: "'Nunito Sans', sans-serif",
    moderna: "'Inter', sans-serif",
    cercana: "'Nunito', sans-serif",
    editorial: "'Fraunces', Georgia, serif",
    grotesca: "'Archivo', sans-serif",
    minimal: "'Manrope', sans-serif",
    lujo: "'Cormorant Garamond', Georgia, serif",
    tecnica: "'IBM Plex Sans', sans-serif",
    vitrina: "'Ubuntu', sans-serif",
    urbano: "'Anton', Impact, sans-serif",
    sastre: "'Playfair Display', Georgia, serif",
};

/** Contraste del mejor texto (blanco o negro) sobre un color. */
function bestContrast(hex: string): number {
    return /^#[0-9a-f]{6}$/i.test(hex) ? Math.max(contrast(hex, '#FFFFFF'), contrast(hex, '#000000')) : 0;
}

function ContrastNote({ ratio, ok, good, bad }: { ratio: number; ok: boolean; good: string; bad: string }) {
    return (
        <p
            className={cn(
                'mt-2 flex items-center gap-2 rounded-[9px] px-3 py-2 text-[12.5px] font-medium',
                ok ? 'bg-[var(--accent-soft)] text-[var(--accent)]' : 'bg-[var(--danger-soft)] text-[var(--danger)]',
            )}
        >
            {ok ? <CheckIcon size={15} weight="bold" /> : <WarningCircleIcon size={15} weight="fill" />}
            Contraste {ratio.toFixed(2).replace('.', ',')}:1 — {ok ? good : bad}
        </p>
    );
}

function Swatches({ colors, value, disabled, onPick }: { colors: string[]; value: string; disabled: boolean; onPick: (color: string) => void }) {
    return (
        <div className="flex flex-wrap gap-2">
            {colors.map((swatch) => (
                <button
                    key={swatch}
                    type="button"
                    disabled={disabled}
                    onClick={() => onPick(swatch)}
                    aria-label={swatch}
                    title={swatch}
                    style={{ backgroundColor: swatch }}
                    className={cn(
                        'grid size-10 place-items-center rounded-[11px] border-2 transition-transform',
                        value.toUpperCase() === swatch.toUpperCase() ? 'scale-105 border-[var(--text)]' : 'border-transparent hover:scale-105',
                    )}
                >
                    {value.toUpperCase() === swatch.toUpperCase() && <CheckIcon size={16} weight="bold" color={ink(swatch)} />}
                </button>
            ))}
        </div>
    );
}

/**
 * Aspecto: la marca de la sede (nombre, lema, logo, isotipo, favicon), sus colores (acento y
 * rojo de oferta, con su contraste), la tipografía, las formas, el esquema y la tarjeta de
 * producto. En la plantilla no hay marca ni colores: esos los pone cada sede.
 */
export function AppearanceTab({ context, theme, settings, readOnly, locked, onPersonalize, structureOptions, endpoints, onSaved, preview, errors, suggestion }: AppearanceTabProps) {
    const [draft, setDraft] = useState({
        display_name: theme.display_name,
        tagline: theme.tagline,
        accent: theme.accent,
        deal_color: theme.deal_color,
        scheme: theme.scheme,
        allow_scheme_toggle: theme.allow_scheme_toggle,
        font_pair: theme.font_pair,
        radius: theme.radius,
        density: theme.density,
        button_style: theme.button_style,
        logo_font: theme.logo_font ?? 'none',
        surface: theme.surface ?? 'white',
        heading_case: theme.heading_case ?? 'normal',
        container: theme.container ?? 'normal',
        button_shape: theme.button_shape ?? 'default',
        logo_media_id: theme.logo?.id ?? null,
        mark_media_id: theme.mark?.id ?? null,
        favicon_media_id: theme.favicon?.id ?? null,
    });

    useAutosave(draft, (value) =>
        router.patch(endpoints.theme, value as unknown as Record<string, string>, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: onSaved,
            onError: (problems) => toast.error(Object.values(problems)[0] ?? 'No se pudo guardar.'),
        }),
    );

    const set = <K extends keyof typeof draft>(key: K, value: (typeof draft)[K]) => setDraft((current) => ({ ...current, [key]: value }));
    const brand = context === 'store';
    const shape = readOnly || locked;
    const choices = structureOptions.choices;

    const accentRatio = bestContrast(draft.accent);
    const dealRatio = /^#[0-9a-f]{6}$/i.test(draft.deal_color) ? contrast(draft.deal_color, '#FFFFFF') : 0;

    // Solo se ofrece si cambia algo: con los colores ya puestos, el botón sobra.
    const suggested = suggestion?.palette ?? null;
    const offerSuggestion =
        !!suggested &&
        ((!!suggested.accent && suggested.accent.toUpperCase() !== draft.accent.toUpperCase()) ||
            (!!suggested.deal_color && suggested.deal_color.toUpperCase() !== draft.deal_color.toUpperCase()));

    return (
        <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <div className="flex flex-col gap-4">
                {locked && !readOnly && (
                    <div className="flex items-start gap-2 rounded-[12px] border border-[var(--line)] bg-[var(--surface-2)] px-3 py-2.5 text-[12.5px] text-[var(--muted)]">
                        <LockSimpleIcon size={16} className="mt-px shrink-0" />
                        <span className="flex-1">La tipografía, las formas, el esquema y la tarjeta son de la plantilla. La marca y los colores sí son tuyos.</span>
                        <button type="button" onClick={onPersonalize} className="shrink-0 font-semibold text-[var(--accent)] hover:underline">
                            Personalizar
                        </button>
                    </div>
                )}

                {brand && (
                    <Card>
                        <CardHeader title="Marca" description="Cómo se llama la tienda y con qué se reconoce." />
                        <div className="flex flex-col gap-3">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Input label="Nombre visible" maxLength={60} disabled={readOnly} value={draft.display_name ?? ''} onChange={(event) => set('display_name', event.target.value)} />
                                <Input label="Lema" maxLength={120} disabled={readOnly} value={draft.tagline ?? ''} onChange={(event) => set('tagline', event.target.value)} hint="Opcional. Una línea corta." />
                            </div>
                            <div className="grid gap-3 sm:grid-cols-3">
                                <MediaField compact label="Logo" help="Horizontal, fondo transparente." value={draft.logo_media_id} readOnly={readOnly} onChange={(id) => set('logo_media_id', id)} />
                                <MediaField compact label="Isotipo" help="Cuadrado: la marca sola." value={draft.mark_media_id} readOnly={readOnly} onChange={(id) => set('mark_media_id', id)} />
                                <MediaField compact label="Ícono de la pestaña" help="Cuadrado y simple." value={draft.favicon_media_id} readOnly={readOnly} onChange={(id) => set('favicon_media_id', id)} />
                            </div>
                        </div>
                    </Card>
                )}

                {brand && (
                    <Card>
                        <CardHeader title="Colores" description="El color de la marca va en botones y acentos; el rojo de oferta, en precios rebajados y etiquetas." />
                        {offerSuggestion && suggested && !readOnly && (
                            <button
                                type="button"
                                onClick={() =>
                                    setDraft((current) => ({
                                        ...current,
                                        accent: suggested.accent?.toUpperCase() ?? current.accent,
                                        deal_color: suggested.deal_color?.toUpperCase() ?? current.deal_color,
                                    }))
                                }
                                className="mb-4 flex w-full items-center gap-3 rounded-[12px] border border-dashed border-[var(--line-2)] px-3 py-2.5 text-left hover:border-[var(--accent)]"
                            >
                                <span className="flex shrink-0 -space-x-1.5">
                                    {[suggested.accent, suggested.deal_color].filter(Boolean).map((color) => (
                                        <span key={color} className="size-7 rounded-full border-2 border-[var(--surface)]" style={{ backgroundColor: color }} />
                                    ))}
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block text-[13px] font-semibold">Usar los colores sugeridos por la plantilla</span>
                                    <span className="block text-[12px] text-[var(--muted)]">
                                        {suggestion?.template ? `Los de «${suggestion.template}». ` : ''}Se guardan como cualquier cambio de color; puedes volver a los tuyos cuando quieras.
                                    </span>
                                </span>
                            </button>
                        )}
                        <p className="mb-2 text-[12.5px] font-medium">Color de la marca</p>
                        <Swatches colors={SWATCHES} value={draft.accent} disabled={readOnly} onPick={(color) => set('accent', color)} />
                        <div className="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_56px]">
                            <Input label="Color exacto" value={draft.accent} disabled={readOnly} onChange={(event) => set('accent', event.target.value.toUpperCase())} error={errors.accent} hint="Formato #RRGGBB." />
                            <input
                                type="color"
                                aria-label="Elegir el color de la marca"
                                disabled={readOnly}
                                value={/^#[0-9a-f]{6}$/i.test(draft.accent) ? draft.accent : '#0E6E4E'}
                                onChange={(event) => set('accent', event.target.value.toUpperCase())}
                                className="mt-6 h-11 w-14 cursor-pointer rounded-[10px] border border-[var(--line)] bg-transparent"
                            />
                        </div>
                        <ContrastNote
                            ratio={accentRatio}
                            ok={accentRatio >= 4.5}
                            good={`los botones llevan letra ${ink(draft.accent) === '#FFFFFF' ? 'blanca' : 'oscura'} encima y se leen bien.`}
                            bad="no se guarda: el texto encima no se lee."
                        />

                        <p className="mb-2 mt-5 text-[12.5px] font-medium">Rojo de oferta</p>
                        <Swatches colors={DEAL_SWATCHES} value={draft.deal_color} disabled={readOnly} onPick={(color) => set('deal_color', color)} />
                        <div className="mt-3">
                            <Input label="Color exacto" value={draft.deal_color} disabled={readOnly} onChange={(event) => set('deal_color', event.target.value.toUpperCase())} error={errors.deal_color} />
                        </div>
                        <ContrastNote ratio={dealRatio} ok={dealRatio >= 4.5} good="el precio de oferta se lee sobre blanco." bad="no se guarda: sobre blanco no se lee (necesita 4,5:1)." />
                    </Card>
                )}

                <Card>
                    <CardHeader title="Tipografía" description="La letra de toda la tienda, títulos y precios incluidos." />
                    <div className="grid gap-2 sm:grid-cols-2">
                        {(choices.font_pair ?? []).map((font) => (
                            <button
                                key={font.value}
                                type="button"
                                disabled={shape}
                                onClick={() => set('font_pair', font.value as ThemeRow['font_pair'])}
                                aria-pressed={draft.font_pair === font.value}
                                className={cn(
                                    'flex min-h-[84px] flex-col items-start gap-1 rounded-[12px] border p-3 text-left transition-colors disabled:opacity-70',
                                    draft.font_pair === font.value ? 'border-[var(--accent)] bg-[var(--accent-soft)]' : 'border-[var(--line)] hover:border-[var(--line-2)]',
                                )}
                            >
                                <span className="text-[11.5px] font-medium uppercase tracking-wide text-[var(--muted)]">{font.label}</span>
                                <span className="text-[20px] font-extrabold leading-tight" style={{ fontFamily: FONT_SAMPLES[font.value] }}>
                                    Arroz $ 12.900
                                </span>
                            </button>
                        ))}
                    </div>
                </Card>

                <Card>
                    <CardHeader title="Formas y esquema" />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Select label="Esquinas" options={choices.radius ?? []} disabled={shape} value={draft.radius} onChange={(event) => set('radius', event.target.value as ThemeRow['radius'])} />
                        <Select label="Densidad" options={choices.density ?? []} disabled={shape} value={draft.density} onChange={(event) => set('density', event.target.value as ThemeRow['density'])} />
                        <Select label="Botones" options={choices.button_style ?? []} disabled={shape} value={draft.button_style} onChange={(event) => set('button_style', event.target.value as ThemeRow['button_style'])} />
                        <Select label="Forma de los botones" options={choices.button_shape ?? []} disabled={shape} value={draft.button_shape} onChange={(event) => set('button_shape', event.target.value as ThemeRow['button_shape'])} />
                        <Select label="Esquema" options={choices.scheme ?? []} disabled={shape} value={draft.scheme} onChange={(event) => set('scheme', event.target.value as ThemeRow['scheme'])} />
                        <Select
                            label="Fondo de la tienda"
                            options={choices.surface ?? []}
                            disabled={shape}
                            value={draft.surface}
                            onChange={(event) => set('surface', event.target.value as ThemeRow['surface'])}
                            hint="En esquema oscuro manda el oscuro."
                        />
                        <Select label="Ancho de la página" options={choices.container ?? []} disabled={shape} value={draft.container} onChange={(event) => set('container', event.target.value as ThemeRow['container'])} />
                        <Select label="Títulos" options={choices.heading_case ?? []} disabled={shape} value={draft.heading_case} onChange={(event) => set('heading_case', event.target.value as ThemeRow['heading_case'])} />
                        <Select
                            label="Letra del nombre"
                            options={choices.logo_font ?? []}
                            disabled={shape}
                            value={draft.logo_font}
                            onChange={(event) => set('logo_font', event.target.value as ThemeRow['logo_font'])}
                            hint="Se nota cuando el encabezado muestra solo el nombre."
                        />
                    </div>
                    <div className="mt-3">
                        <Toggle
                            label="El cliente puede cambiar entre claro y oscuro"
                            hint="El interruptor sale en el pie de la tienda, nunca en el encabezado."
                            checked={draft.allow_scheme_toggle}
                            disabled={shape}
                            onChange={(value) => set('allow_scheme_toggle', value)}
                        />
                    </div>
                    <p className="mt-3 rounded-[9px] bg-[var(--surface-2)] px-3 py-2 text-[12px] text-[var(--muted)]">
                        Los botones y las filas que se tocan se mantienen en 44 px aunque la densidad sea compacta: en un celular, un blanco más pequeño se falla.
                    </p>
                </Card>

                <StructureSection settingKey="card" initial={settings.card} readOnly={readOnly} designLocked={locked} options={structureOptions} endpoints={endpoints} onSaved={onSaved} />
            </div>

            <div className="min-h-[520px] min-w-0">{preview}</div>
        </div>
    );
}

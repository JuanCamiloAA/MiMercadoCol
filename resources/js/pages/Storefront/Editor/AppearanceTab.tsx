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
import type { EditorContext, Endpoints, SettingsRow, StructureOptions, ThemeRow } from './types';

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
export function AppearanceTab({ context, theme, settings, readOnly, locked, onPersonalize, structureOptions, endpoints, onSaved, preview, errors }: AppearanceTabProps) {
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
                        <Select label="Esquema" options={choices.scheme ?? []} disabled={shape} value={draft.scheme} onChange={(event) => set('scheme', event.target.value as ThemeRow['scheme'])} />
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

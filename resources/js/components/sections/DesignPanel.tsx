import { ArrowCounterClockwiseIcon, ArrowsOutIcon, CaretDownIcon, ImageSquareIcon, MagicWandIcon, type Icon } from '@phosphor-icons/react';
import { type ReactNode, useState } from 'react';
import { SchemeSwitch, useEditorScheme } from '@/components/sections/editor-scheme';
import { Select, Toggle } from '@/components/ui/Field';
import { cn } from '@/lib/cn';
import { isHex, normalize } from '@/lib/color';

/**
 * La pestaña «Diseño» de una sección, la misma en el editor de la tienda, en el de las plantillas
 * y en el de la landing.
 *
 * El formulario se arma solo con el catálogo que manda el servidor (`SectionAppearance::groups`):
 * agregar un ajuste allá no obliga a tocar este archivo. Lo que se guarda es solo lo que difiere
 * del defecto del tipo de bloque; volver a un valor de fábrica lo quita, y «Restablecer» deja la
 * sección como venía.
 *
 * Los archivos de fondo son distintos en cada superficie (en la tienda un id de la biblioteca, en
 * la landing una ruta subida): los pinta quien usa el panel, con `renderMedia`.
 */

type Dict = Record<string, unknown>;

export interface DesignField {
    type: 'select' | 'toggle' | 'range' | 'color' | 'media' | 'video' | 'image';
    label: string;
    help?: string;
    options?: { value: string; label: string }[];
    /** Los colores con nombre del tema de la superficie. */
    tokens?: { value: string; label: string }[];
    min?: number;
    max?: number;
    step?: number;
    unit?: string;
    show_if?: { field: string; value: string | string[] };
}

export interface DesignGroup {
    label: string;
    icon: string;
    help?: string;
    /** Puede ser distinto en modo oscuro: se guarda aparte, en `appearance.dark`. */
    themed?: boolean;
    fields: Record<string, DesignField>;
}

export type DesignCatalogue = Record<string, DesignGroup>;

const GROUP_ICONS: Record<string, Icon> = {
    ArrowsOut: ArrowsOutIcon,
    ImageSquare: ImageSquareIcon,
    MagicWand: MagicWandIcon,
};

interface DesignPanelProps {
    catalogue: DesignCatalogue;
    /** El diseño de fábrica del tipo de bloque (`appearance_defaults`). */
    defaults: Dict;
    /** Lo guardado: solo lo que alguien cambió. */
    value: Dict;
    readOnly?: boolean;
    onChange: (next: Dict) => void;
    /** Cómo se ve cada color con nombre en esta superficie (para la muestra). */
    swatches?: Record<string, string>;
    /** El campo de un archivo de fondo (`media`, `video` o `image`). */
    renderMedia: (key: string, field: DesignField, value: unknown, set: (value: unknown) => void) => ReactNode;
}

/** Si un campo se muestra con lo elegido. Ocultarlo no borra su valor. */
function shown(field: DesignField, values: Dict): boolean {
    if (!field.show_if) {
        return true;
    }

    const expected = Array.isArray(field.show_if.value) ? field.show_if.value : [field.show_if.value];

    return expected.map(String).includes(String(values[field.show_if.field] ?? ''));
}

const same = (a: unknown, b: unknown): boolean => String(a ?? '') === String(b ?? '');

export function DesignPanel({ catalogue, defaults, value, readOnly = false, onChange, swatches = {}, renderMedia }: DesignPanelProps) {
    const [open, setOpen] = useState<Record<string, boolean>>({ size: true });
    // El modo que se revisa (Claro | Oscuro), el mismo de la vista previa.
    const { scheme } = useEditorScheme();
    const dark = scheme === 'dark';
    const light: Dict = { ...value };
    delete light.dark;
    const overrides: Dict = value.dark && typeof value.dark === 'object' && !Array.isArray(value.dark) ? (value.dark as Dict) : {};
    const lightValues: Dict = { ...defaults, ...light };
    const changed = Object.keys(value).length > 0;

    /** Lo que se ve en cada grupo: en oscuro, el fondo con lo de oscuro encima; lo demás es lo mismo en los dos. */
    const valuesFor = (group: DesignGroup): Dict => (dark && group.themed ? { ...lightValues, ...overrides } : lightValues);

    const setLight = (key: string, next: unknown) => {
        const copy = { ...value };

        // Lo de fábrica no se guarda: así la sección sigue al defecto de su tipo.
        if (next === null || next === undefined || next === '' || same(next, defaults[key])) {
            delete copy[key];
        } else {
            copy[key] = next;
        }

        onChange(copy);
    };

    const setDark = (key: string, next: unknown) => {
        const copy = { ...overrides };

        // Lo igual a modo claro no se guarda: así sigue a claro si allá cambia.
        if (next === null || next === undefined || next === '' || same(next, lightValues[key])) {
            delete copy[key];
        } else {
            copy[key] = next;
        }

        const updated = { ...value };

        if (Object.keys(copy).length > 0) {
            updated.dark = copy;
        } else {
            delete updated.dark;
        }

        onChange(updated);
    };

    const groupChanged = (group: DesignGroup) => Object.keys(group.fields).some((key) => (dark && group.themed ? key in overrides : key in light));

    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center justify-between gap-2 rounded-card bg-[var(--surface-2)] px-3 py-2">
                <p className="min-w-0 flex-1 text-[0.75rem] text-[var(--muted)]">
                    {dark
                        ? 'Modo oscuro: el fondo, el velo y el color del texto pueden ser otros. Lo que no cambies queda como en claro.'
                        : 'Modo claro. Cambia a oscuro para darle a la sección otro fondo cuando la página se vea oscura.'}
                </p>
                <SchemeSwitch />
            </div>

            {Object.entries(catalogue).map(([name, group]) => {
                const GroupIcon = GROUP_ICONS[group.icon] ?? ArrowsOutIcon;
                const expanded = open[name] ?? false;
                const values = valuesFor(group);
                const shared = dark && !group.themed;

                return (
                    <section key={name} className="rounded-card border border-[var(--line)]">
                        <button
                            type="button"
                            aria-expanded={expanded}
                            onClick={() => setOpen((current) => ({ ...current, [name]: !expanded }))}
                            className="flex min-h-11 w-full items-center gap-2 px-3 text-left"
                        >
                            <GroupIcon size={16} className="shrink-0 text-[var(--muted)]" />
                            <span className="flex-1 text-[0.84375rem] font-semibold">
                                {group.label}
                                {shared && <span className="ml-1.5 text-[0.71875rem] font-normal text-[var(--muted)]">· igual en claro y oscuro</span>}
                                {dark && group.themed && <span className="ml-1.5 text-[0.71875rem] font-normal text-[var(--muted)]">· modo oscuro</span>}
                            </span>
                            {groupChanged(group) && <span className="size-2 rounded-full bg-[var(--accent)]" title="Con cambios" />}
                            <CaretDownIcon size={14} className={cn('shrink-0 text-[var(--muted)] transition-transform', expanded && 'rotate-180')} />
                        </button>

                        {expanded && (
                            <div className="flex flex-col gap-3.5 border-t border-[var(--line)] p-3">
                                {group.help && <p className="text-[0.75rem] text-[var(--muted)]">{group.help}</p>}
                                {Object.entries(group.fields)
                                    .filter(([, field]) => shown(field, values))
                                    .map(([key, field]) => (
                                        <DesignInput
                                            // Con el modo cambia lo que se edita: el campo arranca de nuevo.
                                            key={`${dark && group.themed ? 'dark' : 'light'}-${key}`}
                                            name={key}
                                            field={field}
                                            value={values[key]}
                                            readOnly={readOnly}
                                            swatches={swatches}
                                            onChange={(next) => (dark && group.themed ? setDark(key, next) : setLight(key, next))}
                                            renderMedia={renderMedia}
                                        />
                                    ))}
                            </div>
                        )}
                    </section>
                );
            })}

            {!readOnly && dark && (
                <button
                    type="button"
                    disabled={Object.keys(overrides).length === 0}
                    onClick={() => {
                        const updated = { ...value };
                        delete updated.dark;
                        onChange(updated);
                    }}
                    className="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-control text-[0.8125rem] font-medium text-[var(--muted)] hover:text-[var(--text)] disabled:opacity-40"
                >
                    <ArrowCounterClockwiseIcon size={14} /> Dejar el fondo igual que en modo claro
                </button>
            )}

            {!readOnly && !dark && (
                <button
                    type="button"
                    disabled={!changed}
                    onClick={() => onChange({})}
                    className="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-control text-[0.8125rem] font-medium text-[var(--muted)] hover:text-[var(--text)] disabled:opacity-40"
                >
                    <ArrowCounterClockwiseIcon size={14} /> Restablecer el diseño (claro y oscuro)
                </button>
            )}
        </div>
    );
}

function DesignInput({
    name,
    field,
    value,
    readOnly,
    swatches,
    onChange,
    renderMedia,
}: {
    name: string;
    field: DesignField;
    value: unknown;
    readOnly: boolean;
    swatches: Record<string, string>;
    onChange: (value: unknown) => void;
    renderMedia: DesignPanelProps['renderMedia'];
}) {
    switch (field.type) {
        case 'select':
            return (
                <Select
                    label={field.label}
                    hint={field.help}
                    options={field.options ?? []}
                    disabled={readOnly}
                    value={String(value ?? '')}
                    onChange={(event) => onChange(event.target.value)}
                />
            );

        case 'toggle':
            return <Toggle label={field.label} hint={field.help} disabled={readOnly} checked={value === true} onChange={onChange} />;

        case 'range': {
            const number = typeof value === 'number' ? value : Number(value ?? field.min ?? 0);

            return (
                <label className="flex flex-col gap-1.5 text-[0.8125rem] font-medium">
                    <span className="flex items-center justify-between gap-2">
                        {field.label}
                        <span className="tabular-nums text-[var(--muted)]">
                            {number}
                            {field.unit ?? ''}
                        </span>
                    </span>
                    <input
                        type="range"
                        min={field.min}
                        max={field.max}
                        step={field.step}
                        disabled={readOnly}
                        value={number}
                        onChange={(event) => onChange(Number(event.target.value))}
                        className="w-full accent-[var(--accent)]"
                    />
                    {field.help && <span className="text-[0.75rem] font-normal text-[var(--muted)]">{field.help}</span>}
                </label>
            );
        }

        case 'color':
            return <ColorInput field={field} value={typeof value === 'string' ? value : ''} readOnly={readOnly} swatches={swatches} onChange={onChange} />;

        default:
            return <>{renderMedia(name, field, value, onChange)}</>;
    }
}

/** Un color con nombre del tema, o uno propio. Con nombre sigue a la marca si la marca cambia. */
function ColorInput({
    field,
    value,
    readOnly,
    swatches,
    onChange,
}: {
    field: DesignField;
    value: string;
    readOnly: boolean;
    swatches: Record<string, string>;
    onChange: (value: unknown) => void;
}) {
    const custom = isHex(value) ? normalize(value) : null;

    return (
        <div className="flex flex-col gap-1.5">
            <p className="text-[0.8125rem] font-medium">{field.label}</p>
            <div className="flex flex-wrap gap-1.5" role="radiogroup" aria-label={field.label}>
                {(field.tokens ?? []).map((token) => (
                    <button
                        key={token.value}
                        type="button"
                        role="radio"
                        aria-checked={value === token.value}
                        disabled={readOnly}
                        onClick={() => onChange(token.value)}
                        title={token.label}
                        className={cn(
                            'inline-flex min-h-9 items-center gap-1.5 rounded-full border px-2.5 text-[0.75rem] font-medium transition-colors',
                            value === token.value ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]' : 'border-[var(--line)] text-[var(--muted)] hover:text-[var(--text)]',
                        )}
                    >
                        <span className="size-3.5 shrink-0 rounded-full border border-[var(--line-2)]" style={{ background: swatches[token.value] ?? 'transparent' }} />
                        {token.label}
                    </button>
                ))}
                <label
                    className={cn(
                        'inline-flex min-h-9 cursor-pointer items-center gap-1.5 rounded-full border px-2.5 text-[0.75rem] font-medium',
                        custom ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]' : 'border-[var(--line)] text-[var(--muted)] hover:text-[var(--text)]',
                    )}
                >
                    <input
                        type="color"
                        disabled={readOnly}
                        value={custom ?? '#0E6E4E'}
                        onChange={(event) => onChange(event.target.value.toUpperCase())}
                        className="size-4 cursor-pointer rounded-full border-0 bg-transparent p-0"
                    />
                    {custom ?? 'Propio'}
                </label>
            </div>
            {field.help && <p className="text-[0.75rem] text-[var(--muted)]">{field.help}</p>}
        </div>
    );
}

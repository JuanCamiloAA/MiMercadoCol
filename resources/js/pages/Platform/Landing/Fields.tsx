import { ArrowDownIcon, ArrowUpIcon, ImageIcon, PlusIcon, TrashIcon } from '@phosphor-icons/react';
import { useState } from 'react';
import { ICONS } from '@/components/landing/LandingBlocks';
import { Input, Select, Textarea, Toggle } from '@/components/ui/Field';
import { cn } from '@/lib/cn';
import { csrfHeaders, errorMessage } from '@/lib/http';

export interface FieldSchema {
    type: 'text' | 'textarea' | 'url' | 'toggle' | 'select' | 'image' | 'icon' | 'color' | 'repeater';
    label: string;
    help?: string;
    max?: number;
    options?: { value: string; label: string }[];
    singular?: string;
    max_items?: number;
    item?: Record<string, FieldSchema>;
    default?: unknown;
}

export type Values = Record<string, unknown>;

interface FieldProps {
    name: string;
    field: FieldSchema;
    value: unknown;
    /** La direccion de una imagen ya guardada (el valor es la ruta en el disco). */
    imageUrl?: string | null;
    icons: string[];
    onChange: (value: unknown, imageUrl?: string | null) => void;
}

/**
 * Un campo del editor de la landing. El formulario se arma solo con lo que diga `fields()` en el
 * servidor: aquí no se sabe de qué bloque se trata.
 */
export function LandingField({ name, field, value, imageUrl, icons, onChange }: FieldProps) {
    switch (field.type) {
        case 'text':
        case 'url':
            return (
                <Input
                    label={field.label}
                    hint={field.help}
                    value={typeof value === 'string' ? value : ''}
                    maxLength={field.max}
                    placeholder={field.type === 'url' ? '#tiendas, /contratar, https://…' : undefined}
                    onChange={(event) => onChange(event.target.value)}
                />
            );
        case 'textarea':
            return <Textarea label={field.label} hint={field.help} rows={3} maxLength={field.max} value={typeof value === 'string' ? value : ''} onChange={(event) => onChange(event.target.value)} />;
        case 'toggle':
            return <Toggle checked={value === true} onChange={(checked) => onChange(checked)} label={field.label} hint={field.help} />;
        case 'select':
            return <Select label={field.label} value={typeof value === 'string' ? value : ''} options={field.options ?? []} onChange={(event) => onChange(event.target.value)} />;
        case 'color':
            return (
                <label className="flex flex-col gap-1.5 text-[0.8125rem] font-medium">
                    {field.label}
                    <span className="flex items-center gap-2">
                        <input type="color" value={typeof value === 'string' ? value : '#0E6E4E'} onChange={(event) => onChange(event.target.value.toUpperCase())} className="size-10 rounded-control border border-[var(--line)]" />
                        <span className="font-mono text-[0.8125rem]">{typeof value === 'string' ? value : ''}</span>
                    </span>
                </label>
            );
        case 'icon':
            return <IconField label={field.label} value={typeof value === 'string' ? value : ''} icons={icons} onChange={onChange} />;
        case 'image':
            return <ImageField name={name} label={field.label} help={field.help} path={typeof value === 'string' ? value : null} url={imageUrl ?? null} onChange={onChange} />;
        case 'repeater':
            return <RepeaterField field={field} value={Array.isArray(value) ? (value as Values[]) : []} icons={icons} onChange={onChange} />;
        default:
            return null;
    }
}

function IconField({ label, value, icons, onChange }: { label: string; value: string; icons: string[]; onChange: (value: unknown) => void }) {
    return (
        <div>
            <p className="mb-1.5 text-[0.8125rem] font-medium">{label}</p>
            <div className="flex flex-wrap gap-1">
                {icons.map((name) => {
                    const IconComponent = ICONS[name];

                    if (!IconComponent) {
                        return null;
                    }

                    return (
                        <button
                            key={name}
                            type="button"
                            title={name}
                            onClick={() => onChange(name)}
                            className={cn(
                                'grid size-9 place-items-center rounded-control border',
                                value === name ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]' : 'border-[var(--line)] text-[var(--muted)] hover:text-[var(--text)]',
                            )}
                        >
                            <IconComponent size={18} />
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

/**
 * Sube la imagen al servidor (JPG, PNG o WebP, hasta 4 MB) y guarda su ruta en el bloque.
 */
export function ImageField({
    name,
    label,
    help,
    path,
    url,
    onChange,
}: {
    name: string;
    label: string;
    help?: string;
    path: string | null;
    url: string | null;
    onChange: (value: unknown, imageUrl?: string | null) => void;
}) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const upload = async (file: File) => {
        setBusy(true);
        setError(null);

        const body = new FormData();
        body.append('image', file);

        try {
            const response = await fetch(route('platform.landing.upload'), {
                method: 'POST',
                headers: { Accept: 'application/json', ...csrfHeaders() },
                credentials: 'same-origin',
                body,
            });

            if (!response.ok) {
                setError(await errorMessage(response, 'No se pudo subir la imagen.'));

                return;
            }

            const result = (await response.json()) as { path: string; url: string };
            onChange(result.path, result.url);
        } catch {
            setError('No se pudo subir la imagen. Revisa la conexión.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div>
            <p className="mb-1.5 text-[0.8125rem] font-medium">{label}</p>
            <div className="flex items-center gap-3">
                <span className="grid size-16 shrink-0 place-items-center overflow-hidden rounded-control border border-[var(--line)] bg-[var(--surface-2)]">
                    {url && path ? <img src={url} alt="" className="size-full object-cover" /> : <ImageIcon size={22} className="text-[var(--muted)]" />}
                </span>
                <div className="flex flex-wrap gap-2 text-[0.8125rem]">
                    <label className="inline-flex h-9 cursor-pointer items-center rounded-control border border-[var(--line)] px-3 font-medium hover:bg-[var(--surface-2)]">
                        {busy ? 'Subiendo…' : path ? 'Cambiar' : 'Subir imagen'}
                        <input
                            type="file"
                            name={name}
                            accept="image/jpeg,image/png,image/webp"
                            className="sr-only"
                            disabled={busy}
                            onChange={(event) => {
                                const file = event.target.files?.[0];

                                if (file) {
                                    void upload(file);
                                }

                                event.target.value = '';
                            }}
                        />
                    </label>
                    {path && (
                        <button type="button" onClick={() => onChange(null, null)} className="h-9 rounded-control px-2 text-[var(--muted)] hover:text-[var(--danger)]">
                            Quitar
                        </button>
                    )}
                </div>
            </div>
            {help && <p className="mt-1 text-[0.75rem] text-[var(--muted)]">{help}</p>}
            {error && <p className="mt-1 text-[0.75rem] font-medium text-[var(--danger)]">{error}</p>}
        </div>
    );
}

function RepeaterField({ field, value, icons, onChange }: { field: FieldSchema; value: Values[]; icons: string[]; onChange: (value: unknown) => void }) {
    const item = field.item ?? {};
    const max = field.max_items ?? 12;

    const blank = (): Values => Object.fromEntries(Object.entries(item).map(([key, sub]) => [key, sub.default ?? (sub.type === 'toggle' ? false : '')]));

    const set = (index: number, key: string, next: unknown) => onChange(value.map((row, position) => (position === index ? { ...row, [key]: next } : row)));

    const move = (index: number, delta: number) => {
        const target = index + delta;

        if (target < 0 || target >= value.length) {
            return;
        }

        const next = [...value];
        [next[index], next[target]] = [next[target], next[index]];
        onChange(next);
    };

    return (
        <div>
            <p className="mb-1.5 text-[0.8125rem] font-medium">{field.label}</p>
            <div className="flex flex-col gap-2">
                {value.map((row, index) => (
                    <div key={index} className="rounded-control border border-[var(--line)] bg-[var(--surface-2)] p-3">
                        <div className="mb-2 flex items-center justify-between gap-2">
                            <span className="text-[0.75rem] font-semibold uppercase tracking-wide text-[var(--muted)]">
                                {field.singular ?? 'Elemento'} {index + 1}
                            </span>
                            <span className="flex gap-1">
                                <IconButton label="Subir" onClick={() => move(index, -1)} disabled={index === 0}>
                                    <ArrowUpIcon size={14} />
                                </IconButton>
                                <IconButton label="Bajar" onClick={() => move(index, 1)} disabled={index === value.length - 1}>
                                    <ArrowDownIcon size={14} />
                                </IconButton>
                                <IconButton label="Quitar" onClick={() => onChange(value.filter((_, position) => position !== index))}>
                                    <TrashIcon size={14} />
                                </IconButton>
                            </span>
                        </div>
                        <div className="flex flex-col gap-2.5">
                            {Object.entries(item).map(([key, sub]) => (
                                <LandingField key={key} name={key} field={sub} value={row[key]} icons={icons} onChange={(next) => set(index, key, next)} />
                            ))}
                        </div>
                    </div>
                ))}
                {value.length < max && (
                    <button
                        type="button"
                        onClick={() => onChange([...value, blank()])}
                        className="inline-flex h-9 w-fit items-center gap-1.5 rounded-control border border-dashed border-[var(--line-2)] px-3 text-[0.8125rem] font-medium text-[var(--muted)] hover:text-[var(--text)]"
                    >
                        <PlusIcon size={14} /> Agregar {field.singular ?? 'elemento'}
                    </button>
                )}
            </div>
        </div>
    );
}

function IconButton({ label, onClick, disabled, children }: { label: string; onClick: () => void; disabled?: boolean; children: React.ReactNode }) {
    return (
        <button
            type="button"
            title={label}
            aria-label={label}
            onClick={onClick}
            disabled={disabled}
            className="grid size-7 place-items-center rounded-[0.5rem] text-[var(--muted)] hover:bg-[var(--surface)] hover:text-[var(--text)] disabled:opacity-30"
        >
            {children}
        </button>
    );
}

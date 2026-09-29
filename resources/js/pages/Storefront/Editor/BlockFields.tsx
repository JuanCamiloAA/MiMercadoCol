import { CameraIcon, ImageIcon, MagnifyingGlassIcon, PlusIcon, TrashIcon, XIcon } from '@phosphor-icons/react';
import { createContext, useContext, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Input, Select, Textarea, Toggle } from '@/components/ui/Field';
import { cn } from '@/lib/cn';
import { csrfHeaders, errorMessage } from '@/lib/http';
import { StoreIcon } from '@/pages/Storefront/blocks/StoreBlocks';
import type { Dict, FieldSchema, MediaConfig, MediaRow, Option, TargetKind, TargetValue } from './types';

/**
 * Lo que necesitan los campos y no cambia de uno a otro: los íconos, las opciones que salen de
 * la base, la biblioteca de imágenes, las páginas a las que se puede enlazar y los destinos
 * permitidos. En la plantilla no hay categorías ni artículos de ninguna empresa.
 */
export interface EditorEnv {
    icons: string[];
    fieldOptions: Record<string, { value: string; label: string }[]>;
    media: MediaConfig;
    pages: Option[];
    targetKinds: TargetKind[];
    productsUrl: string;
}

export const EditorEnvContext = createContext<EditorEnv>({
    icons: [],
    fieldOptions: {},
    media: { max_kb: 4096, accept: '.jpg,.jpeg,.png,.webp', index: '', store: '' },
    pages: [],
    targetKinds: ['page', 'search', 'url'],
    productsUrl: '',
});

export function useEditorEnv(): EditorEnv {
    return useContext(EditorEnvContext);
}

/** Si un campo se muestra según lo que diga otro. */
export function isVisible(field: Pick<FieldSchema, 'show_if'>, values: Dict): boolean {
    if (!field.show_if) {
        return true;
    }

    const current = String(values[field.show_if.field] ?? '');
    const expected = Array.isArray(field.show_if.value) ? field.show_if.value.map(String) : [String(field.show_if.value)];

    return expected.includes(current);
}

interface FieldProps {
    name: string;
    field: FieldSchema;
    value: unknown;
    readOnly: boolean;
    onChange: (value: unknown) => void;
}

/** Un campo del formulario, según lo que diga el esquema del tipo de bloque. */
export function BlockField({ name, field, value, readOnly, onChange }: FieldProps) {
    const env = useEditorEnv();
    const options = field.options_from ? (env.fieldOptions[field.options_from] ?? []) : (field.options ?? []);

    switch (field.type) {
        case 'textarea':
        case 'markdown':
            return (
                <Textarea
                    label={field.label}
                    hint={field.help}
                    rows={field.rows ?? 3}
                    maxLength={field.max}
                    disabled={readOnly}
                    value={String(value ?? '')}
                    onChange={(event) => onChange(event.target.value)}
                />
            );

        case 'select':
            return (
                <Select
                    label={field.label}
                    hint={field.help}
                    options={options.map((option) => ({ value: String(option.value), label: option.label }))}
                    placeholder={field.options_from ? 'Elige una opción' : undefined}
                    disabled={readOnly}
                    value={String(value ?? '')}
                    onChange={(event) => {
                        const picked = options.find((option) => String(option.value) === event.target.value);
                        onChange(picked ? picked.value : event.target.value);
                    }}
                />
            );

        case 'number':
            return (
                <Input
                    label={field.label}
                    hint={field.help}
                    type="number"
                    min={field.min}
                    max={field.max}
                    step={field.step}
                    disabled={readOnly}
                    value={String(value ?? '')}
                    onChange={(event) => onChange(Number(event.target.value))}
                />
            );

        case 'toggle':
            return <Toggle label={field.label} hint={field.help} disabled={readOnly} checked={value !== false} onChange={onChange} />;

        case 'datetime':
            return (
                <Input
                    label={field.label}
                    hint={field.help}
                    type="datetime-local"
                    disabled={readOnly}
                    value={String(value ?? '')}
                    onChange={(event) => onChange(event.target.value || null)}
                />
            );

        case 'icon':
            return <IconField label={field.label} value={String(value ?? '')} readOnly={readOnly} onChange={onChange} />;

        case 'media':
            return <MediaField label={field.label} help={field.help} value={value as number | null} readOnly={readOnly} onChange={onChange} />;

        case 'target':
            return (
                <TargetField
                    label={field.label}
                    help={field.help}
                    kinds={field.kinds}
                    value={value as TargetValue | null}
                    readOnly={readOnly}
                    onChange={onChange}
                />
            );

        case 'repeater':
            return <RepeaterField field={field} readOnly={readOnly} value={(value as Dict[] | undefined) ?? []} onChange={onChange} />;

        case 'categories':
            return (
                <ChipPicker
                    label={field.label}
                    options={env.fieldOptions.categories ?? []}
                    value={((value as (string | number)[] | undefined) ?? []).map(Number)}
                    readOnly={readOnly}
                    onChange={onChange}
                />
            );

        case 'products':
            return <ProductPicker label={field.label} value={(value as number[] | undefined) ?? []} readOnly={readOnly} onChange={onChange} />;

        default:
            return (
                <Input
                    label={field.label}
                    hint={field.help}
                    maxLength={field.max}
                    disabled={readOnly}
                    value={String(value ?? '')}
                    onChange={(event) => onChange(event.target.value)}
                    name={name}
                />
            );
    }
}

export function IconField({
    label,
    value,
    readOnly,
    onChange,
    allowEmpty = false,
}: {
    label: string;
    value: string;
    readOnly: boolean;
    onChange: (value: string | null) => void;
    allowEmpty?: boolean;
}) {
    const { icons } = useEditorEnv();

    return (
        <div>
            <p className="mb-1.5 text-[12.5px] font-medium text-[var(--text)]">{label}</p>
            <div className="grid max-h-[132px] grid-cols-8 gap-1 overflow-y-auto rounded-[10px] border border-[var(--line)] p-1.5">
                {allowEmpty && (
                    <button
                        type="button"
                        disabled={readOnly}
                        onClick={() => onChange(null)}
                        title="Sin ícono"
                        className={cn(
                            'grid aspect-square min-h-[34px] place-items-center rounded-[7px] text-[11px] transition-colors',
                            value === '' ? 'bg-[var(--accent)] text-[var(--accent-ink)]' : 'text-[var(--muted)] hover:bg-[var(--surface-2)]',
                        )}
                    >
                        —
                    </button>
                )}
                {icons.map((icon) => (
                    <button
                        key={icon}
                        type="button"
                        disabled={readOnly}
                        onClick={() => onChange(icon)}
                        title={icon}
                        aria-label={icon}
                        aria-pressed={value === icon}
                        className={cn(
                            'grid aspect-square min-h-[34px] place-items-center rounded-[7px] transition-colors',
                            value === icon ? 'bg-[var(--accent)] text-[var(--accent-ink)]' : 'text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]',
                        )}
                    >
                        <StoreIcon name={icon} size={16} />
                    </button>
                ))}
            </div>
        </div>
    );
}

/**
 * La biblioteca se pide una sola vez por pantalla: un banner con seis laminas tiene doce campos
 * de imagen, y cada uno pidiendola por su lado serian doce peticiones iguales.
 */
const libraries = new Map<string, Promise<MediaRow[]>>();

function loadLibrary(url: string, fresh = false): Promise<MediaRow[]> {
    if (fresh || !libraries.has(url)) {
        libraries.set(
            url,
            fetch(url, { headers: { Accept: 'application/json' } })
                .then((response) => (response.ok ? response.json() : { media: [] }))
                .then((result: { media: MediaRow[] }) => result.media)
                .catch(() => []),
        );
    }

    return libraries.get(url) as Promise<MediaRow[]>;
}

/**
 * Elige una imagen de la biblioteca o sube una nueva.
 *
 * El texto alternativo se pide al subir y no se puede dejar en blanco: una imagen sin
 * descripción no existe para quien usa lector de pantalla. En la plantilla la biblioteca es la
 * de la plataforma.
 */
export function MediaField({
    label,
    help,
    value,
    readOnly,
    onChange,
    compact = false,
}: {
    label: string;
    help?: string;
    value: number | null;
    readOnly: boolean;
    onChange: (value: number | null) => void;
    compact?: boolean;
}) {
    const { media } = useEditorEnv();
    const [library, setLibrary] = useState<MediaRow[]>([]);
    const [uploading, setUploading] = useState(false);
    const [alt, setAlt] = useState('');
    const [pending, setPending] = useState<File | null>(null);
    const input = useRef<HTMLInputElement>(null);
    const camera = useRef<HTMLInputElement>(null);

    const pick = (event: React.ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];

        if (file) {
            setPending(file);
            setAlt('');
        }

        // Se limpia para que elegir dos veces la misma foto vuelva a disparar el cambio.
        event.target.value = '';
    };

    useEffect(() => {
        if (!media.index) {
            return;
        }

        let alive = true;
        loadLibrary(media.index).then((rows) => alive && setLibrary(rows));

        return () => {
            alive = false;
        };
    }, [media.index]);

    const chosen = library.find((item) => item.id === value) ?? null;

    const upload = () => {
        if (!pending || alt.trim().length < 3) {
            toast.error('Describe la imagen antes de subirla.');

            return;
        }

        setUploading(true);

        const body = new FormData();
        body.append('image', pending);
        body.append('alt', alt);

        fetch(media.store, {
            method: 'POST',
            body,
            headers: { ...csrfHeaders(), Accept: 'application/json' },
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(await errorMessage(response, 'No se pudo subir la imagen.'));
                }

                return (await response.json()) as { media: MediaRow };
            })
            .then((result) => {
                setLibrary((current) => [result.media, ...current]);
                // Los demas campos la veran la proxima vez que pidan la biblioteca.
                void loadLibrary(media.index, true);
                onChange(result.media.id);
                setPending(null);
                setAlt('');
            })
            .catch((error: Error) => toast.error(error.message))
            .finally(() => setUploading(false));
    };

    return (
        <div>
            <p className="mb-1.5 text-[12.5px] font-medium text-[var(--text)]">{label}</p>

            {chosen ? (
                <div className="relative overflow-hidden rounded-[12px] border border-[var(--line)]">
                    <img src={chosen.variants?.wide ?? chosen.url} alt={chosen.alt ?? ''} className={cn('w-full bg-[var(--surface-2)] object-cover', compact ? 'h-[84px]' : 'h-[120px]')} />
                    {!readOnly && (
                        <button
                            type="button"
                            onClick={() => onChange(null)}
                            className="absolute right-2 top-2 min-h-[32px] rounded-[8px] bg-black/60 px-2.5 text-[11.5px] font-medium text-white"
                        >
                            Quitar
                        </button>
                    )}
                    <p className={cn('border-t border-[var(--line)] px-2.5 py-1.5 text-[11.5px]', chosen.alt ? 'text-[var(--muted)]' : 'font-semibold text-[var(--danger)]')}>
                        {chosen.alt || 'Sin texto alternativo: no se puede publicar así.'}
                    </p>
                </div>
            ) : value ? (
                <p className="rounded-[10px] border border-dashed border-[var(--line-2)] px-3 py-2 text-[12px] text-[var(--muted)]">
                    Imagen #{value}{' '}
                    {!readOnly && (
                        <button type="button" className="font-semibold text-[var(--accent)]" onClick={() => onChange(null)}>
                            Quitar
                        </button>
                    )}
                </p>
            ) : (
                <>
                    {library.length > 0 && !readOnly && (
                        <div className="mb-2 grid max-h-[124px] grid-cols-4 gap-1.5 overflow-y-auto rounded-[10px] border border-[var(--line)] p-1.5">
                            {library.map((item) => (
                                <button
                                    key={item.id}
                                    type="button"
                                    onClick={() => onChange(item.id)}
                                    title={item.alt ?? ''}
                                    className="overflow-hidden rounded-[8px] border border-transparent hover:border-[var(--accent)]"
                                >
                                    <img src={item.variants?.square ?? item.url} alt={item.alt ?? ''} className="h-[54px] w-full object-cover" />
                                </button>
                            ))}
                        </div>
                    )}

                    {pending ? (
                        <div className="rounded-[12px] border border-[var(--line)] p-3">
                            <p className="mb-2 truncate text-[12.5px] text-[var(--muted)]">{pending.name}</p>
                            <Input
                                label="Describe la imagen"
                                value={alt}
                                onChange={(event) => setAlt(event.target.value)}
                                hint="Obligatorio. Es lo que lee quien no la puede ver."
                                placeholder="Canasta con frutas y verduras frescas"
                            />
                            <div className="mt-2 flex gap-2">
                                <Button size="sm" loading={uploading} onClick={upload}>
                                    Subir
                                </Button>
                                <Button size="sm" variant="ghost" onClick={() => setPending(null)}>
                                    Cancelar
                                </Button>
                            </div>
                        </div>
                    ) : (
                        !readOnly && (
                            <div className="flex flex-col gap-2">
                                <button
                                    type="button"
                                    onClick={() => input.current?.click()}
                                    className={cn(
                                        'flex w-full flex-col items-center justify-center gap-1.5 rounded-[12px] border border-dashed border-[var(--line-2)] text-[var(--muted)] transition-colors hover:border-[var(--accent)] hover:bg-[var(--surface-2)]',
                                        compact ? 'h-[64px]' : 'h-[88px]',
                                    )}
                                >
                                    <ImageIcon size={22} />
                                    <span className="text-[12.5px]">Subir una imagen · máximo {Math.round(media.max_kb / 1024)} MB</span>
                                </button>

                                {/* En el celular la foto suele estar en la cámara, no en la galería. */}
                                <button
                                    type="button"
                                    onClick={() => camera.current?.click()}
                                    className="flex min-h-[44px] w-full items-center justify-center gap-1.5 rounded-[11px] border border-[var(--line)] text-[13px] font-medium text-[var(--muted)] hover:text-[var(--text)] md:hidden"
                                >
                                    <CameraIcon size={17} />
                                    Tomar una foto
                                </button>
                            </div>
                        )
                    )}
                </>
            )}

            {help && <p className="mt-1 text-[11.5px] text-[var(--muted)]">{help}</p>}

            <input ref={input} type="file" accept={media.accept} className="hidden" onChange={pick} />
            <input ref={camera} type="file" accept="image/*" capture="environment" className="hidden" onChange={pick} />
        </div>
    );
}

const KIND_LABELS: Record<TargetKind, string> = {
    category: 'Una categoría',
    page: 'Una página',
    search: 'Una búsqueda',
    url: 'Un enlace',
    whatsapp: 'WhatsApp de la sede',
};

/** El destino de un botón: una categoría, una página de la tienda, una búsqueda, un enlace o WhatsApp. */
export function TargetField({
    label,
    help,
    kinds,
    value,
    readOnly,
    onChange,
    optional = true,
}: {
    label: string;
    help?: string;
    kinds?: TargetKind[];
    value: TargetValue | null;
    readOnly: boolean;
    onChange: (value: TargetValue | null) => void;
    optional?: boolean;
}) {
    const env = useEditorEnv();
    const allowed = (kinds ?? env.targetKinds).filter((kind) => env.targetKinds.includes(kind) || kind === 'whatsapp');
    const kind = value?.kind ?? '';

    return (
        <div>
            <p className="mb-1.5 text-[12.5px] font-medium text-[var(--text)]">{label}</p>

            <div className="grid grid-cols-2 gap-2">
                <Select
                    options={[
                        ...(optional ? [{ value: '', label: 'Sin destino' }] : []),
                        ...allowed.map((item) => ({ value: item, label: KIND_LABELS[item] })),
                    ]}
                    disabled={readOnly}
                    value={kind}
                    onChange={(event) => onChange(event.target.value === '' ? null : { kind: event.target.value as TargetKind, value: '' })}
                />

                {kind === 'category' && (
                    <Select
                        options={env.fieldOptions.categories ?? []}
                        placeholder="Elige la categoría"
                        disabled={readOnly}
                        value={String(value?.value ?? '')}
                        onChange={(event) => onChange({ kind: 'category', value: event.target.value })}
                    />
                )}

                {kind === 'page' && (
                    <Select
                        options={env.pages}
                        placeholder="Elige la página"
                        disabled={readOnly}
                        value={String(value?.value ?? '')}
                        onChange={(event) => onChange({ kind: 'page', value: event.target.value })}
                    />
                )}

                {(kind === 'url' || kind === 'search' || kind === 'whatsapp') && (
                    <Input
                        disabled={readOnly}
                        value={String(value?.value ?? '')}
                        placeholder={kind === 'url' ? 'https://… o /tienda/…' : kind === 'search' ? 'Qué se busca: arroz' : 'Mensaje: Hola, quiero pedir…'}
                        onChange={(event) => onChange({ kind, value: event.target.value })}
                    />
                )}
            </div>

            {help && <p className="mt-1 text-[11.5px] text-[var(--muted)]">{help}</p>}
        </div>
    );
}

function RepeaterField({
    field,
    readOnly,
    value,
    onChange,
}: {
    field: FieldSchema;
    readOnly: boolean;
    value: Dict[];
    onChange: (value: Dict[]) => void;
}) {
    const item = field.item ?? {};
    const full = field.max_items !== undefined && value.length >= field.max_items;

    return (
        <div>
            <div className="mb-1.5 flex items-baseline justify-between">
                <p className="text-[12.5px] font-medium text-[var(--text)]">{field.label}</p>
                <span className="text-[11.5px] tabular-nums text-[var(--muted)]">
                    {value.length}
                    {field.max_items ? ` de ${field.max_items}` : ''}
                </span>
            </div>

            <div className="flex flex-col gap-2">
                {value.map((row, index) => (
                    <div key={index} className="rounded-[11px] border border-[var(--line)] bg-[var(--surface-2)] p-2.5">
                        <div className="mb-2 flex items-center justify-between">
                            <span className="text-[11.5px] font-semibold uppercase tracking-wide text-[var(--muted)]">
                                {field.singular ?? 'fila'} {index + 1}
                            </span>
                            {!readOnly && (
                                <span className="flex items-center gap-0.5">
                                    <button
                                        type="button"
                                        disabled={index === 0}
                                        onClick={() => {
                                            const next = [...value];
                                            [next[index - 1], next[index]] = [next[index], next[index - 1]];
                                            onChange(next);
                                        }}
                                        aria-label="Subir"
                                        className="grid size-8 place-items-center rounded-[6px] text-[var(--muted)] hover:text-[var(--text)] disabled:opacity-30"
                                    >
                                        ↑
                                    </button>
                                    <button
                                        type="button"
                                        disabled={index === value.length - 1}
                                        onClick={() => {
                                            const next = [...value];
                                            [next[index + 1], next[index]] = [next[index], next[index + 1]];
                                            onChange(next);
                                        }}
                                        aria-label="Bajar"
                                        className="grid size-8 place-items-center rounded-[6px] text-[var(--muted)] hover:text-[var(--text)] disabled:opacity-30"
                                    >
                                        ↓
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => onChange(value.filter((_, position) => position !== index))}
                                        aria-label="Quitar"
                                        className="grid size-8 place-items-center rounded-[6px] text-[var(--muted)] hover:bg-[var(--danger-soft)] hover:text-[var(--danger)]"
                                    >
                                        <TrashIcon size={13} />
                                    </button>
                                </span>
                            )}
                        </div>

                        <div className="flex flex-col gap-2.5">
                            {Object.entries(item)
                                .filter(([, schema]) => isVisible(schema, row))
                                .map(([key, schema]) => (
                                    <BlockField
                                        key={key}
                                        name={key}
                                        field={schema}
                                        value={row[key]}
                                        readOnly={readOnly}
                                        onChange={(next) => onChange(value.map((entry, position) => (position === index ? { ...entry, [key]: next } : entry)))}
                                    />
                                ))}
                        </div>
                    </div>
                ))}
            </div>

            {!readOnly && (
                <Button
                    variant="secondary"
                    size="sm"
                    className="mt-2"
                    disabled={full}
                    icon={<PlusIcon size={14} weight="bold" />}
                    onClick={() => onChange([...value, { ...(field.item_defaults ?? {}) }])}
                >
                    Agregar {field.singular ?? 'fila'}
                </Button>
            )}
        </div>
    );
}

export function ChipPicker({
    label,
    options,
    value,
    readOnly,
    onChange,
    help,
}: {
    label: string;
    options: { value: string; label: string }[];
    value: number[];
    readOnly: boolean;
    onChange: (value: number[]) => void;
    help?: string;
}) {
    return (
        <div>
            <p className="mb-1.5 text-[12.5px] font-medium text-[var(--text)]">{label}</p>
            <div className="flex flex-wrap gap-1.5">
                {options.map((option) => {
                    const id = Number(option.value);
                    const on = value.includes(id);

                    return (
                        <button
                            key={option.value}
                            type="button"
                            disabled={readOnly}
                            aria-pressed={on}
                            onClick={() => onChange(on ? value.filter((item) => item !== id) : [...value, id])}
                            className={cn(
                                'min-h-[36px] rounded-[9px] border px-2.5 text-[12.5px] font-medium transition-colors',
                                on ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]' : 'border-[var(--line)] text-[var(--muted)] hover:text-[var(--text)]',
                            )}
                        >
                            {option.label}
                        </button>
                    );
                })}
            </div>
            {help && <p className="mt-1 text-[11.5px] text-[var(--muted)]">{help}</p>}
        </div>
    );
}

function ProductPicker({ label, value, readOnly, onChange }: { label: string; value: number[]; readOnly: boolean; onChange: (value: number[]) => void }) {
    const { productsUrl } = useEditorEnv();
    const [search, setSearch] = useState('');
    const [hits, setHits] = useState<{ id: number; name: string; sku: string }[]>([]);
    const [names, setNames] = useState<Record<number, string>>({});

    useEffect(() => {
        if (!productsUrl) {
            return;
        }

        const timer = setTimeout(() => {
            fetch(`${productsUrl}?search=${encodeURIComponent(search)}`, { headers: { Accept: 'application/json' } })
                .then((response) => (response.ok ? response.json() : []))
                .then((rows: { id: number; name: string; sku: string }[]) => {
                    setHits(rows);
                    setNames((current) => {
                        const next = { ...current };
                        rows.forEach((row) => {
                            next[row.id] = row.name;
                        });

                        return next;
                    });
                })
                .catch(() => setHits([]));
        }, 300);

        return () => clearTimeout(timer);
    }, [search, productsUrl]);

    return (
        <div>
            <p className="mb-1.5 text-[12.5px] font-medium text-[var(--text)]">{label}</p>

            <div className="relative">
                <MagnifyingGlassIcon size={15} className="pointer-events-none absolute left-3 top-3 text-[var(--muted)]" />
                <input
                    type="text"
                    value={search}
                    disabled={readOnly}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Buscar por nombre o SKU"
                    className="h-11 w-full rounded-[10px] border border-[var(--line)] bg-[var(--surface-2)] pl-9 pr-3 text-[13.5px] focus:border-[var(--accent)] focus:outline-none"
                />
            </div>

            <ul className="mt-1.5 max-h-[124px] overflow-y-auto rounded-[10px] border border-[var(--line)]">
                {hits.length === 0 && <li className="px-3 py-2 text-[12.5px] text-[var(--muted)]">Ningún artículo coincide.</li>}
                {hits.map((hit) => (
                    <li key={hit.id}>
                        <button
                            type="button"
                            disabled={readOnly || value.includes(hit.id)}
                            onClick={() => onChange([...value, hit.id])}
                            className="flex min-h-[38px] w-full items-center justify-between px-3 py-1.5 text-left text-[12.5px] hover:bg-[var(--surface-2)] disabled:opacity-40"
                        >
                            <span className="truncate">{hit.name}</span>
                            <span className="ml-2 shrink-0 font-mono text-[11px] text-[var(--muted)]">{hit.sku}</span>
                        </button>
                    </li>
                ))}
            </ul>

            {value.length > 0 && (
                <ul className="mt-2 flex flex-wrap gap-1.5">
                    {value.map((id) => (
                        <li key={id} className="flex min-h-[32px] items-center gap-1.5 rounded-[8px] border border-[var(--line)] bg-[var(--surface-2)] px-2 text-[12px]">
                            {names[id] ?? `Artículo ${id}`}
                            {!readOnly && (
                                <button type="button" onClick={() => onChange(value.filter((item) => item !== id))} aria-label="Quitar" className="text-[var(--muted)] hover:text-[var(--danger)]">
                                    <XIcon size={12} />
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

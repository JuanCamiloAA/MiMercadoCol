import { ArrowDownIcon, ArrowUpIcon, FilmStripIcon, ImageIcon, MoonIcon, PlusIcon, SunIcon, TrashIcon } from '@phosphor-icons/react';
import { useEffect, useState } from 'react';
import { ICONS } from '@/components/landing/LandingBlocks';
import { useEditorScheme } from '@/components/sections/editor-scheme';
import { Input, Select, Textarea, Toggle } from '@/components/ui/Field';
import { cn } from '@/lib/cn';
import { csrfHeaders, errorMessage } from '@/lib/http';

/** `['clave' => ['valor', …]]`: se cumple si el valor actual de cada clave está en su lista. */
export type FieldCondition = Record<string, unknown[]>;

export interface FieldSchema {
    type: 'text' | 'textarea' | 'url' | 'toggle' | 'select' | 'image' | 'video' | 'number' | 'icon' | 'color' | 'repeater';
    label: string;
    help?: string;
    /** Largo maximo de un texto; en un `number`, el valor maximo. */
    max?: number;
    min?: number;
    step?: number;
    /** Lo mas que pesa un video, en megas: se revisa antes de subirlo. */
    max_mb?: number;
    options?: { value: string; label: string; when?: FieldCondition }[];
    singular?: string;
    max_items?: number;
    item?: Record<string, FieldSchema>;
    default?: unknown;
    /** Subtítulo del grupo en el formulario. */
    section?: string;
    /** El campo solo se ve si se cumple. Ocultarlo no borra su valor. */
    when?: FieldCondition;
    /** Como se ve la miniatura de una imagen (ver `ImageField`). */
    preview?: 'cover' | 'logo' | 'logo-dark';
    /** Las versiones de una imagen o un video (`MediaVariants`): versión → clave hermana. */
    variants?: Partial<Record<'mobile' | 'dark' | 'mobile_dark', string>>;
    /** En una hermana: la clave del campo principal, que la edita junto con las demás. */
    variant_of?: string;
}

export type Values = Record<string, unknown>;

/** Si una condición `when` se cumple con los valores del bloque. Sin condición, siempre. */
export function applies(when: FieldCondition | undefined, values: Values): boolean {
    return !when || Object.entries(when).every(([key, allowed]) => allowed.some((value) => String(value) === String(values[key])));
}

interface FieldProps {
    name: string;
    field: FieldSchema;
    value: unknown;
    /** Todos los valores del bloque: un select puede ofrecer opciones según otro campo. */
    values?: Values;
    /** La direccion de una imagen o un video ya guardado (el valor es la ruta en el disco). */
    imageUrl?: string | null;
    /** Las direcciones de lo subido dentro de las listas, por ruta (`LandingBlock::mediaUrlMap`). */
    mediaUrls?: Record<string, string | null>;
    icons: string[];
    /** `urls`: lo que se acaba de subir dentro de una lista, ruta → direccion. */
    onChange: (value: unknown, imageUrl?: string | null, urls?: Record<string, string | null>) => void;
}

/**
 * Un campo del editor de la landing. El formulario se arma solo con lo que diga `fields()` en el
 * servidor: aquí no se sabe de qué bloque se trata.
 */
export function LandingField({ name, field, value, values = {}, imageUrl, mediaUrls, icons, onChange }: FieldProps) {
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
        case 'select': {
            const options = (field.options ?? []).filter((item) => applies(item.when, values)).map(({ value: optionValue, label }) => ({ value: optionValue, label }));
            // Si el valor guardado ya no se ofrece, se ve la primera: la misma que deja el servidor al guardar.
            const current = options.some((item) => item.value === String(value ?? '')) ? String(value) : (options[0]?.value ?? '');

            return <Select label={field.label} hint={field.help} value={current} options={options} onChange={(event) => onChange(event.target.value)} />;
        }
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
        case 'number':
            return (
                <Input
                    label={field.label}
                    hint={field.help}
                    type="number"
                    inputMode="decimal"
                    min={field.min}
                    max={field.max}
                    step={field.step}
                    value={typeof value === 'number' ? value : ''}
                    onChange={(event) => onChange(event.target.value === '' ? null : Number(event.target.value))}
                />
            );
        case 'video':
            return <VideoField name={name} label={field.label} help={field.help} maxMb={field.max_mb} path={typeof value === 'string' ? value : null} url={imageUrl ?? null} onChange={onChange} />;
        case 'image':
            return <ImageField name={name} label={field.label} help={field.help} preview={field.preview} path={typeof value === 'string' ? value : null} url={imageUrl ?? null} onChange={onChange} />;
        case 'repeater':
            return <RepeaterField field={field} value={Array.isArray(value) ? (value as Values[]) : []} mediaUrls={mediaUrls} icons={icons} onChange={onChange} />;
        default:
            return null;
    }
}

type KeyChange = (key: string, value: unknown, imageUrl?: string | null, urls?: Record<string, string | null>) => void;

/**
 * Un campo dentro de unos datos (el bloque o una fila de una lista). Las versiones de una imagen
 * o un video (celular, modo oscuro) se editan juntas en su campo principal; las hermanas
 * (`variant_of`) no se pintan solas.
 *
 * `urlOf` da la dirección de lo ya subido en una clave: en el bloque va por clave y en las listas
 * por ruta (`LandingBlock::mediaUrlMap`).
 */
export function LandingFieldRow({
    name,
    field,
    values,
    urlOf,
    mediaUrls,
    icons,
    onChange,
}: {
    name: string;
    field: FieldSchema;
    values: Values;
    urlOf: (key: string) => string | null;
    mediaUrls?: Record<string, string | null>;
    icons: string[];
    onChange: KeyChange;
}) {
    if (field.variant_of) {
        return null;
    }

    if (field.variants && (field.type === 'image' || field.type === 'video')) {
        return <LandingMediaVariants name={name} field={field} values={values} urlOf={urlOf} onChange={onChange} />;
    }

    return (
        <LandingField
            name={name}
            field={field}
            value={values[name]}
            values={values}
            imageUrl={urlOf(name)}
            mediaUrls={mediaUrls}
            icons={icons}
            onChange={(value, imageUrl, urls) => onChange(name, value, imageUrl, urls)}
        />
    );
}

/**
 * Las cuatro versiones de una imagen o un video de la landing: computador y celular, en modo
 * claro y en modo oscuro. Se ven las cuatro casillas; la que se toca se abre abajo para subir.
 * Arranca abierta la del modo que se está revisando. Solo la de computador en claro cuenta como
 * la principal: las demás, si faltan, se reemplazan (la de celular por la de computador, la
 * oscura por la clara).
 */
function LandingMediaVariants({ name, field, values, urlOf, onChange }: { name: string; field: FieldSchema; values: Values; urlOf: (key: string) => string | null; onChange: KeyChange }) {
    const { scheme } = useEditorScheme();
    const video = field.type === 'video';
    const variants = field.variants ?? {};
    const slots = [
        { key: name, device: 'Computador', mode: 'light' as const, hint: field.help ?? '' },
        ...(variants.mobile ? [{ key: variants.mobile, device: 'Celular', mode: 'light' as const, hint: 'Opcional. Si falta, se recorta la de computador.' }] : []),
        ...(variants.dark ? [{ key: variants.dark, device: 'Computador', mode: 'dark' as const, hint: 'Opcional. Si falta, en modo oscuro se usa la de modo claro.' }] : []),
        ...(variants.mobile_dark
            ? [{ key: variants.mobile_dark, device: 'Celular', mode: 'dark' as const, hint: 'Opcional. Si falta, se usa la de computador en oscuro y, si tampoco está, la clara.' }]
            : []),
    ];
    const desktopFor = (mode: 'light' | 'dark') => slots.find((slot) => slot.mode === mode)?.key ?? name;
    const [active, setActive] = useState(() => desktopFor(scheme));

    // Al cambiar el modo que se revisa, se abre la casilla de ese modo.
    useEffect(() => {
        setActive(desktopFor(scheme));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [scheme]);

    const current = slots.find((slot) => slot.key === active) ?? slots[0];
    const path = typeof values[current.key] === 'string' && values[current.key] !== '' ? (values[current.key] as string) : null;
    const label = `${field.label} · ${current.device.toLowerCase()} · modo ${current.mode === 'light' ? 'claro' : 'oscuro'}`;
    const set = (next: unknown, url?: string | null) => onChange(current.key, next, url);

    return (
        <div>
            <p className="mb-1.5 text-[0.8125rem] font-medium">{field.label}</p>
            <div className="grid grid-cols-2 gap-2">
                {(['light', 'dark'] as const).map((mode) => (
                    <div key={mode}>
                        <p className="mb-1 flex items-center gap-1 text-[0.65625rem] font-semibold uppercase tracking-wide text-[var(--muted)]">
                            {mode === 'light' ? <SunIcon size={12} /> : <MoonIcon size={12} />}
                            Modo {mode === 'light' ? 'claro' : 'oscuro'}
                        </p>
                        <div className="grid grid-cols-2 gap-1.5">
                            {slots
                                .filter((slot) => slot.mode === mode)
                                .map((slot) => {
                                    const url = typeof values[slot.key] === 'string' && values[slot.key] !== '' ? urlOf(slot.key) : null;

                                    return (
                                        <button
                                            key={slot.key}
                                            type="button"
                                            onClick={() => setActive(slot.key)}
                                            aria-pressed={active === slot.key}
                                            title={`${slot.device} · modo ${slot.mode === 'light' ? 'claro' : 'oscuro'}`}
                                            className={cn(
                                                'relative aspect-[16/11] overflow-hidden rounded-[0.5625rem] border-2 bg-[var(--surface-2)]',
                                                active === slot.key ? 'border-[var(--accent)]' : 'border-transparent hover:border-[var(--line-2)]',
                                                slot.mode === 'dark' && !url && 'bg-[#1C1F24]',
                                            )}
                                        >
                                            {url ? (
                                                video ? (
                                                    <video src={`${url}#t=0.5`} muted preload="metadata" className="pointer-events-none size-full object-cover" />
                                                ) : (
                                                    <img src={url} alt="" className="size-full object-cover" />
                                                )
                                            ) : (
                                                <span className={cn('grid size-full place-items-center', slot.mode === 'dark' ? 'text-white/50' : 'text-[var(--muted)]')}>
                                                    {video ? <FilmStripIcon size={18} /> : <ImageIcon size={18} />}
                                                </span>
                                            )}
                                            <span className="absolute inset-x-0 bottom-0 truncate bg-black/55 px-1.5 py-0.5 text-[0.65625rem] font-semibold text-white">
                                                {slot.device === 'Computador' ? 'PC' : slot.device}
                                            </span>
                                        </button>
                                    );
                                })}
                        </div>
                    </div>
                ))}
            </div>
            <div className="mt-2.5 rounded-control border border-[var(--line)] p-2.5">
                {video ? (
                    <VideoField key={current.key} name={current.key} label={label} help={current.hint || undefined} maxMb={field.max_mb} path={path} url={path ? urlOf(current.key) : null} onChange={set} />
                ) : (
                    <ImageField key={current.key} name={current.key} label={label} help={current.hint || undefined} preview={field.preview} path={path} url={path ? urlOf(current.key) : null} onChange={set} />
                )}
            </div>
        </div>
    );
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
    preview = 'cover',
}: {
    name: string;
    label: string;
    help?: string;
    path: string | null;
    url: string | null;
    onChange: (value: unknown, imageUrl?: string | null) => void;
    /** `logo`: entera y a lo ancho; `logo-dark`, ademas sobre fondo oscuro (el logo blanco no se ve en claro). */
    preview?: 'cover' | 'logo' | 'logo-dark';
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
                <span
                    data-theme={preview === 'logo-dark' ? 'dark' : undefined}
                    className={cn(
                        'grid h-16 shrink-0 place-items-center overflow-hidden rounded-control border border-[var(--line)] bg-[var(--surface-2)]',
                        preview === 'cover' ? 'w-16' : 'w-32 p-2',
                    )}
                >
                    {url && path ? (
                        <img src={url} alt="" className={cn('size-full', preview === 'cover' ? 'object-cover' : 'object-contain')} />
                    ) : (
                        <ImageIcon size={22} className="text-[var(--muted)]" />
                    )}
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

/**
 * Sube un video (MP4 o WebM) y guarda su ruta en el bloque. Va con `XMLHttpRequest` y no con
 * `fetch` para mostrar cuanto lleva: un video tarda en subir. El peso se revisa antes, para no
 * subir veinte megas que el servidor va a rechazar.
 */
export function VideoField({
    name,
    label,
    help,
    maxMb,
    path,
    url,
    onChange,
}: {
    name: string;
    label: string;
    help?: string;
    maxMb?: number;
    path: string | null;
    url: string | null;
    onChange: (value: unknown, videoUrl?: string | null) => void;
}) {
    const [progress, setProgress] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);

    const upload = (file: File) => {
        if (maxMb && file.size > maxMb * 1024 * 1024) {
            setError(`El video pesa más de ${maxMb} MB. Recórtalo o comprímelo antes de subirlo.`);

            return;
        }

        setError(null);
        setProgress(0);

        const body = new FormData();
        body.append('video', file);

        const request = new XMLHttpRequest();
        request.open('POST', route('platform.landing.upload-video'));
        request.withCredentials = true;
        request.setRequestHeader('Accept', 'application/json');
        Object.entries(csrfHeaders()).forEach(([header, value]) => request.setRequestHeader(header, value));

        request.upload.onprogress = (event) => {
            if (event.lengthComputable) {
                setProgress(Math.round((event.loaded / event.total) * 100));
            }
        };

        request.onload = async () => {
            setProgress(null);

            if (request.status >= 200 && request.status < 300) {
                const result = JSON.parse(request.responseText) as { path: string; url: string };
                onChange(result.path, result.url);

                return;
            }

            setError(
                request.status === 413
                    ? 'El video pesa más de lo que acepta el servidor.'
                    : await errorMessage(new Response(request.responseText, { status: request.status }), 'No se pudo subir el video.'),
            );
        };

        request.onerror = () => {
            setProgress(null);
            setError('No se pudo subir el video. Revisa la conexión.');
        };

        request.send(body);
    };

    return (
        <div>
            <p className="mb-1.5 text-[0.8125rem] font-medium">{label}</p>
            <div className="flex items-center gap-3">
                <span className="grid h-16 w-28 shrink-0 place-items-center overflow-hidden rounded-control border border-[var(--line)] bg-[var(--surface-2)]">
                    {url && path ? <video src={url} muted loop autoPlay playsInline className="size-full object-cover" /> : <FilmStripIcon size={22} className="text-[var(--muted)]" />}
                </span>
                <div className="flex flex-wrap gap-2 text-[0.8125rem]">
                    <label className="inline-flex h-9 cursor-pointer items-center rounded-control border border-[var(--line)] px-3 font-medium hover:bg-[var(--surface-2)]">
                        {progress !== null ? `Subiendo… ${progress} %` : path ? 'Cambiar' : 'Subir video'}
                        <input
                            type="file"
                            name={name}
                            accept="video/mp4,video/webm"
                            className="sr-only"
                            disabled={progress !== null}
                            onChange={(event) => {
                                const file = event.target.files?.[0];

                                if (file) {
                                    upload(file);
                                }

                                event.target.value = '';
                            }}
                        />
                    </label>
                    {path && progress === null && (
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

function RepeaterField({
    field,
    value,
    mediaUrls,
    icons,
    onChange,
}: {
    field: FieldSchema;
    value: Values[];
    mediaUrls?: Record<string, string | null>;
    icons: string[];
    onChange: (value: unknown, imageUrl?: string | null, urls?: Record<string, string | null>) => void;
}) {
    const item = field.item ?? {};
    const max = field.max_items ?? 12;

    const blank = (): Values => Object.fromEntries(Object.entries(item).map(([key, sub]) => [key, sub.default ?? (sub.type === 'toggle' ? false : '')]));

    const set = (index: number, key: string, next: unknown, urls?: Record<string, string | null>) =>
        onChange(
            value.map((row, position) => (position === index ? { ...row, [key]: next } : row)),
            undefined,
            urls,
        );

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
                                <LandingFieldRow
                                    key={key}
                                    name={key}
                                    field={sub}
                                    values={row}
                                    // En las listas las direcciones van por ruta.
                                    urlOf={(slot) => (typeof row[slot] === 'string' ? (mediaUrls?.[row[slot] as string] ?? null) : null)}
                                    mediaUrls={mediaUrls}
                                    icons={icons}
                                    onChange={(slot, next, url) => set(index, slot, next, typeof next === 'string' && url ? { [next]: url } : undefined)}
                                />
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

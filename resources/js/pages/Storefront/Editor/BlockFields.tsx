import { CameraIcon, FilmStripIcon, ImageIcon, MagnifyingGlassIcon, MoonIcon, PlusIcon, SunIcon, TrashIcon, XIcon } from '@phosphor-icons/react';
import { createContext, useContext, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { SchemeSwitch, useEditorScheme } from '@/components/sections/editor-scheme';
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
    media: { max_kb: 4096, accept: '.jpg,.jpeg,.png,.webp', index: '', store: '', video_max_kb: 25600, video_accept: 'video/mp4,video/webm', video_store: '' },
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

        case 'video':
            return <VideoField label={field.label} help={field.help} value={value as number | null} readOnly={readOnly} onChange={onChange} />;

        case 'color':
            return (
                <label className="flex flex-col gap-1.5 text-[12.5px] font-medium text-[var(--text)]">
                    {field.label}
                    <span className="flex items-center gap-2">
                        <input
                            type="color"
                            disabled={readOnly}
                            value={typeof value === 'string' ? value : '#000000'}
                            onChange={(event) => onChange(event.target.value.toUpperCase())}
                            className="h-10 w-12 rounded-[8px] border border-[var(--line)]"
                        />
                        <span className="font-mono text-[12.5px]">{typeof value === 'string' ? value : ''}</span>
                    </span>
                    {field.help && <span className="text-[11.5px] font-normal text-[var(--muted)]">{field.help}</span>}
                </label>
            );

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

        case 'style':
            // El diseño de un banner (`BannerStyle`): sus selects guardan juntos en un objeto, y
            // «Como el bloque» (`auto`) no se guarda.
            return (
                <div className="flex flex-col gap-2.5">
                    {Object.entries(field.fields ?? {}).map(([key, sub]) => {
                        const current = value && typeof value === 'object' ? (value as Dict) : {};

                        return (
                            <BlockField
                                key={key}
                                name={`${name}.${key}`}
                                field={sub}
                                value={current[key] ?? 'auto'}
                                readOnly={readOnly}
                                onChange={(next) => {
                                    const copy = { ...current };

                                    if (next === 'auto' || next === '' || next == null) {
                                        delete copy[key];
                                    } else {
                                        copy[key] = next;
                                    }

                                    onChange(copy);
                                }}
                            />
                        );
                    })}
                </div>
            );

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

/** La biblioteca de videos: la misma dirección que la de imágenes, pidiendo solo videos. */
function videoLibraryUrl(index: string): string {
    return index === '' ? '' : `${index}${index.includes('?') ? '&' : '?'}kind=video`;
}

/**
 * Elige un video de la biblioteca o sube uno nuevo (MP4 o WebM). Como la imagen, pide su
 * descripción al subirlo. Va con `XMLHttpRequest` y no con `fetch` para mostrar cuánto lleva: un
 * video tarda en subir. El peso se revisa antes, para no subir algo que el servidor rechaza.
 */
export function VideoField({
    label,
    help,
    value,
    readOnly,
    onChange,
}: {
    label: string;
    help?: string;
    value: number | null;
    readOnly: boolean;
    onChange: (value: number | null) => void;
}) {
    const { media } = useEditorEnv();
    const libraryUrl = videoLibraryUrl(media.index);
    const [library, setLibrary] = useState<MediaRow[]>([]);
    const [pending, setPending] = useState<File | null>(null);
    const [alt, setAlt] = useState('');
    const [progress, setProgress] = useState<number | null>(null);
    const input = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (!libraryUrl) {
            return;
        }

        let alive = true;
        loadLibrary(libraryUrl).then((rows) => alive && setLibrary(rows));

        return () => {
            alive = false;
        };
    }, [libraryUrl]);

    const chosen = library.find((item) => item.id === value) ?? null;

    const pick = (event: React.ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        event.target.value = '';

        if (!file) {
            return;
        }

        if (file.size > media.video_max_kb * 1024) {
            toast.error(`El video pesa más de ${Math.round(media.video_max_kb / 1024)} MB. Recórtalo o comprímelo antes de subirlo.`);

            return;
        }

        setPending(file);
        setAlt('');
    };

    const upload = () => {
        if (!pending || alt.trim().length < 3) {
            toast.error('Describe el video antes de subirlo.');

            return;
        }

        const body = new FormData();
        body.append('video', pending);
        body.append('alt', alt);

        const request = new XMLHttpRequest();
        request.open('POST', media.video_store);
        request.withCredentials = true;
        request.setRequestHeader('Accept', 'application/json');
        Object.entries(csrfHeaders()).forEach(([header, headerValue]) => request.setRequestHeader(header, headerValue));
        request.upload.onprogress = (event) => event.lengthComputable && setProgress(Math.round((event.loaded / event.total) * 100));

        request.onload = async () => {
            setProgress(null);

            if (request.status >= 200 && request.status < 300) {
                const result = JSON.parse(request.responseText) as { media: MediaRow };
                setLibrary((current) => [result.media, ...current]);
                void loadLibrary(libraryUrl, true);
                onChange(result.media.id);
                setPending(null);
                setAlt('');

                return;
            }

            toast.error(
                request.status === 413
                    ? 'El video pesa más de lo que acepta el servidor.'
                    : await errorMessage(new Response(request.responseText, { status: request.status }), 'No se pudo subir el video.'),
            );
        };

        request.onerror = () => {
            setProgress(null);
            toast.error('No se pudo subir el video. Revisa la conexión.');
        };

        setProgress(0);
        request.send(body);
    };

    return (
        <div>
            <p className="mb-1.5 text-[12.5px] font-medium text-[var(--text)]">{label}</p>

            {chosen ? (
                <div className="relative overflow-hidden rounded-[12px] border border-[var(--line)]">
                    <video src={chosen.url} muted loop autoPlay playsInline className="h-[120px] w-full bg-[var(--surface-2)] object-cover" />
                    {!readOnly && (
                        <button
                            type="button"
                            onClick={() => onChange(null)}
                            className="absolute right-2 top-2 min-h-[32px] rounded-[8px] bg-black/60 px-2.5 text-[11.5px] font-medium text-white"
                        >
                            Quitar
                        </button>
                    )}
                    <p className="border-t border-[var(--line)] px-2.5 py-1.5 text-[11.5px] text-[var(--muted)]">{chosen.alt}</p>
                </div>
            ) : value ? (
                <p className="rounded-[10px] border border-dashed border-[var(--line-2)] px-3 py-2 text-[12px] text-[var(--muted)]">
                    Video #{value}{' '}
                    {!readOnly && (
                        <button type="button" className="font-semibold text-[var(--accent)]" onClick={() => onChange(null)}>
                            Quitar
                        </button>
                    )}
                </p>
            ) : (
                <>
                    {library.length > 0 && !readOnly && (
                        <div className="mb-2 grid max-h-[124px] grid-cols-3 gap-1.5 overflow-y-auto rounded-[10px] border border-[var(--line)] p-1.5">
                            {library.map((item) => (
                                <button
                                    key={item.id}
                                    type="button"
                                    onClick={() => onChange(item.id)}
                                    title={item.alt ?? ''}
                                    className="overflow-hidden rounded-[8px] border border-transparent hover:border-[var(--accent)]"
                                >
                                    {/* `#t=0.5`: el navegador muestra un cuadro del video sin reproducirlo. */}
                                    <video src={`${item.url}#t=0.5`} muted preload="metadata" className="pointer-events-none h-[54px] w-full bg-[var(--surface-2)] object-cover" />
                                </button>
                            ))}
                        </div>
                    )}

                    {pending ? (
                        <div className="rounded-[12px] border border-[var(--line)] p-3">
                            <p className="mb-2 truncate text-[12.5px] text-[var(--muted)]">{pending.name}</p>
                            <Input
                                label="Describe el video"
                                value={alt}
                                onChange={(event) => setAlt(event.target.value)}
                                hint="Obligatorio. Es lo que lee quien no lo puede ver."
                                placeholder="Así se arma un pedido en la tienda"
                            />
                            <div className="mt-2 flex gap-2">
                                <Button size="sm" loading={progress !== null} onClick={upload}>
                                    {progress !== null ? `Subiendo… ${progress} %` : 'Subir'}
                                </Button>
                                <Button size="sm" variant="ghost" disabled={progress !== null} onClick={() => setPending(null)}>
                                    Cancelar
                                </Button>
                            </div>
                        </div>
                    ) : (
                        !readOnly && (
                            <button
                                type="button"
                                onClick={() => input.current?.click()}
                                className="flex h-[88px] w-full flex-col items-center justify-center gap-1.5 rounded-[12px] border border-dashed border-[var(--line-2)] text-[var(--muted)] transition-colors hover:border-[var(--accent)] hover:bg-[var(--surface-2)]"
                            >
                                <FilmStripIcon size={22} />
                                <span className="text-[12.5px]">Subir un video · MP4 o WebM, máximo {Math.round(media.video_max_kb / 1024)} MB</span>
                            </button>
                        )
                    )}
                </>
            )}

            {help && <p className="mt-1 text-[11.5px] text-[var(--muted)]">{help}</p>}

            <input ref={input} type="file" accept={media.video_accept} className="hidden" onChange={pick} />
        </div>
    );
}

interface VariantSlot {
    key: string;
    device: string;
    mode: 'light' | 'dark';
    hint: string;
}

/**
 * Una imagen o un video con sus versiones (`App\Support\MediaVariants`): computador y celular, en
 * modo claro y en modo oscuro. Se ven las cuatro casillas a la vez; la que se toca se abre abajo
 * para escoger o subir. Arranca abierta la del modo que se está revisando.
 *
 * Solo la de computador en modo claro puede ser obligatoria: las demás, si faltan, se reemplazan
 * (la de celular por la de computador, la oscura por la clara).
 */
export function MediaVariantsField({
    name,
    field,
    values,
    readOnly,
    onPatch,
}: {
    name: string;
    field: FieldSchema;
    values: Dict;
    readOnly: boolean;
    onPatch: (patch: Dict) => void;
}) {
    const { media } = useEditorEnv();
    const { scheme } = useEditorScheme();
    const video = field.type === 'video';
    const variants = field.variants ?? {};
    const responsive = Boolean(variants.mobile || variants.mobile_dark);
    const slots: VariantSlot[] = [
        { key: name, device: responsive ? 'Computador' : 'Normal', mode: 'light', hint: field.help ?? '' },
        ...(variants.mobile ? [{ key: variants.mobile, device: 'Celular', mode: 'light' as const, hint: 'Opcional. Si falta, se recorta la de computador.' }] : []),
        ...(variants.dark
            ? [{ key: variants.dark, device: responsive ? 'Computador' : 'Fondo oscuro', mode: 'dark' as const, hint: 'Opcional. Si falta, en modo oscuro se usa la de modo claro.' }]
            : []),
        ...(variants.mobile_dark
            ? [{ key: variants.mobile_dark, device: 'Celular', mode: 'dark' as const, hint: 'Opcional. Si falta, se usa la de computador en oscuro y, si tampoco está, la clara.' }]
            : []),
    ];
    const desktopFor = (mode: 'light' | 'dark') => slots.find((slot) => slot.mode === mode)?.key ?? name;
    const [active, setActive] = useState<string>(() => desktopFor(scheme));
    const [library, setLibrary] = useState<MediaRow[]>([]);
    const libraryUrl = video ? videoLibraryUrl(media.index) : media.index;
    const ids = slots.map((slot) => values[slot.key] ?? '').join(',');

    // Al cambiar el modo que se revisa, se abre la casilla de ese modo.
    useEffect(() => {
        setActive(desktopFor(scheme));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [scheme]);

    // Las miniaturas salen de la misma biblioteca (pedida una vez por pantalla).
    useEffect(() => {
        if (!libraryUrl) {
            return;
        }

        let alive = true;
        loadLibrary(libraryUrl).then((rows) => alive && setLibrary(rows));

        return () => {
            alive = false;
        };
    }, [libraryUrl, ids]);

    const current = slots.find((slot) => slot.key === active) ?? slots[0];
    const value = (values[current.key] as number | null | undefined) ?? null;
    const label = `${field.label} · ${current.device.toLowerCase()} · modo ${current.mode === 'light' ? 'claro' : 'oscuro'}`;
    const change = (next: number | null) => onPatch({ [current.key]: next });

    const tile = (slot: VariantSlot) => {
        const id = values[slot.key] as number | null | undefined;
        const row = id ? library.find((item) => item.id === id) : null;

        return (
            <button
                key={slot.key}
                type="button"
                onClick={() => setActive(slot.key)}
                aria-pressed={active === slot.key}
                title={`${slot.device} · modo ${slot.mode === 'light' ? 'claro' : 'oscuro'}`}
                className={cn(
                    'relative aspect-[16/11] overflow-hidden rounded-[9px] border-2 bg-[var(--surface-2)] text-left transition-colors',
                    active === slot.key ? 'border-[var(--accent)]' : 'border-transparent hover:border-[var(--line-2)]',
                    slot.mode === 'dark' && !row && 'bg-[#1C1F24]',
                )}
            >
                {row ? (
                    video ? (
                        <video src={`${row.url}#t=0.5`} muted preload="metadata" className="pointer-events-none size-full object-cover" />
                    ) : (
                        <img src={row.variants?.square ?? row.url} alt="" className="size-full object-cover" />
                    )
                ) : (
                    <span className={cn('grid size-full place-items-center', slot.mode === 'dark' ? 'text-white/50' : 'text-[var(--muted)]')}>
                        {video ? <FilmStripIcon size={18} /> : <ImageIcon size={18} />}
                    </span>
                )}
                <span className="absolute inset-x-0 bottom-0 truncate bg-black/55 px-1.5 py-0.5 text-[10.5px] font-semibold text-white">{slot.device === 'Computador' ? 'PC' : slot.device}</span>
            </button>
        );
    };

    return (
        <div>
            <p className="mb-1.5 text-[12.5px] font-medium text-[var(--text)]">{field.label}</p>
            <div className="grid grid-cols-2 gap-2">
                {(['light', 'dark'] as const).map((mode) => (
                    <div key={mode}>
                        <p className="mb-1 flex items-center gap-1 text-[10.5px] font-semibold uppercase tracking-wide text-[var(--muted)]">
                            {mode === 'light' ? <SunIcon size={12} /> : <MoonIcon size={12} />}
                            Modo {mode === 'light' ? 'claro' : 'oscuro'}
                        </p>
                        <div className={cn('grid gap-1.5', responsive ? 'grid-cols-2' : 'grid-cols-1')}>{slots.filter((slot) => slot.mode === mode).map(tile)}</div>
                    </div>
                ))}
            </div>

            <div className="mt-2.5 rounded-[11px] border border-[var(--line)] p-2.5">
                {video ? (
                    <VideoField key={current.key} label={label} help={current.hint || undefined} value={value} readOnly={readOnly} onChange={change} />
                ) : (
                    <MediaField key={current.key} label={label} help={current.hint || undefined} value={value} readOnly={readOnly} onChange={change} compact />
                )}
            </div>
        </div>
    );
}

/**
 * Un campo dentro de unos datos (el bloque o una fila de una lista). Las versiones de una imagen
 * o un video se editan juntas en su campo principal; las hermanas (`variant_of`) no se pintan solas.
 */
export function FieldRow({ name, field, values, readOnly, onPatch }: { name: string; field: FieldSchema; values: Dict; readOnly: boolean; onPatch: (patch: Dict) => void }) {
    if (field.variant_of) {
        return null;
    }

    if (field.variants && (field.type === 'media' || field.type === 'video')) {
        return <MediaVariantsField name={name} field={field} values={values} readOnly={readOnly} onPatch={onPatch} />;
    }

    return <BlockField name={name} field={field} value={values[name]} readOnly={readOnly} onChange={(value) => onPatch({ [name]: value })} />;
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

/** Si el diseño de una fila tiene algo distinto de lo de fábrica (un `style` con claves, otro color u otro velo). */
function designChanged(item: Record<string, FieldSchema>, row: Dict, defaults: Dict): boolean {
    return Object.entries(item).some(([key, schema]) => {
        if (schema.group !== 'design') {
            return false;
        }

        const current = row[key];

        if (schema.type === 'style') {
            return Boolean(current && typeof current === 'object' && Object.keys(current).length > 0);
        }

        return current != null && current !== '' && String(current) !== String(defaults[key] ?? '');
    });
}

/**
 * «Diseño de esta lámina» en el modo que se revisa. En claro se edita la fila como siempre; en
 * oscuro, lo que cambia allá (`fila.dark`): color del texto, velo y estilo. Lo que en oscuro
 * queda igual que en claro no se guarda, así sigue a claro si allá cambia.
 */
function RowDesign({ item, row, defaults, readOnly, onChange }: { item: Record<string, FieldSchema>; row: Dict; defaults: Dict; readOnly: boolean; onChange: (row: Dict) => void }) {
    const { scheme } = useEditorScheme();
    const dark = scheme === 'dark';
    const overrides = row.dark && typeof row.dark === 'object' && !Array.isArray(row.dark) ? (row.dark as Dict) : {};
    const lightStyle = (row.style && typeof row.style === 'object' ? row.style : {}) as Dict;
    const shown: Dict = dark ? { ...row, ...overrides, style: { ...lightStyle, ...((overrides.style as Dict | undefined) ?? {}) } } : row;
    const fields = Object.entries(item).filter(([, schema]) => schema.group === 'design');
    const changedDark = Object.keys(overrides).length > 0;

    const set = (key: string, next: unknown) => {
        if (!dark) {
            onChange({ ...row, [key]: next });

            return;
        }

        const copy = { ...overrides };

        if (item[key]?.type === 'style') {
            const diff = Object.fromEntries(Object.entries((next as Dict | null) ?? {}).filter(([sub, value]) => String(value) !== String(lightStyle[sub] ?? '')));

            if (Object.keys(diff).length > 0) {
                copy.style = diff;
            } else {
                delete copy.style;
            }
        } else if (next == null || next === '' || String(next) === String(row[key] ?? defaults[key] ?? '')) {
            delete copy[key];
        } else {
            copy[key] = next;
        }

        const updated = { ...row };

        if (Object.keys(copy).length > 0) {
            updated.dark = copy;
        } else {
            delete updated.dark;
        }

        onChange(updated);
    };

    return (
        <details className="group/design rounded-[9px] border border-[var(--line)] bg-[var(--surface)]">
            <summary className="flex min-h-[40px] cursor-pointer list-none items-center justify-between px-2.5 text-[12.5px] font-medium text-[var(--text)]">
                {Object.values(item).find((schema) => schema.type === 'style')?.label ?? 'Diseño'}
                <span className="flex items-center gap-1.5 text-[11px] text-[var(--muted)]">
                    {designChanged(item, row, defaults) && <span className="size-2 rounded-full bg-[var(--accent)]" title="Con cambios en modo claro" />}
                    {changedDark && <MoonIcon size={12} weight="fill" aria-label="Con cambios en modo oscuro" />}
                    <span className="transition-transform group-open/design:rotate-180">▾</span>
                </span>
            </summary>
            <div className="flex flex-col gap-2.5 border-t border-[var(--line)] p-2.5">
                <div className="flex items-center justify-between gap-2">
                    <p className="text-[11.5px] text-[var(--muted)]">{dark ? 'En modo oscuro. Lo que no cambies aquí queda como en claro.' : 'En modo claro.'}</p>
                    <SchemeSwitch compact />
                </div>
                {fields
                    .filter(([, schema]) => isVisible(schema, shown))
                    .map(([key, schema]) => (
                        <BlockField key={`${scheme}-${key}`} name={key} field={schema} value={shown[key]} readOnly={readOnly} onChange={(next) => set(key, next)} />
                    ))}
                {dark && changedDark && !readOnly && (
                    <button
                        type="button"
                        onClick={() => {
                            const updated = { ...row };
                            delete updated.dark;
                            onChange(updated);
                        }}
                        className="self-start text-[12px] font-semibold text-[var(--accent)] hover:underline"
                    >
                        Dejarla igual que en modo claro
                    </button>
                )}
            </div>
        </details>
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
                                .filter(([, schema]) => schema.group !== 'design' && isVisible(schema, row))
                                .map(([key, schema]) => (
                                    <FieldRow
                                        key={key}
                                        name={key}
                                        field={schema}
                                        values={row}
                                        readOnly={readOnly}
                                        onPatch={(patch) => onChange(value.map((entry, position) => (position === index ? { ...entry, ...patch } : entry)))}
                                    />
                                ))}

                            {/* «Diseño de esta lámina»: plegado aparte, para que el contenido se lea primero. */}
                            {Object.values(item).some((schema) => schema.group === 'design') && (
                                <RowDesign
                                    item={item}
                                    row={row}
                                    defaults={field.item_defaults ?? {}}
                                    readOnly={readOnly}
                                    onChange={(next) => onChange(value.map((entry, position) => (position === index ? next : entry)))}
                                />
                            )}
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

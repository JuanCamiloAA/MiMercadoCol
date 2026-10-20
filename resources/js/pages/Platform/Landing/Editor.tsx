import { router, useForm } from '@inertiajs/react';
import {
    ArrowDownIcon,
    ArrowSquareOutIcon,
    ArrowUpIcon,
    CircleIcon,
    ClockCounterClockwiseIcon,
    CopyIcon,
    DesktopIcon,
    DeviceMobileIcon,
    EyeIcon,
    EyeSlashIcon,
    PlusIcon,
    TrashIcon,
    WarningCircleIcon,
} from '@phosphor-icons/react';
import { Fragment, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { type DesignCatalogue, DesignPanel } from '@/components/sections/DesignPanel';
import { type EditorScheme, EditorSchemeContext, SchemeSwitch, useEditorScheme } from '@/components/sections/editor-scheme';
import { Button } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Input, Select, Textarea } from '@/components/ui/Field';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { ConfirmDialog, Modal } from '@/components/ui/Modal';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import { isHex, mix, normalize } from '@/lib/color';
import { dateTime } from '@/lib/format';
import { csrfHeaders, errorMessage } from '@/lib/http';
import { usePermission } from '@/lib/permissions';
import { applies, type FieldSchema, ImageField, LandingFieldRow, type Values, VideoField } from './Fields';

interface BlockRow {
    id: number;
    uuid: string;
    type: string;
    is_visible: boolean;
    data: Values;
    image_urls: Record<string, string | null>;
}

interface CatalogueEntry {
    type: string;
    label: string;
    icon: string;
    help: string;
    fields: Record<string, FieldSchema>;
    unique: boolean;
    deletable: boolean;
    hideable: boolean;
    hide_warning: string | null;
    /** Lleva la pestaña «Diseño» (el encabezado y el pie no). */
    supports_appearance: boolean;
    appearance_defaults: Values;
}

interface Status {
    has_unpublished_changes: boolean;
    published_version: number | null;
    published_at: string | null;
    issues: string[];
}

interface Settings {
    platform_name: string;
    support_email: string;
    leads_notify_email: string;
    brand_accent: string;
    brand_logo: string | null;
    brand_logo_url: string | null;
    brand_logo_dark: string | null;
    brand_logo_dark_url: string | null;
    brand_mark: string | null;
    brand_mark_url: string | null;
    font_body: string;
    font_display: string;
    font_numeric: string;
    seo_title: string;
    seo_description: string;
    seo_image: string | null;
    seo_image_url: string | null;
    favicon: string | null;
    favicon_url: string | null;
    whatsapp: string;
    terms_text: string;
    privacy_text: string;
    default_name: string;
}

interface EditorProps {
    blocks: BlockRow[];
    catalogue: CatalogueEntry[];
    appearanceCatalogue: DesignCatalogue;
    icons: string[];
    settings: Settings;
    fontOptions: Record<'body' | 'display' | 'numeric', { value: string; label: string }[]>;
    versions: { id: number; number: number; note: string | null; published_at: string | null; publisher: string | null; is_current: boolean }[];
    status: Status;
    previewUrl: string;
    publicUrl: string;
}

/**
 * Plataforma > Landing. Se edita el borrador (se guarda solo); el público ve la última versión
 * publicada. La vista previa va en un `iframe`: `md:`/`lg:` miden el ancho de la ventana.
 */
export default function LandingEditor({ blocks, catalogue, appearanceCatalogue, icons, settings, fontOptions, versions, status: initialStatus, previewUrl, publicUrl }: EditorProps) {
    const { can } = usePermission();
    const canEdit = can('platform.landing.edit');
    const canPublish = can('platform.landing.publish');
    const types = useMemo(() => Object.fromEntries(catalogue.map((entry) => [entry.type, entry])), [catalogue]);

    const [tab, setTab] = useState<'content' | 'settings'>('content');
    const [selected, setSelected] = useState<string | null>(blocks[0]?.uuid ?? null);
    const [drafts, setDrafts] = useState<Record<string, BlockRow>>(() => Object.fromEntries(blocks.map((block) => [block.uuid, block])));
    const [status, setStatus] = useState(initialStatus);
    const [saving, setSaving] = useState(false);
    const [version, setVersion] = useState(0);
    const [adding, setAdding] = useState(false);
    const [publishing, setPublishing] = useState(false);
    const [history, setHistory] = useState(false);
    const [removing, setRemoving] = useState<BlockRow | null>(null);
    const [hiding, setHiding] = useState<BlockRow | null>(null);
    // «Contenido» o «Diseño» del bloque elegido.
    const [pane, setPane] = useState<'content' | 'design'>('content');
    // El modo que se revisa (Claro | Oscuro): lo comparten la vista previa, «Diseño» y las imágenes.
    const [scheme, setScheme] = useState<EditorScheme>('light');
    const schemeContext = useMemo(() => ({ scheme, setScheme }), [scheme]);
    const timers = useRef<Record<string, ReturnType<typeof setTimeout>>>({});

    // Lo que llega del servidor (agregar, borrar, reordenar, publicar) reemplaza el borrador local,
    // y un bloque recien agregado o duplicado queda elegido.
    const known = useRef(new Set(blocks.map((block) => block.uuid)));

    useEffect(() => {
        const added = blocks.find((block) => !known.current.has(block.uuid));
        known.current = new Set(blocks.map((block) => block.uuid));

        setDrafts(Object.fromEntries(blocks.map((block) => [block.uuid, block])));
        setStatus(initialStatus);
        setVersion((current) => current + 1);
        setSelected((current) => added?.uuid ?? (current && blocks.some((block) => block.uuid === current) ? current : (blocks[0]?.uuid ?? null)));
    }, [blocks, initialStatus]);

    const save = useCallback((uuid: string, data: Values, selects: string[]) => {
        clearTimeout(timers.current[uuid]);
        timers.current[uuid] = setTimeout(async () => {
            setSaving(true);

            try {
                const response = await fetch(route('platform.landing.blocks.update', uuid), {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
                    credentials: 'same-origin',
                    body: JSON.stringify({ data }),
                });

                if (!response.ok) {
                    toast.error(await errorMessage(response, 'No se pudo guardar el bloque.'));

                    return;
                }

                const result = (await response.json()) as { status: Status; block?: { data: Values } };
                setStatus(result.status);
                setVersion((current) => current + 1);

                // El servidor manda: si corrigió un select (el mapa que admite el diseño), el
                // formulario lo muestra. Solo selects, y solo si nadie lo cambió mientras se guardaba:
                // un texto recortado a medio escribir le movería las letras a quien teclea.
                const saved = result.block?.data;
                const corrected = saved ? selects.filter((key) => typeof saved[key] === 'string' && saved[key] !== data[key]) : [];

                if (saved && corrected.length > 0) {
                    setDrafts((current) => {
                        const block = current[uuid];
                        const untouched = block ? corrected.filter((key) => block.data[key] === data[key]) : [];

                        if (!block || untouched.length === 0) {
                            return current;
                        }

                        return { ...current, [uuid]: { ...block, data: { ...block.data, ...Object.fromEntries(untouched.map((key) => [key, saved[key]])) } } };
                    });
                }
            } catch {
                toast.error('No se pudo guardar. Revisa la conexión.');
            } finally {
                setSaving(false);
            }
        }, 600);
    }, []);

    const change = (uuid: string, key: string, value: unknown, imageUrl?: string | null, urls?: Record<string, string | null>) => {
        setDrafts((current) => {
            const block = current[uuid];
            const data = { ...block.data, [key]: value };
            const selects = Object.entries(types[block.type]?.fields ?? {})
                .filter(([, field]) => field.type === 'select')
                .map(([name]) => name);
            save(uuid, data, selects);

            return {
                ...current,
                [uuid]: {
                    ...block,
                    data,
                    image_urls: { ...block.image_urls, ...(imageUrl !== undefined ? { [key]: imageUrl } : {}), ...urls },
                },
            };
        });
    };

    // Los colores con nombre de la pestaña «Diseño», con el acento de la plataforma.
    const landingSwatches = useMemo(() => {
        const accent = isHex(settings.brand_accent) ? normalize(settings.brand_accent) : '#0E6E4E';

        return { page: '#FFFFFF', band: '#F2F4F3', soft: mix('#FFFFFF', accent, 0.12), brand: accent, dark: '#0B1220', light: '#FFFFFF' };
    }, [settings.brand_accent]);

    const ordered = blocks.map((block) => drafts[block.uuid] ?? block);
    const current = selected ? drafts[selected] : null;
    const definition = current ? types[current.type] : null;

    const move = (uuid: string, delta: number) => {
        const uuids = ordered.map((block) => block.uuid);
        const index = uuids.indexOf(uuid);
        const target = index + delta;

        if (target < 0 || target >= uuids.length) {
            return;
        }

        [uuids[index], uuids[target]] = [uuids[target], uuids[index]];
        router.post(route('platform.landing.blocks.reorder'), { uuids }, { preserveScroll: true, preserveState: true });
    };

    const setVisible = (block: BlockRow, visible: boolean) =>
        router.patch(route('platform.landing.blocks.visibility', block.uuid), { is_visible: visible }, { preserveScroll: true, preserveState: true, onFinish: () => setHiding(null) });

    const addable = catalogue.filter((entry) => !(entry.unique && ordered.some((block) => block.type === entry.type)));

    return (
        <AppLayout title="Landing pública">
            <EditorSchemeContext.Provider value={schemeContext}>
            <PageHeader
                title="Landing pública"
                description="La página de entrada de la plataforma: buscador de tiendas, planes y contratación."
                actions={
                    <>
                        <a
                            href={publicUrl}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex h-10 items-center gap-2 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-4 text-sm font-medium hover:bg-[var(--surface-3)]"
                        >
                            <ArrowSquareOutIcon size={16} /> Ver la landing
                        </a>
                        <Button variant="secondary" icon={<ClockCounterClockwiseIcon size={16} />} onClick={() => setHistory(true)}>
                            Historial
                        </Button>
                        {canPublish && (
                            <Button onClick={() => setPublishing(true)} disabled={!status.has_unpublished_changes}>
                                Publicar
                            </Button>
                        )}
                    </>
                }
            />

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <div className="flex gap-0.5 rounded-control bg-[var(--surface-2)] p-0.5">
                    {(['content', 'settings'] as const).map((item) => (
                        <button
                            key={item}
                            type="button"
                            onClick={() => setTab(item)}
                            className={cn('h-9 rounded-[0.5rem] px-4 text-[0.875rem] font-medium', tab === item ? 'bg-[var(--surface)] shadow-sm' : 'text-[var(--muted)]')}
                        >
                            {item === 'content' ? 'Contenido' : 'Ajustes'}
                        </button>
                    ))}
                </div>
                <span
                    className={cn(
                        'flex items-center gap-1.5 rounded-control px-2.5 py-1.5 text-[0.78125rem] font-medium',
                        status.has_unpublished_changes ? 'bg-[var(--warn-soft)] text-[var(--warn)]' : 'bg-[var(--accent-soft)] text-[var(--accent)]',
                    )}
                >
                    <CircleIcon size={9} weight="fill" />
                    {saving ? 'Guardando…' : status.has_unpublished_changes ? 'Borrador con cambios sin publicar' : 'Publicado'}
                </span>
                {status.published_at && (
                    <span className="text-[0.78125rem] text-[var(--muted)]">
                        Versión {status.published_version} · {dateTime(status.published_at)}
                    </span>
                )}
                {status.issues.map((issue) => (
                    <span key={issue} className="flex items-center gap-1 text-[0.78125rem] font-medium text-[var(--danger)]">
                        <WarningCircleIcon size={14} /> {issue}
                    </span>
                ))}
            </div>

            {tab === 'content' ? (
                <div className="grid gap-4 xl:grid-cols-[17rem_minmax(0,22rem)_minmax(0,1fr)]">
                    <Card padded={false} className="h-fit">
                        <ul className="flex flex-col divide-y divide-[var(--line)]">
                            {ordered.map((block, index) => {
                                const entry = types[block.type];

                                return (
                                    <li key={block.uuid} className={cn('flex items-center gap-1 px-2 py-1.5', block.uuid === selected && 'bg-[var(--accent-soft)]')}>
                                        <button type="button" onClick={() => setSelected(block.uuid)} className="min-w-0 flex-1 truncate px-1.5 py-1.5 text-left text-[0.84375rem] font-medium">
                                            <span className={cn(!block.is_visible && 'text-[var(--muted)] line-through')}>{entry?.label ?? block.type}</span>
                                        </button>
                                        {canEdit && (
                                            <span className="flex shrink-0">
                                                <SmallButton label="Subir" onClick={() => move(block.uuid, -1)} disabled={index === 0}>
                                                    <ArrowUpIcon size={13} />
                                                </SmallButton>
                                                <SmallButton label="Bajar" onClick={() => move(block.uuid, 1)} disabled={index === ordered.length - 1}>
                                                    <ArrowDownIcon size={13} />
                                                </SmallButton>
                                                {entry?.hideable && (
                                                    <SmallButton
                                                        label={block.is_visible ? 'Ocultar' : 'Mostrar'}
                                                        onClick={() => (block.is_visible && entry.hide_warning ? setHiding(block) : setVisible(block, !block.is_visible))}
                                                    >
                                                        {block.is_visible ? <EyeIcon size={13} /> : <EyeSlashIcon size={13} />}
                                                    </SmallButton>
                                                )}
                                            </span>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                        {canEdit && addable.length > 0 && (
                            <div className="border-t border-[var(--line)] p-2">
                                <Button variant="ghost" size="sm" icon={<PlusIcon size={14} />} onClick={() => setAdding(true)} className="w-full">
                                    Agregar bloque
                                </Button>
                            </div>
                        )}
                    </Card>

                    <Card className="h-fit">
                        {current && definition ? (
                            <>
                                <CardHeader title={definition.label} description={definition.help} />
                                {definition.supports_appearance && (
                                    <div className="mb-4 flex gap-0.5 rounded-control bg-[var(--surface-2)] p-0.5" role="tablist" aria-label="Qué se edita del bloque">
                                        {(['content', 'design'] as const).map((item) => (
                                            <button
                                                key={item}
                                                type="button"
                                                role="tab"
                                                aria-selected={pane === item}
                                                onClick={() => setPane(item)}
                                                className={cn('h-9 flex-1 rounded-[0.5rem] text-[0.8125rem] font-medium', pane === item ? 'bg-[var(--surface)] shadow-sm' : 'text-[var(--muted)]')}
                                            >
                                                {item === 'content' ? 'Contenido' : 'Diseño'}
                                            </button>
                                        ))}
                                    </div>
                                )}
                                {definition.supports_appearance && pane === 'design' ? (
                                    <DesignPanel
                                        key={current.uuid}
                                        catalogue={appearanceCatalogue}
                                        defaults={definition.appearance_defaults}
                                        value={(current.data.appearance as Values | undefined) ?? {}}
                                        readOnly={!canEdit}
                                        swatches={landingSwatches}
                                        onChange={(next) => canEdit && change(current.uuid, 'appearance', next)}
                                        renderMedia={(key, field, value, set) => {
                                            const path = typeof value === 'string' ? value : null;
                                            const url = path ? (current.image_urls[path] ?? null) : null;
                                            // Las direcciones de los fondos van por ruta, como las de las listas.
                                            const pick = (next: unknown, nextUrl?: string | null) => {
                                                if (typeof next === 'string' && nextUrl) {
                                                    setDrafts((drafts) => {
                                                        const block = drafts[current.uuid];

                                                        return block ? { ...drafts, [current.uuid]: { ...block, image_urls: { ...block.image_urls, [next]: nextUrl } } } : drafts;
                                                    });
                                                }

                                                set(next);
                                            };

                                            return field.type === 'video' ? (
                                                <VideoField name={key} label={field.label} help={field.help} path={path} url={url} onChange={pick} />
                                            ) : (
                                                <ImageField name={key} label={field.label} help={field.help} path={path} url={url} onChange={pick} />
                                            );
                                        }}
                                    />
                                ) : (
                                <div className="flex flex-col gap-3">
                                    {/* Solo los campos que valen con lo elegido (`when`); los demás conservan su valor. */}
                                    {Object.entries(definition.fields)
                                        // Las versiones de una imagen (`variant_of`) van dentro de su campo principal.
                                        .filter(([, field]) => applies(field.when, current.data) && !field.variant_of)
                                        .map(([key, field], index, shown) => (
                                            <Fragment key={`${current.uuid}-${key}`}>
                                                {field.section && field.section !== shown[index - 1]?.[1].section && (
                                                    <h3
                                                        className={cn(
                                                            'text-[0.75rem] font-semibold uppercase tracking-wide text-[var(--muted)]',
                                                            index > 0 && 'mt-2 border-t border-[var(--line)] pt-4',
                                                        )}
                                                    >
                                                        {field.section}
                                                    </h3>
                                                )}
                                                <LandingFieldRow
                                                    name={key}
                                                    field={field}
                                                    values={current.data}
                                                    urlOf={(slot) => current.image_urls[slot] ?? null}
                                                    mediaUrls={current.image_urls}
                                                    icons={icons}
                                                    onChange={(slot, value, imageUrl, urls) => canEdit && change(current.uuid, slot, value, imageUrl, urls)}
                                                />
                                            </Fragment>
                                        ))}
                                </div>
                                )}
                                {canEdit && (
                                    <div className="mt-5 flex flex-wrap gap-2 border-t border-[var(--line)] pt-4">
                                        {!definition.unique && (
                                            <Button
                                                size="sm"
                                                variant="secondary"
                                                icon={<CopyIcon size={14} />}
                                                onClick={() => router.post(route('platform.landing.blocks.duplicate', current.uuid), {}, { preserveScroll: true })}
                                            >
                                                Duplicar
                                            </Button>
                                        )}
                                        {definition.deletable && (
                                            <Button size="sm" variant="ghost" icon={<TrashIcon size={14} />} onClick={() => setRemoving(current)}>
                                                Quitar
                                            </Button>
                                        )}
                                        {!definition.deletable && <p className="text-[0.75rem] text-[var(--muted)]">Bloque obligatorio: no se puede quitar.</p>}
                                    </div>
                                )}
                            </>
                        ) : (
                            <p className="text-[0.875rem] text-[var(--muted)]">Elige un bloque para editarlo.</p>
                        )}
                    </Card>

                    <PreviewFrame url={previewUrl} version={version} />
                </div>
            ) : (
                <SettingsForm settings={settings} fontOptions={fontOptions} canEdit={canEdit} />
            )}

            <Modal open={adding} onClose={() => setAdding(false)} title="Agregar bloque" size="lg">
                <div className="grid gap-2 sm:grid-cols-2">
                    {addable.map((entry) => (
                        <button
                            key={entry.type}
                            type="button"
                            onClick={() =>
                                router.post(route('platform.landing.blocks.store'), { type: entry.type }, { preserveScroll: true, onSuccess: () => setAdding(false) })
                            }
                            className="rounded-control border border-[var(--line)] px-3 py-2.5 text-left hover:border-[var(--accent)]"
                        >
                            <span className="block text-[0.875rem] font-semibold">{entry.label}</span>
                            <span className="text-[0.78125rem] text-[var(--muted)]">{entry.help}</span>
                        </button>
                    ))}
                </div>
            </Modal>

            <PublishDialog open={publishing} onClose={() => setPublishing(false)} />

            <Modal open={history} onClose={() => setHistory(false)} title="Historial de publicaciones" size="md">
                {versions.length === 0 ? (
                    <p className="text-[0.875rem] text-[var(--muted)]">Todavía no se ha publicado.</p>
                ) : (
                    <ul className="flex flex-col divide-y divide-[var(--line)]">
                        {versions.map((item) => (
                            <li key={item.id} className="flex items-center justify-between gap-3 py-2.5 text-[0.875rem]">
                                <span>
                                    <strong>Versión {item.number}</strong> {item.is_current && <Badge tone="accent">Publicada</Badge>}
                                    <span className="block text-[0.78125rem] text-[var(--muted)]">
                                        {item.published_at ? dateTime(item.published_at) : ''}
                                        {item.publisher ? ` · ${item.publisher}` : ''}
                                        {item.note ? ` · ${item.note}` : ''}
                                    </span>
                                </span>
                                {canPublish && !item.is_current && (
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        onClick={() => router.post(route('platform.landing.restore', item.id), {}, { preserveScroll: true, onSuccess: () => setHistory(false) })}
                                    >
                                        Restaurar
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Modal>

            <ConfirmDialog
                open={removing !== null}
                onClose={() => setRemoving(null)}
                onConfirm={() => removing && router.delete(route('platform.landing.blocks.destroy', removing.uuid), { preserveScroll: true, onFinish: () => setRemoving(null) })}
                title="Quitar el bloque"
                message="Se quita del borrador. El público lo sigue viendo hasta que publiques."
                confirmLabel="Quitar"
            />

            <ConfirmDialog
                open={hiding !== null}
                onClose={() => setHiding(null)}
                onConfirm={() => hiding && setVisible(hiding, false)}
                title="¿Ocultar este bloque?"
                message={hiding ? types[hiding.type]?.hide_warning ?? '' : ''}
                confirmLabel="Ocultar de todas formas"
            />
            </EditorSchemeContext.Provider>
        </AppLayout>
    );
}

function SmallButton({ label, onClick, disabled, children }: { label: string; onClick: () => void; disabled?: boolean; children: React.ReactNode }) {
    return (
        <button
            type="button"
            title={label}
            aria-label={label}
            onClick={onClick}
            disabled={disabled}
            className="grid size-7 place-items-center rounded-[0.5rem] text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)] disabled:opacity-30"
        >
            {children}
        </button>
    );
}

function PreviewFrame({ url, version }: { url: string; version: number }) {
    const [device, setDevice] = useState<'mobile' | 'desktop'>('desktop');
    // El modo que se revisa (Claro | Oscuro), el mismo de la pestaña «Diseño».
    const { scheme } = useEditorScheme();
    const [scale, setScale] = useState(1);
    const box = useRef<HTMLDivElement>(null);
    const width = device === 'mobile' ? 390 : 1280;
    const height = device === 'mobile' ? 760 : 900;

    useEffect(() => {
        const fit = () => setScale(Math.min(1, ((box.current?.clientWidth ?? width) - 24) / width));

        fit();
        window.addEventListener('resize', fit);

        return () => window.removeEventListener('resize', fit);
    }, [width]);

    return (
        <div className="flex min-h-[32rem] flex-col rounded-card border border-[var(--line)] bg-[var(--surface)]">
            <div className="flex items-center justify-between gap-2 border-b border-[var(--line)] px-3 py-2">
                <p className="text-[0.75rem] font-semibold uppercase tracking-wide text-[var(--muted)]">Vista previa del borrador</p>
                <SchemeSwitch compact className="ml-auto" />
                <div className="flex items-center gap-0.5 rounded-[0.5rem] bg-[var(--surface-2)] p-0.5">
                    {(['mobile', 'desktop'] as const).map((item) => (
                        <button
                            key={item}
                            type="button"
                            onClick={() => setDevice(item)}
                            aria-label={item === 'mobile' ? 'Celular' : 'Computador'}
                            title={item === 'mobile' ? 'Celular 390 px' : 'Computador 1280 px'}
                            className={cn('grid size-8 place-items-center rounded-[0.375rem]', device === item ? 'bg-[var(--surface)] shadow-sm' : 'text-[var(--muted)]')}
                        >
                            {item === 'mobile' ? <DeviceMobileIcon size={15} /> : <DesktopIcon size={15} />}
                        </button>
                    ))}
                </div>
            </div>
            <div ref={box} className="min-h-0 flex-1 overflow-auto bg-[var(--surface-2)] p-3">
                <div style={{ height: height * scale, width: width * scale }} className="mx-auto">
                    <iframe
                        key={`${device}-${scheme}-${version}`}
                        title="Vista previa de la landing"
                        src={`${url}?v=${version}&scheme=${scheme}`}
                        style={{ width, height, transform: `scale(${scale})`, transformOrigin: 'top left', border: '1px solid var(--line)', borderRadius: 14, background: 'white' }}
                    />
                </div>
            </div>
        </div>
    );
}

function PublishDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
    const form = useForm({ note: '' });

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Publicar la landing"
            description="Lo que está en el borrador pasa a ser lo que ve el público. Queda una versión en el historial."
            size="sm"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button loading={form.processing} onClick={() => form.post(route('platform.landing.publish'), { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } })}>
                        Publicar
                    </Button>
                </>
            }
        >
            <Input label="Nota (opcional)" placeholder="Nuevos planes de octubre" value={form.data.note} onChange={(event) => form.setData('note', event.target.value)} error={form.errors.note} />
            {(form.errors as Record<string, string | undefined>).publish && (
                <p className="mt-2 text-[0.8125rem] font-medium text-[var(--danger)]">{(form.errors as Record<string, string | undefined>).publish}</p>
            )}
        </Modal>
    );
}

function SettingsForm({ settings, fontOptions, canEdit }: { settings: Settings; fontOptions: EditorProps['fontOptions']; canEdit: boolean }) {
    const form = useForm({
        platform_name: settings.platform_name,
        support_email: settings.support_email,
        leads_notify_email: settings.leads_notify_email,
        brand_accent: settings.brand_accent,
        brand_logo: settings.brand_logo,
        brand_logo_dark: settings.brand_logo_dark,
        brand_mark: settings.brand_mark,
        font_body: settings.font_body,
        font_display: settings.font_display,
        font_numeric: settings.font_numeric,
        seo_title: settings.seo_title,
        seo_description: settings.seo_description,
        seo_image: settings.seo_image,
        favicon: settings.favicon,
        whatsapp: settings.whatsapp,
        terms_text: settings.terms_text,
        privacy_text: settings.privacy_text,
    });
    const [urls, setUrls] = useState({
        brand_logo: settings.brand_logo_url,
        brand_logo_dark: settings.brand_logo_dark_url,
        brand_mark: settings.brand_mark_url,
        seo_image: settings.seo_image_url,
        favicon: settings.favicon_url,
    });
    const { data, setData, errors } = form;

    const image = (key: keyof typeof urls) => (value: unknown, url?: string | null) => {
        setData(key, typeof value === 'string' ? value : null);
        setUrls((current) => ({ ...current, [key]: url ?? null }));
    };

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.put(route('platform.landing.settings'), { preserveScroll: true });
            }}
            className="grid max-w-[64rem] gap-4 lg:grid-cols-2"
        >
            <Card>
                <CardHeader
                    title="Marca de la plataforma"
                    description="La de la landing, el ingreso con varias empresas y el consolidado del super usuario. Cada empresa usa la suya: esta no la reemplaza."
                />
                <div className="flex flex-col gap-3">
                    <Input label="Nombre de la plataforma" placeholder={settings.default_name} value={data.platform_name} onChange={(e) => setData('platform_name', e.target.value)} error={errors.platform_name} disabled={!canEdit} />
                    <label className="flex flex-col gap-1.5 text-[0.8125rem] font-medium">
                        Color de la marca
                        <span className="flex items-center gap-2">
                            <input type="color" value={data.brand_accent} onChange={(e) => setData('brand_accent', e.target.value.toUpperCase())} className="size-10 rounded-control border border-[var(--line)]" disabled={!canEdit} />
                            <span className="font-mono">{data.brand_accent}</span>
                        </span>
                        {errors.brand_accent && <span className="text-[0.75rem] text-[var(--danger)]">{errors.brand_accent}</span>}
                    </label>
                    <ImageField name="brand_logo" label="Logo" help="Horizontal, para fondo claro. PNG con fondo transparente." preview="logo" path={data.brand_logo} url={urls.brand_logo} onChange={image('brand_logo')} />
                    <ImageField
                        name="brand_logo_dark"
                        label="Logo para fondo oscuro"
                        help="El del menú oscuro y el tema oscuro. Sin él se usa el de fondo claro."
                        preview="logo-dark"
                        path={data.brand_logo_dark}
                        url={urls.brand_logo_dark}
                        onChange={image('brand_logo_dark')}
                    />
                    <ImageField name="brand_mark" label="Isotipo" help="Cuadrado, mínimo 512 × 512 px. Menú angosto e ícono de iOS." path={data.brand_mark} url={urls.brand_mark} onChange={image('brand_mark')} />
                    <Select label="Letra del texto" value={data.font_body} options={fontOptions.body} onChange={(e) => setData('font_body', e.target.value)} disabled={!canEdit} />
                    <Select label="Letra de los títulos" value={data.font_display} options={fontOptions.display} onChange={(e) => setData('font_display', e.target.value)} disabled={!canEdit} />
                    <Select label="Letra de las cifras" value={data.font_numeric} options={fontOptions.numeric} onChange={(e) => setData('font_numeric', e.target.value)} disabled={!canEdit} />
                </div>
            </Card>

            <Card>
                <CardHeader title="Contacto y buscadores" />
                <div className="flex flex-col gap-3">
                    <Input label="Correo de soporte" type="email" value={data.support_email} onChange={(e) => setData('support_email', e.target.value)} error={errors.support_email} disabled={!canEdit} />
                    <Input label="Correo que recibe las solicitudes" type="email" value={data.leads_notify_email} onChange={(e) => setData('leads_notify_email', e.target.value)} error={errors.leads_notify_email} disabled={!canEdit} />
                    <Input label="WhatsApp de contacto" placeholder="+57 300 123 4567" value={data.whatsapp} onChange={(e) => setData('whatsapp', e.target.value)} error={errors.whatsapp} disabled={!canEdit} />
                    <Input label="Título para buscadores" maxLength={70} value={data.seo_title} onChange={(e) => setData('seo_title', e.target.value)} error={errors.seo_title} disabled={!canEdit} />
                    <Textarea label="Descripción para buscadores" maxLength={170} rows={2} value={data.seo_description} onChange={(e) => setData('seo_description', e.target.value)} error={errors.seo_description} disabled={!canEdit} />
                    <ImageField name="seo_image" label="Imagen al compartir" help="1200 × 630 px: la vista previa de WhatsApp y las redes." preview="logo" path={data.seo_image} url={urls.seo_image} onChange={image('seo_image')} />
                    <ImageField name="favicon" label="Ícono de la pestaña" help="Cuadrado, 32 × 32 px. Landing, ingreso y panel del super usuario." path={data.favicon} url={urls.favicon} onChange={image('favicon')} />
                </div>
            </Card>

            <Card className="lg:col-span-2">
                <CardHeader title="Textos legales" description="Se enlazan desde la landing, el registro de clientes y la contratación." />
                <div className="grid gap-3 lg:grid-cols-2">
                    <Textarea label="Términos y condiciones" rows={10} value={data.terms_text} onChange={(e) => setData('terms_text', e.target.value)} error={errors.terms_text} disabled={!canEdit} />
                    <Textarea label="Tratamiento de datos personales (Ley 1581 de 2012)" rows={10} value={data.privacy_text} onChange={(e) => setData('privacy_text', e.target.value)} error={errors.privacy_text} disabled={!canEdit} />
                </div>
            </Card>

            {canEdit && (
                <div className="lg:col-span-2">
                    <Button type="submit" loading={form.processing} disabled={!form.isDirty}>
                        Guardar ajustes
                    </Button>
                </div>
            )}
        </form>
    );
}

import { Link, router, usePage } from '@inertiajs/react';
import {
    ArrowCounterClockwiseIcon,
    ArrowLeftIcon,
    ArrowsLeftRightIcon,
    CheckCircleIcon,
    CircleIcon,
    ClockCounterClockwiseIcon,
    PaintBrushIcon,
    StorefrontIcon,
} from '@phosphor-icons/react';
import { useCallback, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Checkbox, Input, Select } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { Badge } from '@/components/ui/Misc';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import { dateTime, time } from '@/lib/format';
import type { PageProps } from '@/types';
import { AppearanceTab } from './AppearanceTab';
import { EditorEnvContext, type EditorEnv } from './BlockFields';
import { ContentTab, type Selection } from './ContentTab';
import { withId } from './editor-api';
import { Checklist, FunctionsTab } from './FunctionsTab';
import { PreviewFrame, type PreviewView } from './PreviewFrame';
import type {
    BlockRow,
    CatalogueEntry,
    EditorContext,
    EditorPermissions,
    EditorTab,
    Endpoints,
    Issue,
    SettingsRow,
    SlotRow,
    StorefrontRow,
    StructureKey,
    StructureOptions,
    TemplateInfo,
    ThemeRow,
    VersionRow,
    ZoneRow,
} from './types';

interface EditorProps {
    context: EditorContext;
    storefront: StorefrontRow;
    blocks: BlockRow[];
    catalogue: CatalogueEntry[];
    theme: ThemeRow;
    settings: SettingsRow;
    zones: ZoneRow[];
    slots: SlotRow[];
    checks: { blocking: Issue[]; warnings: Issue[] };
    fieldOptions: Record<string, { value: string; label: string }[]>;
    structureOptions: StructureOptions;
    previewOptions: { categories: { value: number; label: string }[]; products: { value: number; label: string }[] };
    previewStores?: { value: number; label: string }[];
    icons: string[];
    stores: { value: number; label: string }[];
    template: TemplateInfo;
    endpoints: Endpoints;
    permissions: EditorPermissions;
    media: { max_kb: number; accept: string };
}

const TABS: { key: EditorTab; label: string }[] = [
    { key: 'appearance', label: 'Aspecto' },
    { key: 'content', label: 'Contenido' },
    { key: 'settings', label: 'Funciones' },
];

/**
 * El editor de la tienda.
 *
 * Lo que se edita es el borrador de la sede activa (o de la plantilla, en la plataforma); el
 * cliente sigue viendo lo publicado hasta que alguien toque Publicar. No hay botón de guardar:
 * cada cambio baja solo al borrador.
 */
export default function StorefrontEditor({
    context,
    storefront,
    blocks,
    catalogue,
    theme,
    settings,
    zones,
    slots,
    checks,
    fieldOptions,
    structureOptions,
    previewOptions,
    previewStores,
    icons,
    stores,
    template,
    endpoints,
    permissions,
    media,
}: EditorProps) {
    const errors = (usePage<PageProps>().props.errors ?? {}) as Record<string, string>;
    const isTemplate = context === 'template';
    const locked = !isTemplate && template.follows;

    const [tab, setTab] = useState<EditorTab>('content');
    const [page, setPage] = useState('home');
    const [selection, setSelection] = useState<Selection>(() => {
        const first = blocks.find((block) => block.page === 'home');

        return first ? { kind: 'block', uuid: first.uuid } : null;
    });
    const [view, setView] = useState<PreviewView>({ page: 'home', category: '', product: '' });
    const [previewStore, setPreviewStore] = useState<number | null>(isTemplate ? (storefront.store.id || null) : null);
    const [publishing, setPublishing] = useState(false);
    const [showPublish, setShowPublish] = useState(false);
    const [showHistory, setShowHistory] = useState(false);
    const [showCopy, setShowCopy] = useState(false);
    const [showPersonalize, setShowPersonalize] = useState(false);
    const [showReattach, setShowReattach] = useState(false);
    const [label, setLabel] = useState('');
    const [versions, setVersions] = useState<VersionRow[]>([]);
    const [savedAt, setSavedAt] = useState<string | null>(storefront.last_saved_at);

    // Cada guardado cambia esta firma, y con ella se recarga la vista previa.
    const previewVersion = useMemo(
        () => JSON.stringify([blocks.map((block) => [block.id, block.sort_order, block.is_visible, block.devices, block.payload]), theme, settings]),
        [blocks, theme, settings],
    );

    const onSaved = useCallback(() => setSavedAt(new Date().toISOString()), []);

    const choosePage = (next: string) => {
        setPage(next);
        setView((current) => ({ ...current, page: next }));

        const first = blocks.find((block) => block.page === next);
        setSelection(first ? { kind: 'block', uuid: first.uuid } : null);
    };

    const selectBlock = useCallback(
        (uuid: string) => {
            const block = blocks.find((item) => item.uuid === uuid);

            if (block) {
                setTab('content');
                setPage(block.page);
                setSelection({ kind: 'block', uuid });
            }
        },
        [blocks],
    );

    const selectZone = useCallback((zone: StructureKey) => {
        setTab('content');
        setSelection({ kind: 'zone', key: zone });
    }, []);

    const openHistory = () => {
        setShowHistory(true);

        fetch(endpoints.history, { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : { versions: [] }))
            .then((result: { versions: VersionRow[] }) => setVersions(result.versions))
            .catch(() => toast.error('No se pudo cargar el historial.'));
    };

    const publish = () => {
        setPublishing(true);

        router.post(
            endpoints.publish,
            { label },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setShowPublish(false);
                    setLabel('');
                },
                onError: () => toast.error('No se pudo publicar. Revisa la lista de antes de publicar.'),
                onFinish: () => setPublishing(false),
            },
        );
    };

    const env: EditorEnv = useMemo(
        () => ({
            icons,
            fieldOptions,
            media: { ...media, index: endpoints.media_index, store: endpoints.media_store },
            pages: structureOptions.pages,
            targetKinds: structureOptions.target_kinds,
            productsUrl: endpoints.products,
        }),
        [icons, fieldOptions, media, endpoints, structureOptions],
    );

    const preview = (
        <PreviewFrame
            url={storefront.preview_url}
            version={previewVersion}
            view={view}
            onView={setView}
            pages={structureOptions.block_pages}
            categories={previewOptions.categories}
            products={previewOptions.products}
            activeUuid={selection?.kind === 'block' ? selection.uuid : null}
            activeZone={selection?.kind === 'zone' ? selection.key : null}
            onSelectBlock={selectBlock}
            onSelectZone={selectZone}
            stores={isTemplate ? previewStores : undefined}
            store={isTemplate ? previewStore : null}
            onStore={isTemplate ? setPreviewStore : undefined}
        />
    );

    const blocked = checks.blocking.length > 0;
    const title = isTemplate ? `Plantilla «${storefront.store.name}»` : 'Editor de la tienda';

    return (
        <AppLayout title={title}>
            <EditorEnvContext.Provider value={env}>
                {/* Barra del editor: siempre a la vista, en computador y en celular. */}
                <div className="mb-4 flex flex-col gap-3 rounded-[14px] border border-[var(--line)] bg-[var(--surface)] p-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="flex min-w-0 items-center gap-2">
                            {isTemplate && (
                                <Link href={route('platform.store_templates.index')} aria-label="Volver a las plantillas" className="grid size-9 place-items-center rounded-[10px] text-[var(--muted)] hover:bg-[var(--surface-2)]">
                                    <ArrowLeftIcon size={18} />
                                </Link>
                            )}
                            <span className="grid size-9 shrink-0 place-items-center rounded-[10px] bg-[var(--accent-soft)] text-[var(--accent)]">
                                {isTemplate ? <PaintBrushIcon size={18} weight="fill" /> : <StorefrontIcon size={18} weight="fill" />}
                            </span>
                            <div className="min-w-0">
                                <p className="truncate text-[14px] font-semibold leading-tight">{title}</p>
                                <p className="truncate text-[11.5px] text-[var(--muted)]">
                                    {isTemplate
                                        ? `${template.is_default ? 'Por defecto · ' : ''}${template.followers ?? 0} ${template.followers === 1 ? 'tienda la sigue' : 'tiendas la siguen'}`
                                        : `Sede ${storefront.store.name}`}
                                </p>
                            </div>
                        </div>

                        <div className="ml-auto flex flex-wrap items-center gap-2">
                            <span
                                className={cn(
                                    'flex items-center gap-1.5 rounded-[9px] px-2.5 py-1.5 text-[12px] font-medium',
                                    storefront.has_unpublished_changes ? 'bg-[var(--warn-soft)] text-[var(--warn)]' : 'bg-[var(--accent-soft)] text-[var(--accent)]',
                                )}
                            >
                                <CircleIcon size={9} weight="fill" />
                                {storefront.has_unpublished_changes ? `Borrador${savedAt ? ` · guardado ${time(savedAt)}` : ''}` : 'Publicado'}
                            </span>

                            {!isTemplate && permissions.publish && storefront.published_at && (
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    onClick={() => router.patch(route('storefront.editor.online'), { is_online: !storefront.is_online }, { preserveScroll: true })}
                                    title={storefront.is_online ? 'Apagarla: los clientes verán que no está disponible' : 'Encenderla para que los clientes compren'}
                                >
                                    <CircleIcon size={9} weight="fill" className={storefront.is_online ? 'text-[var(--accent)]' : 'text-[var(--muted)]'} />
                                    {storefront.is_online ? 'En línea' : 'Apagada'}
                                </Button>
                            )}

                            {permissions.history && (
                                <Button variant="secondary" size="sm" icon={<ClockCounterClockwiseIcon size={15} />} onClick={openHistory}>
                                    Historial
                                </Button>
                            )}

                            {permissions.publish && (
                                <Button size="sm" icon={<CheckCircleIcon size={15} weight="bold" />} disabled={!storefront.has_unpublished_changes} onClick={() => setShowPublish(true)}>
                                    Publicar
                                </Button>
                            )}
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <div className="flex gap-0.5 rounded-[10px] bg-[var(--surface-2)] p-0.5" role="tablist">
                            {TABS.map((item) => (
                                <button
                                    key={item.key}
                                    type="button"
                                    role="tab"
                                    aria-selected={tab === item.key}
                                    onClick={() => setTab(item.key)}
                                    className={cn(
                                        'min-h-[38px] rounded-[8px] px-3.5 text-[13px] font-medium transition-colors',
                                        tab === item.key ? 'bg-[var(--surface)] text-[var(--text)] shadow-sm' : 'text-[var(--muted)] hover:text-[var(--text)]',
                                    )}
                                >
                                    {item.label}
                                </button>
                            ))}
                        </div>

                        {!permissions.publish && <p className="text-[11.5px] text-[var(--muted)]">Puedes dejar los cambios listos; publicarlos necesita permiso de un administrador.</p>}
                    </div>

                    <div className="flex flex-wrap items-center gap-2 border-t border-[var(--line)] pt-2.5 text-[12px] text-[var(--muted)]">
                        {isTemplate ? (
                            <span>
                                Esto es solo diseño: la marca, el domicilio, los pagos y el contacto los pone cada sede. Publicar le llega a todas las tiendas que la siguen.
                            </span>
                        ) : locked ? (
                            <span className="flex flex-wrap items-center gap-2">
                                <Badge tone="info">Plantilla «{template.name}»</Badge>
                                Esta tienda sigue la plantilla y recibe sus cambios. La marca, los colores, el domicilio y los pagos sí son tuyos.
                                {permissions.content && (
                                    <Button variant="ghost" size="sm" icon={<PaintBrushIcon size={14} />} onClick={() => setShowPersonalize(true)}>
                                        Personalizar
                                    </Button>
                                )}
                            </span>
                        ) : (
                            <span>
                                Todo lo que cambies aquí aplica solo a la <strong className="text-[var(--text)]">sede {storefront.store.name}</strong>.
                            </span>
                        )}

                        {!isTemplate && permissions.content && template.available.length > 0 && (
                            <Button variant="ghost" size="sm" icon={<ArrowCounterClockwiseIcon size={14} />} onClick={() => setShowReattach(true)}>
                                {locked ? 'Aplicar otra plantilla' : 'Volver a una plantilla'}
                            </Button>
                        )}

                        {!isTemplate && permissions.copy_store && stores.length > 0 && (
                            <Button variant="ghost" size="sm" icon={<ArrowsLeftRightIcon size={14} />} onClick={() => setShowCopy(true)}>
                                Copiar entre sedes
                            </Button>
                        )}

                        {storefront.published_at && (
                            <span className="ml-auto">
                                Publicado {dateTime(storefront.published_at)}
                                {storefront.published_by ? ` por ${storefront.published_by}` : ''}
                                {storefront.published_version ? ` · versión ${storefront.published_version}` : ''}
                            </span>
                        )}
                    </div>

                    {errors.template && <p className="text-[12.5px] font-medium text-[var(--danger)]">{errors.template}</p>}
                </div>

                {tab === 'content' && (
                    <ContentTab
                        context={context}
                        blocks={blocks}
                        catalogue={catalogue}
                        page={page}
                        onPage={choosePage}
                        settings={settings}
                        structureOptions={structureOptions}
                        endpoints={endpoints}
                        readOnly={!permissions.content}
                        locked={locked}
                        onPersonalize={() => setShowPersonalize(true)}
                        selection={selection}
                        onSelect={setSelection}
                        onSaved={onSaved}
                        preview={preview}
                    />
                )}

                {tab === 'appearance' && (
                    <AppearanceTab
                        context={context}
                        theme={theme}
                        settings={settings}
                        readOnly={!permissions.appearance}
                        locked={locked}
                        onPersonalize={() => setShowPersonalize(true)}
                        structureOptions={structureOptions}
                        endpoints={endpoints}
                        onSaved={onSaved}
                        preview={preview}
                        errors={errors}
                    />
                )}

                {tab === 'settings' && (
                    <FunctionsTab
                        context={context}
                        endpoints={endpoints}
                        structureOptions={structureOptions}
                        locked={locked}
                        onPersonalize={() => setShowPersonalize(true)}
                        settings={settings}
                        zones={zones}
                        slots={slots}
                        checks={checks}
                        readOnly={!permissions.settings}
                        canSeeCoupons={permissions.coupons}
                        storeName={storefront.store.name}
                        slug={storefront.slug}
                        onSaved={onSaved}
                    />
                )}

                <Modal
                    open={showPublish}
                    onClose={() => setShowPublish(false)}
                    title={isTemplate ? 'Publicar la plantilla' : 'Publicar la tienda'}
                    description={
                        isTemplate
                            ? `Las ${template.followers ?? 0} tiendas que siguen esta plantilla la verán en su próxima visita. Sus versiones no cambian.`
                            : `Lo que ve el cliente de la sede ${storefront.store.name} pasará a ser lo que tienes en el borrador.`
                    }
                    size="md"
                    footer={
                        <>
                            <Button variant="secondary" onClick={() => setShowPublish(false)}>
                                Cancelar
                            </Button>
                            <Button loading={publishing} disabled={blocked} onClick={publish}>
                                Publicar
                            </Button>
                        </>
                    }
                >
                    <div className="flex flex-col gap-3">
                        <Checklist checks={checks} />

                        <Input
                            label="Ponle un nombre a esta publicación"
                            value={label}
                            maxLength={80}
                            onChange={(event) => setLabel(event.target.value)}
                            placeholder="Campaña del jueves"
                            hint="Opcional. Sirve para reconocerla en el historial."
                        />

                        {errors.publish && <p className="text-[12.5px] font-medium text-[var(--danger)]">{errors.publish}</p>}
                    </div>
                </Modal>

                <Modal open={showHistory} onClose={() => setShowHistory(false)} title="Historial de publicaciones" size="lg">
                    {versions.length === 0 ? (
                        <p className="py-6 text-center text-[13px] text-[var(--muted)]">Todavía no hay publicaciones.</p>
                    ) : (
                        <ul className="flex flex-col gap-1.5">
                            {versions.map((version) => (
                                <li
                                    key={version.id}
                                    className={cn(
                                        'flex flex-wrap items-center justify-between gap-3 rounded-[11px] border px-3 py-2.5',
                                        version.is_current ? 'border-[var(--accent)] bg-[var(--accent-soft)]' : 'border-[var(--line)]',
                                    )}
                                >
                                    <span className="min-w-0">
                                        <span className="flex items-center gap-2">
                                            <span className="font-mono text-[12px] text-[var(--muted)]">v{version.number}</span>
                                            <span className="truncate text-[13px] font-medium">{version.label ?? 'Sin nombre'}</span>
                                            {version.is_current && <Badge tone="accent">{isTemplate ? 'Publicada' : 'En la tienda'}</Badge>}
                                        </span>
                                        <span className="mt-0.5 block truncate text-[11.5px] text-[var(--muted)]">{version.changes.join(' · ')}</span>
                                        <span className="block truncate text-[11px] text-[var(--muted)]">
                                            {version.published_at ? dateTime(version.published_at) : 'Guardada sin publicar'}
                                            {version.author ? ` · ${version.author}` : ''}
                                        </span>
                                    </span>

                                    {!version.is_current && permissions.publish && (
                                        <Button
                                            variant="secondary"
                                            size="sm"
                                            onClick={() => router.post(withId(endpoints.restore, version.id), {}, { preserveScroll: true, onSuccess: () => setShowHistory(false) })}
                                        >
                                            Volver a esta
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}

                    <p className="mt-3 border-t border-[var(--line)] pt-2 text-[11.5px] text-[var(--muted)]">
                        Volver a una versión no borra historia: se publica una copia y el borrador queda igual a ella.
                    </p>
                </Modal>

                <Modal
                    open={showPersonalize}
                    onClose={() => setShowPersonalize(false)}
                    title="Personalizar la tienda"
                    size="md"
                    footer={
                        <>
                            <Button variant="secondary" onClick={() => setShowPersonalize(false)}>
                                Cancelar
                            </Button>
                            <Button
                                icon={<PaintBrushIcon size={15} />}
                                onClick={() =>
                                    router.post(endpoints.detach, {}, { preserveScroll: true, onSuccess: () => setShowPersonalize(false), onError: () => toast.error('No se pudo personalizar la tienda.') })
                                }
                            >
                                Personalizar
                            </Button>
                        </>
                    }
                >
                    <p className="text-[14px]">
                        Esta tienda usa la plantilla «{template.name}». Si la personalizas, dejará de recibir sus cambios.
                    </p>
                    <p className="mt-2 text-[12.5px] text-[var(--muted)]">
                        Lo que ven hoy tus clientes no cambia: el diseño de la plantilla queda como tuyo, con sus imágenes copiadas a tu biblioteca. Puedes volver a la plantilla
                        cuando quieras.
                    </p>
                </Modal>

                <ReattachDialog open={showReattach} onClose={() => setShowReattach(false)} template={template} endpoint={endpoints.reattach} />

                {!isTemplate && <CopyDialog open={showCopy} onClose={() => setShowCopy(false)} stores={stores} storeName={storefront.store.name} />}
            </EditorEnvContext.Provider>
        </AppLayout>
    );
}

function ReattachDialog({ open, onClose, template, endpoint }: { open: boolean; onClose: () => void; template: TemplateInfo; endpoint: string }) {
    const initial = template.id ?? template.available.find((item) => item.is_default)?.id ?? template.available[0]?.id ?? null;
    const [chosen, setChosen] = useState<string>(initial ? String(initial) : '');
    const [saving, setSaving] = useState(false);

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={template.follows ? 'Aplicar otra plantilla' : 'Volver a una plantilla'}
            size="md"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button
                        loading={saving}
                        disabled={chosen === ''}
                        onClick={() => {
                            setSaving(true);
                            router.post(endpoint, { template_id: Number(chosen) }, { preserveScroll: true, onSuccess: onClose, onFinish: () => setSaving(false) });
                        }}
                    >
                        Usar esta plantilla
                    </Button>
                </>
            }
        >
            <div className="flex flex-col gap-3">
                <Select
                    label="Plantilla"
                    options={template.available.map((item) => ({ value: String(item.id), label: `${item.name}${item.is_default ? ' (por defecto)' : ''}` }))}
                    value={chosen}
                    onChange={(event) => setChosen(event.target.value)}
                />
                {template.available.find((item) => String(item.id) === chosen)?.description && (
                    <p className="text-[12.5px] text-[var(--muted)]">{template.available.find((item) => String(item.id) === chosen)?.description}</p>
                )}
                <p className="rounded-[10px] bg-[var(--warn-soft)] px-3 py-2 text-[13px] font-medium text-[var(--warn)]">
                    Perderás tu diseño propio; tus datos de domicilio, pagos y marca se conservan. Tu diseño actual queda guardado en el historial por si quieres volver.
                </p>
            </div>
        </Modal>
    );
}

function CopyDialog({ open, onClose, stores, storeName }: { open: boolean; onClose: () => void; stores: { value: number; label: string }[]; storeName: string }) {
    const [direction, setDirection] = useState<'from' | 'to_all'>('from');
    const [storeId, setStoreId] = useState(String(stores[0]?.value ?? ''));
    const [parts, setParts] = useState<string[]>(['theme', 'blocks', 'settings']);
    const [saving, setSaving] = useState(false);

    const toggle = (part: string) => setParts((current) => (current.includes(part) ? current.filter((item) => item !== part) : [...current, part]));

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Copiar entre sedes"
            description="Lo copiado siempre cae en el borrador de destino: nadie publica por ti."
            size="md"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button
                        loading={saving}
                        disabled={parts.length === 0 || (direction === 'from' && storeId === '')}
                        onClick={() => {
                            setSaving(true);
                            router.post(
                                route('storefront.editor.copy'),
                                { direction, store_id: direction === 'from' ? Number(storeId) : null, parts },
                                { preserveScroll: true, onSuccess: onClose, onFinish: () => setSaving(false) },
                            );
                        }}
                    >
                        Copiar
                    </Button>
                </>
            }
        >
            <div className="flex flex-col gap-3">
                <Select
                    label="Qué quieres hacer"
                    options={[
                        { value: 'from', label: `Traer la configuración de otra sede a ${storeName}` },
                        { value: 'to_all', label: `Llevar la de ${storeName} a todas las demás` },
                    ]}
                    value={direction}
                    onChange={(event) => setDirection(event.target.value as 'from' | 'to_all')}
                />

                {direction === 'from' && <Select label="Desde qué sede" options={stores} value={storeId} onChange={(event) => setStoreId(event.target.value)} placeholder="Elige la sede" />}

                <div className="rounded-[10px] border border-[var(--line)] bg-[var(--surface-2)] p-3">
                    <p className="mb-2 text-[12px] font-semibold uppercase tracking-wide text-[var(--muted)]">Qué se copia</p>
                    <div className="flex flex-col gap-2">
                        <Checkbox label="Aspecto (marca, colores, tipografía)" checked={parts.includes('theme')} onChange={() => toggle('theme')} />
                        <Checkbox label="Contenido (los bloques de todas las páginas y la plantilla que se sigue)" checked={parts.includes('blocks')} onChange={() => toggle('blocks')} />
                        <Checkbox
                            label="Funciones y estructura (domicilio, pagos, barra superior, menú, tarjeta, pie…)"
                            checked={parts.includes('settings')}
                            onChange={() => toggle('settings')}
                        />
                    </div>
                </div>
            </div>
        </Modal>
    );
}

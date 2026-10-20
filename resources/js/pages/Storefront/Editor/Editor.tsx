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
import type { DesignCatalogue } from '@/components/sections/DesignPanel';
import { type EditorScheme, EditorSchemeContext } from '@/components/sections/editor-scheme';
import { GroupChips, useGroupFilter } from '@/components/storefront/template-gallery';
import { Button } from '@/components/ui/Button';
import { Checkbox, Input, Select } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { Badge } from '@/components/ui/Misc';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import { isHex, mix, normalize } from '@/lib/color';
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
    appearanceCatalogue: DesignCatalogue;
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
    media: { max_kb: number; accept: string; video_max_kb: number; video_accept: string };
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
    appearanceCatalogue,
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
    // El modo que se revisa (Claro | Oscuro): arranca en el de la tienda si es fijo oscuro.
    const [scheme, setScheme] = useState<EditorScheme>(theme.scheme === 'dark' ? 'dark' : 'light');
    const schemeContext = useMemo(() => ({ scheme, setScheme }), [scheme]);

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
            media: { ...media, index: endpoints.media_index, store: endpoints.media_store, video_store: endpoints.media_video_store },
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

    // Los colores con nombre de la pestaña «Diseño», con los de esta sede (los mismos tonos que `StoreTheme`).
    const swatches = useMemo(() => {
        const accent = isHex(theme.accent) ? normalize(theme.accent) : '#0E6E4E';

        return {
            page: theme.scheme === 'dark' ? '#101418' : '#F6F7F5',
            soft: mix('#FFFFFF', accent, 0.12),
            brand: accent,
            brand_dark: mix(accent, '#000000', 0.38),
            deal: isHex(theme.deal_color) ? normalize(theme.deal_color) : '#D92D20',
            dark: '#14171A',
            light: '#FFFFFF',
        };
    }, [theme.accent, theme.deal_color, theme.scheme]);

    const blocked = checks.blocking.length > 0;
    const title = isTemplate ? `Plantilla «${storefront.store.name}»` : 'Editor de la tienda';

    return (
        <AppLayout title={title}>
            <EditorEnvContext.Provider value={env}>
            <EditorSchemeContext.Provider value={schemeContext}>
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
                        appearanceCatalogue={appearanceCatalogue}
                        canDesign={permissions.appearance}
                        swatches={swatches}
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
                        suggestion={!isTemplate && template.palette ? { template: template.name, palette: template.palette } : null}
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

                <ReattachDialog
                    open={showReattach}
                    onClose={() => setShowReattach(false)}
                    template={template}
                    endpoint={endpoints.reattach}
                    themeEndpoint={endpoints.theme}
                    canColor={permissions.appearance}
                />

                {!isTemplate && <CopyDialog open={showCopy} onClose={() => setShowCopy(false)} stores={stores} storeName={storefront.store.name} />}
            </EditorSchemeContext.Provider>
            </EditorEnvContext.Provider>
        </AppLayout>
    );
}

/**
 * Elegir plantilla: la galería del catálogo (miniatura, nombre, para quién), filtrable por tipo
 * de comercio. Aplicar reemplaza el diseño, nunca la marca ni la operación. Los colores que
 * sugiere la plantilla solo se aplican si se marca la casilla, con la misma regla de contraste
 * de Aspecto.
 */
function ReattachDialog({
    open,
    onClose,
    template,
    endpoint,
    themeEndpoint,
    canColor,
}: {
    open: boolean;
    onClose: () => void;
    template: TemplateInfo;
    endpoint: string;
    themeEndpoint: string;
    canColor: boolean;
}) {
    const initial = template.id ?? template.available.find((item) => item.is_default)?.id ?? template.available[0]?.id ?? null;
    const [chosen, setChosen] = useState<number | null>(initial);
    const [useColors, setUseColors] = useState(false);
    const [saving, setSaving] = useState(false);
    const filter = useGroupFilter(template.available);
    const selected = template.available.find((item) => item.id === chosen) ?? null;
    const current = template.follows && selected?.id === template.id;
    const palette = canColor && selected?.palette && (selected.palette.accent || selected.palette.deal_color) ? selected.palette : null;

    const apply = () => {
        if (!selected) {
            return;
        }

        setSaving(true);
        router.post(
            endpoint,
            { template_id: selected.id },
            {
                preserveScroll: true,
                onSuccess: () => {
                    if (palette && useColors) {
                        router.patch(themeEndpoint, { ...palette }, {
                            preserveScroll: true,
                            onSuccess: () => toast.success('También quedaron sus colores sugeridos.'),
                            onError: (problems) => toast.error(Object.values(problems)[0] ?? 'No se pudieron aplicar los colores.'),
                        });
                    }
                    onClose();
                },
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={template.follows ? 'Aplicar otra plantilla' : 'Volver a una plantilla'}
            description="Elige el diseño. Tus productos, tu marca y tus datos se quedan como están."
            size="xl"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button loading={saving} disabled={!selected || current} onClick={apply}>
                        {current ? 'Ya usas esta plantilla' : 'Usar esta plantilla'}
                    </Button>
                </>
            }
        >
            <div className="flex flex-col gap-3">
                <GroupChips items={template.available} groups={filter.groups} value={filter.group} onChange={filter.setGroup} />

                <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {filter.visible.map((item) => (
                        <li key={item.id}>
                            <button
                                type="button"
                                aria-pressed={item.id === chosen}
                                onClick={() => setChosen(item.id)}
                                className={cn(
                                    'flex h-full w-full flex-col overflow-hidden rounded-[12px] border-2 text-left transition-colors',
                                    item.id === chosen ? 'border-[var(--accent)] bg-[var(--accent-soft)]' : 'border-[var(--line)] hover:border-[var(--line-2)]',
                                )}
                            >
                                <span className="grid aspect-[3/2] w-full place-items-center overflow-hidden bg-[var(--surface-2)] text-[var(--muted)]">
                                    {item.preview ? <img src={item.preview} alt="" loading="lazy" className="h-full w-full object-cover" /> : <PaintBrushIcon size={28} />}
                                </span>
                                <span className="flex flex-col gap-0.5 px-3 py-2.5">
                                    <span className="flex flex-wrap items-center gap-1.5">
                                        <span className="text-[14px] font-semibold">{item.name}</span>
                                        {item.is_default && <Badge tone="neutral">Por defecto</Badge>}
                                        {template.follows && item.id === template.id && <Badge tone="accent">La que usas</Badge>}
                                    </span>
                                    {item.audience && <span className="text-[12px] text-[var(--muted)]">{item.audience}</span>}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>

                {selected?.description && <p className="text-[12.5px] text-[var(--muted)]">{selected.description}</p>}

                {palette && !current && (
                    <div className="flex flex-wrap items-center gap-3 rounded-[10px] border border-[var(--line)] px-3 py-2.5">
                        <Checkbox
                            checked={useColors}
                            onChange={(event) => setUseColors(event.target.checked)}
                            label="Usar también sus colores sugeridos"
                            hint="Cambia el color de tu marca y el de las ofertas. Lo puedes deshacer en Aspecto."
                        />
                        <span className="ml-auto flex items-center gap-1.5">
                            {[palette.accent, palette.deal_color].filter(Boolean).map((color) => (
                                <span key={color} title={color} className="size-6 rounded-full border border-[var(--line)]" style={{ backgroundColor: color }} />
                            ))}
                        </span>
                    </div>
                )}

                <p className="rounded-[10px] bg-[var(--warn-soft)] px-3 py-2 text-[13px] font-medium text-[var(--warn)]">
                    Reemplaza el diseño de tu tienda: bloques, tipografía, formas y estructura. Nunca cambia tu marca, tus colores (salvo que lo marques arriba), el domicilio, los pagos ni el
                    contacto. Tu diseño actual queda en el historial por si quieres volver.
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

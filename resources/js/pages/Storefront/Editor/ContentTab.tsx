import { router } from '@inertiajs/react';
import {
    ArrowDownIcon,
    ArrowUpIcon,
    CopyIcon,
    DotsSixVerticalIcon,
    EyeIcon,
    EyeSlashIcon,
    LockSimpleIcon,
    PlusIcon,
    TrashIcon,
} from '@phosphor-icons/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { type DesignCatalogue, DesignPanel } from '@/components/sections/DesignPanel';
import { Button } from '@/components/ui/Button';
import { Input, Select } from '@/components/ui/Field';
import { ConfirmDialog, Modal } from '@/components/ui/Modal';
import { Badge } from '@/components/ui/Misc';
import { cn } from '@/lib/cn';
import { StoreIcon } from '@/pages/Storefront/blocks/StoreBlocks';
import { ChipPicker, FieldRow, isVisible, MediaField, useEditorEnv, VideoField } from './BlockFields';
import { withId } from './editor-api';
import { STRUCTURE_LABELS, StructureSection } from './StructureForms';
import type { BlockRow, CatalogueEntry, Dict, EditorContext, Endpoints, SettingsRow, StructureKey, StructureOptions } from './types';

export type Selection = { kind: 'block'; uuid: string } | { kind: 'zone'; key: StructureKey } | null;

type Panel = 'list' | 'properties' | 'preview';

const PANELS: { key: Panel; label: string }[] = [
    { key: 'list', label: 'Página' },
    { key: 'properties', label: 'Propiedades' },
    { key: 'preview', label: 'Vista previa' },
];

/** Las piezas de la estructura global, en el orden en que aparecen en la tienda. */
export const STRUCTURE_KEYS: StructureKey[] = ['topbar', 'header', 'nav', 'cart_bar', 'mobile_nav', 'footer', 'floating'];

interface ContentTabProps {
    context: EditorContext;
    blocks: BlockRow[];
    catalogue: CatalogueEntry[];
    /** La pestaña «Diseño» de cada sección (`SectionAppearance::groups`). */
    appearanceCatalogue: DesignCatalogue;
    /** Quien no tiene permiso de aspecto no ve la pestaña «Diseño» (el servidor tampoco se la acepta). */
    canDesign: boolean;
    /** Cómo se ven los colores con nombre de esta sede, para la muestra. */
    swatches: Record<string, string>;
    page: string;
    onPage: (page: string) => void;
    settings: SettingsRow;
    structureOptions: StructureOptions;
    endpoints: Endpoints;
    readOnly: boolean;
    /** Sigue la plantilla: lo que se ve es de la plantilla; para cambiarlo, personalizar. */
    locked: boolean;
    onPersonalize: () => void;
    selection: Selection;
    onSelect: (selection: Selection) => void;
    onSaved: () => void;
    preview: React.ReactNode;
}

export function ContentTab({
    context,
    blocks,
    catalogue,
    appearanceCatalogue,
    canDesign,
    swatches,
    page,
    onPage,
    settings,
    structureOptions,
    endpoints,
    readOnly,
    locked,
    onPersonalize,
    selection,
    onSelect,
    onSaved,
    preview,
}: ContentTabProps) {
    const [adding, setAdding] = useState(false);
    const [removing, setRemoving] = useState<BlockRow | null>(null);
    const [dragging, setDragging] = useState<number | null>(null);
    const [panel, setPanel] = useState<Panel>('list');
    const editable = !readOnly && !locked;

    const pageBlocks = useMemo(() => blocks.filter((block) => block.page === page).sort((a, b) => a.sort_order - b.sort_order), [blocks, page]);
    const block = selection?.kind === 'block' ? (blocks.find((item) => item.uuid === selection.uuid) ?? null) : null;
    const schema = useMemo(() => catalogue.find((entry) => entry.type === block?.type) ?? null, [catalogue, block]);
    const available = useMemo(() => catalogue.filter((entry) => entry.pages.includes(page)), [catalogue, page]);

    // Elegir algo —en la lista o tocándolo en la vista previa— abre sus propiedades.
    const first = useRef(true);

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        if (selection !== null) {
            setPanel('properties');
        }
    }, [selection]);

    const reorder = (order: (number | string)[]) => router.post(endpoints.blocks_reorder, { order }, { preserveScroll: true, onSuccess: onSaved });

    const move = (row: BlockRow, direction: -1 | 1) => {
        const order = pageBlocks.map((item) => item.id);
        const index = order.indexOf(row.id);
        const target = index + direction;

        if (target < 0 || target >= order.length) {
            return;
        }

        [order[index], order[target]] = [order[target], order[index]];
        reorder(order);
    };

    const drop = (targetIndex: number) => {
        if (dragging === null || dragging === targetIndex) {
            setDragging(null);

            return;
        }

        const order = pageBlocks.map((item) => item.id);
        const [moved] = order.splice(dragging, 1);
        order.splice(targetIndex, 0, moved);

        setDragging(null);
        reorder(order);
    };

    const pageLabel = structureOptions.block_pages.find((option) => option.value === page)?.label ?? 'Portada';

    return (
        <div className="grid gap-4 xl:grid-cols-[302px_404px_minmax(0,1fr)]">
            <div className="flex gap-0.5 rounded-[11px] bg-[var(--surface-2)] p-0.5 xl:hidden">
                {PANELS.map((item) => (
                    <button
                        key={item.key}
                        type="button"
                        onClick={() => setPanel(item.key)}
                        className={cn(
                            'min-h-[44px] flex-1 rounded-[9px] text-[13px] font-medium transition-colors',
                            panel === item.key ? 'bg-[var(--surface)] text-[var(--text)] shadow-sm' : 'text-[var(--muted)]',
                        )}
                    >
                        {item.label}
                    </button>
                ))}
            </div>

            <div className={cn('flex h-fit flex-col gap-3 xl:flex', panel === 'list' ? 'flex' : 'hidden')}>
                <div className="rounded-[14px] border border-[var(--line)] bg-[var(--surface)]">
                    <div className="border-b border-[var(--line)] p-2">
                        <p className="mb-1.5 px-1 text-[11.5px] font-semibold uppercase tracking-wide text-[var(--muted)]">Página</p>
                        <div className="flex flex-wrap gap-1" role="tablist" aria-label="Página de la tienda">
                            {structureOptions.block_pages.map((option) => (
                                <button
                                    key={option.value}
                                    type="button"
                                    role="tab"
                                    aria-selected={page === option.value}
                                    onClick={() => onPage(option.value)}
                                    className={cn(
                                        'min-h-[34px] rounded-[8px] px-2.5 text-[12.5px] font-medium transition-colors',
                                        page === option.value ? 'bg-[var(--accent)] text-[var(--accent-ink)]' : 'bg-[var(--surface-2)] text-[var(--muted)] hover:text-[var(--text)]',
                                    )}
                                >
                                    {option.label} <span className="tabular-nums opacity-70">{blocks.filter((item) => item.page === option.value).length}</span>
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="flex items-center justify-between border-b border-[var(--line)] px-3 py-2">
                        <p className="text-[12px] font-semibold uppercase tracking-wide text-[var(--muted)]">Bloques de {pageLabel.toLowerCase()}</p>
                        {editable && (
                            <Button size="sm" variant="ghost" icon={<PlusIcon size={14} weight="bold" />} onClick={() => setAdding(true)}>
                                Agregar
                            </Button>
                        )}
                        {locked && !readOnly && (
                            <Button size="sm" variant="ghost" icon={<LockSimpleIcon size={14} />} onClick={onPersonalize}>
                                Personalizar
                            </Button>
                        )}
                    </div>

                    {pageBlocks.length === 0 ? (
                        <p className="px-3 py-5 text-center text-[12.5px] text-[var(--muted)]">
                            {page === 'home' ? 'La portada no tiene bloques.' : `La página de ${pageLabel.toLowerCase()} no lleva bloques propios todavía.`}
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-0.5 p-1.5">
                            {pageBlocks.map((row, index) => {
                                const entry = catalogue.find((item) => item.type === row.type);
                                const active = selection?.kind === 'block' && selection.uuid === row.uuid;

                                return (
                                    <li
                                        key={row.uuid}
                                        draggable={editable}
                                        onDragStart={() => setDragging(index)}
                                        onDragOver={(event) => event.preventDefault()}
                                        onDrop={() => drop(index)}
                                        className={cn('flex items-center gap-1 rounded-[10px] px-1.5 py-1.5', active ? 'bg-[var(--accent-soft)]' : 'hover:bg-[var(--surface-2)]', dragging === index && 'opacity-40')}
                                    >
                                        {editable && (
                                            <span className="cursor-grab text-[var(--muted)]" aria-hidden>
                                                <DotsSixVerticalIcon size={14} />
                                            </span>
                                        )}

                                        <button type="button" onClick={() => onSelect({ kind: 'block', uuid: row.uuid })} className="flex min-h-[40px] min-w-0 flex-1 items-center gap-2 text-left">
                                            <span className={cn('grid size-7 shrink-0 place-items-center rounded-[8px]', active ? 'bg-[var(--accent)] text-[var(--accent-ink)]' : 'bg-[var(--surface-2)] text-[var(--muted)]')}>
                                                <StoreIcon name={entry?.icon} size={15} />
                                            </span>

                                            <span className="min-w-0">
                                                <span className={cn('block truncate text-[12.5px] font-medium', active ? 'text-[var(--accent)]' : 'text-[var(--text)]')}>{row.summary}</span>
                                                <span className="mt-0.5 flex flex-wrap items-center gap-1">
                                                    {row.is_scheduled && <Badge tone="info">Programado</Badge>}
                                                    {!row.is_visible && <Badge tone="neutral">Oculto</Badge>}
                                                    {row.is_empty && <Badge tone="warn">Vacío</Badge>}
                                                    {row.devices !== 'all' && <Badge tone="neutral">{row.devices === 'mobile' ? 'Celular' : 'Computador'}</Badge>}
                                                    {((row.payload.only_categories as number[] | undefined) ?? []).length > 0 && <Badge tone="info">Algunas categorías</Badge>}
                                                </span>
                                            </span>
                                        </button>

                                        {editable && (
                                            <span className="flex shrink-0 flex-col">
                                                <button type="button" disabled={index === 0} onClick={() => move(row, -1)} aria-label="Subir" className="grid size-11 place-items-center rounded-[8px] text-[var(--muted)] hover:bg-[var(--surface-2)] disabled:opacity-25 xl:hidden">
                                                    <ArrowUpIcon size={16} />
                                                </button>
                                                <button type="button" disabled={index === pageBlocks.length - 1} onClick={() => move(row, 1)} aria-label="Bajar" className="grid size-11 place-items-center rounded-[8px] text-[var(--muted)] hover:bg-[var(--surface-2)] disabled:opacity-25 xl:hidden">
                                                    <ArrowDownIcon size={16} />
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => router.patch(withId(endpoints.block_update, row.id), { is_visible: !row.is_visible }, { preserveScroll: true, onSuccess: onSaved })}
                                                    aria-label={row.is_visible ? 'Ocultar' : 'Mostrar'}
                                                    title={row.is_visible ? 'Ocultar en la tienda' : 'Mostrar en la tienda'}
                                                    className="grid size-11 place-items-center rounded-[8px] text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)] xl:size-8"
                                                >
                                                    {row.is_visible ? <EyeIcon size={16} /> : <EyeSlashIcon size={16} />}
                                                </button>
                                            </span>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}

                    {editable && pageBlocks.length > 1 && <p className="border-t border-[var(--line)] px-3 py-2 text-[11px] text-[var(--muted)]">Arrastra para reordenar. En celular, con las flechas.</p>}
                </div>

                <div className="rounded-[14px] border border-[var(--line)] bg-[var(--surface)]">
                    <p className="border-b border-[var(--line)] px-3 py-2.5 text-[12px] font-semibold uppercase tracking-wide text-[var(--muted)]">Estructura de toda la tienda</p>
                    <ul className="flex flex-col gap-0.5 p-1.5">
                        {STRUCTURE_KEYS.map((key) => {
                            const active = selection?.kind === 'zone' && selection.key === key;

                            return (
                                <li key={key}>
                                    <button
                                        type="button"
                                        onClick={() => onSelect({ kind: 'zone', key })}
                                        className={cn('flex min-h-[44px] w-full flex-col items-start justify-center rounded-[10px] px-2.5 py-1.5 text-left', active ? 'bg-[var(--accent-soft)]' : 'hover:bg-[var(--surface-2)]')}
                                    >
                                        <span className={cn('text-[12.5px] font-medium', active ? 'text-[var(--accent)]' : 'text-[var(--text)]')}>{STRUCTURE_LABELS[key].title}</span>
                                        <span className="text-[11px] text-[var(--muted)]">{STRUCTURE_LABELS[key].description}</span>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            </div>

            <div className={cn('min-w-0 xl:block', panel === 'properties' ? 'block' : 'hidden')}>
                {selection?.kind === 'zone' ? (
                    <div className="flex flex-col gap-2">
                        {locked && !readOnly && <LockedNotice onPersonalize={onPersonalize} footer={selection.key === 'footer'} />}
                        <StructureSection
                            key={selection.key}
                            settingKey={selection.key}
                            initial={settings[selection.key]}
                            readOnly={readOnly}
                            designLocked={locked}
                            options={structureOptions}
                            endpoints={endpoints}
                            onSaved={onSaved}
                        />
                    </div>
                ) : block && schema ? (
                    <div className="flex flex-col gap-2">
                        {locked && !readOnly && <LockedNotice onPersonalize={onPersonalize} />}
                        <BlockProperties
                            key={block.uuid}
                            context={context}
                            block={block}
                            schema={schema}
                            appearanceCatalogue={appearanceCatalogue}
                            canDesign={canDesign}
                            swatches={swatches}
                            endpoints={endpoints}
                            readOnly={!editable}
                            onSaved={onSaved}
                            onDuplicate={() => router.post(withId(endpoints.block_duplicate, block.id), {}, { preserveScroll: true, onSuccess: onSaved })}
                            onRemove={() => setRemoving(block)}
                        />
                    </div>
                ) : (
                    <div className="rounded-[14px] border border-[var(--line)] bg-[var(--surface)] p-8 text-center">
                        <p className="text-[13px] text-[var(--muted)]">Elige un bloque o una pieza de la estructura a la izquierda, o tócalos directamente en la vista previa.</p>
                    </div>
                )}
            </div>

            <div className={cn('min-h-[520px] min-w-0 xl:block', panel === 'preview' ? 'block' : 'hidden')}>{preview}</div>

            <Modal open={adding} onClose={() => setAdding(false)} title={`Agregar un bloque a ${pageLabel.toLowerCase()}`} size="md">
                <ul className="grid gap-2 sm:grid-cols-2">
                    {available.map((entry) => (
                        <li key={entry.type}>
                            <button
                                type="button"
                                onClick={() =>
                                    router.post(
                                        endpoints.blocks_store,
                                        { type: entry.type, page, after: block && block.page === page ? block.id : null },
                                        {
                                            preserveScroll: true,
                                            onSuccess: (response) => {
                                                setAdding(false);
                                                onSaved();
                                                const known = new Set(blocks.map((item) => item.uuid));
                                                const added = ((response.props as { blocks?: BlockRow[] }).blocks ?? []).find(
                                                    (item) => item.page === page && !known.has(item.uuid),
                                                );

                                                if (added) {
                                                    onSelect({ kind: 'block', uuid: added.uuid });
                                                }
                                            },
                                            onError: (errors) => toast.error(Object.values(errors)[0] ?? 'No se pudo agregar.'),
                                        },
                                    )
                                }
                                className="flex min-h-[64px] w-full items-start gap-2.5 rounded-[12px] border border-[var(--line)] p-3 text-left transition-colors hover:border-[var(--accent)] hover:bg-[var(--surface-2)]"
                            >
                                <span className="grid size-8 shrink-0 place-items-center rounded-[9px] bg-[var(--accent-soft)] text-[var(--accent)]">
                                    <StoreIcon name={entry.icon} size={16} />
                                </span>
                                <span className="min-w-0">
                                    <span className="block text-[13.5px] font-medium">{entry.label}</span>
                                    <span className="block text-[11.5px] text-[var(--muted)]">{entry.help}</span>
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            </Modal>

            <ConfirmDialog
                open={removing !== null}
                onClose={() => setRemoving(null)}
                onConfirm={() => {
                    if (removing) {
                        router.delete(withId(endpoints.block_destroy, removing.id), {
                            preserveScroll: true,
                            onSuccess: () => {
                                onSelect(null);
                                onSaved();
                            },
                        });
                    }
                    setRemoving(null);
                }}
                title="Eliminar el bloque"
                message="Se quita del borrador. El cliente sigue viendo lo publicado hasta que publiques de nuevo."
                confirmLabel="Eliminar"
                tone="danger"
            />
        </div>
    );
}

function LockedNotice({ onPersonalize, footer = false }: { onPersonalize: () => void; footer?: boolean }) {
    return (
        <div className="flex items-start gap-2 rounded-[12px] border border-[var(--line)] bg-[var(--surface-2)] px-3 py-2.5 text-[12.5px] text-[var(--muted)]">
            <LockSimpleIcon size={16} className="mt-px shrink-0" />
            <span className="flex-1">
                {footer
                    ? 'El diseño del pie es de la plantilla. Lo que es de la sede —contacto, redes y textos legales— sí se edita aquí.'
                    : 'Esto lo pone la plantilla. Para cambiarlo, personaliza la tienda.'}
            </span>
            <button type="button" onClick={onPersonalize} className="shrink-0 font-semibold text-[var(--accent)] hover:underline">
                Personalizar
            </button>
        </div>
    );
}

interface PropertiesProps {
    context: EditorContext;
    block: BlockRow;
    schema: CatalogueEntry;
    appearanceCatalogue: DesignCatalogue;
    canDesign: boolean;
    swatches: Record<string, string>;
    endpoints: Endpoints;
    readOnly: boolean;
    onSaved: () => void;
    onDuplicate: () => void;
    onRemove: () => void;
}

/**
 * Las propiedades del bloque, con guardado automático.
 *
 * No hay botón «Guardar»: cada cambio se manda al borrador con 600 ms de espera. Lo que sí hay
 * es «Deshacer», porque el que se equivoca escribiendo quiere volver atrás, no guardar.
 */
function BlockProperties({ context, block, schema, appearanceCatalogue, canDesign, swatches, endpoints, readOnly, onSaved, onDuplicate, onRemove }: PropertiesProps) {
    const env = useEditorEnv();
    const [tab, setTab] = useState<'content' | 'design' | 'where' | 'schedule'>('content');
    const [payload, setPayload] = useState<Dict>(block.payload);
    const [savedAt, setSavedAt] = useState<number | null>(null);
    const history = useRef<Dict[]>([]);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const first = useRef(true);

    const patch = useCallback(
        (data: Record<string, unknown>) =>
            router.patch(withId(endpoints.block_update, block.id), data as Record<string, string>, {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setSavedAt(Date.now());
                    onSaved();
                },
                onError: (errors) => toast.error(Object.values(errors)[0] ?? 'No se pudo guardar.'),
            }),
        [block.id, endpoints.block_update, onSaved],
    );

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        if (timer.current) {
            clearTimeout(timer.current);
        }

        timer.current = setTimeout(() => patch({ payload }), 600);

        return () => {
            if (timer.current) {
                clearTimeout(timer.current);
            }
        };
    }, [payload, patch]);

    const set = (key: string, value: unknown) => setMany({ [key]: value });

    // Varias claves de una vez: una imagen y sus versiones (celular, modo oscuro) son claves hermanas.
    const setMany = (patch: Dict) => {
        // Las últimas veinte acciones se pueden deshacer.
        history.current = [...history.current.slice(-19), payload];
        setPayload((current) => ({ ...current, ...patch }));
    };

    const undo = useCallback(() => {
        const previous = history.current.pop();

        if (previous) {
            setPayload(previous);
        }
    }, []);

    // Ctrl/Cmd + Z deshace, como en cualquier editor.
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;

            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z' && !target?.closest('input, textarea')) {
                event.preventDefault();
                undo();
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [undo]);

    const onlyCategories = context === 'store' && block.page === 'category';

    return (
        <div className="rounded-[14px] border border-[var(--line)] bg-[var(--surface)]">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--line)] px-4 py-3">
                <div className="min-w-0">
                    <h2 className="truncate text-[15px] font-semibold text-[var(--text)]">{schema.label}</h2>
                    <p className="truncate text-[11.5px] text-[var(--muted)]">{block.summary}</p>
                </div>

                {!readOnly && (
                    <div className="flex items-center gap-1">
                        <Button variant="ghost" size="sm" icon={<CopyIcon size={14} />} onClick={onDuplicate}>
                            Duplicar
                        </Button>
                        <Button variant="ghost" size="sm" icon={<TrashIcon size={14} />} onClick={onRemove}>
                            Eliminar
                        </Button>
                    </div>
                )}
            </div>

            {/* Un bloque oculto no sale en la tienda aunque tenga todo: que se note aquí mismo. */}
            {!block.is_visible && (
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--line)] bg-[var(--warn-soft)] px-4 py-2.5">
                    <p className="flex items-center gap-2 text-[12.5px] font-medium text-[var(--warn)]">
                        <EyeSlashIcon size={16} />
                        Oculto: el cliente no ve este bloque.
                    </p>
                    {!readOnly && (
                        <Button size="sm" variant="secondary" icon={<EyeIcon size={14} />} onClick={() => patch({ is_visible: true })}>
                            Mostrar en la tienda
                        </Button>
                    )}
                </div>
            )}

            <div className="flex gap-0.5 border-b border-[var(--line)] px-2 pt-2">
                {(
                    [
                        ['content', 'Contenido'],
                        ...(canDesign ? ([['design', 'Diseño']] as const) : []),
                        ['where', 'Dónde se ve'],
                        ['schedule', 'Cuándo se ve'],
                    ] as const
                ).map(([key, label]) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => setTab(key)}
                        className={cn(
                            'min-h-[38px] rounded-t-[9px] px-3 text-[12.5px] font-medium transition-colors',
                            tab === key ? 'border-b-2 border-[var(--accent)] text-[var(--accent)]' : 'text-[var(--muted)] hover:text-[var(--text)]',
                        )}
                    >
                        {label}
                    </button>
                ))}
            </div>

            <div className="flex flex-col gap-3.5 p-4">
                {tab === 'content' &&
                    Object.entries(schema.fields)
                        .filter(([, field]) => isVisible(field, payload))
                        .map(([key, field]) => <FieldRow key={key} name={key} field={field} values={payload} readOnly={readOnly} onPatch={setMany} />)}

                {tab === 'design' && canDesign && (
                    <DesignPanel
                        catalogue={appearanceCatalogue}
                        defaults={schema.appearance_defaults ?? {}}
                        value={(payload.appearance as Dict | undefined) ?? {}}
                        readOnly={readOnly}
                        swatches={swatches}
                        onChange={(next) => set('appearance', next)}
                        renderMedia={(key, field, value, change) =>
                            field.type === 'video' ? (
                                <VideoField label={field.label} help={field.help} value={(value as number | null) ?? null} readOnly={readOnly} onChange={change} />
                            ) : (
                                <MediaField label={field.label} help={field.help} value={(value as number | null) ?? null} readOnly={readOnly} onChange={change} />
                            )
                        }
                    />
                )}

                {tab === 'where' && (
                    <>
                        <Select
                            label="En qué pantallas se ve"
                            options={[
                                { value: 'all', label: 'En todas' },
                                { value: 'mobile', label: 'Solo en celular' },
                                { value: 'desktop', label: 'Solo en computador' },
                            ]}
                            value={block.devices}
                            disabled={readOnly}
                            onChange={(event) => patch({ devices: event.target.value })}
                            hint="Un bloque de celular no aparece en computador, y al revés."
                        />
                        {onlyCategories && (
                            <ChipPicker
                                label="Solo en estas categorías"
                                help="Vacío: en todas. Una categoría incluye sus subcategorías."
                                options={env.fieldOptions.categories ?? []}
                                value={((payload.only_categories as number[] | undefined) ?? []).map(Number)}
                                readOnly={readOnly}
                                onChange={(ids) => set('only_categories', ids)}
                            />
                        )}
                    </>
                )}

                {tab === 'schedule' && (
                    <>
                        <div className="grid grid-cols-2 gap-3">
                            <Input
                                label="Desde"
                                type="datetime-local"
                                value={block.visible_from ?? ''}
                                disabled={readOnly}
                                onChange={(event) => patch({ visible_from: event.target.value || null })}
                                hint="Vacío: desde ya."
                            />
                            <Input
                                label="Hasta"
                                type="datetime-local"
                                value={block.visible_to ?? ''}
                                disabled={readOnly}
                                onChange={(event) => patch({ visible_to: event.target.value || null })}
                                hint="Vacío: sin fin."
                            />
                        </div>

                        <p className="rounded-[9px] bg-[var(--surface-2)] px-3 py-2 text-[12px] text-[var(--muted)]">
                            {block.is_scheduled
                                ? block.is_live
                                    ? 'Programado y dentro de su ventana: hoy se ve.'
                                    : 'Programado fuera de su ventana: hoy no se ve en la tienda.'
                                : 'Sin programar: se ve siempre que esté visible.'}
                        </p>
                    </>
                )}
            </div>

            {!readOnly && (
                <div className="flex items-center justify-between gap-2 border-t border-[var(--line)] px-4 py-2.5">
                    <span className="text-[11.5px] text-[var(--muted)]">{savedAt ? 'Guardado en el borrador' : 'Los cambios se guardan solos'}</span>
                    <Button variant="ghost" size="sm" disabled={history.current.length === 0} onClick={undo}>
                        Deshacer
                    </Button>
                </div>
            )}
        </div>
    );
}

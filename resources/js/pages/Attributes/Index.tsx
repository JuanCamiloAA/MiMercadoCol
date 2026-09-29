import { router, useForm } from '@inertiajs/react';
import { DotsSixVerticalIcon, PencilSimpleIcon, PlusIcon, TagSimpleIcon, TrashIcon } from '@phosphor-icons/react';
import { type FormEvent, useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Checkbox, Input, Select } from '@/components/ui/Field';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { ConfirmDialog, Modal } from '@/components/ui/Modal';
import { EmptyState } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import type { AttributeRow, AttributeValue } from '@/types/variants';

interface Option {
    value: string;
    label: string;
}

interface Props {
    attributes: AttributeRow[];
    displays: Option[];
    measures: (Option & { units: Option[] })[];
    canManage: boolean;
}

/**
 * El catálogo de características de la empresa: con qué se arman los subartículos. Lo primero
 * son las medidas (volumen, peso, unidades) porque la tienda es de canasta familiar; también
 * sabor, tamaño, color o talla, y lo que la empresa quiera crear.
 */
export default function AttributesIndex({ attributes, displays, measures, canManage }: Props) {
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<AttributeRow | null>(null);
    const [removing, setRemoving] = useState<AttributeRow | null>(null);

    return (
        <AppLayout title="Atributos">
            <PageHeader
                title="Atributos"
                description="Las características con que se arman los artículos con subartículos: volumen, peso, sabor, tamaño, talla, color… Cada empresa las ajusta a lo que vende."
                actions={
                    canManage && (
                        <Button icon={<PlusIcon size={16} weight="bold" />} onClick={() => setCreating(true)}>
                            Nueva característica
                        </Button>
                    )
                }
            />

            {attributes.length === 0 ? (
                <Card>
                    <EmptyState icon={<TagSimpleIcon size={28} />} title="Sin características" description="Crea la primera: una medida como el volumen o el peso, o un texto como el sabor." />
                </Card>
            ) : (
                <div className="grid gap-4 xl:grid-cols-2">
                    {attributes.map((attribute) => (
                        <AttributeCard
                            key={attribute.id}
                            attribute={attribute}
                            measures={measures}
                            displays={displays}
                            canManage={canManage}
                            onEdit={() => setEditing(attribute)}
                            onRemove={() => setRemoving(attribute)}
                        />
                    ))}
                </div>
            )}

            <AttributeDialog open={creating} onClose={() => setCreating(false)} displays={displays} measures={measures} attribute={null} />
            <AttributeDialog open={editing !== null} onClose={() => setEditing(null)} displays={displays} measures={measures} attribute={editing} />

            <ConfirmDialog
                open={removing !== null}
                onClose={() => setRemoving(null)}
                onConfirm={() => {
                    if (removing) {
                        router.delete(route('attributes.destroy', removing.id), { preserveScroll: true });
                    }
                    setRemoving(null);
                }}
                title="Eliminar la característica"
                message="Solo se puede si ningún artículo la usa. Si la usan, desactívala."
                confirmLabel="Eliminar"
                tone="danger"
            />
        </AppLayout>
    );
}

function AttributeCard({
    attribute,
    measures,
    displays,
    canManage,
    onEdit,
    onRemove,
}: {
    attribute: AttributeRow;
    measures: (Option & { units: Option[] })[];
    displays: Option[];
    canManage: boolean;
    onEdit: () => void;
    onRemove: () => void;
}) {
    const [values, setValues] = useState(attribute.values);
    const [dragging, setDragging] = useState<number | null>(null);
    const [editing, setEditing] = useState<AttributeValue | null>(null);
    const measure = measures.find((entry) => entry.value === attribute.measure) ?? null;
    const display = displays.find((entry) => entry.value === attribute.display)?.label;
    const used = values.reduce((sum, value) => sum + value.usage, 0);

    // Lo que llega del servidor manda: tras guardar, la lista vuelve a su orden real.
    const [synced, setSynced] = useState(attribute.values);

    if (synced !== attribute.values) {
        setSynced(attribute.values);
        setValues(attribute.values);
    }

    const drop = (target: number) => {
        if (dragging === null || dragging === target) {
            return;
        }

        const next = [...values];
        const [moved] = next.splice(dragging, 1);
        next.splice(target, 0, moved);
        setValues(next);
        setDragging(null);
        router.post(route('attributes.values.reorder', attribute.id), { order: next.map((value) => value.id) }, { preserveScroll: true });
    };

    return (
        <Card className={cn(!attribute.is_active && 'opacity-70')}>
            <div className="mb-3 flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <h2 className="text-[1.0625rem] font-semibold text-[var(--text)]">{attribute.name}</h2>
                    <div className="mt-1 flex flex-wrap gap-1.5">
                        <Badge tone={measure ? 'accent' : 'neutral'}>{measure ? measure.label : 'Texto'}</Badge>
                        {display && <Badge>{display}</Badge>}
                        {attribute.name_in_label && <Badge tone="info">Sale con su nombre: «{attribute.name} …»</Badge>}
                        {!attribute.is_active && <Badge tone="warn">Inactiva</Badge>}
                        {used > 0 && <Badge>{used === 1 ? '1 subartículo' : `${used} subartículos`}</Badge>}
                    </div>
                </div>
                {canManage && (
                    <div className="flex items-center gap-1">
                        <Button variant="ghost" size="sm" icon={<PencilSimpleIcon size={14} />} onClick={onEdit}>
                            Editar
                        </Button>
                        {used === 0 && (
                            <Button variant="ghost" size="sm" icon={<TrashIcon size={14} />} onClick={onRemove}>
                                Eliminar
                            </Button>
                        )}
                    </div>
                )}
            </div>

            {values.length === 0 ? (
                <p className="mb-3 text-[0.8125rem] text-[var(--muted)]">Sin valores todavía{attribute.measure ? '' : ': cada empresa pone los suyos'}.</p>
            ) : (
                <ul className="mb-3 flex flex-wrap gap-1.5" aria-label={`Valores de ${attribute.name}`}>
                    {values.map((value, index) => (
                        <li
                            key={value.id}
                            draggable={canManage}
                            onDragStart={() => setDragging(index)}
                            onDragOver={(event) => event.preventDefault()}
                            onDrop={() => drop(index)}
                            className={cn(
                                'flex items-center gap-1 rounded-full border border-[var(--line)] bg-[var(--surface)] py-1 pl-1.5 pr-1 text-[0.8125rem]',
                                !value.is_active && 'border-dashed text-[var(--muted)]',
                                dragging === index && 'opacity-50',
                            )}
                        >
                            {canManage && <DotsSixVerticalIcon size={14} className="cursor-grab text-[var(--muted)]" aria-hidden />}
                            {value.color_hex && <span className="size-3.5 rounded-full border border-[var(--line-2)]" style={{ backgroundColor: value.color_hex }} aria-hidden />}
                            {value.media_url && <img src={value.media_url} alt="" className="size-4 rounded-full object-cover" />}
                            <span className="font-medium">{value.value}</span>
                            {value.usage > 0 && <span className="text-[0.75rem] text-[var(--muted)] tabular-nums">· {value.usage}</span>}
                            {!value.is_active && <span className="text-[0.75rem]">(inactivo)</span>}
                            {canManage && (
                                <button
                                    type="button"
                                    onClick={() => setEditing(value)}
                                    aria-label={`Editar ${value.value}`}
                                    className="grid size-6 place-items-center rounded-full text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]"
                                >
                                    <PencilSimpleIcon size={12} />
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {canManage && attribute.is_active && <NewValue attribute={attribute} measure={measure} />}
            {canManage && values.length > 1 && <p className="mt-2 text-[0.75rem] text-[var(--muted)]">Arrastra los valores para ordenarlos: así salen en el formulario y en la tienda.</p>}

            <ValueDialog value={editing} attribute={attribute} onClose={() => setEditing(null)} />
        </Card>
    );
}

/** Un valor nuevo: en una medida, cantidad y unidad; en un texto, el valor (y su color si es una muestra). */
function NewValue({ attribute, measure }: { attribute: AttributeRow; measure: (Option & { units: Option[] }) | null }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        value: '',
        amount: '',
        unit: measure?.units[0]?.value ?? '',
        color_hex: attribute.display === 'swatches' ? '#1E4E9A' : '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('attributes.values.store', attribute.id), { preserveScroll: true, onSuccess: () => reset('value', 'amount') });
    };

    return (
        <form onSubmit={submit} className="flex flex-wrap items-end gap-2">
            {measure ? (
                <>
                    <Input
                        label="Cantidad"
                        type="number"
                        step="0.001"
                        min="0"
                        value={data.amount}
                        onChange={(event) => setData('amount', event.target.value)}
                        error={errors.amount}
                        containerClassName="w-32"
                    />
                    <Select label="Unidad" options={measure.units} value={data.unit} onChange={(event) => setData('unit', event.target.value)} error={errors.unit} />
                </>
            ) : (
                <>
                    <Input
                        label="Valor nuevo"
                        value={data.value}
                        onChange={(event) => setData('value', event.target.value)}
                        error={errors.value}
                        placeholder={attribute.display === 'swatches' ? 'Azul oscuro' : 'Mora'}
                        containerClassName="min-w-40 flex-1"
                    />
                    {attribute.display === 'swatches' && (
                        <label className="flex flex-col gap-1.5 text-[0.8125rem] font-medium">
                            Color
                            <input
                                type="color"
                                value={data.color_hex || '#000000'}
                                onChange={(event) => setData('color_hex', event.target.value.toUpperCase())}
                                className="h-[calc(2.5rem*var(--density))] w-14 cursor-pointer rounded-control border border-[var(--line)] bg-[var(--surface)] p-1"
                            />
                        </label>
                    )}
                </>
            )}
            <Button type="submit" variant="secondary" loading={processing} icon={<PlusIcon size={14} weight="bold" />}>
                Agregar
            </Button>
        </form>
    );
}

function AttributeDialog({
    open,
    onClose,
    attribute,
    displays,
    measures,
}: {
    open: boolean;
    onClose: () => void;
    attribute: AttributeRow | null;
    displays: Option[];
    measures: (Option & { units: Option[] })[];
}) {
    const { data, setData, post, put, processing, errors, reset } = useForm<{ name: string; display: string; measure: string; name_in_label: boolean; is_active: boolean }>({
        name: attribute?.name ?? '',
        display: attribute?.display ?? 'buttons',
        measure: attribute?.measure ?? '',
        name_in_label: attribute?.name_in_label ?? false,
        is_active: attribute?.is_active ?? true,
    });

    // El formulario se rellena con la característica que se abre.
    const [loaded, setLoaded] = useState<number | null>(null);

    if (open && (attribute?.id ?? 0) !== loaded) {
        setLoaded(attribute?.id ?? 0);
        setData({
            name: attribute?.name ?? '',
            display: attribute?.display ?? 'buttons',
            measure: attribute?.measure ?? '',
            name_in_label: attribute?.name_in_label ?? false,
            is_active: attribute?.is_active ?? true,
        });
    }

    const close = () => {
        setLoaded(null);
        reset();
        onClose();
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: close };

        if (attribute) {
            put(route('attributes.update', attribute.id), options);
        } else {
            post(route('attributes.store'), options);
        }
    };

    return (
        <Modal
            open={open}
            onClose={close}
            title={attribute ? `Editar «${attribute.name}»` : 'Nueva característica'}
            footer={
                <>
                    <Button variant="ghost" onClick={close}>
                        Cancelar
                    </Button>
                    <Button type="submit" form="attribute-form" loading={processing}>
                        Guardar
                    </Button>
                </>
            }
        >
            <form id="attribute-form" onSubmit={submit} className="flex flex-col gap-4">
                <Input label="Nombre" required value={data.name} onChange={(event) => setData('name', event.target.value)} error={errors.name} placeholder="Sabor" autoFocus />
                {!attribute && (
                    <Select
                        label="Qué es"
                        options={[{ value: '', label: 'Un texto: sabor, tamaño, talla, color…' }, ...measures.map((measure) => ({ value: measure.value, label: `Una medida: ${measure.label}` }))]}
                        value={data.measure}
                        onChange={(event) => setData('measure', event.target.value)}
                        hint="Una medida guarda cantidad y unidad («500 ml», «1 kg») y la tienda calcula el precio por litro o por kilo."
                        error={errors.measure}
                    />
                )}
                <Select label="Cómo se escoge en la tienda" options={displays} value={data.display} onChange={(event) => setData('display', event.target.value)} error={errors.display} />
                <Checkbox
                    label="Mostrar el nombre con el valor"
                    hint={`Así sale en la caja y en el tiquete: «${data.name || 'Talla'} 8» en vez de solo «8».`}
                    checked={data.name_in_label}
                    onChange={(event) => setData('name_in_label', event.target.checked)}
                />
                {attribute && (
                    <Checkbox
                        label="Activa"
                        hint="Inactiva no se ofrece para artículos nuevos; los que ya la usan la conservan."
                        checked={data.is_active}
                        onChange={(event) => setData('is_active', event.target.checked)}
                    />
                )}
            </form>
        </Modal>
    );
}

function ValueDialog({ value, attribute, onClose }: { value: AttributeValue | null; attribute: AttributeRow; onClose: () => void }) {
    const { data, setData, processing, errors } = useForm<{ value: string; color_hex: string; is_active: boolean }>({ value: '', color_hex: '', is_active: true });
    const [loaded, setLoaded] = useState<number | null>(null);
    const [removing, setRemoving] = useState(false);

    if (value && value.id !== loaded) {
        setLoaded(value.id);
        setData({ value: value.value, color_hex: value.color_hex ?? '', is_active: value.is_active });
    }

    const close = () => {
        setLoaded(null);
        onClose();
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!value) {
            return;
        }

        const payload: Record<string, unknown> = { is_active: data.is_active, color_hex: data.color_hex || null };

        if (!attribute.measure) {
            payload.value = data.value;
        }

        router.put(route('attributes.values.update', value.id), payload as Record<string, string>, { preserveScroll: true, onSuccess: close });
    };

    return (
        <>
            <Modal
                open={value !== null && !removing}
                onClose={close}
                title={value ? `«${value.value}» en ${attribute.name}` : ''}
                size="sm"
                footer={
                    <>
                        {value && value.usage === 0 && (
                            <Button variant="ghost" icon={<TrashIcon size={14} />} onClick={() => setRemoving(true)} className="mr-auto">
                                Eliminar
                            </Button>
                        )}
                        <Button variant="ghost" onClick={close}>
                            Cancelar
                        </Button>
                        <Button type="submit" form="value-form" loading={processing}>
                            Guardar
                        </Button>
                    </>
                }
            >
                <form id="value-form" onSubmit={submit} className="flex flex-col gap-4">
                    {attribute.measure ? (
                        <p className="text-[0.8125rem] text-[var(--muted)]">
                            Una medida no se reescribe: los subartículos que la usan ya tomaron ese contenido. Si está mal, desactívala y crea la correcta.
                        </p>
                    ) : (
                        <Input label="Valor" value={data.value} onChange={(event) => setData('value', event.target.value)} error={errors.value} />
                    )}
                    {attribute.display === 'swatches' && (
                        <label className="flex items-center gap-3 text-[0.8125rem] font-medium">
                            Color de la muestra
                            <input
                                type="color"
                                value={data.color_hex || '#000000'}
                                onChange={(event) => setData('color_hex', event.target.value.toUpperCase())}
                                className="h-9 w-14 cursor-pointer rounded-control border border-[var(--line)] bg-[var(--surface)] p-1"
                            />
                        </label>
                    )}
                    <Checkbox
                        label="Activo"
                        hint={value && value.usage > 0 ? `Lo usan ${value.usage} subartículos: desactivado, ellos lo conservan y los nuevos no lo ofrecen.` : 'Inactivo no se ofrece para artículos nuevos.'}
                        checked={data.is_active}
                        onChange={(event) => setData('is_active', event.target.checked)}
                    />
                </form>
            </Modal>

            <ConfirmDialog
                open={removing}
                onClose={() => setRemoving(false)}
                onConfirm={() => {
                    if (value) {
                        router.delete(route('attributes.values.destroy', value.id), { preserveScroll: true, onSuccess: close });
                    }
                    setRemoving(false);
                }}
                title="Eliminar el valor"
                message="Ningún subartículo lo usa, así que se puede eliminar."
                confirmLabel="Eliminar"
                tone="danger"
            />
        </>
    );
}

import { router } from '@inertiajs/react';
import { ArrowLeftIcon, CaretDownIcon, CaretRightIcon, PlusIcon, StarIcon, TrashIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { type FormEvent, type KeyboardEvent, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { ImageGalleryField } from '@/components/catalog/ImageGalleryField';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Checkbox, Input, Select, Textarea } from '@/components/ui/Field';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { Table } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';
import { csrfHeaders, errorMessage } from '@/lib/http';
import type { AttributeRow, AttributeValue, SelectOption } from '@/types/variants';

type Mode = 'create' | 'edit' | 'convert' | 'group';

interface ExistingRow {
    id: number;
    name: string;
    value_1_id: number | null;
    value_2_id: number | null;
    sku: string;
    barcode: string | null;
    price: number;
    online_price: number | null;
    wholesale_price: number | null;
    cost?: number;
    content_amount: number | null;
    content_unit: string | null;
    min_stock: number;
    is_active: boolean;
    is_online_visible: boolean;
    unit: string;
    sold_by_weight: boolean;
    track_lots: boolean;
    allow_negative_stock: boolean;
    purchase_pack_size: number | null;
    description: string | null;
    has_movements: boolean;
}

interface Props {
    mode: Mode;
    family: {
        id: number | null;
        name: string;
        code: string | null;
        category_id: number | null;
        brand: string | null;
        supplier_id: number | null;
        tax_rate: number;
        description: string | null;
        attribute_1_id: number | null;
        attribute_2_id: number | null;
        is_active: boolean;
        is_online_visible: boolean;
        is_featured: boolean;
        default_variant_id: number | null;
    };
    rows: ExistingRow[];
    attributes: AttributeRow[];
    images: { id: number; url: string; value_id: number | null; is_cover: boolean }[];
    /** Cuántas fotos comunes se le montan (Ajustes > Catálogo). */
    imageLimit: number;
    categories: SelectOption[];
    suppliers: SelectOption[];
    stores: SelectOption[];
    units: SelectOption[];
    contentUnits: SelectOption[];
    taxRates: SelectOption[];
    canManageAttributes: boolean;
    canViewCost: boolean;
    canEditPrice: boolean;
}

/** Una fila de la matriz tal como se edita. */
interface Row {
    /** Id del artículo si ya existe (editar, convertir, agrupar). */
    id: number | null;
    /** Nombre del artículo que se agrupa, para reconocerlo. */
    original: string | null;
    v1: number | null;
    v2: number | null;
    sku: string;
    skuAuto: boolean;
    barcode: string;
    price: string;
    online_price: string;
    wholesale_price: string;
    cost: string;
    content_amount: string;
    content_unit: string;
    min_stock: string;
    is_active: boolean;
    is_online_visible: boolean;
    unit: string;
    sold_by_weight: boolean;
    track_lots: boolean;
    allow_negative_stock: boolean;
    purchase_pack_size: string;
    description: string;
    initial: Record<number, string>;
    has_movements: boolean;
}

const keyOf = (v1: number | null, v2: number | null) => `${v1 ?? 0}-${v2 ?? 0}`;

/** «900 ml» → «900ML», «Azul oscuro» → «AZULOSCURO»: la parte del SKU que sale de un valor. */
const codePart = (value: string) =>
    value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^A-Za-z0-9]/g, '')
        .toUpperCase()
        .slice(0, 10);

function emptyRow(v1: number | null, v2: number | null): Row {
    return {
        id: null,
        original: null,
        v1,
        v2,
        sku: '',
        skuAuto: true,
        barcode: '',
        price: '',
        online_price: '',
        wholesale_price: '',
        cost: '',
        content_amount: '',
        content_unit: '',
        min_stock: '0',
        is_active: true,
        is_online_visible: true,
        unit: 'unit',
        sold_by_weight: false,
        track_lots: false,
        allow_negative_stock: false,
        purchase_pack_size: '',
        description: '',
        initial: {},
        has_movements: false,
    };
}

function fromExisting(row: ExistingRow): Row {
    return {
        id: row.id,
        original: row.name,
        v1: row.value_1_id,
        v2: row.value_2_id,
        sku: row.sku,
        skuAuto: false,
        barcode: row.barcode ?? '',
        price: String(row.price ?? ''),
        online_price: row.online_price ? String(row.online_price) : '',
        wholesale_price: row.wholesale_price ? String(row.wholesale_price) : '',
        cost: row.cost !== undefined ? String(row.cost) : '',
        content_amount: row.content_amount ? String(row.content_amount) : '',
        content_unit: row.content_unit ?? '',
        min_stock: String(row.min_stock ?? 0),
        is_active: row.is_active,
        is_online_visible: row.is_online_visible,
        unit: row.unit,
        sold_by_weight: row.sold_by_weight,
        track_lots: row.track_lots,
        allow_negative_stock: row.allow_negative_stock,
        purchase_pack_size: row.purchase_pack_size ? String(row.purchase_pack_size) : '',
        description: row.description ?? '',
        initial: {},
        has_movements: row.has_movements,
    };
}

const TITLES: Record<Mode, string> = {
    create: 'Nuevo artículo con subartículos',
    edit: 'Editar artículo con subartículos',
    convert: 'Convertir en artículo con subartículos',
    group: 'Agrupar artículos',
};

/**
 * Crear, editar, convertir o agrupar un artículo con subartículos: lo común arriba y la matriz
 * de subartículos (una fila por combinación) abajo. El servidor valida todo otra vez y dice
 * cada error por el nombre de su fila.
 */
export default function FamilyForm(props: Props) {
    const { mode, family, attributes: initialAttributes, stores, canViewCost, canEditPrice, canManageAttributes } = props;
    const [catalog, setCatalog] = useState<AttributeRow[]>(initialAttributes);
    const [common, setCommon] = useState({
        name: family.name ?? '',
        code: family.code ?? '',
        category_id: String(family.category_id ?? props.categories[0]?.value ?? ''),
        brand: family.brand ?? '',
        supplier_id: family.supplier_id ? String(family.supplier_id) : '',
        tax_rate: String(family.tax_rate ?? 19),
        description: family.description ?? '',
        is_active: family.is_active,
        is_online_visible: family.is_online_visible,
        is_featured: family.is_featured,
    });
    const [attr1, setAttr1] = useState<number | null>(family.attribute_1_id);
    const [attr2, setAttr2] = useState<number | null>(family.attribute_2_id);

    // Las filas que ya son artículos (editar, convertir, agrupar) y las que arma la matriz.
    const [members, setMembers] = useState<Row[]>(() => props.rows.map(fromExisting));
    const [generated, setGenerated] = useState<Record<string, Row>>({});
    const [selected1, setSelected1] = useState<number[]>(() => unique(props.rows.map((row) => row.value_1_id)));
    const [selected2, setSelected2] = useState<number[]>(() => unique(props.rows.map((row) => row.value_2_id)));
    const [excluded, setExcluded] = useState<Set<string>>(new Set());
    const [removed, setRemoved] = useState<Set<number>>(new Set());
    const [defaultKey, setDefaultKey] = useState<string | null>(() => {
        const row = props.rows.find((entry) => entry.id === family.default_variant_id);

        return row ? `id:${row.id}` : null;
    });
    const [expanded, setExpanded] = useState<string | null>(null);
    const [files, setFiles] = useState<{ file: File; value: string }[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [submittedKeys, setSubmittedKeys] = useState<string[]>([]);
    const [processing, setProcessing] = useState(false);
    const barcodeRefs = useRef<Record<string, HTMLInputElement | null>>({});

    const attribute1 = catalog.find((attribute) => attribute.id === attr1) ?? null;
    const attribute2 = catalog.find((attribute) => attribute.id === attr2) ?? null;
    const valueOf = (id: number | null) => (id ? (catalog.flatMap((attribute) => attribute.values).find((value) => value.id === id) ?? null) : null);
    const measureValue = (row: Row) => [valueOf(row.v1), valueOf(row.v2)].find((value) => value?.amount && value.unit) ?? null;
    const perRowValues = mode === 'convert' || mode === 'group';
    const locked = mode === 'edit';

    const label = (row: Row) => {
        const parts: string[] = [];

        [
            [attribute1, valueOf(row.v1)],
            [attribute2, valueOf(row.v2)],
        ].forEach(([attribute, value]) => {
            if (value && attribute) {
                parts.push((attribute as AttributeRow).name_in_label ? `${(attribute as AttributeRow).name} ${(value as AttributeValue).value}` : (value as AttributeValue).value);
            }
        });

        return parts.join(' · ');
    };

    const skuFor = (v1: number | null, v2: number | null) => {
        const base = codePart(common.code) || codePart(common.name.split(' ').slice(0, 2).join('')) || 'ART';

        return [base, codePart(valueOf(v1)?.value ?? ''), codePart(valueOf(v2)?.value ?? '')].filter(Boolean).join('-').slice(0, 40);
    };

    // Las combinaciones marcadas que no son de un artículo que ya existe son filas nuevas.
    const rows = useMemo(() => {
        const memberRows = members.filter((row) => !removed.has(row.id ?? -1)).map((row) => ({ key: row.id ? `id:${row.id}` : keyOf(row.v1, row.v2), row }));
        const taken = new Set(members.map((row) => keyOf(row.v1, row.v2)));
        const combos: Row[] = [];

        if (attr1) {
            for (const v1 of selected1) {
                for (const v2 of attr2 ? selected2 : [null]) {
                    if (attr2 && v2 === null) {
                        continue;
                    }

                    const key = keyOf(v1, v2);

                    if (taken.has(key) || excluded.has(key)) {
                        continue;
                    }

                    const existing = generated[key];
                    const row = existing ?? emptyRow(v1, v2);

                    combos.push({ ...row, sku: row.skuAuto ? skuFor(v1, v2) : row.sku });
                }
            }
        }

        // Con dos características, agrupadas por la primera, en el orden de sus valores.
        const order = (row: Row) => {
            const a = attribute1?.values.findIndex((value) => value.id === row.v1) ?? 0;
            const b = attribute2?.values.findIndex((value) => value.id === row.v2) ?? 0;

            return (a < 0 ? 999 : a) * 1000 + (b < 0 ? 999 : b);
        };

        return [...memberRows, ...combos.map((row) => ({ key: keyOf(row.v1, row.v2), row }))].sort((a, b) => order(a.row) - order(b.row));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [members, generated, selected1, selected2, excluded, removed, attr1, attr2, catalog, common.code, common.name]);

    const updateRow = (key: string, patch: Partial<Row>) => {
        if (key.startsWith('id:')) {
            const id = Number(key.slice(3));
            setMembers((current) => current.map((row) => (row.id === id ? { ...row, ...patch } : row)));

            return;
        }

        const current = rows.find((entry) => entry.key === key)?.row;

        if (current) {
            setGenerated((all) => ({ ...all, [key]: { ...current, ...patch } }));
        }
    };

    const toggleValue = (which: 1 | 2, id: number) => {
        const setter = which === 1 ? setSelected1 : setSelected2;
        setter((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]));
    };

    // --- Acciones de llenado -------------------------------------------------------------
    const [fill, setFill] = useState({ field: 'price', amount: '', scope: 'all', how: 'set' });

    const fillScopes = useMemo(() => {
        const scopes: SelectOption[] = [{ value: 'all', label: 'Todas las filas' }];

        [
            [attribute1, selected1],
            [attribute2, selected2],
        ].forEach(([attribute, ids]) => {
            (ids as number[]).forEach((id) => {
                const value = valueOf(id);

                if (attribute && value) {
                    scopes.push({ value: `${id}`, label: `Las de ${(attribute as AttributeRow).name} ${value.value}` });
                }
            });
        });

        return scopes;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [attribute1, attribute2, selected1, selected2, catalog]);

    const applyFill = () => {
        const amount = Number(fill.amount);

        if (!Number.isFinite(amount) || fill.amount === '') {
            toast.error('Escribe el valor que quieres aplicar.');

            return;
        }

        let touched = 0;

        rows.forEach(({ key, row }) => {
            if (fill.scope !== 'all' && row.v1 !== Number(fill.scope) && row.v2 !== Number(fill.scope)) {
                return;
            }

            const field = fill.field as 'price' | 'online_price' | 'cost' | 'min_stock';
            const current = Number(row[field]) || 0;
            const next = fill.how === 'add' ? current + amount : amount;
            updateRow(key, { [field]: String(Math.max(0, Math.round(next))) } as Partial<Row>);
            touched++;
        });

        toast.success(touched === 1 ? '1 fila actualizada.' : `${touched} filas actualizadas.`);
    };

    // --- Valor nuevo al vuelo --------------------------------------------------------------
    const createValue = async (attribute: AttributeRow, payload: { value?: string; amount?: string; unit?: string; color_hex?: string }) => {
        const response = await fetch(route('attributes.values.store', attribute.id), {
            method: 'POST',
            headers: { ...csrfHeaders(), 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify(payload),
        });

        if (!response.ok) {
            toast.error(await errorMessage(response, 'No se pudo crear el valor.'));

            return;
        }

        const { value } = (await response.json()) as { value: AttributeValue };

        setCatalog((current) =>
            current.map((entry) =>
                entry.id === attribute.id
                    ? {
                          ...entry,
                          values: entry.values.some((existing) => existing.id === value.id)
                              ? entry.values
                              : [...entry.values, value].sort((a, b) => a.sort_order - b.sort_order),
                      }
                    : entry,
            ),
        );
        toggleValue(attribute.id === attr1 ? 1 : 2, value.id);
    };

    // --- Guardar ---------------------------------------------------------------------------
    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!attr1) {
            toast.error('Elige al menos una característica.');

            return;
        }

        const payloadRows = rows.map(({ row }) => ({
            ...(row.id ? { id: row.id } : {}),
            value_1_id: row.v1,
            value_2_id: attr2 ? row.v2 : null,
            sku: row.sku.trim(),
            barcode: row.barcode.trim(),
            price: row.price === '' ? 0 : Number(row.price),
            online_price: row.online_price === '' ? null : Number(row.online_price),
            wholesale_price: row.wholesale_price === '' ? null : Number(row.wholesale_price),
            ...(canViewCost ? { cost: row.cost === '' ? 0 : Number(row.cost) } : {}),
            content_amount: row.content_amount === '' ? null : Number(row.content_amount),
            content_unit: row.content_unit || null,
            min_stock: Number(row.min_stock) || 0,
            is_active: row.is_active,
            is_online_visible: row.is_online_visible,
            unit: row.unit,
            sold_by_weight: row.sold_by_weight,
            track_lots: row.track_lots,
            allow_negative_stock: row.allow_negative_stock,
            purchase_pack_size: row.purchase_pack_size === '' ? null : Number(row.purchase_pack_size),
            description: row.description,
            initial_stocks: row.id ? [] : stores.map((store) => ({ store_id: store.value, quantity: Number(row.initial[Number(store.value)] ?? 0) || 0 })),
        }));

        const defaultIndex = Math.max(0, rows.findIndex((entry) => entry.key === defaultKey));
        const payload = {
            ...common,
            supplier_id: common.supplier_id || null,
            attribute_1_id: attr1,
            attribute_2_id: attr2,
            default_index: defaultIndex,
            variants: payloadRows,
            images: files.map((entry) => entry.file),
            image_values: files.map((entry) => (entry.value ? Number(entry.value) : null)),
        };

        const url =
            mode === 'create'
                ? route('products.families.store')
                : mode === 'edit'
                  ? route('products.families.update', family.id ?? 0)
                  : mode === 'convert'
                    ? route('products.convert', props.rows[0]?.id ?? 0)
                    : route('products.families.group');

        setSubmittedKeys(rows.map((entry) => entry.key));
        setProcessing(true);
        router.post(url, (mode === 'edit' ? { ...payload, _method: 'put' } : payload) as never, {
            forceFormData: files.length > 0,
            preserveScroll: true,
            onError: (bag) => {
                setErrors(bag);
                toast.error('Revisa los datos: hay errores marcados.');
            },
            onSuccess: () => {
                setErrors({});
                setFiles([]);
            },
            onFinish: () => setProcessing(false),
        });
    };

    const rowError = (key: string, field: string) => {
        const index = submittedKeys.indexOf(key);

        return index >= 0 ? errors[`variants.${index}.${field}`] : undefined;
    };

    const matrixErrors = Object.entries(errors).filter(([field]) => field.startsWith('variants'));

    // El escáner escribe el código y manda Enter: se baja a la siguiente fila.
    const onBarcodeKey = (event: KeyboardEvent<HTMLInputElement>, index: number) => {
        if (event.key !== 'Enter') {
            return;
        }

        event.preventDefault();
        const next = rows[index + 1];

        if (next) {
            barcodeRefs.current[next.key]?.focus();
        }
    };

    const valueOptions = (attribute: AttributeRow | null) => (attribute ? attribute.values.filter((value) => value.is_active).map((value) => ({ value: value.id, label: value.value })) : []);
    const attributeOptions = catalog.filter((attribute) => attribute.is_active).map((attribute) => ({ value: attribute.id, label: attribute.name }));

    return (
        <AppLayout title={TITLES[mode]}>
            <PageHeader
                title={TITLES[mode]}
                description={
                    mode === 'convert'
                        ? 'El artículo queda como el primer subartículo: conserva su existencia, su kardex y sus ventas.'
                        : mode === 'group'
                          ? 'Cada artículo conserva su existencia, su kardex y sus ventas. Ponle a cada uno sus valores.'
                          : 'Cada subartículo se cobra, se compra y se cuenta por separado. En la tienda se ve uno solo, con sus opciones.'
                }
                actions={
                    <ButtonLink
                        href={family.id ? route('products.families.show', family.id) : route('products.index')}
                        variant="secondary"
                        icon={<ArrowLeftIcon size={16} />}
                    >
                        Volver
                    </ButtonLink>
                }
            />

            {mode === 'create' && (
                <div className="mb-4 flex flex-wrap items-center gap-2" role="radiogroup" aria-label="Tipo de artículo">
                    <span className="text-[0.8125rem] font-medium text-[var(--muted)]">Tipo de artículo:</span>
                    <ButtonLink href={route('products.create')} variant="secondary" size="sm">
                        Individual
                    </ButtonLink>
                    <span className="inline-flex items-center rounded-control bg-[var(--accent)] px-3 py-1.5 text-[0.8125rem] font-semibold text-[var(--accent-ink)]" role="radio" aria-checked="true">
                        Con subartículos: presentaciones, sabores, tallas…
                    </span>
                </div>
            )}

            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader title="Lo común" description="Se copia a cada subartículo: categoría, marca, proveedor e IVA son los mismos para todos." />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Input
                                label="Nombre"
                                required
                                value={common.name}
                                onChange={(event) => setCommon({ ...common, name: event.target.value })}
                                error={errors.name}
                                placeholder="Aceite de girasol"
                                containerClassName="sm:col-span-2"
                            />
                            <Input
                                label="Código de referencia"
                                value={common.code}
                                onChange={(event) => setCommon({ ...common, code: event.target.value })}
                                error={errors.code}
                                hint="Opcional. No cobra nada: en caja ofrece elegir el subartículo."
                            />
                            <Input label="Marca" value={common.brand} onChange={(event) => setCommon({ ...common, brand: event.target.value })} error={errors.brand} />
                            <Select
                                label="Categoría"
                                required
                                options={props.categories}
                                value={common.category_id}
                                onChange={(event) => setCommon({ ...common, category_id: event.target.value })}
                                error={errors.category_id}
                            />
                            <Select
                                label="Proveedor principal"
                                options={props.suppliers}
                                placeholder="Sin proveedor"
                                value={common.supplier_id}
                                onChange={(event) => setCommon({ ...common, supplier_id: event.target.value })}
                                error={errors.supplier_id}
                            />
                            <Select label="IVA" options={props.taxRates} value={common.tax_rate} onChange={(event) => setCommon({ ...common, tax_rate: event.target.value })} error={errors.tax_rate} />
                            <Textarea
                                label="Descripción"
                                rows={3}
                                value={common.description}
                                onChange={(event) => setCommon({ ...common, description: event.target.value })}
                                error={errors.description}
                                hint="La de todos, salvo que un subartículo tenga la suya."
                                className="sm:col-span-2"
                            />
                        </div>
                    </Card>

                    <div className="flex flex-col gap-4">
                        <Card>
                            <CardHeader title="Características" description="Hasta dos: la primera arma las filas y la segunda las columnas de cada fila." />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Select
                                    label="Primera"
                                    required
                                    placeholder="Elige: volumen, peso, sabor…"
                                    options={attributeOptions}
                                    value={attr1 ?? ''}
                                    disabled={locked}
                                    onChange={(event) => {
                                        setAttr1(event.target.value ? Number(event.target.value) : null);
                                        setSelected1([]);
                                    }}
                                    error={errors.attribute_1_id}
                                />
                                <Select
                                    label="Segunda (opcional)"
                                    placeholder="Ninguna"
                                    options={attributeOptions.filter((option) => option.value !== attr1)}
                                    value={attr2 ?? ''}
                                    disabled={locked && family.attribute_2_id !== null}
                                    onChange={(event) => {
                                        setAttr2(event.target.value ? Number(event.target.value) : null);
                                        setSelected2([]);
                                    }}
                                    error={errors.attribute_2_id}
                                />
                            </div>

                            {[
                                [attribute1, 1],
                                [attribute2, 2],
                            ].map(([attribute, which]) =>
                                attribute ? (
                                    <ValuePicker
                                        key={(attribute as AttributeRow).id}
                                        attribute={attribute as AttributeRow}
                                        selected={which === 1 ? selected1 : selected2}
                                        onToggle={(id) => toggleValue(which as 1 | 2, id)}
                                        onCreate={canManageAttributes ? (payload) => createValue(attribute as AttributeRow, payload) : undefined}
                                        hint={perRowValues ? 'Marca aquí presentaciones nuevas; los artículos que ya existen eligen su valor en su fila.' : undefined}
                                    />
                                ) : null,
                            )}
                        </Card>

                        <Card>
                            <CardHeader
                                title="Fotos comunes"
                                description="Salen en la tienda si el subartículo no tiene las suyas. Marca una foto con un valor (un color) para mostrarla al elegirlo. Las propias de cada subartículo se suben al editarlo."
                            />
                            <ImageGalleryField
                                images={props.images.map((image) => ({ ...image, badge: image.value_id ? (valueOf(image.value_id)?.value ?? null) : null }))}
                                files={files.map((entry) => entry.file)}
                                limit={props.imageLimit}
                                onAddFiles={(picked) => setFiles([...files, ...picked.map((file) => ({ file, value: '' }))])}
                                onRemoveFile={(index) => setFiles(files.filter((_, position) => position !== index))}
                                onRemove={
                                    family.id
                                        ? (image) =>
                                              router.delete(route('products.families.images.destroy', [family.id ?? 0, image.id]), { preserveScroll: true, preserveState: true })
                                        : undefined
                                }
                                onMakeCover={
                                    family.id
                                        ? (image) =>
                                              router.patch(route('products.families.images.cover', [family.id ?? 0, image.id]), {}, { preserveScroll: true, preserveState: true })
                                        : undefined
                                }
                                renderFileExtra={(index) => (
                                    <select
                                        aria-label="A qué valor corresponde"
                                        value={files[index]?.value ?? ''}
                                        onChange={(event) => setFiles(files.map((item, position) => (position === index ? { ...item, value: event.target.value } : item)))}
                                        className="w-24 rounded-chip border border-[var(--line)] bg-[var(--surface)] text-[0.6875rem]"
                                    >
                                        <option value="">Todas</option>
                                        {[...selected1, ...selected2].map((id) => (
                                            <option key={id} value={id}>
                                                {valueOf(id)?.value}
                                            </option>
                                        ))}
                                    </select>
                                )}
                                error={errors.images ?? errors['images.0']}
                            />
                        </Card>
                    </div>
                </div>

                <Card padded={false}>
                    <div className="flex flex-wrap items-start justify-between gap-3 border-b border-[var(--line)] p-4">
                        <div>
                            <h2 className="text-[1rem] font-semibold text-[var(--text)]">Subartículos</h2>
                            <p className="text-[0.8125rem] text-[var(--muted)]">
                                {rows.length === 1 ? '1 subartículo' : `${rows.length} subartículos`}. Enfoca «Código de barras» y escanea: baja solo a la siguiente fila.
                            </p>
                        </div>

                        {rows.length > 1 && (
                            <div className="flex flex-wrap items-end gap-2">
                                <Select
                                    label="Llenar"
                                    options={[
                                        { value: 'price', label: 'Precio' },
                                        { value: 'online_price', label: 'Precio en línea' },
                                        ...(canViewCost ? [{ value: 'cost', label: 'Costo' }] : []),
                                        { value: 'min_stock', label: 'Mínimo' },
                                    ]}
                                    value={fill.field}
                                    onChange={(event) => setFill({ ...fill, field: event.target.value })}
                                />
                                <Select
                                    label="Cómo"
                                    options={[
                                        { value: 'set', label: 'Poner' },
                                        { value: 'add', label: 'Sumar' },
                                    ]}
                                    value={fill.how}
                                    onChange={(event) => setFill({ ...fill, how: event.target.value })}
                                />
                                <Input label="Valor" type="number" value={fill.amount} onChange={(event) => setFill({ ...fill, amount: event.target.value })} containerClassName="w-28" />
                                <Select label="A cuáles" options={fillScopes} value={fill.scope} onChange={(event) => setFill({ ...fill, scope: event.target.value })} />
                                <Button variant="secondary" onClick={applyFill}>
                                    Aplicar
                                </Button>
                            </div>
                        )}
                    </div>

                    {matrixErrors.length > 0 && (
                        <ul className="m-4 flex flex-col gap-1 rounded-control bg-[var(--danger-soft)] px-4 py-3 text-[0.8125rem] text-[var(--danger)]" role="alert">
                            {matrixErrors.slice(0, 8).map(([field, message]) => (
                                <li key={field} className="flex items-start gap-1.5">
                                    <WarningCircleIcon size={15} className="mt-0.5 shrink-0" />
                                    {message}
                                </li>
                            ))}
                            {matrixErrors.length > 8 && <li>Y {matrixErrors.length - 8} más.</li>}
                        </ul>
                    )}

                    {rows.length === 0 ? (
                        <p className="p-6 text-center text-[0.875rem] text-[var(--muted)]">
                            {attr1 ? 'Marca los valores de arriba para armar los subartículos.' : 'Elige una característica y marca sus valores.'}
                        </p>
                    ) : (
                        <Table className="rounded-none border-x-0 border-b-0" tableClassName="min-w-[68rem] text-[0.8125rem]">
                            <thead>
                                <tr className="border-b border-[var(--line)] text-left text-[0.75rem] uppercase tracking-wide text-[var(--muted)]">
                                    <th className="w-8 px-2 py-2" />
                                    <th className="px-2 py-2">Subartículo</th>
                                    <th className="px-2 py-2">SKU</th>
                                    <th className="px-2 py-2">Código de barras</th>
                                    <th className="px-2 py-2 text-right">Precio</th>
                                    <th className="px-2 py-2 text-right">En línea</th>
                                    {canViewCost && <th className="px-2 py-2 text-right">Costo</th>}
                                    <th className="px-2 py-2">Contenido</th>
                                    <th className="px-2 py-2 text-right">Mínimo</th>
                                    <th className="px-2 py-2 text-center">Activo</th>
                                    <th className="px-2 py-2 text-center">En línea</th>
                                    <th className="px-2 py-2 text-center" title="Se muestra primero en la tienda">
                                        <StarIcon size={14} className="inline" />
                                    </th>
                                    <th className="w-10 px-2 py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map(({ key, row }, index) => {
                                    const measure = measureValue(row);
                                    const priceLocked = row.id !== null && !canEditPrice;
                                    const cell = (field: string) => rowError(key, field);
                                    const previous = rows[index - 1]?.row;
                                    const newGroup = attr2 && previous && previous.v1 !== row.v1;

                                    return (
                                        <FragmentRows
                                            key={key}
                                            expandedPanel={expanded === key}
                                            separator={Boolean(newGroup)}
                                            main={
                                                <tr className={cn('border-b border-[var(--line)] align-top', !row.is_active && 'bg-[var(--surface-2)]')}>
                                                    <td className="px-2 py-2">
                                                        <button
                                                            type="button"
                                                            onClick={() => setExpanded(expanded === key ? null : key)}
                                                            aria-label={expanded === key ? 'Cerrar el detalle' : 'Abrir el detalle'}
                                                            aria-expanded={expanded === key}
                                                            className="grid size-7 place-items-center rounded-chip text-[var(--muted)] hover:bg-[var(--surface-2)]"
                                                        >
                                                            {expanded === key ? <CaretDownIcon size={14} /> : <CaretRightIcon size={14} />}
                                                        </button>
                                                    </td>
                                                    <td className="px-2 py-2">
                                                        {perRowValues && row.id ? (
                                                            <div className="flex min-w-44 flex-col gap-1">
                                                                <span className="text-[0.75rem] text-[var(--muted)]">{row.original}</span>
                                                                <select
                                                                    aria-label={`Valor de ${attribute1?.name ?? 'la primera característica'}`}
                                                                    value={row.v1 ?? ''}
                                                                    onChange={(event) => updateRow(key, { v1: event.target.value ? Number(event.target.value) : null })}
                                                                    className={cn('h-8 rounded-control border bg-[var(--surface)] px-2', cell('value_1_id') ? 'border-[var(--danger)]' : 'border-[var(--line)]')}
                                                                >
                                                                    <option value="">{attribute1 ? `${attribute1.name}…` : 'Elige la característica'}</option>
                                                                    {valueOptions(attribute1).map((option) => (
                                                                        <option key={option.value} value={option.value}>
                                                                            {option.label}
                                                                        </option>
                                                                    ))}
                                                                </select>
                                                                {attribute2 && (
                                                                    <select
                                                                        aria-label={`Valor de ${attribute2.name}`}
                                                                        value={row.v2 ?? ''}
                                                                        onChange={(event) => updateRow(key, { v2: event.target.value ? Number(event.target.value) : null })}
                                                                        className={cn('h-8 rounded-control border bg-[var(--surface)] px-2', cell('value_2_id') ? 'border-[var(--danger)]' : 'border-[var(--line)]')}
                                                                    >
                                                                        <option value="">{attribute2.name}…</option>
                                                                        {valueOptions(attribute2).map((option) => (
                                                                            <option key={option.value} value={option.value}>
                                                                                {option.label}
                                                                            </option>
                                                                        ))}
                                                                    </select>
                                                                )}
                                                            </div>
                                                        ) : (
                                                            <div className="min-w-40">
                                                                <span className="font-semibold text-[var(--text)]">{label(row) || '—'}</span>
                                                                <span className="block truncate text-[0.75rem] text-[var(--muted)]">
                                                                    {common.name || 'Nombre'} · {label(row)}
                                                                </span>
                                                                {row.id === null && <Badge tone="accent">Nuevo</Badge>}
                                                            </div>
                                                        )}
                                                    </td>
                                                    <td className="px-2 py-2">
                                                        <CellInput
                                                            value={row.sku}
                                                            error={cell('sku')}
                                                            ariaLabel={`SKU de ${label(row)}`}
                                                            onChange={(value) => updateRow(key, { sku: value, skuAuto: false })}
                                                            className="w-36"
                                                        />
                                                    </td>
                                                    <td className="px-2 py-2">
                                                        <CellInput
                                                            inputRef={(element) => {
                                                                barcodeRefs.current[key] = element;
                                                            }}
                                                            value={row.barcode}
                                                            error={cell('barcode')}
                                                            ariaLabel={`Código de barras de ${label(row)}`}
                                                            onChange={(value) => updateRow(key, { barcode: value })}
                                                            onKeyDown={(event) => onBarcodeKey(event, index)}
                                                            className="w-40 tabular-nums"
                                                        />
                                                    </td>
                                                    <td className="px-2 py-2">
                                                        <CellInput
                                                            type="number"
                                                            value={row.price}
                                                            error={cell('price')}
                                                            disabled={priceLocked}
                                                            ariaLabel={`Precio de ${label(row)}`}
                                                            onChange={(value) => updateRow(key, { price: value })}
                                                            className="w-28 text-right tabular-nums"
                                                        />
                                                    </td>
                                                    <td className="px-2 py-2">
                                                        <CellInput
                                                            type="number"
                                                            value={row.online_price}
                                                            error={cell('online_price')}
                                                            disabled={priceLocked}
                                                            placeholder="Igual"
                                                            ariaLabel={`Precio en línea de ${label(row)}`}
                                                            onChange={(value) => updateRow(key, { online_price: value })}
                                                            className="w-28 text-right tabular-nums"
                                                        />
                                                    </td>
                                                    {canViewCost && (
                                                        <td className="px-2 py-2">
                                                            <CellInput
                                                                type="number"
                                                                value={row.cost}
                                                                error={cell('cost')}
                                                                ariaLabel={`Costo de ${label(row)}`}
                                                                onChange={(value) => updateRow(key, { cost: value })}
                                                                className="w-24 text-right tabular-nums"
                                                            />
                                                        </td>
                                                    )}
                                                    <td className="px-2 py-2">
                                                        {measure ? (
                                                            <span className="text-[var(--muted)]" title="Lo pone la presentación">
                                                                {measure.value}
                                                            </span>
                                                        ) : (
                                                            <div className="flex gap-1">
                                                                <CellInput
                                                                    type="number"
                                                                    value={row.content_amount}
                                                                    ariaLabel={`Contenido de ${label(row)}`}
                                                                    onChange={(value) => updateRow(key, { content_amount: value })}
                                                                    className="w-20 text-right"
                                                                />
                                                                <select
                                                                    aria-label={`Unidad del contenido de ${label(row)}`}
                                                                    value={row.content_unit}
                                                                    onChange={(event) => updateRow(key, { content_unit: event.target.value })}
                                                                    className="h-8 rounded-control border border-[var(--line)] bg-[var(--surface)] px-1"
                                                                >
                                                                    <option value="">—</option>
                                                                    {props.contentUnits.map((unit) => (
                                                                        <option key={unit.value} value={unit.value}>
                                                                            {unit.label}
                                                                        </option>
                                                                    ))}
                                                                </select>
                                                            </div>
                                                        )}
                                                    </td>
                                                    <td className="px-2 py-2">
                                                        <CellInput
                                                            type="number"
                                                            value={row.min_stock}
                                                            ariaLabel={`Mínimo de ${label(row)}`}
                                                            onChange={(value) => updateRow(key, { min_stock: value })}
                                                            className="w-16 text-right"
                                                        />
                                                    </td>
                                                    <td className="px-2 py-2 text-center">
                                                        <input
                                                            type="checkbox"
                                                            aria-label={`${label(row)} activo`}
                                                            checked={row.is_active}
                                                            onChange={(event) => updateRow(key, { is_active: event.target.checked })}
                                                            className="size-4 accent-[var(--accent)]"
                                                        />
                                                    </td>
                                                    <td className="px-2 py-2 text-center">
                                                        <input
                                                            type="checkbox"
                                                            aria-label={`${label(row)} visible en línea`}
                                                            checked={row.is_online_visible}
                                                            onChange={(event) => updateRow(key, { is_online_visible: event.target.checked })}
                                                            className="size-4 accent-[var(--accent)]"
                                                        />
                                                    </td>
                                                    <td className="px-2 py-2 text-center">
                                                        <input
                                                            type="radio"
                                                            name="default-variant"
                                                            aria-label={`${label(row)} se muestra primero`}
                                                            checked={defaultKey === key || (defaultKey === null && index === 0)}
                                                            onChange={() => setDefaultKey(key)}
                                                            className="size-4 accent-[var(--accent)]"
                                                        />
                                                    </td>
                                                    <td className="px-2 py-2">
                                                        {row.has_movements ? (
                                                            <span title="Tiene movimientos: no se quita, se desactiva." className="grid size-7 place-items-center text-[var(--muted)]">
                                                                <WarningCircleIcon size={15} />
                                                            </span>
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                aria-label={`Quitar ${label(row) || 'la fila'}`}
                                                                onClick={() => {
                                                                    if (row.id !== null && mode !== 'edit') {
                                                                        toast.info('Ese artículo es el que se está agrupando: para dejarlo por fuera, vuelve al listado.');

                                                                        return;
                                                                    }

                                                                    if (row.id !== null) {
                                                                        setRemoved(new Set([...removed, row.id]));
                                                                    } else {
                                                                        setExcluded(new Set([...excluded, key]));
                                                                    }
                                                                }}
                                                                className="grid size-7 place-items-center rounded-chip text-[var(--muted)] hover:bg-[var(--danger-soft)] hover:text-[var(--danger)]"
                                                            >
                                                                <TrashIcon size={14} />
                                                            </button>
                                                        )}
                                                    </td>
                                                </tr>
                                            }
                                            panel={
                                                <tr className="border-b border-[var(--line)] bg-[var(--surface-2)]">
                                                    <td />
                                                    <td colSpan={canViewCost ? 12 : 11} className="px-2 py-3">
                                                        <div className="grid gap-3 md:grid-cols-4">
                                                            <Select
                                                                label="Se vende por"
                                                                options={props.units}
                                                                value={row.unit}
                                                                onChange={(event) => updateRow(key, { unit: event.target.value })}
                                                            />
                                                            <Input
                                                                label="Empaque de compra"
                                                                type="number"
                                                                value={row.purchase_pack_size}
                                                                onChange={(event) => updateRow(key, { purchase_pack_size: event.target.value })}
                                                                hint="Unidades por caja al pedir."
                                                            />
                                                            <Input
                                                                label="Precio mayorista"
                                                                type="number"
                                                                value={row.wholesale_price}
                                                                disabled={priceLocked}
                                                                onChange={(event) => updateRow(key, { wholesale_price: event.target.value })}
                                                            />
                                                            <div className="flex flex-col gap-2 pt-1">
                                                                <Checkbox label="Se vende por peso" checked={row.sold_by_weight} onChange={(event) => updateRow(key, { sold_by_weight: event.target.checked })} />
                                                                <Checkbox label="Controla lotes" checked={row.track_lots} onChange={(event) => updateRow(key, { track_lots: event.target.checked })} />
                                                                <Checkbox
                                                                    label="Permite vender sin existencia"
                                                                    checked={row.allow_negative_stock}
                                                                    onChange={(event) => updateRow(key, { allow_negative_stock: event.target.checked })}
                                                                />
                                                            </div>
                                                            <Textarea
                                                                label="Descripción propia"
                                                                rows={2}
                                                                value={row.description}
                                                                onChange={(event) => updateRow(key, { description: event.target.value })}
                                                                hint="Vacía: la común."
                                                                className="md:col-span-2"
                                                            />
                                                            {row.id === null ? (
                                                                <div className="md:col-span-2">
                                                                    <p className="mb-1.5 text-[0.8125rem] font-medium">Existencia inicial por sede</p>
                                                                    <div className="flex flex-wrap gap-2">
                                                                        {stores.map((store) => (
                                                                            <Input
                                                                                key={store.value}
                                                                                label={store.label}
                                                                                type="number"
                                                                                min="0"
                                                                                value={row.initial[Number(store.value)] ?? ''}
                                                                                onChange={(event) => updateRow(key, { initial: { ...row.initial, [Number(store.value)]: event.target.value } })}
                                                                                containerClassName="w-32"
                                                                            />
                                                                        ))}
                                                                    </div>
                                                                </div>
                                                            ) : (
                                                                <p className="text-[0.75rem] text-[var(--muted)] md:col-span-2">
                                                                    Fotos propias, lotes y la existencia se manejan en la ficha del subartículo.{' '}
                                                                    {mode === 'edit' && (
                                                                        <a href={route('products.edit', row.id)} className="font-semibold text-[var(--accent)] hover:underline">
                                                                            Abrir su ficha
                                                                        </a>
                                                                    )}
                                                                </p>
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            }
                                        />
                                    );
                                })}
                            </tbody>
                        </Table>
                    )}

                    {(excluded.size > 0 || removed.size > 0) && (
                        <div className="flex flex-wrap items-center gap-2 border-t border-[var(--line)] px-4 py-3 text-[0.8125rem] text-[var(--muted)]">
                            {excluded.size + removed.size === 1 ? '1 combinación quitada.' : `${excluded.size + removed.size} combinaciones quitadas.`}
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => {
                                    setExcluded(new Set());
                                    setRemoved(new Set());
                                }}
                            >
                                Volver a ponerlas
                            </Button>
                        </div>
                    )}
                </Card>

                <Card>
                    <CardHeader title="Tienda y estado" />
                    <div className="flex flex-wrap gap-6">
                        <Checkbox
                            label="Activo"
                            hint="Sin subartículos activos, el artículo se apaga solo."
                            checked={common.is_active}
                            onChange={(event) => setCommon({ ...common, is_active: event.target.checked })}
                        />
                        <Checkbox
                            label="Visible en la tienda en línea"
                            hint="Sale una sola vez, con sus opciones para escoger."
                            checked={common.is_online_visible}
                            onChange={(event) => setCommon({ ...common, is_online_visible: event.target.checked })}
                        />
                        <Checkbox label="Destacado" checked={common.is_featured} onChange={(event) => setCommon({ ...common, is_featured: event.target.checked })} />
                    </div>
                </Card>

                <div className="flex flex-wrap items-center justify-end gap-2">
                    {rows.length > 0 && (
                        <span className="mr-auto text-[0.8125rem] text-[var(--muted)]">
                            Precios de {money(Math.min(...rows.map(({ row }) => Number(row.price) || 0)))} a {money(Math.max(...rows.map(({ row }) => Number(row.price) || 0)))}
                        </span>
                    )}
                    <ButtonLink href={family.id ? route('products.families.show', family.id) : route('products.index')} variant="ghost">
                        Cancelar
                    </ButtonLink>
                    <Button type="submit" loading={processing} disabled={rows.length === 0}>
                        {mode === 'create' ? 'Crear artículo' : mode === 'edit' ? 'Guardar cambios' : mode === 'convert' ? 'Convertir' : 'Agrupar'}
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}

function unique(ids: (number | null)[]): number[] {
    return Array.from(new Set(ids.filter((id): id is number => id !== null)));
}

/** La fila y, si está abierta, su detalle; con una raya entre grupos de la primera característica. */
function FragmentRows({ main, panel, expandedPanel, separator }: { main: React.ReactNode; panel: React.ReactNode; expandedPanel: boolean; separator: boolean }) {
    return (
        <>
            {separator && (
                <tr aria-hidden>
                    <td colSpan={14} className="h-1.5 bg-[var(--surface-2)]" />
                </tr>
            )}
            {main}
            {expandedPanel && panel}
        </>
    );
}

function CellInput({
    value,
    onChange,
    error,
    ariaLabel,
    type = 'text',
    className,
    disabled,
    placeholder,
    onKeyDown,
    inputRef,
}: {
    value: string;
    onChange: (value: string) => void;
    error?: string;
    ariaLabel: string;
    type?: string;
    className?: string;
    disabled?: boolean;
    placeholder?: string;
    onKeyDown?: (event: KeyboardEvent<HTMLInputElement>) => void;
    inputRef?: (element: HTMLInputElement | null) => void;
}) {
    return (
        <input
            ref={inputRef}
            type={type}
            value={value}
            disabled={disabled}
            placeholder={placeholder}
            aria-label={ariaLabel}
            aria-invalid={error ? true : undefined}
            title={error}
            onChange={(event) => onChange(event.target.value)}
            onKeyDown={onKeyDown}
            className={cn(
                'h-8 rounded-control border bg-[var(--surface)] px-2 focus:border-[var(--accent)] focus:outline-none disabled:bg-[var(--surface-2)] disabled:text-[var(--muted)]',
                error ? 'border-[var(--danger)] bg-[var(--danger-soft)]' : 'border-[var(--line)]',
                className,
            )}
        />
    );
}

/** Los valores de una característica como chips para marcar, y «+ Nuevo valor» al vuelo. */
function ValuePicker({
    attribute,
    selected,
    onToggle,
    onCreate,
    hint,
}: {
    attribute: AttributeRow;
    selected: number[];
    onToggle: (id: number) => void;
    onCreate?: (payload: { value?: string; amount?: string; unit?: string; color_hex?: string }) => Promise<void>;
    hint?: string;
}) {
    const [adding, setAdding] = useState(false);
    const [text, setText] = useState('');
    const [amount, setAmount] = useState('');
    const [unit, setUnit] = useState(attribute.units[0] ?? '');
    const [color, setColor] = useState('#1E4E9A');
    const measure = attribute.measure !== null;

    const create = async () => {
        if (!onCreate) {
            return;
        }

        await onCreate(measure ? { amount, unit } : { value: text, ...(attribute.display === 'swatches' ? { color_hex: color } : {}) });
        setText('');
        setAmount('');
        setAdding(false);
    };

    return (
        <div className="mt-4">
            <p className="mb-1.5 text-[0.8125rem] font-medium text-[var(--text)]">
                {attribute.name} <span className="font-normal text-[var(--muted)]">· {selected.length} marcados</span>
            </p>
            <div className="flex flex-wrap gap-1.5">
                {attribute.values
                    .filter((value) => value.is_active || selected.includes(value.id))
                    .map((value) => {
                        const on = selected.includes(value.id);

                        return (
                            <button
                                key={value.id}
                                type="button"
                                aria-pressed={on}
                                onClick={() => onToggle(value.id)}
                                className={cn(
                                    'flex h-8 items-center gap-1.5 rounded-full border px-3 text-[0.8125rem] font-medium transition-colors',
                                    on ? 'border-[var(--accent)] bg-[var(--accent)] text-[var(--accent-ink)]' : 'border-[var(--line)] bg-[var(--surface)] hover:border-[var(--accent)]',
                                )}
                            >
                                {value.color_hex && <span className="size-3.5 rounded-full border border-white/60" style={{ backgroundColor: value.color_hex }} aria-hidden />}
                                {value.value}
                            </button>
                        );
                    })}
                {onCreate && !adding && (
                    <button
                        type="button"
                        onClick={() => setAdding(true)}
                        className="flex h-8 items-center gap-1 rounded-full border border-dashed border-[var(--line-2)] px-3 text-[0.8125rem] text-[var(--muted)] hover:border-[var(--accent)] hover:text-[var(--accent)]"
                    >
                        <PlusIcon size={13} weight="bold" />
                        Nuevo valor
                    </button>
                )}
            </div>
            {adding && onCreate && (
                <div className="mt-2 flex flex-wrap items-center gap-2">
                    {measure ? (
                        <>
                            <input
                                type="number"
                                step="0.001"
                                min="0"
                                autoFocus
                                aria-label="Cantidad"
                                placeholder="Cantidad"
                                value={amount}
                                onChange={(event) => setAmount(event.target.value)}
                                className="h-8 w-28 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2 text-[0.8125rem]"
                            />
                            <select
                                aria-label="Unidad"
                                value={unit}
                                onChange={(event) => setUnit(event.target.value)}
                                className="h-8 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2 text-[0.8125rem]"
                            >
                                {attribute.units.map((option) => (
                                    <option key={option} value={option}>
                                        {option === 'l' ? 'L' : option === 'un' ? 'und' : option}
                                    </option>
                                ))}
                            </select>
                        </>
                    ) : (
                        <>
                            <input
                                autoFocus
                                aria-label="Valor nuevo"
                                placeholder="Valor nuevo"
                                value={text}
                                onChange={(event) => setText(event.target.value)}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') {
                                        event.preventDefault();
                                        void create();
                                    }
                                }}
                                className="h-8 w-40 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2 text-[0.8125rem]"
                            />
                            {attribute.display === 'swatches' && (
                                <input type="color" aria-label="Color" value={color} onChange={(event) => setColor(event.target.value.toUpperCase())} className="h-8 w-10 rounded-control border border-[var(--line)]" />
                            )}
                        </>
                    )}
                    <Button size="sm" onClick={() => void create()}>
                        Crear
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => setAdding(false)}>
                        Cancelar
                    </Button>
                </div>
            )}
            {hint && <p className="mt-1.5 text-[0.75rem] text-[var(--muted)]">{hint}</p>}
        </div>
    );
}

import { router, useForm } from '@inertiajs/react';
import { ArrowLeftIcon, ImageIcon, TrashIcon } from '@phosphor-icons/react';
import { type FormEvent, useMemo } from 'react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Checkbox, Input, Select, Textarea } from '@/components/ui/Field';
import { PageHeader } from '@/components/ui/Misc';
import AppLayout from '@/layouts/AppLayout';
import { money, percent } from '@/lib/format';

interface ProductFormProps {
    mode: 'create' | 'edit';
    product: {
        id: number;
        category_id: number;
        supplier_id: number | null;
        name: string;
        sku: string;
        barcode: string | null;
        brand: string | null;
        unit: string;
        sold_by_weight: boolean;
        cost: number;
        price: number;
        wholesale_price: number | null;
        online_price: number | null;
        tax_rate: number;
        min_stock: number;
        track_lots: boolean;
        allow_negative_stock: boolean;
        is_active: boolean;
        is_online_visible: boolean;
        is_featured: boolean;
        description: string | null;
        /** Si es un subartículo: lo común se edita en el artículo principal. */
        family?: { id: number; name: string | null; label: string | null } | null;
    } | null;
    categories: { value: number; label: string }[];
    suppliers: { value: number; label: string }[];
    stores: { value: number; label: string }[];
    initialStocks: { store_id: number; store_name: string; quantity: number }[];
    images?: { id: number; path: string }[];
}

const UNITS = [
    { value: 'unit', label: 'Unidad' },
    { value: 'kg', label: 'Kilogramo' },
    { value: 'g', label: 'Gramo' },
    { value: 'l', label: 'Litro' },
    { value: 'pack', label: 'Paquete' },
];

const TAX_RATES = [
    { value: 0, label: 'Exento (0 %)' },
    { value: 5, label: 'IVA 5 %' },
    { value: 19, label: 'IVA 19 %' },
];

export default function ProductForm({ mode, product, categories, suppliers, stores, initialStocks, images = [] }: ProductFormProps) {
    const family = product?.family ?? null;
    const { data, setData, post, put, processing, errors } = useForm({
        category_id: product?.category_id ?? (categories[0]?.value ?? 0),
        supplier_id: product?.supplier_id ?? '',
        name: product?.name ?? '',
        sku: product?.sku ?? '',
        barcode: product?.barcode ?? '',
        brand: product?.brand ?? '',
        unit: product?.unit ?? 'unit',
        sold_by_weight: product?.sold_by_weight ?? false,
        cost: product?.cost ?? 0,
        price: product?.price ?? 0,
        wholesale_price: product?.wholesale_price ?? '',
        online_price: product?.online_price ?? '',
        tax_rate: product?.tax_rate ?? 19,
        min_stock: product?.min_stock ?? 0,
        track_lots: product?.track_lots ?? false,
        allow_negative_stock: product?.allow_negative_stock ?? false,
        is_active: product?.is_active ?? true,
        is_online_visible: product?.is_online_visible ?? false,
        is_featured: product?.is_featured ?? false,
        description: product?.description ?? '',
        initial_stocks: stores.map((store) => ({ store_id: store.value, quantity: 0 })),
        images: [] as File[],
    });

    // El margen se recalcula mientras se escribe: asi nadie fija un precio por debajo del costo sin darse cuenta.
    const margin = useMemo(() => {
        const price = Number(data.price) || 0;
        const cost = Number(data.cost) || 0;

        if (price <= 0) {
            return 0;
        }

        return ((price - cost) / price) * 100;
    }, [data.price, data.cost]);

    const taxBase = useMemo(() => {
        const price = Number(data.price) || 0;
        const rate = Number(data.tax_rate) || 0;

        return Math.round(price / (1 + rate / 100));
    }, [data.price, data.tax_rate]);

    const setStockFor = (storeId: number, quantity: number) => {
        setData(
            'initial_stocks',
            data.initial_stocks.map((entry) => (entry.store_id === storeId ? { ...entry, quantity } : entry)),
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (mode === 'create') {
            post(route('products.store'), { forceFormData: data.images.length > 0 });
        } else if (product) {
            // Inertia no envia archivos con PUT: se usa POST con _method para no perder la subida.
            router.post(
                route('products.update', product.id),
                { ...data, _method: 'put' },
                { forceFormData: data.images.length > 0 },
            );
        }
    };

    return (
        <AppLayout title={mode === 'create' ? 'Nuevo articulo' : 'Editar articulo'}>
            <PageHeader
                title={mode === 'create' ? 'Nuevo articulo' : `Editar «${product?.name}»`}
                description="Los precios se guardan con IVA incluido; la base se calcula hacia atras."
                actions={
                    <ButtonLink href={route('products.index')} variant="secondary" icon={<ArrowLeftIcon size={16} />}>
                        Volver
                    </ButtonLink>
                }
            />

            {mode === 'create' && (
                <div className="mb-4 flex flex-wrap items-center gap-2" role="radiogroup" aria-label="Tipo de artículo">
                    <span className="text-[0.8125rem] font-medium text-[var(--muted)]">Tipo de artículo:</span>
                    <span className="inline-flex items-center rounded-control bg-[var(--accent)] px-3 py-1.5 text-[0.8125rem] font-semibold text-[var(--accent-ink)]" role="radio" aria-checked="true">
                        Individual
                    </span>
                    <ButtonLink href={route('products.families.create')} variant="secondary" size="sm">
                        Con subartículos: presentaciones, sabores, tallas…
                    </ButtonLink>
                </div>
            )}

            {family && (
                <div className="mb-4 flex flex-wrap items-center gap-2 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-4 py-3 text-[0.8125rem]">
                    <span>
                        Subartículo de <strong>{family.name}</strong>
                        {family.label ? ` — ${family.label}` : ''}. El nombre, la categoría, la marca, el proveedor y el IVA se editan en el artículo principal.
                    </span>
                    <ButtonLink href={route('products.families.edit', family.id)} variant="secondary" size="sm" className="ml-auto">
                        Editar el principal
                    </ButtonLink>
                </div>
            )}

            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader title="Identificacion" />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Input
                                label="Nombre"
                                required
                                value={data.name}
                                disabled={Boolean(family)}
                                onChange={(event) => setData('name', event.target.value)}
                                error={errors.name}
                                containerClassName="sm:col-span-2"
                            />
                            <Input label="SKU" required value={data.sku} onChange={(event) => setData('sku', event.target.value)} error={errors.sku} />
                            <Input
                                label="Codigo de barras"
                                value={data.barcode}
                                onChange={(event) => setData('barcode', event.target.value)}
                                error={errors.barcode}
                            />
                            <Input label="Marca" value={data.brand} disabled={Boolean(family)} onChange={(event) => setData('brand', event.target.value)} error={errors.brand} />
                            <Select
                                label="Categoria"
                                required
                                disabled={Boolean(family)}
                                options={categories}
                                value={data.category_id}
                                onChange={(event) => setData('category_id', Number(event.target.value))}
                                error={errors.category_id}
                            />
                            <Select
                                label="Proveedor"
                                disabled={Boolean(family)}
                                options={suppliers}
                                placeholder="Sin proveedor"
                                value={data.supplier_id}
                                onChange={(event) => setData('supplier_id', event.target.value)}
                                error={errors.supplier_id}
                            />
                            <Select
                                label="Unidad de venta"
                                required
                                options={UNITS}
                                value={data.unit}
                                onChange={(event) => setData('unit', event.target.value)}
                                error={errors.unit}
                            />
                            <div className="sm:col-span-2">
                                <Checkbox
                                    label="Se vende por peso"
                                    hint="Permite cantidades con decimales en el punto de venta."
                                    checked={data.sold_by_weight}
                                    onChange={(event) => setData('sold_by_weight', event.target.checked)}
                                />
                            </div>
                            <Textarea
                                label="Descripcion"
                                value={data.description}
                                onChange={(event) => setData('description', event.target.value)}
                                error={errors.description}
                                className="sm:col-span-2"
                            />
                        </div>
                    </Card>

                    <div className="flex flex-col gap-4">
                        <Card>
                            <CardHeader title="Precios y tributos" />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Input
                                    label="Costo"
                                    type="number"
                                    min={0}
                                    required
                                    value={data.cost}
                                    onChange={(event) => setData('cost', Number(event.target.value))}
                                    error={errors.cost}
                                    prefix="$"
                                />
                                <Input
                                    label="Precio al publico"
                                    type="number"
                                    min={0}
                                    required
                                    value={data.price}
                                    onChange={(event) => setData('price', Number(event.target.value))}
                                    error={errors.price}
                                    prefix="$"
                                />
                                <Select
                                    label="IVA"
                                    required
                                    disabled={Boolean(family)}
                                    options={TAX_RATES}
                                    value={data.tax_rate}
                                    onChange={(event) => setData('tax_rate', Number(event.target.value))}
                                    error={errors.tax_rate}
                                />
                                <Input
                                    label="Existencia minima"
                                    type="number"
                                    min={0}
                                    required
                                    value={data.min_stock}
                                    onChange={(event) => setData('min_stock', Number(event.target.value))}
                                    error={errors.min_stock}
                                />
                                <Input
                                    label="Precio mayorista"
                                    type="number"
                                    min={0}
                                    value={data.wholesale_price}
                                    onChange={(event) => setData('wholesale_price', event.target.value)}
                                    error={errors.wholesale_price}
                                    prefix="$"
                                />
                                <Input
                                    label="Precio en linea"
                                    type="number"
                                    min={0}
                                    value={data.online_price}
                                    onChange={(event) => setData('online_price', event.target.value)}
                                    error={errors.online_price}
                                    prefix="$"
                                />
                            </div>

                            <div className="mt-4 grid grid-cols-3 gap-3 rounded-control border border-[var(--line)] bg-[var(--surface-2)] p-3">
                                <div>
                                    <p className="text-[0.71875rem] uppercase tracking-wide text-[var(--muted)]">Base gravable</p>
                                    <p className="font-mono text-[0.9375rem] font-semibold tabular">{money(taxBase)}</p>
                                </div>
                                <div>
                                    <p className="text-[0.71875rem] uppercase tracking-wide text-[var(--muted)]">IVA</p>
                                    <p className="font-mono text-[0.9375rem] font-semibold tabular">{money((Number(data.price) || 0) - taxBase)}</p>
                                </div>
                                <div>
                                    <p className="text-[0.71875rem] uppercase tracking-wide text-[var(--muted)]">Margen</p>
                                    <p
                                        className="font-mono text-[0.9375rem] font-semibold tabular"
                                        style={{ color: margin < 10 ? 'var(--danger)' : margin < 20 ? 'var(--warn)' : 'var(--accent)' }}
                                    >
                                        {percent(margin)}
                                    </p>
                                </div>
                            </div>
                        </Card>

                        <Card>
                            <CardHeader title="Inventario y tienda" />
                            <div className="flex flex-col gap-3">
                                <Checkbox
                                    label="Articulo activo"
                                    checked={data.is_active}
                                    onChange={(event) => setData('is_active', event.target.checked)}
                                />
                                <Checkbox
                                    label="Controlar lotes y vencimientos"
                                    checked={data.track_lots}
                                    onChange={(event) => setData('track_lots', event.target.checked)}
                                />
                                <Checkbox
                                    label="Permitir existencias negativas"
                                    hint="Solo para articulos a granel que se pesan al final del dia."
                                    checked={data.allow_negative_stock}
                                    onChange={(event) => setData('allow_negative_stock', event.target.checked)}
                                />
                                <Checkbox
                                    label="Visible en la tienda en linea"
                                    checked={data.is_online_visible}
                                    onChange={(event) => setData('is_online_visible', event.target.checked)}
                                />
                                <Checkbox
                                    label="Destacado en la tienda"
                                    checked={data.is_featured}
                                    onChange={(event) => setData('is_featured', event.target.checked)}
                                />
                            </div>
                        </Card>
                    </div>
                </div>

                <Card>
                    <CardHeader
                        title="Imagenes"
                        description="La primera imagen es la portada: se ve en el listado, en el punto de venta y en la tienda."
                    />

                    {images.length > 0 && (
                        <div className="mb-4 flex flex-wrap gap-2">
                            {images.map((image) => (
                                <div key={image.id} className="relative">
                                    <img
                                        src={`/storage/${image.path}`}
                                        alt=""
                                        className="size-24 rounded-control border border-[var(--line)] object-cover"
                                    />
                                    <button
                                        type="button"
                                        aria-label="Eliminar la imagen"
                                        onClick={() =>
                                            router.delete(route('products.images.destroy', [product!.id, image.id]), {
                                                preserveScroll: true,
                                            })
                                        }
                                        className="absolute -right-1.5 -top-1.5 grid size-6 place-items-center rounded-full border border-[var(--line)] bg-[var(--surface)] text-[var(--danger)] shadow-sm"
                                    >
                                        <TrashIcon size={13} />
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}

                    <label className="flex cursor-pointer flex-col items-center justify-center gap-1.5 rounded-card border border-dashed border-[var(--line-2)] px-4 py-6 text-center transition-colors hover:border-[var(--accent)] hover:bg-[var(--surface-2)]">
                        <ImageIcon size={26} className="text-[var(--muted)]" />
                        <span className="text-[0.84375rem] font-medium">
                            {data.images.length > 0
                                ? `${data.images.length} ${data.images.length === 1 ? 'imagen lista' : 'imagenes listas'} para subir`
                                : 'Elegir imagenes'}
                        </span>
                        <span className="text-[0.75rem] text-[var(--muted)]">JPG, PNG o WebP · maximo 2 MB cada una</span>
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            multiple
                            className="hidden"
                            onChange={(event) => setData('images', Array.from(event.target.files ?? []))}
                        />
                    </label>
                    {errors.images && <p className="mt-2 text-[0.78125rem] font-medium text-[var(--danger)]">{errors.images}</p>}
                </Card>

                {mode === 'create' && stores.length > 0 && (
                    <Card>
                        <CardHeader title="Existencia inicial" description="Queda registrada como un ajuste en el kardex de cada sede." />
                        <div className="grid gap-4 sm:grid-cols-3">
                            {stores.map((store) => (
                                <Input
                                    key={store.value}
                                    label={`Sede ${store.label}`}
                                    type="number"
                                    min={0}
                                    step="0.001"
                                    value={data.initial_stocks.find((entry) => entry.store_id === store.value)?.quantity ?? 0}
                                    onChange={(event) => setStockFor(store.value, Number(event.target.value))}
                                />
                            ))}
                        </div>
                    </Card>
                )}

                {mode === 'edit' && initialStocks.length > 0 && (
                    <Card>
                        <CardHeader
                            title="Existencias actuales"
                            description="Se cambian desde inventario, con ajustes o traslados que dejan rastro."
                        />
                        <div className="grid gap-3 sm:grid-cols-3">
                            {initialStocks.map((stock) => (
                                <div key={stock.store_id} className="rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 py-2.5">
                                    <p className="text-[0.71875rem] uppercase tracking-wide text-[var(--muted)]">Sede {stock.store_name}</p>
                                    <p className="font-mono text-[1.0625rem] font-semibold tabular">{stock.quantity}</p>
                                </div>
                            ))}
                        </div>
                    </Card>
                )}

                <div>
                    <Button type="submit" size="lg" loading={processing}>
                        {mode === 'create' ? 'Crear el articulo' : 'Guardar cambios'}
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}

import { router, usePage } from '@inertiajs/react';
import { ArrowDownIcon, ArrowLeftIcon, ArrowUpIcon, MagnifyingGlassIcon, TrashIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { type FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Checkbox, Input, Select, Textarea } from '@/components/ui/Field';
import { PageHeader } from '@/components/ui/Misc';
import AppLayout from '@/layouts/AppLayout';
import { money, number, percent, UNIT_SHORT } from '@/lib/format';
import type { PageProps } from '@/types';
import type { ComponentLine, ComponentProduct, PricingMode, ServiceFormData, ServiceOption } from '@/types/services';

interface ServiceFormProps {
    mode: 'create' | 'edit';
    service: ServiceFormData | null;
    categories: ServiceOption[];
    pricingModes: ServiceOption[];
    taxRates: ServiceOption[];
    canViewCost: boolean;
    canEditPrice: boolean;
}

const SEARCH_MS = 250;

/**
 * Crear o editar un servicio.
 *
 * Los precios llevan IVA incluido. Con artículos se elige cómo se cobra: precio cerrado (los
 * artículos van incluidos) o servicio + artículos (cada uno a su precio vigente en caja, o al
 * que se le fije aquí). El precio final que se ve abajo es el mismo que calcula la caja.
 */
export default function ServiceForm({ mode, service, categories, pricingModes, taxRates, canViewCost, canEditPrice }: ServiceFormProps) {
    const errors = (usePage<PageProps>().props.errors ?? {}) as Record<string, string>;

    const [form, setForm] = useState({
        code: service?.code ?? '',
        barcode: service?.barcode ?? '',
        name: service?.name ?? '',
        category_id: service?.category_id ? String(service.category_id) : '',
        description: service?.description ?? '',
        price: service?.price ?? 0,
        online_price: service?.online_price !== null && service?.online_price !== undefined ? String(service.online_price) : '',
        cost: service?.cost ?? 0,
        tax_rate: service?.tax_rate ?? 19,
        pricing_mode: (service?.pricing_mode ?? 'bundled') as PricingMode,
        open_price: service?.open_price ?? false,
        is_active: service?.is_active ?? true,
        is_online_visible: service?.is_online_visible ?? false,
        is_featured: service?.is_featured ?? false,
        sort_order: service?.sort_order ?? 0,
    });
    const [components, setComponents] = useState<ComponentLine[]>(service?.components ?? []);
    const [image, setImage] = useState<File | null>(null);
    const [processing, setProcessing] = useState(false);

    const [search, setSearch] = useState('');
    const [results, setResults] = useState<ComponentProduct[]>([]);
    const latest = useRef('');

    const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) => setForm((current) => ({ ...current, [key]: value }));
    const priceLocked = mode === 'edit' && !canEditPrice;
    const itemized = components.length > 0 && form.pricing_mode === 'itemized';
    // El precio con que se cobra un artículo: el fijado en el servicio o, si no hay, el vigente.
    const priceOf = (line: ComponentLine) => line.custom_price ?? line.unit_price ?? 0;

    useEffect(() => {
        const term = search.trim();
        latest.current = term;

        if (term.length < 2) {
            setResults([]);

            return;
        }

        const timer = setTimeout(() => {
            fetch(`${route('services.products')}?search=${encodeURIComponent(term)}`, { headers: { Accept: 'application/json' } })
                .then((response) => (response.ok ? response.json() : Promise.reject(response)))
                .then((body: { products: ComponentProduct[] }) => {
                    if (latest.current === term) {
                        setResults(body.products);
                    }
                })
                .catch(() => toast.error('No se pudo buscar el artículo.'));
        }, SEARCH_MS);

        return () => clearTimeout(timer);
    }, [search]);

    const addComponent = (product: ComponentProduct) => {
        if (components.some((line) => line.product_id === product.product_id)) {
            toast.info(`${product.name} ya está en el servicio.`);

            return;
        }

        setComponents((current) => [...current, { ...product, quantity: 1, returnable_by_default: true, custom_price: null }]);
        setSearch('');
        setResults([]);
    };

    const updateComponent = (index: number, changes: Partial<ComponentLine>) =>
        setComponents((current) => current.map((line, position) => (position === index ? { ...line, ...changes } : line)));

    const move = (index: number, direction: -1 | 1) =>
        setComponents((current) => {
            const target = index + direction;

            if (target < 0 || target >= current.length) {
                return current;
            }

            const next = [...current];
            [next[index], next[target]] = [next[target], next[index]];

            return next;
        });

    const totals = useMemo(() => {
        const componentsCost = components.reduce((sum, line) => sum + Math.round(line.quantity * (line.unit_cost ?? 0)), 0);
        const componentsPrice = itemized ? components.reduce((sum, line) => sum + Math.round(line.quantity * priceOf(line)), 0) : 0;
        const cost = (Number(form.cost) || 0) + componentsCost;
        const price = form.open_price ? 0 : (Number(form.price) || 0) + componentsPrice;

        return { cost, price, margin: price > 0 ? ((price - cost) / price) * 100 : null };
    }, [components, form.cost, form.price, form.open_price, itemized]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setProcessing(true);

        const payload: Record<string, unknown> = {
            ...form,
            category_id: form.category_id === '' ? null : Number(form.category_id),
            online_price: form.online_price === '' || form.open_price ? null : Number(form.online_price),
            is_online_visible: form.open_price ? false : form.is_online_visible,
            is_featured: form.open_price ? false : form.is_featured,
            pricing_mode: components.length === 0 ? 'bundled' : form.pricing_mode,
            components: components.map((line) => ({
                family: Boolean(line.family),
                product_id: line.product_id,
                quantity: Math.round(line.quantity * 1000) / 1000,
                // En precio cerrado los artículos valen $0: no se guarda precio fijo.
                unit_price: itemized ? (line.custom_price ?? null) : null,
                returnable_by_default: line.returnable_by_default,
            })),
        };

        if (!canViewCost) {
            delete payload.cost;
        }

        if (image) {
            payload.image = image;
        }

        const options = { forceFormData: image !== null, preserveScroll: true, onFinish: () => setProcessing(false) };

        if (mode === 'create') {
            router.post(route('services.store'), payload as never, options);
        } else if (service) {
            // Con archivo, Inertia no envía PUT: va POST con _method.
            router.post(route('services.update', service.id), { ...payload, _method: 'put' } as never, options);
        }
    };

    const componentError = (index: number) =>
        errors[`components.${index}.product_id`] ?? errors[`components.${index}.quantity`] ?? errors[`components.${index}.unit_price`];

    return (
        <AppLayout title={mode === 'create' ? 'Nuevo servicio' : 'Editar servicio'}>
            <PageHeader
                title={mode === 'create' ? 'Nuevo servicio' : `Editar «${service?.name}»`}
                description="Los precios se guardan con IVA incluido; la base se calcula hacia atrás."
                actions={
                    <ButtonLink href={service ? route('services.show', service.id) : route('services.index')} variant="secondary" icon={<ArrowLeftIcon size={16} />}>
                        Volver
                    </ButtonLink>
                }
            />

            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader title="Datos" />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Input label="Nombre" required value={form.name} maxLength={160} onChange={(event) => set('name', event.target.value)} error={errors.name} containerClassName="sm:col-span-2" />
                            <Input label="Código" required value={form.code} maxLength={40} onChange={(event) => set('code', event.target.value)} error={errors.code} hint="No puede ser el SKU de un artículo." />
                            <Input label="Código de barras" value={form.barcode} maxLength={60} onChange={(event) => set('barcode', event.target.value)} error={errors.barcode} />
                            <Select
                                label="Categoría"
                                options={categories}
                                placeholder="Sin categoría"
                                value={form.category_id}
                                onChange={(event) => set('category_id', event.target.value)}
                                error={errors.category_id}
                            />
                            <Input
                                label="Orden"
                                type="number"
                                min={0}
                                value={form.sort_order}
                                onChange={(event) => set('sort_order', Math.max(0, Math.round(Number(event.target.value) || 0)))}
                                error={errors.sort_order}
                            />
                            <Textarea
                                label="Descripción"
                                value={form.description}
                                onChange={(event) => set('description', event.target.value)}
                                error={errors.description}
                                className="sm:col-span-2"
                            />
                            <div className="flex items-center gap-3 sm:col-span-2">
                                {service?.image_path && !image && <img src={`/storage/${service.image_path}`} alt="" className="size-14 rounded-control object-cover" />}
                                <Input
                                    label="Imagen"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    onChange={(event) => setImage(event.target.files?.[0] ?? null)}
                                    error={errors.image}
                                    containerClassName="flex-1"
                                />
                            </div>
                        </div>
                    </Card>

                    <Card>
                        <CardHeader title="Precio" description={priceLocked ? 'Para cambiar precios hace falta el permiso «Cambiar precios».' : undefined} />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <Checkbox
                                    label="Precio variable: se digita en caja"
                                    hint="Para recargas y servicios cuyo valor cambia cada vez. No se publica en la tienda en línea."
                                    checked={form.open_price}
                                    disabled={priceLocked}
                                    onChange={(event) => set('open_price', event.target.checked)}
                                />
                            </div>
                            {!form.open_price && (
                                <Input
                                    label={itemized ? 'Valor del servicio (sin artículos)' : 'Precio al público con IVA'}
                                    type="number"
                                    required
                                    min={1}
                                    disabled={priceLocked}
                                    value={form.price}
                                    onChange={(event) => set('price', Math.max(0, Math.round(Number(event.target.value) || 0)))}
                                    error={errors.price}
                                />
                            )}
                            <Select
                                label="IVA"
                                required
                                options={taxRates}
                                value={String(form.tax_rate)}
                                onChange={(event) => set('tax_rate', Number(event.target.value))}
                                error={errors.tax_rate}
                            />
                            {canViewCost && (
                                <Input
                                    label="Costo propio"
                                    hint="Mano de obra o insumos que no se llevan en inventario."
                                    type="number"
                                    min={0}
                                    disabled={priceLocked}
                                    value={form.cost}
                                    onChange={(event) => set('cost', Math.max(0, Math.round(Number(event.target.value) || 0)))}
                                    error={errors.cost}
                                />
                            )}
                            {components.length > 0 && (
                                <Select
                                    label="Cómo se cobra"
                                    required
                                    options={pricingModes}
                                    value={form.pricing_mode}
                                    disabled={priceLocked}
                                    onChange={(event) => set('pricing_mode', event.target.value as PricingMode)}
                                    error={errors.pricing_mode}
                                    hint={
                                        form.pricing_mode === 'itemized'
                                            ? 'Servicio + artículos: cada artículo se cobra aparte; quitar uno en caja baja el total.'
                                            : 'Precio cerrado: los artículos van incluidos; quitarlos en caja no cambia el precio.'
                                    }
                                />
                            )}
                            {errors.price && form.open_price && <p className="text-[0.75rem] font-medium text-[var(--danger)] sm:col-span-2">{errors.price}</p>}
                        </div>
                    </Card>
                </div>

                <Card padded={false}>
                    <div className="flex flex-col gap-3 border-b border-[var(--line)] p-5 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p className="text-[0.9375rem] font-semibold">Artículos que consume</p>
                            <p className="text-[0.78125rem] text-[var(--muted)]">
                                {components.length === 0
                                    ? 'Servicio solo por valor.'
                                    : itemized
                                      ? 'Cantidad por una unidad de servicio. Escribe el precio de cada artículo en el servicio o déjalo vacío para cobrar su precio vigente.'
                                      : 'Cantidad por una unidad de servicio. Salen del inventario al venderlo.'}
                            </p>
                        </div>
                        <div className="relative w-full sm:w-96">
                            <Input
                                prefix={<MagnifyingGlassIcon size={16} />}
                                placeholder="Buscar artículo por nombre, SKU o código"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                aria-label="Buscar artículo para el servicio"
                            />
                            {results.length > 0 && (
                                <div className="absolute inset-x-0 top-full z-20 mt-1 max-h-72 overflow-y-auto rounded-card border border-[var(--line)] bg-[var(--surface)] shadow-lg">
                                    {results.map((product) => (
                                        <button
                                            key={product.product_id}
                                            type="button"
                                            onClick={() => addComponent(product)}
                                            className="flex w-full items-center justify-between gap-3 border-b border-[var(--line)] px-3 py-2 text-left last:border-b-0 hover:bg-[var(--surface-2)]"
                                        >
                                            <span className="min-w-0">
                                                <span className="block truncate text-[0.8125rem] font-medium">{product.name}</span>
                                                <span className="block font-mono text-[0.71875rem] text-[var(--muted)]">{product.sku}</span>
                                            </span>
                                            <span className="shrink-0 font-mono text-[0.78125rem] tabular">{money(product.unit_price ?? 0)}</span>
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>

                    {components.length > 0 && (
                        <div className="overflow-x-auto">
                            <table className="w-full border-collapse text-sm">
                                <thead className="bg-[var(--surface-2)] text-left">
                                    <tr className="text-[0.75rem] uppercase tracking-wide text-[var(--muted)]">
                                        <th className="px-4 py-2.5 font-semibold">Artículo</th>
                                        <th className="px-4 py-2.5 text-right font-semibold">Cantidad</th>
                                        <th className="px-4 py-2.5 font-semibold">Si se devuelve</th>
                                        {canViewCost && <th className="px-4 py-2.5 text-right font-semibold">Costo</th>}
                                        {itemized && <th className="px-4 py-2.5 text-right font-semibold">Precio c/u en el servicio</th>}
                                        <th className="px-4 py-2.5" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {components.map((line, index) => (
                                        <tr key={line.product_id} className="border-t border-[var(--line)]">
                                            <td className="px-4 py-2.5">
                                                <p className="text-[0.8125rem] font-medium">{line.family && line.family_name ? line.family_name : line.name}</p>
                                                <p className="font-mono text-[0.71875rem] text-[var(--muted)]">{line.sku}</p>
                                                {line.family_id && (
                                                    <div className="mt-1">
                                                        <Checkbox
                                                            label={`Cualquier presentación de ${line.family_name ?? 'este artículo'}`}
                                                            hint={
                                                                line.family
                                                                    ? `Por defecto: ${line.variant_label ?? line.name}. En caja y en la tienda se cambia por otra sin pedir autorización.`
                                                                    : 'Marca si da igual cuál: en la venta se escoge.'
                                                            }
                                                            checked={Boolean(line.family)}
                                                            onChange={(event) => updateComponent(index, { family: event.target.checked, custom_price: event.target.checked ? null : line.custom_price })}
                                                        />
                                                    </div>
                                                )}
                                                {!line.is_active && (
                                                    <p className="flex items-center gap-1 text-[0.71875rem] font-medium text-[var(--danger)]">
                                                        <WarningCircleIcon size={12} weight="fill" /> Inactivo o eliminado: quítalo o actívalo
                                                    </p>
                                                )}
                                                {componentError(index) && <p className="text-[0.71875rem] font-medium text-[var(--danger)]">{componentError(index)}</p>}
                                            </td>
                                            <td className="px-4 py-2.5 text-right">
                                                <span className="inline-flex items-center gap-1.5">
                                                    <input
                                                        type="number"
                                                        min={0}
                                                        step={line.sold_by_weight ? '0.001' : '1'}
                                                        aria-label={`Cantidad de ${line.name}`}
                                                        value={line.quantity}
                                                        onChange={(event) => updateComponent(index, { quantity: Math.max(0, Math.round((Number(event.target.value) || 0) * 1000) / 1000) })}
                                                        className="h-9 w-20 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2 text-right font-mono text-[0.8125rem] tabular focus:border-[var(--accent)] focus:outline-none"
                                                    />
                                                    <span className="text-[0.75rem] text-[var(--muted)]">{UNIT_SHORT[line.unit ?? 'unit'] ?? ''}</span>
                                                </span>
                                            </td>
                                            <td className="px-4 py-2.5">
                                                <Checkbox
                                                    label="Vuelve al inventario"
                                                    checked={line.returnable_by_default}
                                                    onChange={(event) => updateComponent(index, { returnable_by_default: event.target.checked })}
                                                />
                                            </td>
                                            {canViewCost && (
                                                <td className="px-4 py-2.5 text-right font-mono text-[0.8125rem] tabular">
                                                    {money(Math.round(line.quantity * (line.unit_cost ?? 0)))}
                                                    <span className="block text-[0.6875rem] text-[var(--muted)]">{money(line.unit_cost ?? 0)} c/u</span>
                                                </td>
                                            )}
                                            {itemized && line.family && (
                                                <td className="px-4 py-2.5 text-right text-[0.75rem] text-[var(--muted)]">
                                                    Se cobra la presentación que se elija
                                                    <span className="block font-mono tabular">desde {money(line.unit_price ?? 0)}</span>
                                                </td>
                                            )}
                                            {itemized && !line.family && (
                                                <td className="px-4 py-2.5 text-right">
                                                    <span className="inline-flex flex-col items-end gap-1">
                                                        <input
                                                            type="number"
                                                            min={0}
                                                            step="1"
                                                            inputMode="numeric"
                                                            aria-label={`Precio de ${line.name} en el servicio`}
                                                            placeholder={String(line.unit_price ?? 0)}
                                                            disabled={priceLocked}
                                                            value={line.custom_price ?? ''}
                                                            onChange={(event) =>
                                                                updateComponent(index, {
                                                                    custom_price: event.target.value === '' ? null : Math.max(0, Math.round(Number(event.target.value) || 0)),
                                                                })
                                                            }
                                                            className="h-9 w-28 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2 text-right font-mono text-[0.8125rem] tabular placeholder:text-[var(--muted)] focus:border-[var(--accent)] focus:outline-none disabled:opacity-60"
                                                        />
                                                        {line.custom_price === null || line.custom_price === undefined ? (
                                                            <span className="text-[0.6875rem] text-[var(--muted)]">Precio del artículo</span>
                                                        ) : (
                                                            <span className="text-[0.6875rem] text-[var(--muted)]">
                                                                Artículo {money(line.unit_price ?? 0)}
                                                                {!priceLocked && (
                                                                    <>
                                                                        {' · '}
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => updateComponent(index, { custom_price: null })}
                                                                            className="font-medium text-[var(--accent)] hover:underline"
                                                                        >
                                                                            Usar ese
                                                                        </button>
                                                                    </>
                                                                )}
                                                            </span>
                                                        )}
                                                        <span className="font-mono text-[0.75rem] font-semibold tabular">{money(Math.round(line.quantity * priceOf(line)))}</span>
                                                    </span>
                                                </td>
                                            )}
                                            <td className="px-4 py-2.5 text-right">
                                                <span className="inline-flex gap-1">
                                                    <Button size="sm" variant="ghost" aria-label="Subir" icon={<ArrowUpIcon size={14} />} disabled={index === 0} onClick={() => move(index, -1)} />
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        aria-label="Bajar"
                                                        icon={<ArrowDownIcon size={14} />}
                                                        disabled={index === components.length - 1}
                                                        onClick={() => move(index, 1)}
                                                    />
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        aria-label={`Quitar ${line.name}`}
                                                        icon={<TrashIcon size={14} />}
                                                        onClick={() => setComponents((current) => current.filter((_, position) => position !== index))}
                                                    />
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <dl className="grid grid-cols-2 gap-4 border-t border-[var(--line)] p-5 text-[0.8125rem] sm:grid-cols-3">
                        {canViewCost && (
                            <div>
                                <dt className="text-[var(--muted)]">Costo total</dt>
                                <dd className="font-mono font-semibold tabular">{money(totals.cost)}</dd>
                            </div>
                        )}
                        <div>
                            <dt className="text-[var(--muted)]">{form.open_price ? 'Precio' : 'Precio final en caja'}</dt>
                            <dd className="font-mono font-semibold text-[var(--accent)] tabular">{form.open_price ? 'Se digita al vender' : money(totals.price)}</dd>
                        </div>
                        {canViewCost && !form.open_price && (
                            <div>
                                <dt className="text-[var(--muted)]">Margen</dt>
                                <dd className="font-mono font-semibold tabular" style={{ color: (totals.margin ?? 0) < 0 ? 'var(--danger)' : undefined }}>
                                    {totals.margin === null ? '—' : percent(totals.margin)}
                                </dd>
                            </div>
                        )}
                    </dl>
                </Card>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader title="Estado" />
                        <Checkbox label="Activo" hint="Un servicio inactivo no aparece en la caja." checked={form.is_active} onChange={(event) => set('is_active', event.target.checked)} />
                    </Card>

                    {!form.open_price && (
                        <Card>
                            <CardHeader title="Tienda en línea" description="Se vende tal cual el maestro: el cliente no ajusta los artículos." />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="flex flex-col gap-3">
                                    <Checkbox label="Visible en la tienda" checked={form.is_online_visible} onChange={(event) => set('is_online_visible', event.target.checked)} />
                                    <Checkbox label="Destacado" checked={form.is_featured} onChange={(event) => set('is_featured', event.target.checked)} />
                                </div>
                                <Input
                                    label={itemized ? 'Valor del servicio en línea' : 'Precio en línea'}
                                    hint="Vacío: el mismo de la caja."
                                    type="number"
                                    min={1}
                                    disabled={priceLocked}
                                    value={form.online_price}
                                    onChange={(event) => set('online_price', event.target.value)}
                                    error={errors.online_price}
                                />
                            </div>
                        </Card>
                    )}
                </div>

                {Object.keys(errors).length > 0 && (
                    <p className="text-[0.8125rem] font-medium text-[var(--danger)]">
                        Revisa los campos marcados{errors.components ? `: ${errors.components}` : ''}.
                    </p>
                )}

                <div className="flex justify-end gap-2">
                    <ButtonLink href={service ? route('services.show', service.id) : route('services.index')} variant="secondary">
                        Cancelar
                    </ButtonLink>
                    <Button type="submit" loading={processing}>
                        {mode === 'create' ? 'Crear servicio' : 'Guardar cambios'}
                    </Button>
                </div>
                {components.length > 0 && (
                    <p className="text-right text-[0.75rem] text-[var(--muted)]">
                        {number(components.length)} {components.length === 1 ? 'artículo' : 'artículos'} por servicio.
                    </p>
                )}
            </form>
        </AppLayout>
    );
}

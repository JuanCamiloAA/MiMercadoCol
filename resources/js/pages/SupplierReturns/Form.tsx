import { Link, router, usePage } from '@inertiajs/react';
import { BarcodeIcon, MagnifyingGlassIcon, ReceiptIcon, TrashIcon, TruckIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { type KeyboardEvent, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input, Select } from '@/components/ui/Field';
import { ConfirmDialog, Modal } from '@/components/ui/Modal';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { EmptyState, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { date, money, number, UNIT_SHORT } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { PageProps } from '@/types';
import type { Option, ReturnableOrder, ReturnLine, ReturnProduct } from '@/types/supplier-returns';

interface FormWarehouse {
    value: number;
    label: string;
    /** Unidades de la orden que entraron a esta bodega. */
    received_units: number | null;
}

interface SupplierReturnFormProps {
    mode: 'order' | 'loose';
    supplierReturn: { id: number; number: string; supplier_id: number; warehouse_id: number; note: string | null; shipping_reference: string | null } | null;
    order: { id: number; code: string; status_label: string; supplier: { id: number; name: string | null } } | null;
    store: { id: number; name: string | null };
    suppliers: Option[];
    warehouses: FormWarehouse[];
    warehouseId: number;
    lines: ReturnLine[];
    reasons: Option[];
    returnableOrders: ReturnableOrder[];
}

type EditableLine = ReturnLine & { cost_edited?: boolean };

const SEARCH_MS = 250;

/** Redondea como la base: tres decimales, nunca los restos de coma flotante. */
const qty = (value: number) => Math.round((Number.isFinite(value) ? value : 0) * 1000) / 1000;

/**
 * Armar o corregir una devolución a proveedor.
 *
 * Con orden llegan todos sus renglones con lo recibido, lo ya devuelto y el máximo; el usuario
 * solo pone cuánto de cada uno. Suelta, se busca o se escanea cada artículo. En los dos casos el
 * disponible sale de la bodega elegida, ya sin lo apartado para pedidos: el formulario avisa,
 * pero el tope de verdad lo pone el servidor al despachar.
 */
export default function SupplierReturnForm({
    mode,
    supplierReturn,
    order,
    store,
    suppliers,
    warehouses,
    warehouseId,
    lines: initialLines,
    reasons,
    returnableOrders,
}: SupplierReturnFormProps) {
    const { can } = usePermission();
    const errors = (usePage<PageProps>().props.errors ?? {}) as Record<string, string>;
    const editing = supplierReturn !== null;
    const withOrder = mode === 'order';

    const [supplierId, setSupplierId] = useState<string>(String(order?.supplier.id ?? supplierReturn?.supplier_id ?? ''));
    const [warehouse, setWarehouse] = useState<number>(supplierReturn?.warehouse_id ?? warehouseId);
    const [note, setNote] = useState(supplierReturn?.note ?? '');
    const [shipping, setShipping] = useState(supplierReturn?.shipping_reference ?? '');
    const [lines, setLines] = useState<EditableLine[]>(initialLines);
    const [defaultReason, setDefaultReason] = useState('');

    const [search, setSearch] = useState('');
    const [results, setResults] = useState<ReturnProduct[]>([]);
    const [searching, setSearching] = useState(false);
    const [pickingOrder, setPickingOrder] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const lastSupplier = useRef(supplierId);
    const latestSearch = useRef('');

    const warehouseName = warehouses.find((option) => option.value === warehouse)?.label ?? '';

    const stockOf = useCallback((line: ReturnProduct) => line.stock[String(warehouse)] ?? { quantity: 0, reserved: 0, available: 0 }, [warehouse]);
    const lotsOf = useCallback((line: ReturnProduct) => line.lots.filter((lot) => lot.warehouse_id === warehouse), [warehouse]);

    /** Lo máximo que se puede devolver de un renglón en la bodega elegida. */
    const maxOf = useCallback(
        (line: EditableLine) => {
            const available = stockOf(line).available;

            return withOrder ? Math.min(line.returnable ?? 0, available) : available;
        },
        [stockOf, withOrder],
    );

    /** El error de un renglón, con lo que dio el servidor: primero el suyo, luego el del navegador. */
    const lineError = (line: EditableLine, index: number): string | null => {
        const server = errors[`items.${index}.quantity`] ?? errors[`items.${index}.lot_id`] ?? errors[`items.${index}.product_id`] ?? errors[`items.${index}.purchase_order_item_id`];

        if (server) {
            return server;
        }

        if (line.quantity <= 0) {
            return null;
        }

        if (!line.sold_by_weight && !Number.isInteger(line.quantity)) {
            return `${line.name} se devuelve en unidades enteras.`;
        }

        const max = maxOf(line);

        if (line.quantity > max + 0.0005) {
            if (withOrder && (line.returnable ?? 0) < stockOf(line).available) {
                return `De la orden solo se pueden devolver ${number(line.returnable ?? 0)}.`;
            }

            const reserved = stockOf(line).reserved;

            return `En ${warehouseName} hay ${number(max)} disponibles${reserved > 0 ? ` (${number(reserved)} apartadas para pedidos)` : ''}.`;
        }

        const lot = line.lot_id ? lotsOf(line).find((entry) => entry.id === line.lot_id) : null;

        if (lot && line.quantity > lot.quantity + 0.0005) {
            return `El lote ${lot.code ?? 'sin código'} solo tiene ${number(lot.quantity)}: elige «Automático» para completar con otros lotes.`;
        }

        return null;
    };

    const reasonError = (line: EditableLine, index: number): string | null =>
        errors[`items.${index}.reason`] ?? errors[`items.${index}.note`] ?? (line.quantity > 0 && !line.reason ? 'Elige el motivo.' : null);

    const update = (index: number, changes: Partial<EditableLine>) =>
        setLines((current) => current.map((line, position) => (position === index ? { ...line, ...changes } : line)));

    // Cambiar de bodega deja sin sentido el lote elegido si era de otra.
    useEffect(() => {
        setLines((current) =>
            current.map((line) => (line.lot_id && !line.lots.some((lot) => lot.id === line.lot_id && lot.warehouse_id === warehouse) ? { ...line, lot_id: null } : line)),
        );
    }, [warehouse]);

    const fetchProducts = useCallback(
        async (params: Record<string, string | string[]>) => {
            const query = new URLSearchParams();

            Object.entries(params).forEach(([key, value]) => {
                if (Array.isArray(value)) {
                    value.forEach((entry) => query.append(`${key}[]`, entry));
                } else if (value !== '') {
                    query.set(key, value);
                }
            });

            const response = await fetch(`${route('supplier_returns.products')}?${query.toString()}`, { headers: { Accept: 'application/json' } });

            if (!response.ok) {
                throw new Error('No se pudo buscar.');
            }

            return ((await response.json()) as { products: ReturnProduct[] }).products;
        },
        [],
    );

    // Buscador de la suelta.
    useEffect(() => {
        if (withOrder) {
            return;
        }

        if (timer.current) {
            clearTimeout(timer.current);
        }

        latestSearch.current = search.trim();

        if (search.trim().length < 2) {
            setResults([]);

            return;
        }

        timer.current = setTimeout(() => {
            const term = search.trim();
            setSearching(true);
            fetchProducts({ search: term, supplier: supplierId })
                .then((found) => {
                    if (latestSearch.current === term) {
                        setResults(found);
                    }
                })
                .catch(() => toast.error('No se pudo buscar el artículo.'))
                .finally(() => setSearching(false));
        }, SEARCH_MS);

        return () => {
            if (timer.current) {
                clearTimeout(timer.current);
            }
        };
    }, [search, supplierId, withOrder, fetchProducts]);

    // Al cambiar de proveedor, el costo sugerido y la marca «no figura» se recalculan en el servidor.
    useEffect(() => {
        if (withOrder || lastSupplier.current === supplierId) {
            return;
        }

        lastSupplier.current = supplierId;
        const ids = lines.map((line) => String(line.product_id));

        if (ids.length === 0) {
            return;
        }

        fetchProducts({ supplier: supplierId, ids })
            .then((fresh) =>
                setLines((current) =>
                    current.map((line) => {
                        const product = fresh.find((entry) => entry.product_id === line.product_id);

                        if (!product) {
                            return line;
                        }

                        return {
                            ...line,
                            ...product,
                            unit_cost: line.cost_edited ? line.unit_cost : (product.suggested_cost ?? line.unit_cost),
                        };
                    }),
                ),
            )
            .catch(() => toast.error('No se pudo actualizar el costo sugerido.'));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [supplierId]);

    const addProduct = (product: ReturnProduct) => {
        if (lines.some((line) => line.product_id === product.product_id)) {
            toast.info(`${product.name} ya está en la devolución.`);

            return;
        }

        setLines((current) => [
            ...current,
            {
                ...product,
                unit_cost: product.suggested_cost ?? 0,
                quantity: 1,
                reason: defaultReason || null,
                note: null,
                lot_id: null,
            },
        ]);
        setSearch('');
        setResults([]);
    };

    // El escáner escribe el código completo y un Enter.
    const onSearchKey = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key !== 'Enter') {
            return;
        }

        event.preventDefault();
        const term = search.trim();

        if (term === '') {
            return;
        }

        fetchProducts({ barcode: term, supplier: supplierId })
            .then((found) => {
                if (found.length > 0) {
                    addProduct(found[0]);
                } else if (results.length === 1) {
                    addProduct(results[0]);
                } else {
                    toast.error(`No hay un artículo activo con el código ${term}.`);
                }
            })
            .catch(() => toast.error('No se pudo buscar el código.'));
    };

    const includeLine = (index: number, include: boolean) => {
        const line = lines[index];
        const max = maxOf(line);
        const quantity = include ? (line.sold_by_weight ? max : Math.floor(max)) : 0;

        update(index, { quantity: qty(quantity), reason: include ? (line.reason ?? (defaultReason || null)) : line.reason });
    };

    const returnEverything = () =>
        setLines((current) =>
            current.map((line) => {
                const max = maxOf(line);

                return { ...line, quantity: qty(line.sold_by_weight ? max : Math.floor(max)), reason: line.reason ?? (defaultReason || null) };
            }),
        );

    const applyDefaultReason = (reason: string) => {
        setDefaultReason(reason);

        if (reason !== '') {
            setLines((current) => current.map((line) => (line.quantity > 0 && !line.reason ? { ...line, reason } : line)));
        }
    };

    const active = lines.filter((line) => line.quantity > 0);
    const totals = useMemo(() => {
        let total = 0;
        let base = 0;
        let units = 0;

        lines.forEach((line) => {
            if (line.quantity <= 0) {
                return;
            }

            const gross = Math.round(line.quantity * line.unit_cost);
            total += gross;
            base += line.tax_rate > 0 ? Math.round(gross / (1 + line.tax_rate / 100)) : gross;
            units += line.quantity;
        });

        return { total, base, tax: total - base, units: qty(units) };
    }, [lines]);

    const hasErrors = lines.some((line, index) => lineError(line, index) !== null || reasonError(line, index) !== null);
    const canSubmit = active.length > 0 && !hasErrors && (withOrder || supplierId !== '') && warehouse > 0;

    const submit = (dispatchNow: boolean) => {
        setSubmitting(true);

        const payload = {
            purchase_order_id: order?.id ?? null,
            supplier_id: withOrder ? (order?.supplier.id ?? null) : supplierId === '' ? null : Number(supplierId),
            warehouse_id: warehouse,
            note: note || null,
            shipping_reference: shipping || null,
            dispatch_now: dispatchNow,
            items: lines.map((line) => ({
                product_id: line.product_id,
                purchase_order_item_id: line.purchase_order_item_id ?? null,
                quantity: qty(line.quantity),
                reason: line.reason,
                note: line.note,
                lot_id: line.lot_id,
                unit_cost: withOrder ? null : Math.max(0, Math.round(line.unit_cost)),
            })),
        };

        const options = {
            preserveScroll: true,
            onFinish: () => {
                setSubmitting(false);
                setConfirming(false);
            },
        };

        if (editing && supplierReturn) {
            router.put(route('supplier_returns.update', supplierReturn.id), payload, options);
        } else {
            router.post(route('supplier_returns.store'), payload, options);
        }
    };

    const ordersForSupplier = returnableOrders.filter((entry) => supplierId === '' || String(entry.supplier_id) === supplierId);
    const generalError = errors.items ?? errors.stock ?? errors.status ?? errors.purchase_order_id;

    return (
        <AppLayout title={editing ? `Corregir ${supplierReturn?.number}` : 'Nueva devolución a proveedor'}>
            <PageHeader
                title={editing ? `Corregir ${supplierReturn?.number}` : 'Nueva devolución a proveedor'}
                description={
                    withOrder
                        ? `Contra la orden ${order?.code}: elige qué devuelves y cuánto. Lo que dejes en cero no se guarda.`
                        : 'Sin orden de compra: elige el proveedor y agrega los artículos.'
                }
                breadcrumb={
                    <ButtonLink
                        href={editing && supplierReturn ? route('supplier_returns.show', supplierReturn.id) : route('supplier_returns.index')}
                        size="sm"
                        variant="ghost"
                    >
                        {editing ? 'Volver a la devolución' : 'Volver a devoluciones'}
                    </ButtonLink>
                }
            />

            <Card className="mb-4">
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    {withOrder ? (
                        <div className="flex flex-col gap-1.5">
                            <span className="text-[0.8125rem] font-medium">Proveedor</span>
                            <p className="flex h-[calc(2.5rem*var(--density))] items-center text-sm font-medium">{order?.supplier.name}</p>
                        </div>
                    ) : (
                        <Select
                            label="Proveedor"
                            required
                            options={suppliers}
                            placeholder="Elige el proveedor"
                            value={supplierId}
                            error={errors.supplier_id}
                            onChange={(event) => setSupplierId(event.target.value)}
                        />
                    )}

                    <div className="flex flex-col gap-1.5">
                        <span className="text-[0.8125rem] font-medium">Orden de compra</span>
                        <div className="flex h-[calc(2.5rem*var(--density))] items-center gap-2">
                            {order ? (
                                can('purchases.index.view') ? (
                                    <Link href={route('purchases.show', order.id)} className="inline-flex items-center gap-1.5">
                                        <Badge tone="info">
                                            <ReceiptIcon size={12} /> {order.code}
                                        </Badge>
                                    </Link>
                                ) : (
                                    <Badge tone="info">{order.code}</Badge>
                                )
                            ) : (
                                <>
                                    <span className="text-sm text-[var(--muted)]">Suelta</span>
                                    {!editing && returnableOrders.length > 0 && (
                                        <Button size="sm" variant="ghost" onClick={() => setPickingOrder(true)}>
                                            Buscar una orden recibida
                                        </Button>
                                    )}
                                </>
                            )}
                        </div>
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <span className="text-[0.8125rem] font-medium">Sede</span>
                        <p className="flex h-[calc(2.5rem*var(--density))] items-center text-sm">{store.name}</p>
                    </div>

                    <Select
                        label="Bodega de donde sale"
                        required
                        options={warehouses.map((option) => ({
                            value: option.value,
                            label: option.received_units ? `${option.label} · entró aquí (${number(option.received_units)})` : option.label,
                        }))}
                        value={String(warehouse)}
                        error={errors.warehouse_id}
                        onChange={(event) => setWarehouse(Number(event.target.value))}
                    />

                    <Input
                        label="Guía o transportadora"
                        value={shipping}
                        maxLength={60}
                        error={errors.shipping_reference}
                        onChange={(event) => setShipping(event.target.value)}
                        containerClassName="md:col-span-1"
                    />
                    <Input
                        label="Nota"
                        value={note}
                        maxLength={255}
                        error={errors.note}
                        onChange={(event) => setNote(event.target.value)}
                        containerClassName="md:col-span-1 xl:col-span-2"
                    />
                    <Select
                        label="Motivo para los que no tengan"
                        options={reasons}
                        placeholder="Elige para aplicar"
                        value={defaultReason}
                        onChange={(event) => applyDefaultReason(event.target.value)}
                    />
                </div>
            </Card>

            {generalError && (
                <Card className="mb-4">
                    <p className="flex items-start gap-2 text-[0.8125rem] font-medium text-[var(--danger)]">
                        <WarningCircleIcon size={16} weight="fill" className="mt-px shrink-0" />
                        {generalError}
                    </p>
                </Card>
            )}

            {!withOrder && (
                <Card className="mb-4">
                    <div className="relative">
                        <Input
                            prefix={<MagnifyingGlassIcon size={16} />}
                            suffix={<BarcodeIcon size={16} />}
                            placeholder="Buscar por nombre o SKU, o escanear el código de barras"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            onKeyDown={onSearchKey}
                            aria-label="Buscar artículo"
                        />
                        {(results.length > 0 || searching) && (
                            <div className="absolute inset-x-0 top-full z-20 mt-1 max-h-80 overflow-y-auto rounded-card border border-[var(--line)] bg-[var(--surface)] shadow-lg">
                                {searching && results.length === 0 && <p className="p-3 text-[0.8125rem] text-[var(--muted)]">Buscando…</p>}
                                {results.map((product) => {
                                    const stock = stockOf(product);

                                    return (
                                        <button
                                            key={product.product_id}
                                            type="button"
                                            onClick={() => addProduct(product)}
                                            className="flex w-full items-center justify-between gap-3 border-b border-[var(--line)] px-3 py-2 text-left last:border-b-0 hover:bg-[var(--surface-2)]"
                                        >
                                            <span className="min-w-0">
                                                <span className="block truncate text-[0.8125rem] font-medium">{product.name}</span>
                                                <span className="block font-mono text-[0.71875rem] text-[var(--muted)]">
                                                    {product.sku}
                                                    {product.is_supplier_product === false && supplierId !== '' && (
                                                        <span className="ml-2 font-sans text-[var(--warn)]">No figura como proveedor</span>
                                                    )}
                                                </span>
                                            </span>
                                            <span className="shrink-0 text-right font-mono text-[0.75rem] tabular">
                                                {number(stock.available)} disp.
                                                <span className="block text-[var(--muted)]">{money(product.suggested_cost ?? 0)}</span>
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                </Card>
            )}

            {lines.length === 0 ? (
                <EmptyState
                    title="Todavía no hay artículos"
                    description={withOrder ? 'La orden no tiene renglones con mercancía.' : 'Busca o escanea los artículos que vuelven al proveedor.'}
                    icon={<TruckIcon size={24} />}
                />
            ) : (
                <Card padded={false} className="mb-4">
                    {withOrder && (
                        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--line)] px-4 py-3">
                            <p className="text-[0.78125rem] text-[var(--muted)]">
                                El máximo es lo recibido menos lo ya devuelto y lo que está en otros borradores, sin pasar de lo disponible en {warehouseName}.
                            </p>
                            <Button size="sm" variant="secondary" onClick={returnEverything}>
                                Devolver todo lo disponible
                            </Button>
                        </div>
                    )}
                    <Table className="rounded-none border-0">
                        <THead>
                            <TR>
                                <TH>Artículo</TH>
                                {withOrder && <TH align="right">Recibido</TH>}
                                {withOrder && <TH align="right">Ya devuelto</TH>}
                                {withOrder && <TH align="right">Máximo</TH>}
                                <TH align="right">Disponible</TH>
                                <TH align="right">A devolver</TH>
                                <TH>Motivo y lote</TH>
                                <TH align="right">Costo unitario</TH>
                                <TH align="right">Subtotal</TH>
                                {!withOrder && <TH />}
                            </TR>
                        </THead>
                        <TBody>
                            {lines.map((line, index) => {
                                const stock = stockOf(line);
                                const lots = lotsOf(line);
                                const error = lineError(line, index);
                                const reasonMessage = reasonError(line, index);
                                const unit = UNIT_SHORT[line.unit ?? 'unit'] ?? '';
                                const firstLot = lots[0];

                                return (
                                    <TR key={`${line.purchase_order_item_id ?? 'p'}-${line.product_id}`} className={line.quantity > 0 ? 'bg-[var(--accent-soft)]/30' : undefined}>
                                        <TD className="min-w-52">
                                            <div className="flex items-start gap-2.5">
                                                {withOrder && (
                                                    <input
                                                        type="checkbox"
                                                        aria-label={`Incluir ${line.name}`}
                                                        checked={line.quantity > 0}
                                                        disabled={maxOf(line) <= 0}
                                                        onChange={(event) => includeLine(index, event.target.checked)}
                                                        className="mt-0.5 size-[1.125rem] shrink-0 cursor-pointer accent-[var(--accent)]"
                                                    />
                                                )}
                                                <div className="min-w-0">
                                                    <p className="text-[0.8125rem] font-medium">{line.name}</p>
                                                    <p className="font-mono text-[0.71875rem] text-[var(--muted)]">{line.sku}</p>
                                                    {line.is_supplier_product === false && (
                                                        <p className="text-[0.6875rem] font-medium text-[var(--warn)]">No figura como proveedor de este artículo</p>
                                                    )}
                                                    {error && <p className="mt-1 max-w-72 text-[0.71875rem] font-medium text-[var(--danger)]">{error}</p>}
                                                </div>
                                            </div>
                                        </TD>
                                        {withOrder && (
                                            <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                                {number(line.received ?? 0)}
                                            </TD>
                                        )}
                                        {withOrder && (
                                            <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                                {number((line.returned ?? 0) + (line.in_drafts ?? 0))}
                                                {(line.in_drafts ?? 0) > 0 && (
                                                    <span className="block font-sans text-[0.6875rem] text-[var(--muted)]">{number(line.in_drafts ?? 0)} en borradores</span>
                                                )}
                                            </TD>
                                        )}
                                        {withOrder && (
                                            <TD align="right" className="font-mono text-[0.8125rem] font-medium tabular">
                                                {number(maxOf(line))}
                                            </TD>
                                        )}
                                        <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                            {number(stock.available)} {unit}
                                            {stock.reserved > 0 && (
                                                <span className="block font-sans text-[0.6875rem] text-[var(--warn)]">{number(stock.reserved)} apartadas</span>
                                            )}
                                        </TD>
                                        <TD align="right">
                                            <input
                                                type="number"
                                                min={0}
                                                step={line.sold_by_weight ? '0.001' : '1'}
                                                aria-label={`Cantidad a devolver de ${line.name}`}
                                                value={line.quantity === 0 ? '' : line.quantity}
                                                placeholder="0"
                                                onChange={(event) => update(index, { quantity: qty(Math.max(0, Number(event.target.value))) })}
                                                className={`h-9 w-20 rounded-control border bg-[var(--surface)] px-2 text-right font-mono text-[0.8125rem] tabular focus:border-[var(--accent)] focus:outline-none ${error ? 'border-[var(--danger)]' : 'border-[var(--line)]'}`}
                                            />
                                        </TD>
                                        <TD>
                                            <select
                                                aria-label={`Motivo de ${line.name}`}
                                                value={line.reason ?? ''}
                                                onChange={(event) => update(index, { reason: event.target.value || null })}
                                                className={`h-9 w-44 rounded-control border bg-[var(--surface)] px-2 text-[0.78125rem] focus:border-[var(--accent)] focus:outline-none ${reasonMessage ? 'border-[var(--danger)]' : 'border-[var(--line)]'}`}
                                            >
                                                <option value="">Motivo…</option>
                                                {reasons.map((reason) => (
                                                    <option key={reason.value} value={reason.value}>
                                                        {reason.label}
                                                    </option>
                                                ))}
                                            </select>
                                            {(line.reason === 'other' || line.note) && (
                                                <input
                                                    type="text"
                                                    aria-label={`Nota de ${line.name}`}
                                                    placeholder="Explica el motivo"
                                                    maxLength={255}
                                                    value={line.note ?? ''}
                                                    onChange={(event) => update(index, { note: event.target.value || null })}
                                                    className="mt-1 h-8 w-44 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2 text-[0.75rem] focus:border-[var(--accent)] focus:outline-none"
                                                />
                                            )}
                                            {reasonMessage && <p className="mt-1 text-[0.6875rem] font-medium text-[var(--danger)]">{reasonMessage}</p>}
                                            {line.track_lots && (
                                                <select
                                                    aria-label={`Lote de ${line.name}`}
                                                    value={line.lot_id ?? ''}
                                                    onChange={(event) => update(index, { lot_id: event.target.value ? Number(event.target.value) : null })}
                                                    className="mt-1 h-8 w-44 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2 text-[0.71875rem] focus:border-[var(--accent)] focus:outline-none"
                                                >
                                                    <option value="">{firstLot ? `Lote automático: ${firstLot.code ?? 'sin código'} primero` : 'Sin lotes en esta bodega'}</option>
                                                    {lots.map((lot) => (
                                                        <option key={lot.id} value={lot.id}>
                                                            {lot.code ?? 'Sin código'} · {lot.expires_at ? `vence ${date(lot.expires_at)}` : 'sin vencimiento'} · {number(lot.quantity)}
                                                            {lot.from_order ? ' · de esta orden' : ''}
                                                        </option>
                                                    ))}
                                                </select>
                                            )}
                                        </TD>
                                        <TD align="right">
                                            {withOrder ? (
                                                <span className="font-mono text-[0.8125rem] tabular">{money(line.unit_cost)}</span>
                                            ) : (
                                                <input
                                                    type="number"
                                                    min={0}
                                                    step="1"
                                                    aria-label={`Costo de ${line.name}`}
                                                    value={line.unit_cost}
                                                    onChange={(event) => update(index, { unit_cost: Math.max(0, Math.round(Number(event.target.value) || 0)), cost_edited: true })}
                                                    className="h-9 w-24 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2 text-right font-mono text-[0.8125rem] tabular focus:border-[var(--accent)] focus:outline-none"
                                                />
                                            )}
                                        </TD>
                                        <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                            {line.quantity > 0 ? money(Math.round(line.quantity * line.unit_cost)) : '—'}
                                        </TD>
                                        {!withOrder && (
                                            <TD align="right">
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    aria-label={`Quitar ${line.name}`}
                                                    icon={<TrashIcon size={14} />}
                                                    onClick={() => setLines((current) => current.filter((_, position) => position !== index))}
                                                />
                                            </TD>
                                        )}
                                    </TR>
                                );
                            })}
                        </TBody>
                    </Table>
                </Card>
            )}

            <Card>
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <dl className="grid grid-cols-2 gap-x-6 gap-y-1 text-[0.8125rem] sm:grid-cols-4">
                        <div>
                            <dt className="text-[var(--muted)]">Unidades</dt>
                            <dd className="font-mono font-medium tabular">{number(totals.units)}</dd>
                        </div>
                        <div>
                            <dt className="text-[var(--muted)]">Base</dt>
                            <dd className="font-mono font-medium tabular">{money(totals.base)}</dd>
                        </div>
                        <div>
                            <dt className="text-[var(--muted)]">IVA</dt>
                            <dd className="font-mono font-medium tabular">{money(totals.tax)}</dd>
                        </div>
                        <div>
                            <dt className="text-[var(--muted)]">Total a favor</dt>
                            <dd className="font-mono text-[1.125rem] font-semibold text-[var(--accent)] tabular">{money(totals.total)}</dd>
                        </div>
                    </dl>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="secondary" disabled={!canSubmit} loading={submitting && !confirming} onClick={() => submit(false)}>
                            {editing ? 'Guardar cambios' : 'Guardar borrador'}
                        </Button>
                        {!editing && can('purchases.returns.dispatch') && (
                            <Button disabled={!canSubmit} icon={<TruckIcon size={16} />} onClick={() => setConfirming(true)}>
                                Guardar y despachar
                            </Button>
                        )}
                    </div>
                </div>
            </Card>

            <ConfirmDialog
                open={confirming}
                onClose={() => setConfirming(false)}
                onConfirm={() => submit(true)}
                loading={submitting}
                title="Despachar la devolución"
                message={`Saldrán ${number(totals.units)} unidades de ${warehouseName}. Después no se puede anular: si el proveedor devuelve algo, se registra como rechazo.`}
                confirmLabel="Despachar"
                tone="primary"
            />

            <Modal open={pickingOrder} onClose={() => setPickingOrder(false)} title="Buscar una orden recibida" description="Órdenes de esta sede a las que les queda algo por devolver." size="lg">
                {ordersForSupplier.length === 0 ? (
                    <p className="text-[0.8125rem] text-[var(--muted)]">Ese proveedor no tiene órdenes recibidas con algo por devolver.</p>
                ) : (
                    <div className="flex flex-col divide-y divide-[var(--line)]">
                        {ordersForSupplier.map((entry) => (
                            <Link
                                key={entry.id}
                                href={route('supplier_returns.create', { orden: entry.id })}
                                className="flex items-center justify-between gap-3 py-2.5 hover:bg-[var(--surface-2)]"
                            >
                                <span>
                                    <span className="block font-mono text-[0.8125rem] font-medium">{entry.code}</span>
                                    <span className="block text-[0.75rem] text-[var(--muted)]">
                                        {entry.supplier} · {entry.status_label} · {date(entry.received_at)}
                                    </span>
                                </span>
                                <span className="font-mono text-[0.8125rem] tabular">{money(entry.total)}</span>
                            </Link>
                        ))}
                    </div>
                )}
            </Modal>
        </AppLayout>
    );
}

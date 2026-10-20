import { PurchaseMatrix, type MatrixPick } from '@/components/purchases/PurchaseMatrix';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import { number as formatNumber, money, UNIT_SHORT } from '@/lib/format';
import type { PageProps } from '@/types';
import type { CatalogProduct, ItemOrigin, OrderLine, PurchaseCreateProps } from '@/types/purchases';
import { Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeftIcon,
    BarcodeIcon,
    CheckIcon,
    MagnifyingGlassIcon,
    MinusIcon,
    PackageIcon,
    PlusIcon,
    SquaresFourIcon,
    TrashIcon,
    WarningCircleIcon,
} from '@phosphor-icons/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

type View = 'supplier' | 'low' | 'all';

/** Cuánto se espera a que el comprador deje de escribir antes de preguntarle al servidor. */
const SEARCH_MS = 250;

/**
 * Nueva orden de compra, con la mecánica del punto de venta.
 *
 * El cambio de fondo: **el proveedor no filtra el catálogo, lo resalta**. Antes solo se podía
 * pedir lo que el proveedor elegido surtía, y comprarle dos cosas a dos proveedores obligaba a
 * armar dos órdenes desde cero, recorriendo la tienda dos veces. Aquí se ve todo, se marca
 * quién surte qué, y si la orden queda mezclada se separa sola al guardar.
 */
export default function PurchaseCreate({
    suppliers,
    supplierId,
    preload = [],
    warehouse = null,
    products,
    counts,
    store,
    paymentTerms,
    defaultPaymentTermId,
}: PurchaseCreateProps) {
    const errors = (usePage<PageProps>().props.errors ?? {}) as Record<string, string>;

    const [supplier, setSupplier] = useState<number | null>(supplierId);
    const [catalog, setCatalog] = useState<CatalogProduct[]>(products);
    const [lowStockCount, setLowStockCount] = useState(counts.low_stock);
    const [search, setSearch] = useState('');
    const [view, setView] = useState<View>('supplier');
    const [loading, setLoading] = useState(false);

    const [lines, setLines] = useState<OrderLine[]>([]);
    const [expectedAt, setExpectedAt] = useState('');
    const [note, setNote] = useState('');
    // El plazo sale del proveedor elegido (o el de proveedores por defecto) y se puede cambiar.
    const termOf = (id: number | null) => {
        const own = suppliers.find((option) => option.value === id)?.payment_term_id;

        return String(own ?? defaultPaymentTermId ?? '');
    };
    const [termId, setTermId] = useState(() => termOf(supplierId));

    useEffect(() => {
        setTermId(termOf(supplier));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [supplier]);
    const [submitting, setSubmitting] = useState(false);
    const [sheetOpen, setSheetOpen] = useState(false);
    const [matrixFamily, setMatrixFamily] = useState<number | null>(null);

    const searchRef = useRef<HTMLInputElement>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    /** Trae el catálogo del servidor. El proveedor solo cambia el orden y el resalte. */
    const fetchCatalog = useCallback((term: string, forSupplier: number | null, onlyLow: boolean) => {
        setLoading(true);

        const query = new URLSearchParams();

        if (term !== '') {
            query.set('search', term);
        }

        if (forSupplier !== null) {
            query.set('supplier', String(forSupplier));
        }

        if (onlyLow) {
            query.set('low_stock', '1');
        }

        fetch(`${route('purchases.products')}?${query.toString()}`, { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : null))
            .then((body: { products: CatalogProduct[]; low_stock: number } | null) => {
                if (body) {
                    setCatalog(body.products);
                    setLowStockCount(body.low_stock);
                }
            })
            .catch(() => toast.error('No se pudo cargar el catálogo.'))
            .finally(() => setLoading(false));
    }, []);

    // El buscador consulta al servidor: con miles de artículos, filtrar en el navegador
    // obligaría a traérselos todos primero.
    useEffect(() => {
        if (timer.current) {
            clearTimeout(timer.current);
        }

        timer.current = setTimeout(() => fetchCatalog(search, supplier, view === 'low'), SEARCH_MS);

        return () => {
            if (timer.current) {
                clearTimeout(timer.current);
            }
        };
    }, [search, supplier, view, fetchCatalog]);

    const upsert = useCallback((product: CatalogProduct, origin: ItemOrigin, quantity?: number) => {
        setLines((current) => {
            const existing = current.find((line) => line.product_id === product.id);

            if (existing) {
                // Ya está: se suma el empaque en vez de duplicar el renglón.
                return current.map((line) =>
                    line.product_id === product.id ? { ...line, quantity: line.quantity + (quantity ?? line.pack_size) } : line,
                );
            }

            const supplierLink = product.suppliers.find((item) => item.is_primary) ?? product.suppliers[0] ?? null;

            return [
                ...current,
                {
                    product_id: product.id,
                    name: product.name,
                    sku: product.sku,
                    unit: product.unit,
                    quantity: quantity ?? product.suggested,
                    unit_cost: product.unit_cost,
                    pack_size: product.pack_size,
                    stock: product.stock,
                    min_stock: product.min_stock,
                    is_low: product.is_low,
                    supplier_id: supplierLink?.id ?? null,
                    supplier_label: product.supplier_label,
                    origin,
                    family_id: product.family_id ?? null,
                },
            ];
        });
    }, []);

    // Lo precargado (desde Tienda en línea) entra solo, una vez, con la cantidad sugerida.
    const preloaded = useRef(false);

    useEffect(() => {
        if (preloaded.current || preload.length === 0) {
            return;
        }

        preloaded.current = true;
        preload.forEach((product) => upsert(product, 'manual'));
    }, [preload, upsert]);

    /**
     * Lo que sale de la matriz: cada casilla con cantidad es un renglón normal; una casilla en
     * cero quita el renglón de ese subartículo si estaba.
     */
    const applyMatrix = (picks: MatrixPick[]) => {
        setLines((current) => {
            let next = current.filter((line) => !picks.some((pick) => pick.product.id === line.product_id && pick.quantity <= 0));

            picks
                .filter((pick) => pick.quantity > 0)
                .forEach((pick) => {
                    const exists = next.some((line) => line.product_id === pick.product.id);
                    const supplierLink = pick.product.suppliers.find((item) => item.is_primary) ?? pick.product.suppliers[0] ?? null;

                    next = exists
                        ? next.map((line) =>
                              line.product_id === pick.product.id ? { ...line, quantity: pick.quantity, unit_cost: pick.unit_cost } : line,
                          )
                        : [
                              ...next,
                              {
                                  product_id: pick.product.id,
                                  name: pick.product.name,
                                  sku: pick.product.sku,
                                  unit: pick.product.unit,
                                  quantity: pick.quantity,
                                  unit_cost: pick.unit_cost,
                                  pack_size: pick.product.pack_size,
                                  stock: pick.product.stock,
                                  min_stock: pick.product.min_stock,
                                  is_low: pick.product.is_low,
                                  supplier_id: supplierLink?.id ?? null,
                                  supplier_label: pick.product.supplier_label,
                                  origin: 'manual',
                                  family_id: pick.product.family_id ?? null,
                              },
                          ];
                });

            return next;
        });
        setMatrixFamily(null);
    };

    const setQuantity = (productId: number, quantity: number) =>
        setLines((current) => current.map((line) => (line.product_id === productId ? { ...line, quantity: Math.max(1, quantity) } : line)));

    const setCost = (productId: number, cost: number) =>
        setLines((current) => current.map((line) => (line.product_id === productId ? { ...line, unit_cost: Math.max(0, cost) } : line)));

    const remove = (productId: number) => setLines((current) => current.filter((line) => line.product_id !== productId));

    /** Lee un código de barras: entra con su sugerido y el campo queda listo para el siguiente. */
    const scan = useCallback(
        (code: string) => {
            fetch(`${route('purchases.products')}?barcode=${encodeURIComponent(code)}&supplier=${supplier ?? ''}`, {
                headers: { Accept: 'application/json' },
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((body: { products: CatalogProduct[]; found: boolean } | null) => {
                    if (body?.found && body.products[0]) {
                        upsert(body.products[0], 'scan');
                        setSearch('');
                        toast.success(`${body.products[0].name} · ${body.products[0].suggested} ${UNIT_SHORT[body.products[0].unit] ?? ''}`);

                        return;
                    }

                    toast.error('No encontramos ese código en el catálogo.');
                })
                .catch(() => toast.error('No se pudo consultar el código.'));
        },
        [supplier, upsert],
    );

    /** Agrega de una todos los que están bajo mínimo, con su cantidad sugerida. */
    const addLowStock = useCallback(() => {
        fetch(`${route('purchases.suggestions')}?supplier=${supplier ?? ''}`, { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : null))
            .then((body: { products: CatalogProduct[] } | null) => {
                if (!body || body.products.length === 0) {
                    toast.info('No hay artículos bajo el mínimo.');

                    return;
                }

                body.products.forEach((product) => upsert(product, 'suggestion', product.suggested));
                toast.success(`Se agregaron ${body.products.length} artículos bajo el mínimo.`);
            })
            .catch(() => toast.error('No se pudieron traer las sugerencias.'));
    }, [supplier, upsert]);

    // El comprador no usa el ratón: la pantalla entera se maneja con teclado.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'F1') {
                event.preventDefault();
                searchRef.current?.focus();
                searchRef.current?.select();
            }

            if (event.key === 'F4') {
                event.preventDefault();
                addLowStock();
            }

            if (event.key === 'Escape' && document.activeElement === searchRef.current) {
                searchRef.current?.blur();
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [addLowStock]);

    const inOrder = useMemo(() => new Set(lines.map((line) => line.product_id)), [lines]);

    const visible = useMemo(() => {
        if (view === 'supplier') {
            return catalog.filter((product) => product.is_from_supplier);
        }

        if (view === 'low') {
            return catalog.filter((product) => product.is_low);
        }

        return catalog;
    }, [catalog, view]);

    const fromSupplierCount = catalog.filter((product) => product.is_from_supplier).length;

    const totals = useMemo(() => {
        const units = lines.reduce((sum, line) => sum + line.quantity, 0);
        const total = lines.reduce((sum, line) => sum + Math.round(line.quantity * line.unit_cost), 0);
        const offSupplier = lines.filter((line) => line.supplier_id !== null && line.supplier_id !== supplier);

        return { units, total, offSupplier };
    }, [lines, supplier]);

    const submit = (status: 'draft' | 'sent', split = false) => {
        if (lines.length === 0 || supplier === null) {
            return;
        }

        setSubmitting(true);

        router.post(
            route('purchases.store'),
            {
                supplier_id: supplier,
                expected_at: expectedAt || null,
                note: note || null,
                payment_term_id: termId === '' ? null : Number(termId),
                status,
                split,
                warehouse_id: warehouse?.id ?? null,
                // Solo cantidad, costo y de dónde salió: el resto lo recalcula el servidor.
                items: lines.map((line) => ({
                    product_id: line.product_id,
                    quantity: line.quantity,
                    unit_cost: line.unit_cost,
                    origin: line.origin,
                })),
            },
            {
                onError: () => toast.error('No se pudo crear la orden.'),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    if (suppliers.length === 0) {
        return (
            <AppLayout title="Nueva orden de compra">
                <EmptyState
                    title="Todavía no hay proveedores"
                    description="Una orden de compra necesita a quién pedirle. Crea el primero y vuelve."
                    icon={<PackageIcon size={24} />}
                    action={
                        <Link
                            href={route('suppliers.index')}
                            className="rounded-control inline-flex h-11 items-center bg-[var(--accent)] px-4 text-[0.875rem] font-semibold text-[var(--accent-ink)]"
                        >
                            Ir a proveedores
                        </Link>
                    }
                />
            </AppLayout>
        );
    }

    const orderPanel = (
        <>
            <div className="flex shrink-0 items-center justify-between gap-2 border-b border-[var(--line)] px-4 py-3">
                <div>
                    <p className="text-[0.8125rem] font-semibold">Artículos de la orden</p>
                    <p className="text-[0.71875rem] text-[var(--muted)]">
                        {lines.length} {lines.length === 1 ? 'artículo' : 'artículos'} · {formatNumber(totals.units)} unidades
                    </p>
                </div>
                {lines.length > 0 && (
                    <Button size="sm" variant="ghost" icon={<TrashIcon size={15} />} onClick={() => setLines([])}>
                        Vaciar
                    </Button>
                )}
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto">
                {lines.length === 0 ? (
                    <div className="grid h-full place-items-center gap-2 px-6 py-10 text-center text-[var(--muted)]">
                        <BarcodeIcon size={34} />
                        <p className="text-[0.84375rem]">La orden está vacía.</p>
                        <p className="text-[0.75rem]">Busca, escanea un código o toca un artículo del catálogo.</p>
                    </div>
                ) : (
                    <ul className="flex flex-col">
                        {lines.map((line) => {
                            const off = line.supplier_id !== null && line.supplier_id !== supplier;

                            return (
                                <li key={line.product_id} className="border-b border-[var(--line)] px-4 py-3">
                                    <div className="flex items-start gap-2">
                                        <div className="min-w-0 flex-1">
                                            <p className="text-[0.84375rem] leading-snug font-medium">{line.name}</p>
                                            <p className="mt-0.5 text-[0.71875rem]">
                                                {off ? (
                                                    <span className="text-[var(--warn)]">Es de {line.supplier_label}</span>
                                                ) : line.is_low ? (
                                                    <span className="text-[var(--warn)]">Bajo mínimo · quedan {formatNumber(line.stock)}</span>
                                                ) : (
                                                    <span className="text-[var(--muted)]">
                                                        Existencia {formatNumber(line.stock)} · mínimo {formatNumber(line.min_stock)}
                                                    </span>
                                                )}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => remove(line.product_id)}
                                            aria-label={`Quitar ${line.name}`}
                                            className="rounded-chip grid size-8 shrink-0 place-items-center text-[var(--muted)] hover:bg-[var(--danger-soft)] hover:text-[var(--danger)]"
                                        >
                                            <TrashIcon size={14} />
                                        </button>
                                    </div>

                                    <div className="mt-2 flex flex-wrap items-center gap-2">
                                        <div className="flex items-center gap-1">
                                            <button
                                                type="button"
                                                onClick={() => setQuantity(line.product_id, line.quantity - 1)}
                                                aria-label="Menos uno"
                                                className="rounded-control grid size-11 place-items-center border border-[var(--line)] text-[var(--muted)] hover:text-[var(--text)]"
                                            >
                                                <MinusIcon size={15} weight="bold" />
                                            </button>
                                            <input
                                                type="number"
                                                min={1}
                                                value={line.quantity}
                                                onChange={(event) => setQuantity(line.product_id, Number(event.target.value))}
                                                aria-label={`Cantidad de ${line.name}`}
                                                className="rounded-control tabular h-11 w-16 border border-[var(--line)] bg-[var(--surface-2)] text-center font-mono text-[0.9375rem] focus:border-[var(--accent)] focus:outline-none"
                                            />
                                            <button
                                                type="button"
                                                onClick={() => setQuantity(line.product_id, line.quantity + 1)}
                                                aria-label="Uno más"
                                                className="rounded-control grid size-11 place-items-center border border-[var(--line)] text-[var(--muted)] hover:text-[var(--text)]"
                                            >
                                                <PlusIcon size={15} weight="bold" />
                                            </button>
                                        </div>

                                        <label className="flex items-center gap-1 text-[0.71875rem] text-[var(--muted)]">
                                            <span className="sr-only">Costo unitario de {line.name}</span>
                                            <span aria-hidden>$</span>
                                            <input
                                                type="number"
                                                min={0}
                                                value={line.unit_cost}
                                                onChange={(event) => setCost(line.product_id, Number(event.target.value))}
                                                className="rounded-control tabular h-11 w-24 border border-[var(--line)] bg-[var(--surface-2)] px-2 text-right font-mono text-[0.8125rem] focus:border-[var(--accent)] focus:outline-none"
                                            />
                                        </label>

                                        <span className="tabular ml-auto font-mono text-[0.875rem] font-semibold">
                                            {money(Math.round(line.quantity * line.unit_cost))}
                                        </span>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>

            <div className="shrink-0 border-t border-[var(--line)] px-4 py-3">
                {totals.offSupplier.length > 0 && (
                    <div className="rounded-control mb-3 bg-[var(--warn-soft)] px-3 py-2.5">
                        <p className="flex items-start gap-2 text-[0.78125rem] font-medium text-[var(--warn)]">
                            <WarningCircleIcon size={15} weight="fill" className="mt-px shrink-0" />
                            {totals.offSupplier.length} {totals.offSupplier.length === 1 ? 'artículo no es' : 'artículos no son'} de este proveedor.
                        </p>
                        <Button size="sm" variant="secondary" className="mt-2" loading={submitting} onClick={() => submit('sent', true)}>
                            Separar en una orden por proveedor
                        </Button>
                    </div>
                )}

                <dl className="flex flex-col gap-1 text-[0.8125rem]">
                    <div className="flex items-center justify-between text-[var(--muted)]">
                        <dt>Unidades</dt>
                        <dd className="tabular font-mono">{formatNumber(totals.units)}</dd>
                    </div>
                    <div className="flex items-center justify-between text-[var(--muted)]">
                        <dt>Artículos distintos</dt>
                        <dd className="tabular font-mono">{formatNumber(lines.length)}</dd>
                    </div>
                    <div className="mt-1 flex items-center justify-between border-t border-[var(--line)] pt-2">
                        <dt className="text-[0.875rem] font-semibold">Total estimado</dt>
                        <dd className="tabular font-mono text-[1.5rem] leading-none font-semibold text-[var(--accent)]">{money(totals.total)}</dd>
                    </div>
                </dl>

                {errors.items && <p className="mt-2 text-[0.78125rem] font-medium text-[var(--danger)]">{errors.items}</p>}
                {errors.supplier_id && <p className="mt-2 text-[0.78125rem] font-medium text-[var(--danger)]">{errors.supplier_id}</p>}

                <Button size="pos" block className="mt-3" loading={submitting} disabled={lines.length === 0} onClick={() => submit('sent')}>
                    Crear y enviar al proveedor
                </Button>

                <Button
                    size="md"
                    block
                    variant="secondary"
                    className="mt-2"
                    disabled={lines.length === 0 || submitting}
                    onClick={() => submit('draft')}
                >
                    Guardar como borrador
                </Button>
            </div>
        </>
    );

    return (
        <AppLayout title="Nueva orden de compra">
            <div className="mb-3 flex flex-wrap items-center gap-2">
                <Link
                    href={route('purchases.index')}
                    className="rounded-control inline-flex h-10 items-center gap-1.5 border border-[var(--line)] px-3 text-[0.84375rem] font-medium text-[var(--muted)] hover:text-[var(--text)]"
                >
                    <ArrowLeftIcon size={15} />
                    Volver
                </Link>

                <h1 className="text-[1.125rem] font-semibold">Nueva orden de compra</h1>

                {store && <span className="text-[0.78125rem] text-[var(--muted)]">Sede {store.name}</span>}
                {warehouse && <span className="text-[0.78125rem] text-[var(--muted)]">· Se recibe en {warehouse.name}</span>}

                <Button size="sm" variant="secondary" className="ml-auto" disabled={lines.length === 0 || submitting} onClick={() => submit('draft')}>
                    Guardar como borrador
                </Button>
            </div>

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_408px] xl:items-start">
                <div className="flex min-w-0 flex-col gap-3">
                    {/* Los datos de la orden en una sola línea: antes ocupaban una columna alta
                        y empujaban el catálogo fuera de la pantalla. */}
                    <div className="rounded-card flex flex-wrap items-end gap-3 border border-[var(--line)] bg-[var(--surface)] p-3">
                        <label className="flex min-w-[13.75rem] flex-col gap-1.5">
                            <span className="text-[0.75rem] font-medium text-[var(--muted)]">Proveedor</span>
                            <select
                                value={supplier ?? ''}
                                onChange={(event) => setSupplier(event.target.value === '' ? null : Number(event.target.value))}
                                className="rounded-control h-11 border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.84375rem] font-medium focus:border-[var(--accent)] focus:outline-none"
                            >
                                {suppliers.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <label className="flex flex-col gap-1.5">
                            <span className="text-[0.75rem] font-medium text-[var(--muted)]">Entrega esperada</span>
                            <input
                                type="date"
                                value={expectedAt}
                                onChange={(event) => setExpectedAt(event.target.value)}
                                className="rounded-control h-11 border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.84375rem] focus:border-[var(--accent)] focus:outline-none"
                            />
                        </label>

                        <label className="flex min-w-[10rem] flex-col gap-1.5">
                            <span className="text-[0.75rem] font-medium text-[var(--muted)]">Plazo de pago</span>
                            <select
                                value={termId}
                                onChange={(event) => setTermId(event.target.value)}
                                className="rounded-control h-11 border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.84375rem] focus:border-[var(--accent)] focus:outline-none"
                            >
                                <option value="">Sin plazo</option>
                                {paymentTerms.map((term) => (
                                    <option key={term.value} value={term.value}>
                                        {term.label}
                                    </option>
                                ))}
                            </select>
                            {errors.payment_term_id && (
                                <span className="text-[0.75rem] font-medium text-[var(--danger)]">{errors.payment_term_id}</span>
                            )}
                        </label>

                        <label className="flex min-w-[12.5rem] flex-1 flex-col gap-1.5">
                            <span className="text-[0.75rem] font-medium text-[var(--muted)]">Nota</span>
                            <input
                                type="text"
                                value={note}
                                maxLength={160}
                                onChange={(event) => setNote(event.target.value)}
                                placeholder="Entregar antes de las 9 a. m."
                                className="rounded-control h-11 border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.84375rem] focus:border-[var(--accent)] focus:outline-none"
                            />
                        </label>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <div className="relative flex min-w-[16.25rem] flex-1 items-center">
                            <MagnifyingGlassIcon size={19} className="pointer-events-none absolute left-3.5 text-[var(--muted)]" />
                            <input
                                ref={searchRef}
                                autoFocus
                                type="text"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                onKeyDown={(event) => {
                                    if (event.key !== 'Enter') {
                                        return;
                                    }

                                    event.preventDefault();
                                    const term = search.trim();

                                    // Un código completo se escanea; un texto corto busca.
                                    if (/^\d{8,14}$/.test(term)) {
                                        scan(term);

                                        return;
                                    }

                                    if (visible[0]) {
                                        upsert(visible[0], 'search');
                                        setSearch('');
                                    }
                                }}
                                placeholder="Buscar por nombre o SKU, o escanear el código de barras"
                                className="rounded-card h-[3.25rem] w-full border border-[var(--line)] bg-[var(--surface-2)] pr-3 pl-11 text-[0.9375rem] focus:border-[var(--accent)] focus:outline-none"
                            />
                        </div>

                        {lowStockCount > 0 && (
                            <Button size="lg" variant="secondary" className="h-[3.25rem]" onClick={addLowStock}>
                                Agregar los {formatNumber(lowStockCount)} bajo mínimo
                            </Button>
                        )}
                    </div>

                    <div className="flex flex-wrap gap-1.5">
                        {(
                            [
                                ['supplier', 'Del proveedor', fromSupplierCount],
                                ['low', 'Bajo mínimo', lowStockCount],
                                ['all', 'Todo el catálogo', counts.total],
                            ] as const
                        ).map(([key, label, count]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => setView(key)}
                                className={cn(
                                    'rounded-control min-h-[2.375rem] border px-3 text-[0.78125rem] font-medium transition-colors',
                                    view === key
                                        ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]'
                                        : 'border-[var(--line)] text-[var(--muted)] hover:text-[var(--text)]',
                                )}
                            >
                                {label} · {formatNumber(count)}
                            </button>
                        ))}
                    </div>

                    {visible.length === 0 ? (
                        <EmptyState
                            title={loading ? 'Buscando…' : 'No hay artículos que mostrar'}
                            description={
                                view === 'supplier'
                                    ? 'Este proveedor todavía no surte nada. Mira todo el catálogo: puedes pedirle cualquier artículo.'
                                    : 'Prueba con otra búsqueda o mira todo el catálogo.'
                            }
                            icon={<PackageIcon size={24} />}
                            action={
                                view !== 'all' ? (
                                    <Button variant="secondary" onClick={() => setView('all')}>
                                        Ver todo el catálogo
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <ul className="grid grid-cols-1 gap-2 pb-24 sm:grid-cols-2 lg:grid-cols-3 xl:pb-0 2xl:grid-cols-4">
                            {visible.map((product, index) => {
                                // Un subartículo se ve como su artículo principal: una tarjeta que abre la matriz.
                                if (product.family_id) {
                                    if (visible.findIndex((other) => other.family_id === product.family_id) !== index) {
                                        return null;
                                    }

                                    const members = visible.filter((other) => other.family_id === product.family_id);
                                    const ordered = lines.filter((line) => line.family_id === product.family_id);
                                    const low = members.filter((member) => member.is_low).length;

                                    return (
                                        <li key={`f${product.family_id}`}>
                                            <button
                                                type="button"
                                                onClick={() => setMatrixFamily(product.family_id ?? null)}
                                                className={cn(
                                                    'rounded-card flex min-h-[8.25rem] w-full flex-col justify-between border p-3 text-left transition-colors',
                                                    ordered.length > 0
                                                        ? 'border-[var(--accent)] bg-[var(--accent-soft)]'
                                                        : 'border-[var(--line)] bg-[var(--surface)] hover:bg-[var(--surface-2)]',
                                                )}
                                            >
                                                <span className="min-w-0">
                                                    <span className="flex items-center gap-1.5 text-[0.6875rem] font-semibold text-[var(--accent)]">
                                                        <SquaresFourIcon size={13} />
                                                        Con subartículos
                                                    </span>
                                                    <span className="mt-0.5 block text-[0.8125rem] leading-snug font-medium">
                                                        {product.family_name ?? product.name}
                                                    </span>
                                                </span>
                                                <span className="mt-2 block">
                                                    <span
                                                        className="block text-[0.71875rem]"
                                                        style={{ color: low > 0 ? 'var(--warn)' : 'var(--muted)' }}
                                                    >
                                                        {members.length === 1 ? '1 subartículo' : `${members.length} subartículos`}
                                                        {low > 0 ? ` · ${low} bajo mínimo` : ''}
                                                    </span>
                                                    <span
                                                        className={cn(
                                                            'rounded-control mt-1.5 flex min-h-[2rem] items-center justify-center gap-1.5 text-[0.78125rem] font-semibold',
                                                            ordered.length > 0
                                                                ? 'bg-[var(--accent)] text-[var(--accent-ink)]'
                                                                : 'bg-[var(--surface-2)] text-[var(--text)]',
                                                        )}
                                                    >
                                                        {ordered.length > 0
                                                            ? `En la orden · ${formatNumber(ordered.reduce((sum, line) => sum + line.quantity, 0))}`
                                                            : 'Abrir la matriz'}
                                                    </span>
                                                </span>
                                            </button>
                                        </li>
                                    );
                                }

                                const added = inOrder.has(product.id);
                                const line = lines.find((item) => item.product_id === product.id);

                                return (
                                    <li key={product.id}>
                                        <button
                                            type="button"
                                            onClick={() => upsert(product, 'search')}
                                            className={cn(
                                                'rounded-card flex min-h-[8.25rem] w-full flex-col justify-between border p-3 text-left transition-colors',
                                                added
                                                    ? 'border-[var(--accent)] bg-[var(--accent-soft)]'
                                                    : product.is_from_supplier
                                                      ? 'border-[var(--accent)] bg-[var(--surface)] hover:bg-[var(--surface-2)]'
                                                      : 'border-[var(--line)] bg-[var(--surface)] hover:bg-[var(--surface-2)]',
                                            )}
                                        >
                                            <span className="flex items-start gap-2">
                                                <span
                                                    aria-hidden
                                                    className="mt-1 size-2 shrink-0 rounded-full"
                                                    style={{
                                                        backgroundColor: product.is_out
                                                            ? 'var(--danger)'
                                                            : product.is_low
                                                              ? 'var(--warn)'
                                                              : 'var(--accent)',
                                                    }}
                                                />
                                                <span className="min-w-0 flex-1">
                                                    <span className="flex items-center justify-between gap-2">
                                                        <span className="truncate font-mono text-[0.6875rem] text-[var(--muted)]">{product.sku}</span>
                                                        <span
                                                            className={cn(
                                                                'shrink-0 truncate text-[0.65625rem] font-medium',
                                                                product.is_from_supplier ? 'text-[var(--accent)]' : 'text-[var(--muted)]',
                                                            )}
                                                        >
                                                            {product.is_from_supplier ? 'Del proveedor' : (product.supplier_label ?? 'Sin proveedor')}
                                                        </span>
                                                    </span>
                                                    <span className="mt-0.5 block text-[0.8125rem] leading-snug font-medium">{product.name}</span>
                                                </span>
                                            </span>

                                            <span className="mt-2 block">
                                                <span className="flex items-center justify-between text-[0.71875rem]">
                                                    <span
                                                        style={{
                                                            color: product.is_out ? 'var(--danger)' : product.is_low ? 'var(--warn)' : 'var(--muted)',
                                                        }}
                                                    >
                                                        {formatNumber(product.stock)} {UNIT_SHORT[product.unit] ?? ''} · mín{' '}
                                                        {formatNumber(product.min_stock)}
                                                    </span>
                                                    <span className="tabular font-mono text-[var(--muted)]">{money(product.unit_cost)}</span>
                                                </span>

                                                <span
                                                    className={cn(
                                                        'rounded-control mt-1.5 flex min-h-[2rem] items-center justify-center gap-1.5 text-[0.78125rem] font-semibold',
                                                        added
                                                            ? 'bg-[var(--accent)] text-[var(--accent-ink)]'
                                                            : 'bg-[var(--surface-2)] text-[var(--text)]',
                                                    )}
                                                >
                                                    {added ? (
                                                        <>
                                                            <CheckIcon size={13} weight="bold" />
                                                            En la orden · {formatNumber(line?.quantity ?? 0)}
                                                        </>
                                                    ) : (
                                                        <>
                                                            <PlusIcon size={13} weight="bold" />
                                                            Agregar {formatNumber(product.suggested)}
                                                        </>
                                                    )}
                                                </span>
                                            </span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>

                <PurchaseMatrix
                    familyId={matrixFamily}
                    supplierId={supplier}
                    current={Object.fromEntries(lines.map((line) => [line.product_id, { quantity: line.quantity, unit_cost: line.unit_cost }]))}
                    onClose={() => setMatrixFamily(null)}
                    onApply={applyMatrix}
                />
                {/* En pantalla ancha el panel va al lado; debajo de 1280 px se vuelve una hoja
                    inferior con el total siempre visible. */}
                <aside className="rounded-card hidden max-h-[calc(100vh-9rem)] flex-col overflow-hidden border border-[var(--line)] bg-[var(--surface)] xl:sticky xl:top-20 xl:flex">
                    {orderPanel}
                </aside>
            </div>

            <div className="fixed inset-x-0 bottom-0 z-30 border-t border-[var(--line)] bg-[var(--surface)] xl:hidden">
                {sheetOpen && <div className="flex max-h-[70vh] flex-col">{orderPanel}</div>}

                {!sheetOpen && (
                    <button
                        type="button"
                        onClick={() => setSheetOpen(true)}
                        className="flex min-h-[3.75rem] w-full items-center justify-between gap-3 px-4"
                    >
                        <span className="text-left">
                            <span className="block text-[0.71875rem] text-[var(--muted)]">
                                {lines.length} {lines.length === 1 ? 'artículo' : 'artículos'}
                            </span>
                            <span className="tabular block font-mono text-[1.25rem] font-semibold text-[var(--accent)]">{money(totals.total)}</span>
                        </span>
                        <span className="rounded-control bg-[var(--accent)] px-4 py-2.5 text-[0.84375rem] font-semibold text-[var(--accent-ink)]">
                            Ver la orden
                        </span>
                    </button>
                )}

                {sheetOpen && (
                    <button
                        type="button"
                        onClick={() => setSheetOpen(false)}
                        className="min-h-[2.75rem] w-full border-t border-[var(--line)] text-[0.78125rem] font-medium text-[var(--muted)]"
                    >
                        Cerrar
                    </button>
                )}
            </div>
        </AppLayout>
    );
}

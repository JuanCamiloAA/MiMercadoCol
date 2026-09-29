import { Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeftIcon,
    ArrowLineDownIcon,
    ArrowLineUpIcon,
    BarcodeIcon,
    CheckIcon,
    MagnifyingGlassIcon,
    MinusIcon,
    PackageIcon,
    PlusIcon,
    TrashIcon,
    WarningCircleIcon,
} from '@phosphor-icons/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import { money, number as formatNumber, UNIT_SHORT } from '@/lib/format';
import type { PageProps } from '@/types';
import type { StockLine, StockMovementFormProps, StockProduct } from '@/types/inventory';

type View = 'all' | 'low';

/** Cuánto se espera a que deje de escribir antes de preguntarle al servidor. */
const SEARCH_MS = 250;

/**
 * Entrada o salida de bodega de varios artículos, con la mecánica del punto de venta.
 *
 * La misma pantalla sirve para las dos: lo único que cambia es el signo y la lista de motivos,
 * y cada botón del inventario entra por su ruta. Separarlas en dos archivos obligaría a
 * arreglar dos veces cada cosa que se descubra usándolas.
 *
 * El costo no se edita aquí a propósito. Mercancía que entra con un costo nuevo es una compra,
 * y ese es el único camino que recalcula el costo promedio del artículo.
 */
export default function InventoryMovement({ direction, reasons, warehouses, default_warehouse, products, counts, store }: StockMovementFormProps) {
    const errors = (usePage<PageProps>().props.errors ?? {}) as Record<string, string>;
    const isEntry = direction === 'in';

    const [catalog, setCatalog] = useState<StockProduct[]>(products);
    const [lowStockCount, setLowStockCount] = useState(counts.low_stock);
    const [search, setSearch] = useState('');
    const [view, setView] = useState<View>('all');
    const [loading, setLoading] = useState(false);

    const [lines, setLines] = useState<StockLine[]>([]);
    // El servidor propone la bodega que usaria ese canal; el selector la deja a la vista.
    const [warehouse, setWarehouse] = useState<number>(default_warehouse ?? warehouses[0]?.value ?? 0);
    const [reason, setReason] = useState('');
    const [note, setNote] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [sheetOpen, setSheetOpen] = useState(false);

    const searchRef = useRef<HTMLInputElement>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const fetchCatalog = useCallback((term: string, onlyLow: boolean, forWarehouse: number) => {
        setLoading(true);

        const query = new URLSearchParams();

        if (term !== '') {
            query.set('search', term);
        }

        if (onlyLow) {
            query.set('low_stock', '1');
        }

        if (forWarehouse > 0) {
            query.set('warehouse', String(forWarehouse));
        }

        fetch(`${route('inventory.products')}?${query.toString()}`, { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : null))
            .then((body: { products: StockProduct[]; low_stock: number } | null) => {
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

        timer.current = setTimeout(() => fetchCatalog(search, view === 'low', warehouse), SEARCH_MS);

        return () => {
            if (timer.current) {
                clearTimeout(timer.current);
            }
        };
    }, [search, view, warehouse, fetchCatalog]);

    const upsert = useCallback((product: StockProduct, quantity = 1) => {
        setLines((current) => {
            const existing = current.find((line) => line.product_id === product.id);

            if (existing) {
                // Ya está: se suma en vez de duplicar el renglón. El servidor rechaza repetidos.
                return current.map((line) =>
                    line.product_id === product.id ? { ...line, quantity: line.quantity + quantity } : line,
                );
            }

            return [
                ...current,
                {
                    product_id: product.id,
                    name: product.name,
                    sku: product.sku,
                    unit: product.unit,
                    quantity,
                    unit_cost: product.unit_cost,
                    stock: product.stock,
                    reserved: product.reserved,
                    available: product.available,
                    min_stock: product.min_stock,
                    allow_negative_stock: product.allow_negative_stock,
                },
            ];
        });
    }, []);

    const setQuantity = (productId: number, quantity: number) =>
        setLines((current) =>
            current.map((line) =>
                line.product_id === productId
                    ? { ...line, quantity: Number.isFinite(quantity) ? Math.max(0, quantity) : 0 }
                    : line,
            ),
        );

    const remove = (productId: number) => setLines((current) => current.filter((line) => line.product_id !== productId));

    /** Cambiar de bodega vacia el movimiento: las existencias de los renglones ya no aplican. */
    const changeWarehouse = (id: number) => {
        setWarehouse(id);
        setLines([]);
    };

    /** Lee un código de barras: entra con una unidad y el campo queda listo para el siguiente. */
    const scan = useCallback(
        (code: string) => {
            fetch(`${route('inventory.products')}?barcode=${encodeURIComponent(code)}&warehouse=${warehouse}`, {
                headers: { Accept: 'application/json' },
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((body: { products: StockProduct[]; found: boolean } | null) => {
                    if (body?.found && body.products[0]) {
                        upsert(body.products[0]);
                        setSearch('');
                        toast.success(body.products[0].name);

                        return;
                    }

                    toast.error('No encontramos ese código en el catálogo.');
                })
                .catch(() => toast.error('No se pudo consultar el código.'));
        },
        [upsert, warehouse],
    );

    // Quien cuenta bodega no usa el ratón: el buscador se recupera con F1.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'F1') {
                event.preventDefault();
                searchRef.current?.focus();
                searchRef.current?.select();
            }

            if (event.key === 'Escape' && document.activeElement === searchRef.current) {
                searchRef.current?.blur();
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const inMovement = useMemo(() => new Set(lines.map((line) => line.product_id)), [lines]);
    const visible = useMemo(() => (view === 'low' ? catalog.filter((product) => product.is_low) : catalog), [catalog, view]);

    /** Cómo queda la existencia si el movimiento se registra tal como está. */
    const resultOf = useCallback(
        (line: StockLine) => Math.round((isEntry ? line.stock + line.quantity : line.stock - line.quantity) * 1000) / 1000,
        [isEntry],
    );

    /** Una salida que deja el saldo en negativo sin permiso no la acepta el servidor. */
    const blocks = useCallback(
        (line: StockLine) => !isEntry && !line.allow_negative_stock && resultOf(line) < 0,
        [isEntry, resultOf],
    );

    const totals = useMemo(() => {
        const units = lines.reduce((sum, line) => sum + line.quantity, 0);
        const value = lines.reduce((sum, line) => sum + Math.round(line.quantity * line.unit_cost), 0);
        const blocked = lines.filter(blocks);
        const empty = lines.filter((line) => line.quantity <= 0);
        // Sacar lo apartado deja un pedido sin con qué despacharse: se avisa, no se impide.
        const reserved = isEntry ? [] : lines.filter((line) => line.quantity > line.available && !blocks(line));

        return { units, value, blocked, empty, reserved };
    }, [lines, blocks, isEntry]);

    const ready = lines.length > 0 && reason !== '' && warehouse > 0 && totals.blocked.length === 0 && totals.empty.length === 0;

    const submit = () => {
        if (!ready) {
            return;
        }

        setSubmitting(true);

        router.post(
            route('inventory.movement.store'),
            {
                direction,
                warehouse_id: warehouse,
                reason,
                note: note || null,
                items: lines.map((line) => ({ product_id: line.product_id, quantity: line.quantity })),
            },
            {
                onError: () => toast.error(isEntry ? 'No se pudo registrar la entrada.' : 'No se pudo registrar la salida.'),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const warehouseName = warehouses.find((option) => option.value === warehouse)?.label;

    const title = isEntry ? 'Entrada de mercancía' : 'Salida de mercancía';

    const panel = (
        <>
            <div className="flex shrink-0 items-center justify-between gap-2 border-b border-[var(--line)] px-4 py-3">
                <div>
                    <p className="text-[0.8125rem] font-semibold">Artículos del movimiento</p>
                    <p className="text-[0.71875rem] text-[var(--muted)]">
                        {lines.length} {lines.length === 1 ? 'artículo' : 'artículos'} · {formatNumber(totals.units)} unidades
                    </p>
                    {warehouseName && (
                        <p className="text-[0.71875rem] text-[var(--muted)]">
                            {isEntry ? 'Entra a' : 'Sale de'} <span className="font-medium text-[var(--text)]">{warehouseName}</span>
                        </p>
                    )}
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
                        <p className="text-[0.84375rem]">Todavía no hay artículos.</p>
                        <p className="text-[0.75rem]">Busca, escanea un código o toca un artículo del catálogo.</p>
                    </div>
                ) : (
                    <ul className="flex flex-col">
                        {lines.map((line) => {
                            const result = resultOf(line);
                            const blocked = blocks(line);
                            const eatsReserved = !isEntry && !blocked && line.quantity > line.available;

                            return (
                                <li key={line.product_id} className="border-b border-[var(--line)] px-4 py-3">
                                    <div className="flex items-start gap-2">
                                        <div className="min-w-0 flex-1">
                                            <p className="text-[0.84375rem] font-medium leading-snug">{line.name}</p>
                                            <p className="mt-0.5 text-[0.71875rem]">
                                                {blocked ? (
                                                    <span className="font-medium text-[var(--danger)]">
                                                        No alcanza: hay {formatNumber(line.stock)} {UNIT_SHORT[line.unit] ?? ''}
                                                    </span>
                                                ) : eatsReserved ? (
                                                    <span className="font-medium text-[var(--warn)]">
                                                        Hay {formatNumber(line.reserved)} apartadas para pedidos
                                                    </span>
                                                ) : (
                                                    <span className="text-[var(--muted)]">
                                                        {formatNumber(line.stock)} →{' '}
                                                        <span className="font-medium text-[var(--text)]">{formatNumber(result)}</span>{' '}
                                                        {UNIT_SHORT[line.unit] ?? ''}
                                                    </span>
                                                )}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => remove(line.product_id)}
                                            aria-label={`Quitar ${line.name}`}
                                            className="grid size-8 shrink-0 place-items-center rounded-chip text-[var(--muted)] hover:bg-[var(--danger-soft)] hover:text-[var(--danger)]"
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
                                                className="grid size-11 place-items-center rounded-control border border-[var(--line)] text-[var(--muted)] hover:text-[var(--text)]"
                                            >
                                                <MinusIcon size={15} weight="bold" />
                                            </button>
                                            <input
                                                type="number"
                                                min={0}
                                                step="any"
                                                value={line.quantity}
                                                onChange={(event) => setQuantity(line.product_id, Number(event.target.value))}
                                                aria-label={`Cantidad de ${line.name}`}
                                                className={cn(
                                                    'h-11 w-20 rounded-control border bg-[var(--surface-2)] text-center font-mono text-[0.9375rem] tabular focus:outline-none',
                                                    blocked || line.quantity <= 0
                                                        ? 'border-[var(--danger)] text-[var(--danger)]'
                                                        : 'border-[var(--line)] focus:border-[var(--accent)]',
                                                )}
                                            />
                                            <button
                                                type="button"
                                                onClick={() => setQuantity(line.product_id, line.quantity + 1)}
                                                aria-label="Uno más"
                                                className="grid size-11 place-items-center rounded-control border border-[var(--line)] text-[var(--muted)] hover:text-[var(--text)]"
                                            >
                                                <PlusIcon size={15} weight="bold" />
                                            </button>
                                        </div>

                                        <span className="ml-auto text-right">
                                            <span className="block font-mono text-[0.875rem] font-semibold tabular">
                                                {money(Math.round(line.quantity * line.unit_cost))}
                                            </span>
                                            <span className="block text-[0.65625rem] text-[var(--muted)]">al costo</span>
                                        </span>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>

            <div className="shrink-0 border-t border-[var(--line)] px-4 py-3">
                {totals.blocked.length > 0 && (
                    <p className="mb-3 flex items-start gap-2 rounded-control bg-[var(--danger-soft)] px-3 py-2.5 text-[0.78125rem] font-medium text-[var(--danger)]">
                        <WarningCircleIcon size={15} weight="fill" className="mt-px shrink-0" />
                        {totals.blocked.length === 1
                            ? 'Un artículo no tiene existencia suficiente. Ajusta la cantidad o quítalo.'
                            : `${totals.blocked.length} artículos no tienen existencia suficiente. Ajusta las cantidades o quítalos.`}
                    </p>
                )}

                {totals.reserved.length > 0 && (
                    <p className="mb-3 flex items-start gap-2 rounded-control bg-[var(--warn-soft)] px-3 py-2.5 text-[0.78125rem] font-medium text-[var(--warn)]">
                        <WarningCircleIcon size={15} weight="fill" className="mt-px shrink-0" />
                        {totals.reserved.length === 1
                            ? 'Un artículo tiene unidades apartadas para pedidos. Si las sacas, esos pedidos quedan sin con qué despacharse.'
                            : `${totals.reserved.length} artículos tienen unidades apartadas para pedidos.`}
                    </p>
                )}

                <dl className="flex flex-col gap-1 text-[0.8125rem]">
                    <div className="flex items-center justify-between text-[var(--muted)]">
                        <dt>Unidades</dt>
                        <dd className="font-mono tabular">{formatNumber(totals.units)}</dd>
                    </div>
                    <div className="flex items-center justify-between text-[var(--muted)]">
                        <dt>Artículos distintos</dt>
                        <dd className="font-mono tabular">{formatNumber(lines.length)}</dd>
                    </div>
                    <div className="mt-1 flex items-center justify-between border-t border-[var(--line)] pt-2">
                        <dt className="text-[0.875rem] font-semibold">{isEntry ? 'Entra al costo' : 'Sale al costo'}</dt>
                        <dd
                            className="font-mono text-[1.5rem] font-semibold leading-none tabular"
                            style={{ color: isEntry ? 'var(--accent)' : 'var(--danger)' }}
                        >
                            {isEntry ? '' : '−'}
                            {money(totals.value)}
                        </dd>
                    </div>
                </dl>

                {(['items', 'reason', 'stock', 'direction', 'warehouse_id'] as const).map((key) =>
                    errors[key] ? (
                        <p key={key} className="mt-2 text-[0.78125rem] font-medium text-[var(--danger)]">
                            {errors[key]}
                        </p>
                    ) : null,
                )}

                {reason === '' && lines.length > 0 && (
                    <p className="mt-2 text-[0.78125rem] font-medium text-[var(--warn)]">Falta elegir el motivo.</p>
                )}

                <Button
                    size="pos"
                    block
                    className="mt-3"
                    variant={isEntry ? 'primary' : 'danger'}
                    loading={submitting}
                    disabled={!ready}
                    onClick={submit}
                    icon={isEntry ? <ArrowLineDownIcon size={17} weight="bold" /> : <ArrowLineUpIcon size={17} weight="bold" />}
                >
                    {isEntry ? 'Registrar la entrada' : 'Registrar la salida'}
                </Button>
            </div>
        </>
    );

    return (
        <AppLayout title={title}>
            <div className="mb-3 flex flex-wrap items-center gap-2">
                <Link
                    href={route('inventory.index')}
                    className="inline-flex h-10 items-center gap-1.5 rounded-control border border-[var(--line)] px-3 text-[0.84375rem] font-medium text-[var(--muted)] hover:text-[var(--text)]"
                >
                    <ArrowLeftIcon size={15} />
                    Volver
                </Link>

                <span
                    className="inline-flex h-10 items-center gap-1.5 rounded-control px-3 text-[0.84375rem] font-semibold"
                    style={{
                        backgroundColor: isEntry ? 'var(--accent-soft)' : 'var(--danger-soft)',
                        color: isEntry ? 'var(--accent)' : 'var(--danger)',
                    }}
                >
                    {isEntry ? <ArrowLineDownIcon size={16} weight="bold" /> : <ArrowLineUpIcon size={16} weight="bold" />}
                    {title}
                </span>

                {store && <span className="text-[0.78125rem] text-[var(--muted)]">Sede {store.name}</span>}
            </div>

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_408px] xl:items-start">
                <div className="flex min-w-0 flex-col gap-3">
                    <div className="flex flex-wrap items-end gap-3 rounded-card border border-[var(--line)] bg-[var(--surface)] p-3">
                        <label className="flex min-w-[12.5rem] flex-col gap-1.5">
                            <span className="text-[0.75rem] font-medium text-[var(--muted)]">
                                {isEntry ? 'Bodega que recibe' : 'Bodega de donde sale'}
                            </span>
                            <select
                                value={warehouse}
                                onChange={(event) => changeWarehouse(Number(event.target.value))}
                                className="h-11 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.84375rem] font-medium focus:border-[var(--accent)] focus:outline-none"
                            >
                                {warehouses.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <label className="flex min-w-[15rem] flex-col gap-1.5">
                            <span className="text-[0.75rem] font-medium text-[var(--muted)]">Motivo</span>
                            <select
                                value={reason}
                                onChange={(event) => setReason(event.target.value)}
                                className="h-11 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.84375rem] font-medium focus:border-[var(--accent)] focus:outline-none"
                            >
                                <option value="">Elige el motivo…</option>
                                {reasons.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <label className="flex min-w-[13.75rem] flex-1 flex-col gap-1.5">
                            <span className="text-[0.75rem] font-medium text-[var(--muted)]">Nota</span>
                            <input
                                type="text"
                                value={note}
                                maxLength={160}
                                onChange={(event) => setNote(event.target.value)}
                                placeholder={isEntry ? 'Conteo de bodega del lunes' : 'Se rompieron al bajarlas de la estantería'}
                                className="h-11 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.84375rem] focus:border-[var(--accent)] focus:outline-none"
                            />
                        </label>
                    </div>

                    <div className="relative flex items-center">
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
                                    upsert(visible[0]);
                                    setSearch('');
                                }
                            }}
                            placeholder="Buscar por nombre o SKU, o escanear el código de barras"
                            className="h-[3.25rem] w-full rounded-card border border-[var(--line)] bg-[var(--surface-2)] pl-11 pr-3 text-[0.9375rem] focus:border-[var(--accent)] focus:outline-none"
                        />
                    </div>

                    <div className="flex flex-wrap gap-1.5">
                        {(
                            [
                                ['all', 'Todo el catálogo', counts.total],
                                ['low', 'Bajo mínimo', lowStockCount],
                            ] as const
                        ).map(([key, label, count]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => setView(key)}
                                className={cn(
                                    'min-h-[2.375rem] rounded-control border px-3 text-[0.78125rem] font-medium transition-colors',
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
                            description="Prueba con otra búsqueda o mira todo el catálogo."
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
                        <ul className="grid grid-cols-1 gap-2 pb-24 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4 xl:pb-0">
                            {visible.map((product) => {
                                const added = inMovement.has(product.id);
                                const line = lines.find((item) => item.product_id === product.id);
                                // En una salida, lo agotado no se saca salvo que el artículo lo permita.
                                const unavailable = !isEntry && product.stock <= 0 && !product.allow_negative_stock;

                                return (
                                    <li key={product.id}>
                                        <button
                                            type="button"
                                            disabled={unavailable}
                                            onClick={() => upsert(product)}
                                            className={cn(
                                                'flex min-h-[7.875rem] w-full flex-col justify-between rounded-card border p-3 text-left transition-colors',
                                                added
                                                    ? 'border-[var(--accent)] bg-[var(--accent-soft)]'
                                                    : 'border-[var(--line)] bg-[var(--surface)] hover:bg-[var(--surface-2)]',
                                                unavailable && 'cursor-not-allowed opacity-45 hover:bg-[var(--surface)]',
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
                                                    <span className="truncate font-mono text-[0.6875rem] text-[var(--muted)]">{product.sku}</span>
                                                    <span className="mt-0.5 block text-[0.8125rem] font-medium leading-snug">{product.name}</span>
                                                </span>
                                            </span>

                                            <span className="mt-2 block">
                                                <span className="flex items-center justify-between gap-2 text-[0.71875rem]">
                                                    <span
                                                        style={{
                                                            color: product.is_out
                                                                ? 'var(--danger)'
                                                                : product.is_low
                                                                  ? 'var(--warn)'
                                                                  : 'var(--muted)',
                                                        }}
                                                    >
                                                        {formatNumber(product.stock)} {UNIT_SHORT[product.unit] ?? ''} · mín{' '}
                                                        {formatNumber(product.min_stock)}
                                                    </span>
                                                    {product.reserved > 0 && (
                                                        <span className="shrink-0 text-[var(--warn)]">
                                                            {formatNumber(product.reserved)} apartadas
                                                        </span>
                                                    )}
                                                </span>

                                                <span
                                                    className={cn(
                                                        'mt-1.5 flex min-h-[2rem] items-center justify-center gap-1.5 rounded-control text-[0.78125rem] font-semibold',
                                                        added
                                                            ? 'bg-[var(--accent)] text-[var(--accent-ink)]'
                                                            : 'bg-[var(--surface-2)] text-[var(--text)]',
                                                    )}
                                                >
                                                    {added ? (
                                                        <>
                                                            <CheckIcon size={13} weight="bold" />
                                                            {formatNumber(line?.quantity ?? 0)} {UNIT_SHORT[product.unit] ?? ''}
                                                        </>
                                                    ) : unavailable ? (
                                                        'Sin existencia'
                                                    ) : (
                                                        <>
                                                            <PlusIcon size={13} weight="bold" />
                                                            Agregar
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

                {/* En pantalla ancha el panel va al lado; debajo de 1280 px se vuelve una hoja
                    inferior con el resumen siempre visible. */}
                <aside className="hidden max-h-[calc(100vh-9rem)] flex-col overflow-hidden rounded-card border border-[var(--line)] bg-[var(--surface)] xl:sticky xl:top-20 xl:flex">
                    {panel}
                </aside>
            </div>

            <div className="fixed inset-x-0 bottom-0 z-30 border-t border-[var(--line)] bg-[var(--surface)] xl:hidden">
                {sheetOpen && <div className="flex max-h-[70vh] flex-col">{panel}</div>}

                {!sheetOpen && (
                    <button
                        type="button"
                        onClick={() => setSheetOpen(true)}
                        className="flex min-h-[3.75rem] w-full items-center justify-between gap-3 px-4"
                    >
                        <span className="text-left">
                            <span className="block text-[0.71875rem] text-[var(--muted)]">
                                {lines.length} {lines.length === 1 ? 'artículo' : 'artículos'} · {formatNumber(totals.units)} unidades
                            </span>
                            <span
                                className="block font-mono text-[1.25rem] font-semibold tabular"
                                style={{ color: isEntry ? 'var(--accent)' : 'var(--danger)' }}
                            >
                                {isEntry ? '' : '−'}
                                {money(totals.value)}
                            </span>
                        </span>
                        <span className="rounded-control bg-[var(--accent)] px-4 py-2.5 text-[0.84375rem] font-semibold text-[var(--accent-ink)]">
                            {isEntry ? 'Ver la entrada' : 'Ver la salida'}
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

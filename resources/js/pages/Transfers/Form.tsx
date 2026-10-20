import { Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeftIcon,
    ArrowRightIcon,
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
import { usePermission } from '@/lib/permissions';
import type { PageProps } from '@/types';
import type { StockProduct } from '@/types/inventory';
import type { TransferLine, TransferWarehouse } from '@/types/transfers';

interface TransferFormProps {
    /** `request` arma una solicitud: se pide, no se mueve nada. */
    mode?: 'transfer' | 'request';
    warehouses: TransferWarehouse[];
    /** En una solicitud, las bodegas por las que se puede pedir: las de la sede activa. */
    destinations?: TransferWarehouse[];
    fromId: number | null;
    toId: number | null;
    /** Artículos que entran solos: desde Existencias (uno) o desde Tienda en línea (varios). */
    preload: StockProduct[];
    products: StockProduct[];
    counts: { total: number; low_stock: number };
}

/** Cuánto se espera a que deje de escribir antes de preguntarle al servidor. */
const SEARCH_MS = 250;

/**
 * Armar un traslado, o pedirlo.
 *
 * En un traslado el catálogo muestra la existencia de la **bodega de origen**: lo que se va a
 * mover es lo que hay en ese estante. En una solicitud muestra la de la **bodega que pide**:
 * lo que se pide es lo que le falta. Cambiar esa bodega vacía la lista, porque las cantidades
 * de los renglones dejarían de ser ciertas.
 *
 * Despachar deja la mercancía en camino hasta que la bodega de destino la reciba. El traslado
 * directo junta los dos actos, y solo existe dentro de una sede y con su propio permiso.
 */
export default function TransferForm({
    mode = 'transfer',
    warehouses,
    destinations,
    fromId,
    toId,
    preload,
    products,
    counts,
}: TransferFormProps) {
    const { can } = usePermission();
    const errors = (usePage<PageProps>().props.errors ?? {}) as Record<string, string>;
    const isRequest = mode === 'request';

    const [from, setFrom] = useState<number>(fromId ?? warehouses[0]?.value ?? 0);
    const [to, setTo] = useState<number>(toId ?? warehouses.find((item) => item.value !== fromId)?.value ?? 0);
    const [catalog, setCatalog] = useState<StockProduct[]>(products);
    const [lowStockCount, setLowStockCount] = useState(counts.low_stock);
    const [onlyLow, setOnlyLow] = useState(false);
    const [search, setSearch] = useState('');
    const [loading, setLoading] = useState(false);

    const [lines, setLines] = useState<TransferLine[]>([]);
    const [note, setNote] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [sheetOpen, setSheetOpen] = useState(false);

    const searchRef = useRef<HTMLInputElement>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const preloaded = useRef(false);

    const destinationOptions = isRequest && destinations ? destinations : warehouses;
    const origin = warehouses.find((item) => item.value === from) ?? null;
    const destination = destinationOptions.find((item) => item.value === to) ?? null;
    const sameStore = origin !== null && destination !== null && origin.store_id === destination.store_id;

    // La bodega cuya existencia muestra el catálogo, y de dónde se consulta.
    const stockWarehouse = isRequest ? to : from;
    const stockLabel = isRequest ? destination?.label : origin?.label;
    const catalogRoute = isRequest ? 'transfer_requests.products' : 'inventory.products';

    const fetchCatalog = useCallback(
        (term: string, forWarehouse: number, lowOnly: boolean) => {
            setLoading(true);

            const query = new URLSearchParams();

            if (term !== '') {
                query.set('search', term);
            }

            if (forWarehouse > 0) {
                query.set('warehouse', String(forWarehouse));
            }

            if (lowOnly) {
                query.set('low_stock', '1');
            }

            fetch(`${route(catalogRoute)}?${query.toString()}`, { headers: { Accept: 'application/json' } })
                .then((response) => (response.ok ? response.json() : null))
                .then((body: { products: StockProduct[]; low_stock: number } | null) => {
                    if (body) {
                        setCatalog(body.products);
                        setLowStockCount(body.low_stock);
                    }
                })
                .catch(() => toast.error('No se pudo cargar el catálogo.'))
                .finally(() => setLoading(false));
        },
        [catalogRoute],
    );

    useEffect(() => {
        if (timer.current) {
            clearTimeout(timer.current);
        }

        timer.current = setTimeout(() => fetchCatalog(search, stockWarehouse, onlyLow), SEARCH_MS);

        return () => {
            if (timer.current) {
                clearTimeout(timer.current);
            }
        };
    }, [search, stockWarehouse, onlyLow, fetchCatalog]);

    const upsert = useCallback((product: StockProduct, quantity = 1) => {
        setLines((current) => {
            const existing = current.find((line) => line.product_id === product.id);

            if (existing) {
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
                    // En una solicitud interesa lo que tiene la bodega que pide, no lo apartable.
                    available: isRequest ? product.stock : product.available,
                },
            ];
        });
    }, [isRequest]);

    // Lo precargado entra solo, una vez. En una solicitud se propone lo que le falta a la bodega
    // que pide para llegar a su mínimo; quien la arma lo puede cambiar.
    useEffect(() => {
        if (preloaded.current || preload.length === 0) {
            return;
        }

        preloaded.current = true;
        preload.forEach((product) => upsert(product, isRequest ? Math.max(1, Math.ceil(product.min_stock - product.stock)) : 1));
    }, [preload, upsert, isRequest]);

    const setQuantity = (productId: number, quantity: number) =>
        setLines((current) =>
            current.map((line) =>
                line.product_id === productId
                    ? { ...line, quantity: Number.isFinite(quantity) ? Math.max(0, quantity) : 0 }
                    : line,
            ),
        );

    const remove = (productId: number) => setLines((current) => current.filter((line) => line.product_id !== productId));

    /** Cambiar el origen de un traslado vacía la lista: las existencias ya no aplican. */
    const changeOrigin = (id: number) => {
        setFrom(id);

        if (!isRequest) {
            setLines([]);
        }

        if (id === to) {
            setTo(destinationOptions.find((item) => item.value !== id)?.value ?? 0);
        }
    };

    /** En una solicitud pasa lo mismo con la bodega que pide, que es la que muestra el catálogo. */
    const changeDestination = (id: number) => {
        setTo(id);

        if (isRequest) {
            setLines([]);
        }

        if (id === from) {
            setFrom(warehouses.find((item) => item.value !== id)?.value ?? 0);
        }
    };

    const scan = useCallback(
        (code: string) => {
            fetch(`${route(catalogRoute)}?barcode=${encodeURIComponent(code)}&warehouse=${stockWarehouse}`, {
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
        [catalogRoute, stockWarehouse, upsert],
    );

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'F1') {
                event.preventDefault();
                searchRef.current?.focus();
                searchRef.current?.select();
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const inTransfer = useMemo(() => new Set(lines.map((line) => line.product_id)), [lines]);
    const visible = useMemo(() => (onlyLow ? catalog.filter((product) => product.is_low) : catalog), [catalog, onlyLow]);

    const totals = useMemo(() => {
        const units = lines.reduce((sum, line) => sum + line.quantity, 0);
        const value = lines.reduce((sum, line) => sum + Math.round(line.quantity * line.unit_cost), 0);
        // Se avisa aquí, pero quien manda es el servidor al despachar: entre armar y despachar
        // puede entrar mercancía. En una solicitud no aplica: se pide lo que falta.
        const short = isRequest ? [] : lines.filter((line) => line.quantity > line.available);
        const empty = lines.filter((line) => line.quantity <= 0);

        return { units, value, short, empty };
    }, [lines, isRequest]);

    const ready = lines.length > 0 && from > 0 && to > 0 && from !== to && totals.empty.length === 0;
    const items = () => lines.map((line) => ({ product_id: line.product_id, quantity: line.quantity }));

    /** `draft` lo deja por despachar, `dispatch` lo saca del origen, `direct` además lo recibe. */
    const submit = (kind: 'draft' | 'dispatch' | 'direct') => {
        if (!ready) {
            return;
        }

        setSubmitting(true);

        router.post(
            route('transfers.store'),
            {
                from_warehouse_id: from,
                to_warehouse_id: to,
                note: note || null,
                dispatch: kind === 'dispatch',
                direct: kind === 'direct',
                items: items(),
            },
            {
                onError: () => toast.error('No se pudo registrar el traslado.'),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const submitRequest = () => {
        if (!ready) {
            return;
        }

        setSubmitting(true);

        router.post(
            route('transfer_requests.store'),
            { from_warehouse_id: from, to_warehouse_id: to, note: note || null, items: items() },
            {
                onError: () => toast.error('No se pudo enviar la solicitud.'),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const title = isRequest ? 'Nueva solicitud de traslado' : 'Nuevo traslado';

    const panel = (
        <>
            <div className="flex shrink-0 items-center justify-between gap-2 border-b border-[var(--line)] px-4 py-3">
                <div>
                    <p className="text-[0.8125rem] font-semibold">{isRequest ? 'Artículos que se piden' : 'Artículos del traslado'}</p>
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
                        <p className="text-[0.84375rem]">{isRequest ? 'La solicitud está vacía.' : 'El traslado está vacío.'}</p>
                        <p className="text-[0.75rem]">Busca, escanea un código o toca un artículo del catálogo.</p>
                    </div>
                ) : (
                    <ul className="flex flex-col">
                        {lines.map((line) => {
                            const short = line.quantity > line.available;

                            return (
                                <li key={line.product_id} className="border-b border-[var(--line)] px-4 py-3">
                                    <div className="flex items-start gap-2">
                                        <div className="min-w-0 flex-1">
                                            <p className="text-[0.84375rem] font-medium leading-snug">{line.name}</p>
                                            <p className="mt-0.5 text-[0.71875rem]">
                                                {isRequest ? (
                                                    <span className="text-[var(--muted)]">
                                                        Hoy tiene {formatNumber(line.available)} {UNIT_SHORT[line.unit] ?? ''}
                                                    </span>
                                                ) : short ? (
                                                    <span className="font-medium text-[var(--warn)]">
                                                        En el origen hay {formatNumber(line.available)}{' '}
                                                        {UNIT_SHORT[line.unit] ?? ''}
                                                    </span>
                                                ) : (
                                                    <span className="text-[var(--muted)]">
                                                        Disponible {formatNumber(line.available)} {UNIT_SHORT[line.unit] ?? ''}
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
                                                    line.quantity <= 0
                                                        ? 'border-[var(--danger)] text-[var(--danger)]'
                                                        : short
                                                          ? 'border-[var(--warn)] text-[var(--warn)]'
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

                                        <span className="ml-auto font-mono text-[0.875rem] font-semibold tabular">
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
                {totals.short.length > 0 && (
                    <p className="mb-3 flex items-start gap-2 rounded-control bg-[var(--warn-soft)] px-3 py-2.5 text-[0.78125rem] font-medium text-[var(--warn)]">
                        <WarningCircleIcon size={15} weight="fill" className="mt-px shrink-0" />
                        {totals.short.length === 1
                            ? 'Un artículo pide más de lo que hay en el origen. Puedes guardarlo sin despachar, pero no despacharlo.'
                            : `${totals.short.length} artículos piden más de lo que hay en el origen.`}
                    </p>
                )}

                <dl className="flex flex-col gap-1 text-[0.8125rem]">
                    <div className="flex items-center justify-between text-[var(--muted)]">
                        <dt>Unidades</dt>
                        <dd className="font-mono tabular">{formatNumber(totals.units)}</dd>
                    </div>
                    <div className="mt-1 flex items-center justify-between border-t border-[var(--line)] pt-2">
                        <dt className="text-[0.875rem] font-semibold">Valor al costo</dt>
                        <dd className="font-mono text-[1.5rem] font-semibold leading-none text-[var(--accent)] tabular">
                            {money(totals.value)}
                        </dd>
                    </div>
                </dl>

                {(['items', 'from_warehouse_id', 'to_warehouse_id', 'status', 'stock'] as const).map((key) =>
                    errors[key] ? (
                        <p key={key} className="mt-2 text-[0.78125rem] font-medium text-[var(--danger)]">
                            {errors[key]}
                        </p>
                    ) : null,
                )}

                {isRequest ? (
                    <>
                        <Button size="pos" block className="mt-3" loading={submitting} disabled={!ready} onClick={submitRequest}>
                            Enviar solicitud
                        </Button>

                        <p className="mt-2 text-[0.71875rem] text-[var(--muted)]">
                            Pedir no mueve inventario. {origin?.label ?? 'La bodega que surte'} decide cuánto manda y genera el
                            traslado; tú lo recibes cuando llegue.
                        </p>
                    </>
                ) : (
                    <>
                        {can('inventory.transfers.dispatch') && (
                            <Button
                                size="pos"
                                block
                                className="mt-3"
                                loading={submitting}
                                disabled={!ready}
                                onClick={() => submit('dispatch')}
                            >
                                Guardar y despachar
                            </Button>
                        )}

                        {sameStore && can('inventory.transfers.direct') && (
                            <Button
                                size="md"
                                block
                                variant="secondary"
                                className="mt-2"
                                disabled={!ready || submitting}
                                onClick={() => submit('direct')}
                            >
                                Trasladar directo
                            </Button>
                        )}

                        <Button
                            size="md"
                            block
                            variant="secondary"
                            className="mt-2"
                            disabled={!ready || submitting}
                            onClick={() => submit('draft')}
                        >
                            Guardar sin despachar
                        </Button>

                        <p className="mt-2 text-[0.71875rem] text-[var(--muted)]">
                            Al despachar, la mercancía queda en camino hasta que la reciban en {destination?.label ?? 'el destino'}.
                            {sameStore && can('inventory.transfers.direct')
                                ? ' El traslado directo despacha y recibe en el mismo acto.'
                                : ''}
                        </p>
                    </>
                )}
            </div>
        </>
    );

    return (
        <AppLayout title={title}>
            <div className="mb-3 flex flex-wrap items-center gap-2">
                <Link
                    href={route(isRequest ? 'transfer_requests.index' : 'transfers.index')}
                    className="inline-flex h-10 items-center gap-1.5 rounded-control border border-[var(--line)] px-3 text-[0.84375rem] font-medium text-[var(--muted)] hover:text-[var(--text)]"
                >
                    <ArrowLeftIcon size={15} />
                    Volver
                </Link>

                <h1 className="text-[1.125rem] font-semibold">{title}</h1>
            </div>

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_408px] xl:items-start">
                <div className="flex min-w-0 flex-col gap-3">
                    <div className="flex flex-wrap items-end gap-3 rounded-card border border-[var(--line)] bg-[var(--surface)] p-3">
                        {isRequest ? (
                            <>
                                <label className="flex min-w-[13.75rem] flex-1 flex-col gap-1.5">
                                    <span className="text-[0.75rem] font-medium text-[var(--muted)]">Bodega que pide</span>
                                    <select
                                        value={to}
                                        onChange={(event) => changeDestination(Number(event.target.value))}
                                        className="h-11 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.84375rem] font-medium focus:border-[var(--accent)] focus:outline-none"
                                    >
                                        {destinationOptions.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>

                                <span className="hidden self-center pb-3 text-[var(--muted)] sm:block">
                                    <ArrowLeftIcon size={18} />
                                </span>

                                <label className="flex min-w-[13.75rem] flex-1 flex-col gap-1.5">
                                    <span className="text-[0.75rem] font-medium text-[var(--muted)]">Se le pide a</span>
                                    <select
                                        value={from}
                                        onChange={(event) => changeOrigin(Number(event.target.value))}
                                        className="h-11 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.84375rem] font-medium focus:border-[var(--accent)] focus:outline-none"
                                    >
                                        {warehouses
                                            .filter((option) => option.value !== to)
                                            .map((option) => (
                                                <option key={option.value} value={option.value}>
                                                    {option.label}
                                                </option>
                                            ))}
                                    </select>
                                </label>
                            </>
                        ) : (
                            <>
                                <label className="flex min-w-[13.75rem] flex-1 flex-col gap-1.5">
                                    <span className="text-[0.75rem] font-medium text-[var(--muted)]">Sale de</span>
                                    <select
                                        value={from}
                                        onChange={(event) => changeOrigin(Number(event.target.value))}
                                        className="h-11 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.84375rem] font-medium focus:border-[var(--accent)] focus:outline-none"
                                    >
                                        {warehouses.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>

                                <span className="hidden self-center pb-3 text-[var(--muted)] sm:block">
                                    <ArrowRightIcon size={18} />
                                </span>

                                <label className="flex min-w-[13.75rem] flex-1 flex-col gap-1.5">
                                    <span className="text-[0.75rem] font-medium text-[var(--muted)]">Entra a</span>
                                    <select
                                        value={to}
                                        onChange={(event) => setTo(Number(event.target.value))}
                                        className="h-11 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.84375rem] font-medium focus:border-[var(--accent)] focus:outline-none"
                                    >
                                        {warehouses
                                            .filter((option) => option.value !== from)
                                            .map((option) => (
                                                <option key={option.value} value={option.value}>
                                                    {option.label}
                                                </option>
                                            ))}
                                    </select>
                                </label>
                            </>
                        )}

                        <label className="flex min-w-[12.5rem] flex-1 flex-col gap-1.5">
                            <span className="text-[0.75rem] font-medium text-[var(--muted)]">Nota</span>
                            <input
                                type="text"
                                value={note}
                                maxLength={160}
                                onChange={(event) => setNote(event.target.value)}
                                placeholder={isRequest ? 'Para el fin de semana' : 'Reposición del piso de venta'}
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

                    <p className="text-[0.75rem] text-[var(--muted)]">
                        Las existencias que se muestran son las de <strong className="text-[var(--text)]">{stockLabel ?? '—'}</strong>,{' '}
                        {isRequest ? 'la bodega que pide.' : 'que es de donde va a salir la mercancía.'}
                    </p>

                    {isRequest && (
                        <div className="flex flex-wrap gap-1.5">
                            {(
                                [
                                    [false, 'Todo el catálogo', counts.total],
                                    [true, 'Bajo mínimo', lowStockCount],
                                ] as const
                            ).map(([low, label, count]) => (
                                <button
                                    key={label}
                                    type="button"
                                    onClick={() => setOnlyLow(low)}
                                    className={cn(
                                        'min-h-[2.375rem] rounded-control border px-3 text-[0.78125rem] font-medium transition-colors',
                                        onlyLow === low
                                            ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]'
                                            : 'border-[var(--line)] text-[var(--muted)] hover:text-[var(--text)]',
                                    )}
                                >
                                    {label} · {formatNumber(count)}
                                </button>
                            ))}
                        </div>
                    )}

                    {visible.length === 0 ? (
                        <EmptyState
                            title={loading ? 'Buscando…' : 'No hay artículos que mostrar'}
                            description="Prueba con otra búsqueda."
                            icon={<PackageIcon size={24} />}
                        />
                    ) : (
                        <ul className="grid grid-cols-1 gap-2 pb-24 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4 xl:pb-0">
                            {visible.map((product) => {
                                const added = inTransfer.has(product.id);
                                const line = lines.find((item) => item.product_id === product.id);
                                // Se pide justo lo que no hay; solo al trasladar lo agotado no se toca.
                                const empty = !isRequest && product.available <= 0;

                                return (
                                    <li key={product.id}>
                                        <button
                                            type="button"
                                            disabled={empty}
                                            onClick={() => upsert(product)}
                                            className={cn(
                                                'flex min-h-[7.375rem] w-full flex-col justify-between rounded-card border p-3 text-left transition-colors',
                                                added
                                                    ? 'border-[var(--accent)] bg-[var(--accent-soft)]'
                                                    : 'border-[var(--line)] bg-[var(--surface)] hover:bg-[var(--surface-2)]',
                                                empty && 'cursor-not-allowed opacity-45 hover:bg-[var(--surface)]',
                                            )}
                                        >
                                            <span className="min-w-0">
                                                <span className="truncate font-mono text-[0.6875rem] text-[var(--muted)]">{product.sku}</span>
                                                <span className="mt-0.5 block text-[0.8125rem] font-medium leading-snug">{product.name}</span>
                                            </span>

                                            <span className="mt-2 block">
                                                {isRequest ? (
                                                    <span
                                                        className="block text-[0.71875rem]"
                                                        style={{
                                                            color: product.is_out
                                                                ? 'var(--danger)'
                                                                : product.is_low
                                                                  ? 'var(--warn)'
                                                                  : 'var(--muted)',
                                                        }}
                                                    >
                                                        Tiene {formatNumber(product.stock)} {UNIT_SHORT[product.unit] ?? ''} · mín{' '}
                                                        {formatNumber(product.min_stock)}
                                                    </span>
                                                ) : (
                                                    <span
                                                        className="block text-[0.71875rem]"
                                                        style={{ color: empty ? 'var(--danger)' : 'var(--muted)' }}
                                                    >
                                                        {formatNumber(product.available)} {UNIT_SHORT[product.unit] ?? ''} disponibles
                                                    </span>
                                                )}

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
                                                    ) : empty ? (
                                                        'Sin existencia aquí'
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
                                {lines.length} {lines.length === 1 ? 'artículo' : 'artículos'}
                            </span>
                            <span className="block font-mono text-[1.25rem] font-semibold text-[var(--accent)] tabular">
                                {money(totals.value)}
                            </span>
                        </span>
                        <span className="rounded-control bg-[var(--accent)] px-4 py-2.5 text-[0.84375rem] font-semibold text-[var(--accent-ink)]">
                            {isRequest ? 'Ver la solicitud' : 'Ver el traslado'}
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

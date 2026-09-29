import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { cn } from '@/lib/cn';
import { money, number } from '@/lib/format';
import type { CatalogProduct } from '@/types/purchases';

interface Axis {
    name: string | null;
    values: { id: number; value: string }[];
}

interface Matrix {
    id: number;
    name: string;
    attribute_1: Axis;
    attribute_2: Axis | null;
    rows: CatalogProduct[];
}

export interface MatrixPick {
    product: CatalogProduct;
    quantity: number;
    unit_cost: number;
}

/**
 * La matriz de compra de un artículo con subartículos: el primer valor en filas y el segundo en
 * columnas (con uno solo, una columna). Cantidad y costo por casilla, con la existencia de la
 * sede, el mínimo y lo sugerido de cada subartículo.
 *
 * Es solo pantalla: cada casilla con cantidad se vuelve un renglón normal de la orden.
 */
export function PurchaseMatrix({
    familyId,
    supplierId,
    current,
    onClose,
    onApply,
}: {
    familyId: number | null;
    supplierId: number | null;
    /** Lo que ya lleva la orden de cada subartículo. */
    current: Record<number, { quantity: number; unit_cost: number }>;
    onClose: () => void;
    onApply: (picks: MatrixPick[]) => void;
}) {
    const [matrix, setMatrix] = useState<Matrix | null>(null);
    const [cells, setCells] = useState<Record<number, { quantity: string; unit_cost: string }>>({});
    const [allCost, setAllCost] = useState('');

    useEffect(() => {
        if (familyId === null) {
            setMatrix(null);

            return;
        }

        let alive = true;
        fetch(`${route('purchases.family', familyId)}${supplierId ? `?supplier=${supplierId}` : ''}`, { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? (response.json() as Promise<Matrix>) : Promise.reject(new Error(String(response.status)))))
            .then((data) => {
                if (!alive) {
                    return;
                }

                setMatrix(data);
                setCells(
                    Object.fromEntries(
                        data.rows.map((row) => [
                            row.id,
                            {
                                quantity: current[row.id] ? String(current[row.id].quantity) : '',
                                unit_cost: String(current[row.id]?.unit_cost ?? row.unit_cost),
                            },
                        ]),
                    ),
                );
            })
            .catch(() => toast.error('No se pudo abrir la matriz.'));

        return () => {
            alive = false;
        };
        // Se lee al abrir; `current` cambia con cada toque de la orden y no debe recargarla.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [familyId, supplierId]);

    const cellFor = (v1: number, v2: number | null) => matrix?.rows.find((row) => row.value_1_id === v1 && (v2 === null || row.value_2_id === v2)) ?? null;
    const columns = matrix?.attribute_2 ? matrix.attribute_2.values : [null];

    const totals = useMemo(() => {
        const entries = Object.values(cells);

        return {
            units: entries.reduce((sum, cell) => sum + (Number(cell.quantity) || 0), 0),
            total: entries.reduce((sum, cell) => sum + Math.round((Number(cell.quantity) || 0) * (Number(cell.unit_cost) || 0)), 0),
        };
    }, [cells]);

    const set = (id: number, patch: Partial<{ quantity: string; unit_cost: string }>) => setCells((all) => ({ ...all, [id]: { ...all[id], ...patch } }));

    const suggestAll = () => {
        matrix?.rows.forEach((row) => {
            if (row.is_low) {
                set(row.id, { quantity: String(row.suggested) });
            }
        });
    };

    const apply = () => {
        if (!matrix) {
            return;
        }

        onApply(
            matrix.rows.map((row) => ({
                product: row,
                quantity: Math.max(0, Number(cells[row.id]?.quantity) || 0),
                unit_cost: Math.max(0, Number(cells[row.id]?.unit_cost) || 0),
            })),
        );
    };

    return (
        <Modal
            open={familyId !== null}
            onClose={onClose}
            title={matrix?.name ?? 'Cargando…'}
            description="Cantidad y costo por casilla. Cada casilla con cantidad es un renglón de la orden."
            size="xl"
            footer={
                <>
                    <span className="mr-auto text-[0.8125rem] text-[var(--muted)] tabular-nums">
                        {number(totals.units)} unidades · {money(totals.total)}
                    </span>
                    <Button variant="ghost" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button onClick={apply} disabled={!matrix}>
                        Pasar a la orden
                    </Button>
                </>
            }
        >
            {!matrix ? (
                <p className="py-8 text-center text-[0.875rem] text-[var(--muted)]">Cargando la matriz…</p>
            ) : (
                <>
                    <div className="mb-3 flex flex-wrap items-end gap-2">
                        <label className="flex flex-col gap-1 text-[0.75rem] font-medium">
                            Costo para todas
                            <input
                                type="number"
                                min={0}
                                value={allCost}
                                onChange={(event) => setAllCost(event.target.value)}
                                className="h-9 w-28 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2 text-right tabular-nums"
                            />
                        </label>
                        <Button
                            size="sm"
                            variant="secondary"
                            disabled={allCost === ''}
                            onClick={() => matrix.rows.forEach((row) => set(row.id, { unit_cost: allCost }))}
                        >
                            Aplicar costo a todas
                        </Button>
                        <Button size="sm" variant="secondary" onClick={suggestAll}>
                            Poner lo sugerido a las que están bajo mínimo
                        </Button>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="text-[0.8125rem]">
                            <thead>
                                <tr>
                                    <th className="px-2 py-1.5 text-left text-[0.75rem] uppercase text-[var(--muted)]">{matrix.attribute_1.name}</th>
                                    {columns.map((column) => (
                                        <th key={column?.id ?? 'single'} className="px-2 py-1.5 text-center text-[0.75rem] text-[var(--muted)]">
                                            {column?.value ?? 'Cantidad y costo'}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {matrix.attribute_1.values.map((row) => (
                                    <tr key={row.id} className="border-t border-[var(--line)]">
                                        <th className="px-2 py-2 text-left font-semibold">{row.value}</th>
                                        {columns.map((column) => {
                                            const product = cellFor(row.id, column?.id ?? null);

                                            if (!product) {
                                                return (
                                                    <td key={column?.id ?? 'single'} className="px-2 py-2 text-center text-[var(--muted)]" title="Esta combinación no existe">
                                                        —
                                                    </td>
                                                );
                                            }

                                            const cell = cells[product.id] ?? { quantity: '', unit_cost: '' };

                                            return (
                                                <td key={column?.id ?? 'single'} className="px-1.5 py-1.5 align-top">
                                                    <div
                                                        className={cn(
                                                            'flex min-w-[8.5rem] flex-col gap-1 rounded-control border p-1.5',
                                                            product.is_out ? 'border-[var(--danger)]/30' : product.is_low ? 'border-[var(--warn)]/40' : 'border-[var(--line)]',
                                                        )}
                                                    >
                                                        <input
                                                            type="number"
                                                            min={0}
                                                            value={cell.quantity}
                                                            placeholder="0"
                                                            aria-label={`Cantidad de ${product.name}`}
                                                            onChange={(event) => set(product.id, { quantity: event.target.value })}
                                                            className="h-8 rounded-chip border border-[var(--line)] bg-[var(--surface-2)] px-2 text-center font-semibold tabular-nums focus:border-[var(--accent)] focus:outline-none"
                                                        />
                                                        <input
                                                            type="number"
                                                            min={0}
                                                            value={cell.unit_cost}
                                                            aria-label={`Costo de ${product.name}`}
                                                            onChange={(event) => set(product.id, { unit_cost: event.target.value })}
                                                            className="h-7 rounded-chip border border-[var(--line)] bg-[var(--surface)] px-2 text-right text-[0.75rem] tabular-nums focus:border-[var(--accent)] focus:outline-none"
                                                        />
                                                        <span
                                                            className="text-[0.6875rem] tabular-nums"
                                                            style={{ color: product.is_out ? 'var(--danger)' : product.is_low ? 'var(--warn)' : 'var(--muted)' }}
                                                        >
                                                            Hay {number(product.stock)} · mín {number(product.min_stock)} · sug. {number(product.suggested)}
                                                        </span>
                                                    </div>
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </>
            )}
        </Modal>
    );
}

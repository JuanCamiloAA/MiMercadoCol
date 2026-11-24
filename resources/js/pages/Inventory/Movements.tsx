import { Link } from '@inertiajs/react';
import { ArrowLeftIcon } from '@phosphor-icons/react';
import { MovementReference, type MovementReferenceData } from '@/components/inventory/MovementReference';
import { ButtonLink } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Select } from '@/components/ui/Field';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { dateTime, money, number } from '@/lib/format';
import type { Paginated } from '@/types';

interface MovementRow {
    id: number;
    type: string;
    type_label: string;
    quantity: number;
    balance_after: number;
    unit_cost: number | null;
    store: string | null;
    warehouse: string | null;
    user: string | null;
    note: string | null;
    reference: MovementReferenceData | null;
    occurred_at: string;
}

interface MovementsProps {
    product: { id: number; name: string; sku: string; unit: string; min_stock: number };
    movements: Paginated<MovementRow>;
    filters: { store: string; warehouse: string };
    stores: { value: number; label: string }[];
    warehouses: { value: number; label: string }[];
}

export default function InventoryMovements({ product, movements, filters: initialFilters, stores, warehouses }: MovementsProps) {
    const { filters, update } = useFilters('inventory.movements', initialFilters, { product: product.id });

    return (
        <AppLayout title={`Kardex de ${product.name}`}>
            <PageHeader
                title={`Kardex · ${product.name}`}
                description={`${product.sku} · cada movimiento con el saldo que dejo`}
                breadcrumb={
                    <Link href={route('inventory.index')} className="hover:text-[var(--accent)]">
                        Inventario
                    </Link>
                }
                actions={
                    <ButtonLink href={route('inventory.index')} variant="secondary" icon={<ArrowLeftIcon size={16} />}>
                        Volver
                    </ButtonLink>
                }
            />

            <Card className="mb-4">
                <div className="grid max-w-xl gap-3 sm:grid-cols-2">
                    <Select
                        label="Sede"
                        options={stores}
                        placeholder="Todas las sedes"
                        value={filters.store}
                        onChange={(event) => update('store', event.target.value)}
                    />
                    <Select
                        label="Bodega"
                        options={warehouses}
                        placeholder="Todas las bodegas"
                        value={filters.warehouse}
                        onChange={(event) => update('warehouse', event.target.value)}
                        hint="El saldo de cada renglón es el de su bodega."
                    />
                </div>
            </Card>

            {movements.data.length === 0 ? (
                <EmptyState title="Sin movimientos" description="Este artículo todavía no registra entradas ni salidas." />
            ) : (
                <>
                    <Table>
                        <THead>
                            <TR>
                                <TH>Fecha</TH>
                                <TH>Tipo</TH>
                                <TH>Sede y bodega</TH>
                                <TH align="right">Cantidad</TH>
                                <TH align="right">Saldo</TH>
                                <TH align="right">Costo unitario</TH>
                                <TH>Responsable</TH>
                                <TH>Nota</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {movements.data.map((movement) => (
                                <TR key={movement.id}>
                                    <TD className="text-[0.78125rem] text-[var(--muted)]">{dateTime(movement.occurred_at)}</TD>
                                    <TD>
                                        <Badge tone={movement.quantity >= 0 ? 'accent' : 'neutral'}>{movement.type_label}</Badge>
                                        <MovementReference reference={movement.reference} />
                                    </TD>
                                    <TD className="text-[0.78125rem] text-[var(--muted)]">
                                        {movement.store ?? '—'}
                                        {movement.warehouse ? (
                                            <span className="block text-[0.6875rem]">{movement.warehouse}</span>
                                        ) : null}
                                    </TD>
                                    <TD
                                        align="right"
                                        className="font-mono text-[0.8125rem] tabular"
                                        style={{ color: movement.quantity >= 0 ? 'var(--accent)' : 'var(--danger)' }}
                                    >
                                        {movement.quantity > 0 ? '+' : ''}
                                        {number(movement.quantity)}
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] font-semibold tabular">
                                        {number(movement.balance_after)}
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.78125rem] text-[var(--muted)] tabular">
                                        {movement.unit_cost !== null ? money(movement.unit_cost) : '—'}
                                    </TD>
                                    <TD className="text-[0.78125rem] text-[var(--muted)]">{movement.user ?? '—'}</TD>
                                    <TD className="text-[0.78125rem] text-[var(--muted)]">{movement.note ?? '—'}</TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>

                    <Pagination meta={movements} />
                </>
            )}
        </AppLayout>
    );
}

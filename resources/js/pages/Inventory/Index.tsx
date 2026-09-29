import { Link, useForm } from '@inertiajs/react';
import {
    ArrowLineDownIcon,
    ArrowLineUpIcon,
    ArrowsLeftRightIcon,
    ClockIcon,
    MapPinIcon,
    ReceiptIcon,
    SlidersIcon,
    StackIcon,
    WarningIcon,
} from '@phosphor-icons/react';
import { type FormEvent, useState } from 'react';
import { toast } from 'sonner';
import { MovementReference, type MovementReferenceData } from '@/components/inventory/MovementReference';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader, StatCard } from '@/components/ui/Card';
import { Input, Select, Textarea } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { Badge, PageHeader, SearchInput, StockBar, Tabs } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import { cn } from '@/lib/cn';
import AppLayout from '@/layouts/AppLayout';
import { date, dateTime, money, number, UNIT_SHORT } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { Paginated } from '@/types';
import type { WarehouseOption } from '@/types/inventory';

interface StockRow {
    id: number;
    product_id: number;
    name: string;
    sku: string;
    unit: string;
    category: string | null;
    quantity: number;
    reserved_quantity: number;
    min_stock: number;
    suggested_order: number;
    location: string | null;
    /** En cuantas bodegas de la sede esta repartido, cuando se ven todas. */
    warehouse_count: number;
    value: number;
}

interface MovementRow {
    id: number;
    product: string | null;
    product_id: number;
    sku: string | null;
    type: string;
    type_label: string;
    quantity: number;
    balance_after: number;
    warehouse: string | null;
    user: string | null;
    note: string | null;
    reference: MovementReferenceData | null;
    occurred_at: string;
}

interface ExpiringLot {
    id: number;
    product: string | null;
    product_id: number;
    sku: string | null;
    unit: string | null;
    code: string | null;
    warehouse: string | null;
    quantity: number;
    expires_at: string | null;
    days_left: number | null;
}

/** Una fila del panel «donde esta este articulo». */
interface StockWhere {
    warehouse_id: number;
    warehouse: string;
    store_id: number;
    store: string | null;
    quantity: number;
    reserved: number;
    available: number;
    location: string | null;
    is_pos_source: boolean;
    is_online_source: boolean;
    is_mine: boolean;
}

interface InventoryIndexProps {
    stocks: Paginated<StockRow>;
    filters: { search: string; category: string; state: string; warehouse: string; family: string };
    categories: { value: number; label: string }[];
    families: { value: number; label: string }[];
    stores: { value: number; label: string }[];
    warehouses: WarehouseOption[];
    metrics: { items: number; value: number; low: number; out: number };
    recentMovements: MovementRow[];
    expiringLots: ExpiringLot[];
}

export default function InventoryIndex({
    stocks,
    filters: initialFilters,
    categories,
    families,
    stores,
    warehouses,
    metrics,
    recentMovements,
    expiringLots,
}: InventoryIndexProps) {
    const { can } = usePermission();
    const { filters, update, reset, hasActive } = useFilters('inventory.index', initialFilters);
    const [tab, setTab] = useState<'stock' | 'movements' | 'lots'>('stock');
    const [modal, setModal] = useState<'adjust' | null>(null);
    const [target, setTarget] = useState<StockRow | null>(null);
    const [where, setWhere] = useState<{ row: StockRow; rows: StockWhere[]; total: number; sees_all: boolean } | null>(null);
    const [loadingWhere, setLoadingWhere] = useState(false);

    const adjustForm = useForm({ product_id: 0, warehouse_id: 0, type: 'adjustment', quantity: 0, note: '' });

    const openAdjust = (row: StockRow) => {
        adjustForm.setData({
            product_id: row.product_id,
            // Con una bodega sola no hay nada que preguntar; con varias, la que filtra la vista.
            warehouse_id: Number(filters.warehouse) || warehouses[0]?.value || 0,
            type: 'adjustment',
            quantity: 0,
            note: '',
        });
        adjustForm.clearErrors();
        setTarget(row);
        setModal('adjust');
    };

    /** Abre el panel con lo que hay de este articulo en cada bodega de la empresa. */
    const openWhere = (row: StockRow) => {
        setLoadingWhere(true);
        setWhere({ row, rows: [], total: 0, sees_all: false });

        fetch(route('inventory.stock', row.product_id), { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : null))
            .then((body: { rows: StockWhere[]; total: number; sees_all: boolean } | null) => {
                if (body) {
                    setWhere({ row, rows: body.rows, total: body.total, sees_all: body.sees_all });

                    return;
                }

                setWhere(null);
                toast.error('No se pudo consultar dónde está el artículo.');
            })
            .catch(() => {
                setWhere(null);
                toast.error('No se pudo consultar dónde está el artículo.');
            })
            .finally(() => setLoadingWhere(false));
    };

    const submitAdjust = (event: FormEvent) => {
        event.preventDefault();
        adjustForm.post(route('inventory.adjust'), { preserveScroll: true, onSuccess: () => setModal(null) });
    };

    return (
        <AppLayout title="Inventario">
            <PageHeader
                title="Inventario"
                description="Existencias de la sede activa, con su valor al costo."
                actions={
                    <>
                        {can('inventory.index.adjust') && (
                            <>
                                <ButtonLink
                                    href={route('inventory.entry.create')}
                                    variant="secondary"
                                    icon={<ArrowLineDownIcon size={16} />}
                                >
                                    Registrar entrada
                                </ButtonLink>
                                <ButtonLink
                                    href={route('inventory.exit.create')}
                                    variant="secondary"
                                    icon={<ArrowLineUpIcon size={16} />}
                                >
                                    Registrar salida
                                </ButtonLink>
                            </>
                        )}
                        {can('purchases.index.create') && (
                            <ButtonLink href={route('purchases.create')} icon={<ReceiptIcon size={16} />}>
                                Generar orden de compra
                            </ButtonLink>
                        )}
                    </>
                }
            />

            <div className="mb-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Referencias" value={number(metrics.items)} icon={<StackIcon size={16} weight="bold" />} tone="neutral" />
                <StatCard label="Valor al costo" value={money(metrics.value)} />
                <StatCard
                    label="Bajo el minimo"
                    value={number(metrics.low)}
                    tone={metrics.low > 0 ? 'warn' : 'neutral'}
                    icon={<WarningIcon size={16} weight="bold" />}
                />
                <StatCard label="Agotados" value={number(metrics.out)} tone={metrics.out > 0 ? 'danger' : 'neutral'} />
            </div>

            <Tabs
                className="mb-4"
                tabs={[
                    { value: 'stock', label: 'Existencias', count: stocks.total },
                    { value: 'movements', label: 'Movimientos recientes', count: recentMovements.length },
                    { value: 'lots', label: 'Por vencer', count: expiringLots.length },
                ]}
                active={tab}
                onChange={setTab}
            />

            {tab === 'stock' ? (
                <>
                    <Card className="mb-4">
                        <div className={cn('grid gap-3', warehouses.length > 1 ? 'sm:grid-cols-4' : 'sm:grid-cols-3')}>
                            {warehouses.length > 1 && (
                                <Select
                                    options={warehouses.map((warehouse) => ({ value: warehouse.value, label: warehouse.label }))}
                                    placeholder="Todas las bodegas"
                                    value={filters.warehouse}
                                    onChange={(event) => update('warehouse', event.target.value)}
                                />
                            )}
                            <SearchInput
                                placeholder="Nombre, SKU o codigo"
                                value={filters.search}
                                onChange={(event) => update('search', event.target.value)}
                            />
                            <Select
                                options={categories}
                                placeholder="Todas las categorias"
                                value={filters.category}
                                onChange={(event) => update('category', event.target.value)}
                            />
                            {families.length > 0 && (
                                <Select
                                    options={families}
                                    placeholder="Cualquier artículo principal"
                                    value={filters.family}
                                    onChange={(event) => update('family', event.target.value)}
                                />
                            )}
                            <div className="flex items-start gap-2">
                                <div className="flex-1">
                                    <Select
                                        options={[
                                            { value: 'low', label: 'Bajo el minimo' },
                                            { value: 'out', label: 'Agotados' },
                                            { value: 'over', label: 'Con sobrestock' },
                                        ]}
                                        placeholder="Cualquier estado"
                                        value={filters.state}
                                        onChange={(event) => update('state', event.target.value)}
                                    />
                                </div>
                                {hasActive && (
                                    <Button variant="ghost" onClick={reset}>
                                        Limpiar
                                    </Button>
                                )}
                            </div>
                        </div>
                    </Card>

                    {stocks.data.length === 0 ? (
                        <EmptyState title="Ninguna existencia coincide" description="Cambia los filtros para ver otros articulos." />
                    ) : (
                        <>
                            <Table>
                                <THead>
                                    <TR>
                                        <TH>Articulo</TH>
                                        <TH>Categoria</TH>
                                        <TH>Existencia</TH>
                                        <TH align="right">Reservado</TH>
                                        <TH align="right">Sugerido a pedir</TH>
                                        <TH align="right">Valor</TH>
                                        <TH align="right">Acciones</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {stocks.data.map((row) => (
                                        <TR key={row.id}>
                                            <TD>
                                                {can('products.show.view') ? (
                                                    <Link
                                                        href={route('products.show', row.product_id)}
                                                        className="text-[0.84375rem] font-medium text-[var(--text)] hover:text-[var(--accent)]"
                                                    >
                                                        {row.name}
                                                    </Link>
                                                ) : (
                                                    <span className="text-[0.84375rem] font-medium text-[var(--text)]">{row.name}</span>
                                                )}
                                                <p className="font-mono text-[0.71875rem] text-[var(--muted)]">
                                                    {row.sku}
                                                    {row.location ? ` · ${row.location}` : ''}
                                                    {!filters.warehouse && row.warehouse_count > 1
                                                        ? ` · en ${row.warehouse_count} bodegas`
                                                        : ''}
                                                </p>
                                            </TD>
                                            <TD className="text-[0.78125rem] text-[var(--muted)]">{row.category ?? '—'}</TD>
                                            <TD>
                                                <StockBar quantity={row.quantity} minStock={row.min_stock} />
                                                <span className="mt-0.5 block text-[0.6875rem] text-[var(--muted)]">
                                                    min {row.min_stock} {UNIT_SHORT[row.unit] ?? ''}
                                                </span>
                                            </TD>
                                            <TD align="right" className="font-mono text-[0.8125rem] text-[var(--muted)] tabular">
                                                {number(row.reserved_quantity)}
                                            </TD>
                                            <TD align="right">
                                                {row.suggested_order > 0 ? (
                                                    <Badge tone="warn">{number(row.suggested_order)}</Badge>
                                                ) : (
                                                    <span className="text-[var(--muted)]">—</span>
                                                )}
                                            </TD>
                                            <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                                {money(row.value)}
                                            </TD>
                                            <TD align="right">
                                                <div className="flex items-center justify-end gap-1">
                                                    {can('inventory.movements.view') && (
                                                        <ButtonLink
                                                            href={route('inventory.movements', row.product_id)}
                                                            size="sm"
                                                            variant="ghost"
                                                        >
                                                            Kardex
                                                        </ButtonLink>
                                                    )}
                                                    {can('inventory.index.adjust') && (
                                                        <Button size="sm" variant="ghost" icon={<SlidersIcon size={15} />} onClick={() => openAdjust(row)} />
                                                    )}
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        icon={<MapPinIcon size={15} />}
                                                        onClick={() => openWhere(row)}
                                                        aria-label={`Dónde está ${row.name}`}
                                                    />
                                                    {can('inventory.transfers.create') && (
                                                        <ButtonLink
                                                            href={route('transfers.create', { product: row.product_id })}
                                                            size="sm"
                                                            variant="ghost"
                                                            icon={<ArrowsLeftRightIcon size={15} />}
                                                            aria-label={`Trasladar ${row.name} a otra bodega`}
                                                        />
                                                    )}
                                                </div>
                                            </TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>

                            <Pagination meta={stocks} />
                        </>
                    )}
                </>
            ) : tab === 'lots' ? (
                <Card padded={false}>
                    <div className="px-5 pt-5">
                        <CardHeader
                            title="Lotes por vencer"
                            description="Los proximos 20 dias. Las ventas descuentan primero el lote que vence antes."
                        />
                    </div>

                    {expiringLots.length === 0 ? (
                        <EmptyState
                            title="Nada por vencer"
                            description="Ningun lote de esta sede vence en los proximos 20 dias."
                            icon={<ClockIcon size={24} />}
                        />
                    ) : (
                        <Table className="rounded-none border-x-0 border-b-0">
                            <THead>
                                <TR>
                                    <TH>Articulo</TH>
                                    <TH>Lote</TH>
                                    <TH align="right">Cantidad</TH>
                                    <TH>Vence</TH>
                                    <TH align="center">Dias</TH>
                                    <TH align="right">Acciones</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {expiringLots.map((lot) => (
                                    <TR key={lot.id}>
                                        <TD>
                                            <p className="text-[0.84375rem] font-medium">{lot.product}</p>
                                            <p className="font-mono text-[0.71875rem] text-[var(--muted)]">{lot.sku}</p>
                                        </TD>
                                        <TD className="text-[0.78125rem] text-[var(--muted)]">
                                            <span className="font-mono">{lot.code ?? '—'}</span>
                                            {lot.warehouse ? <span className="block text-[0.6875rem]">{lot.warehouse}</span> : null}
                                        </TD>
                                        <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                            {number(lot.quantity)} {UNIT_SHORT[lot.unit ?? 'unit'] ?? ''}
                                        </TD>
                                        <TD className="text-[0.78125rem] text-[var(--muted)]">{lot.expires_at ? date(lot.expires_at) : '—'}</TD>
                                        <TD align="center">
                                            {lot.days_left === null ? (
                                                <span className="text-[var(--muted)]">—</span>
                                            ) : lot.days_left < 0 ? (
                                                <Badge tone="danger">vencido</Badge>
                                            ) : lot.days_left <= 7 ? (
                                                <Badge tone="danger">{lot.days_left} dias</Badge>
                                            ) : (
                                                <Badge tone="warn">{lot.days_left} dias</Badge>
                                            )}
                                        </TD>
                                        <TD align="right">
                                            {can('inventory.movements.view') && (
                                                <ButtonLink href={route('inventory.movements', lot.product_id)} size="sm" variant="ghost">
                                                    Kardex
                                                </ButtonLink>
                                            )}
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    )}
                </Card>
            ) : (
                <Card padded={false}>
                    <div className="px-5 pt-5">
                        <CardHeader title="Movimientos recientes" description="Ultimos 15 movimientos registrados en esta sede." />
                    </div>
                    <Table className="rounded-none border-x-0 border-b-0">
                        <THead>
                            <TR>
                                <TH>Fecha</TH>
                                <TH>Articulo</TH>
                                <TH>Tipo</TH>
                                <TH align="right">Cantidad</TH>
                                <TH align="right">Saldo</TH>
                                <TH>Responsable</TH>
                                <TH>Nota</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {recentMovements.length === 0 && (
                                <TR>
                                    <TD colSpan={7} className="py-6 text-center text-[0.8125rem] text-[var(--muted)]">
                                        Sin movimientos en esta sede.
                                    </TD>
                                </TR>
                            )}
                            {recentMovements.map((movement) => (
                                <TR key={movement.id}>
                                    <TD className="text-[0.78125rem] text-[var(--muted)]">{dateTime(movement.occurred_at)}</TD>
                                    <TD>
                                        <p className="text-[0.8125rem] font-medium">{movement.product}</p>
                                        <p className="font-mono text-[0.71875rem] text-[var(--muted)]">{movement.sku}</p>
                                    </TD>
                                    <TD>
                                        <Badge tone={movement.quantity >= 0 ? 'accent' : 'neutral'}>{movement.type_label}</Badge>
                                        <MovementReference reference={movement.reference} />
                                        {movement.warehouse ? (
                                            <span className="mt-0.5 block text-[0.6875rem] text-[var(--muted)]">{movement.warehouse}</span>
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
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                        {number(movement.balance_after)}
                                    </TD>
                                    <TD className="text-[0.78125rem] text-[var(--muted)]">{movement.user ?? '—'}</TD>
                                    <TD className="text-[0.78125rem] text-[var(--muted)]">{movement.note ?? '—'}</TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </Card>
            )}

            <Modal
                open={modal === 'adjust'}
                onClose={() => setModal(null)}
                title="Registrar un ajuste"
                description={target?.name}
                size="sm"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setModal(null)}>
                            Cancelar
                        </Button>
                        <Button onClick={submitAdjust} loading={adjustForm.processing}>
                            Registrar
                        </Button>
                    </>
                }
            >
                <form onSubmit={submitAdjust} className="flex flex-col gap-4">
                    {warehouses.length > 1 && (
                        <Select
                            label="Bodega"
                            options={warehouses.map((warehouse) => ({ value: warehouse.value, label: warehouse.label }))}
                            value={adjustForm.data.warehouse_id}
                            onChange={(event) => adjustForm.setData('warehouse_id', Number(event.target.value))}
                            error={adjustForm.errors.warehouse_id}
                        />
                    )}
                    <Select
                        label="Tipo de movimiento"
                        options={[
                            { value: 'adjustment', label: 'Ajuste de inventario' },
                            { value: 'waste', label: 'Averia o perdida' },
                        ]}
                        value={adjustForm.data.type}
                        onChange={(event) => adjustForm.setData('type', event.target.value)}
                        error={adjustForm.errors.type}
                    />
                    <Input
                        label="Cantidad"
                        type="number"
                        step="0.001"
                        required
                        value={adjustForm.data.quantity}
                        onChange={(event) => adjustForm.setData('quantity', Number(event.target.value))}
                        error={adjustForm.errors.quantity}
                        hint={
                            adjustForm.data.type === 'waste'
                                ? 'La averia siempre resta, sin importar el signo.'
                                : 'Positiva para sumar, negativa para restar.'
                        }
                    />
                    <Textarea
                        label="Motivo"
                        required
                        value={adjustForm.data.note}
                        onChange={(event) => adjustForm.setData('note', event.target.value)}
                        error={adjustForm.errors.note}
                        placeholder="Conteo fisico, producto vencido, rotura..."
                    />
                    {target && (
                        <p className="rounded-control bg-[var(--surface-2)] px-3 py-2 text-[0.78125rem] text-[var(--muted)]">
                            Existencia actual: <span className="font-mono tabular">{number(target.quantity)}</span> ·
                            {' '}Quedaria en{' '}
                            <span className="font-mono tabular">
                                {number(target.quantity + (adjustForm.data.type === 'waste' ? -Math.abs(adjustForm.data.quantity) : adjustForm.data.quantity))}
                            </span>
                        </p>
                    )}
                </form>
            </Modal>


            <Modal
                open={where !== null}
                onClose={() => setWhere(null)}
                title="Dónde está este artículo"
                description={where?.row.name}
            >
                {loadingWhere ? (
                    <p className="py-6 text-center text-[0.8125rem] text-[var(--muted)]">Consultando…</p>
                ) : (
                    <div className="flex flex-col gap-3">
                        <p className="text-[0.78125rem] text-[var(--muted)]">
                            En total hay{' '}
                            <strong className="text-[var(--text)]">
                                {number(where?.total ?? 0)} {UNIT_SHORT[where?.row.unit ?? 'unit'] ?? ''}
                            </strong>{' '}
                            {where?.sees_all ? 'en toda la empresa.' : 'en las sedes a las que tienes acceso.'}
                        </p>

                        <Table>
                            <THead>
                                <TR>
                                    <TH>Bodega</TH>
                                    <TH align="right">Hay</TH>
                                    <TH align="right">Apartado</TH>
                                    <TH align="right" />
                                </TR>
                            </THead>
                            <TBody>
                                {(where?.rows ?? []).map((item) => (
                                    <TR key={item.warehouse_id}>
                                        <TD>
                                            <p className="text-[0.8125rem] font-medium">{item.warehouse}</p>
                                            <p className="text-[0.71875rem] text-[var(--muted)]">
                                                {item.store}
                                                {item.is_pos_source ? ' · vende en caja' : ''}
                                                {item.is_online_source ? ' · vende en línea' : ''}
                                                {item.location ? ` · ${item.location}` : ''}
                                            </p>
                                        </TD>
                                        <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                            {number(item.quantity)}
                                        </TD>
                                        <TD align="right" className="font-mono text-[0.78125rem] text-[var(--muted)] tabular">
                                            {item.reserved > 0 ? number(item.reserved) : '—'}
                                        </TD>
                                        <TD align="right">
                                            {can('inventory.transfers.create') && item.is_mine && item.available > 0 && (
                                                <ButtonLink
                                                    href={route('transfers.create', {
                                                        product: where?.row.product_id,
                                                        from: item.warehouse_id,
                                                    })}
                                                    size="sm"
                                                    variant="ghost"
                                                >
                                                    Pedir traslado
                                                </ButtonLink>
                                            )}
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>

                        {!where?.sees_all && (
                            <p className="text-[0.71875rem] text-[var(--muted)]">
                                Solo ves las sedes a las que estás asignado.
                            </p>
                        )}
                    </div>
                )}
            </Modal>
        </AppLayout>
    );
}

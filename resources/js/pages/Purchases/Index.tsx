import { Link } from '@inertiajs/react';
import { PlusIcon, ReceiptIcon } from '@phosphor-icons/react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, StatCard } from '@/components/ui/Card';
import { Select } from '@/components/ui/Field';
import { Badge, PageHeader, SearchInput } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { date, money, number } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { BadgeTone } from '@/components/ui/Misc';
import type { Paginated } from '@/types';

interface PurchaseRow {
    id: number;
    code: string;
    status: string;
    status_label: string;
    supplier: string | null;
    store: string | null;
    user: string | null;
    expected_at: string | null;
    received_at: string | null;
    payment_term: string | null;
    due_at: string | null;
    is_overdue: boolean;
    items_count: number;
    total: number;
    created_at: string;
}

interface PurchasesIndexProps {
    orders: Paginated<PurchaseRow>;
    filters: { search: string; status: string; supplier: string };
    suppliers: { value: number; label: string }[];
    metrics: { draft: number; sent: number; received_month: number };
}

const STATUS_TONES: Record<string, BadgeTone> = {
    draft: 'neutral',
    sent: 'info',
    received: 'accent',
    cancelled: 'danger',
};

export default function PurchasesIndex({ orders, filters: initialFilters, suppliers, metrics }: PurchasesIndexProps) {
    const { can } = usePermission();
    const { filters, update, reset, hasActive } = useFilters('purchases.index', initialFilters);

    return (
        <AppLayout title="Compras">
            <PageHeader
                title="Órdenes de compra"
                description="Lo que se le pidió a cada proveedor y lo que ya llegó."
                actions={
                    can('purchases.index.create') ? (
                        <ButtonLink href={route('purchases.create')} icon={<PlusIcon size={16} weight="bold" />}>
                            Nueva orden
                        </ButtonLink>
                    ) : undefined
                }
            />

            <div className="mb-4 grid gap-4 sm:grid-cols-3">
                <StatCard label="En borrador" value={number(metrics.draft)} tone="neutral" />
                <StatCard label="Enviadas" value={number(metrics.sent)} tone="warn" />
                <StatCard label="Recibido este mes" value={money(metrics.received_month)} icon={<ReceiptIcon size={16} weight="bold" />} />
            </div>

            <Card className="mb-4">
                <div className="grid gap-3 sm:grid-cols-3">
                    <SearchInput placeholder="Código de la orden" value={filters.search} onChange={(event) => update('search', event.target.value)} />
                    <Select options={suppliers} placeholder="Todos los proveedores" value={filters.supplier} onChange={(event) => update('supplier', event.target.value)} />
                    <div className="flex items-start gap-2">
                        <div className="flex-1">
                            <Select
                                options={[
                                    { value: 'draft', label: 'Borrador' },
                                    { value: 'sent', label: 'Enviada' },
                                    { value: 'received', label: 'Recibida' },
                                    { value: 'cancelled', label: 'Anulada' },
                                ]}
                                placeholder="Cualquier estado"
                                value={filters.status}
                                onChange={(event) => update('status', event.target.value)}
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

            {orders.data.length === 0 ? (
                <EmptyState
                    title="Ninguna orden coincide"
                    description="Crea una orden nueva con los artículos bajo el mínimo."
                    icon={<ReceiptIcon size={24} />}
                    action={
                        can('purchases.index.create') ? (
                            <ButtonLink href={route('purchases.create')} icon={<PlusIcon size={16} weight="bold" />}>
                                Nueva orden
                            </ButtonLink>
                        ) : undefined
                    }
                />
            ) : (
                <>
                    <Table>
                        <THead>
                            <TR>
                                <TH>Orden</TH>
                                <TH>Proveedor</TH>
                                <TH>Sede</TH>
                                <TH align="center">Artículos</TH>
                                <TH>Entrega esperada</TH>
                                <TH>Vence</TH>
                                <TH align="right">Total</TH>
                                <TH align="center">Estado</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {orders.data.map((order) => (
                                <TR key={order.id}>
                                    <TD>
                                        <Link
                                            href={route('purchases.show', order.id)}
                                            className="font-mono text-[0.84375rem] font-medium text-[var(--text)] hover:text-[var(--accent)]"
                                        >
                                            {order.code}
                                        </Link>
                                        <p className="text-[0.71875rem] text-[var(--muted)]">{date(order.created_at)}</p>
                                    </TD>
                                    <TD className="text-[0.8125rem]">{order.supplier ?? '—'}</TD>
                                    <TD className="text-[0.78125rem] text-[var(--muted)]">{order.store ?? '—'}</TD>
                                    <TD align="center" className="font-mono text-[0.8125rem] tabular">
                                        {order.items_count}
                                    </TD>
                                    <TD className="text-[0.78125rem] text-[var(--muted)]">{order.expected_at ? date(order.expected_at) : '—'}</TD>
                                    <TD className="text-[0.78125rem]">
                                        {order.due_at ? (
                                            <span className={order.is_overdue ? 'font-semibold text-[var(--danger)]' : 'text-[var(--text)]'}>{date(order.due_at)}</span>
                                        ) : (
                                            <span className="text-[var(--muted)]">{order.payment_term ?? '—'}</span>
                                        )}
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                        {money(order.total)}
                                    </TD>
                                    <TD align="center">
                                        <Badge tone={STATUS_TONES[order.status] ?? 'neutral'}>{order.status_label}</Badge>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>

                    <Pagination meta={orders} />
                </>
            )}
        </AppLayout>
    );
}

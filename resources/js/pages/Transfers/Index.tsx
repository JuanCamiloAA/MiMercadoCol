import { router } from '@inertiajs/react';
import { ArrowsClockwiseIcon, ArrowsLeftRightIcon, PlusIcon, TrayArrowDownIcon, TruckIcon } from '@phosphor-icons/react';
import { ButtonLink } from '@/components/ui/Button';
import { Button } from '@/components/ui/Button';
import { Card, StatCard } from '@/components/ui/Card';
import { Input, Select } from '@/components/ui/Field';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { dateTime, money, number } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { Paginated } from '@/types';
import type { TransferRow, TransferStatus, TransferWarehouse } from '@/types/transfers';

interface TransfersIndexProps {
    transfers: Paginated<TransferRow>;
    filters: { status: string; warehouse: string; from: string; to: string };
    warehouses: TransferWarehouse[];
    statuses: { value: string; label: string }[];
    inTransit: { count: number };
    openRequests: number;
}

const TONES: Record<TransferStatus, 'neutral' | 'warn' | 'accent' | 'danger'> = {
    draft: 'neutral',
    in_transit: 'warn',
    received: 'accent',
    cancelled: 'danger',
};

/**
 * Traslados entre bodegas.
 *
 * Lo que está «en camino» no cuenta en ninguna bodega: por eso tiene su propia tarjeta arriba.
 * Un traslado que se queda en camino es mercancía que nadie va a encontrar buscándola en el
 * inventario de ninguna de las dos sedes.
 */
export default function TransfersIndex({
    transfers,
    filters: initialFilters,
    warehouses,
    statuses,
    inTransit,
    openRequests,
}: TransfersIndexProps) {
    const { can } = usePermission();
    const { filters, update, reset, hasActive } = useFilters('transfers.index', initialFilters);

    return (
        <AppLayout title="Traslados">
            <PageHeader
                title="Traslados"
                description="Mercancía que se mueve de una bodega a otra, con documento."
                actions={
                    <>
                        {can('inventory.transfer_requests.view') && (
                            <ButtonLink href={route('transfer_requests.index')} variant="secondary" icon={<TrayArrowDownIcon size={16} />}>
                                Solicitudes{openRequests > 0 ? ` · ${number(openRequests)} abiertas` : ''}
                            </ButtonLink>
                        )}
                        {can('inventory.transfers.create') && (
                            <>
                                <Button
                                    variant="secondary"
                                    icon={<ArrowsClockwiseIcon size={16} />}
                                    onClick={() => router.post(route('transfers.replenish'))}
                                >
                                    Sugerir reposición
                                </Button>
                                <ButtonLink href={route('transfers.create')} icon={<PlusIcon size={16} />}>
                                    Nuevo traslado
                                </ButtonLink>
                            </>
                        )}
                    </>
                }
            />

            {inTransit.count > 0 && (
                <div className="mb-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="En camino"
                        value={number(inTransit.count)}
                        tone="warn"
                        icon={<TruckIcon size={16} weight="bold" />}
                    />
                </div>
            )}

            <Card className="mb-4">
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <Select
                        options={statuses}
                        placeholder="Cualquier estado"
                        value={filters.status}
                        onChange={(event) => update('status', event.target.value)}
                    />
                    <Select
                        options={warehouses.map((warehouse) => ({ value: warehouse.value, label: warehouse.label }))}
                        placeholder="Cualquier bodega"
                        value={filters.warehouse}
                        onChange={(event) => update('warehouse', event.target.value)}
                    />
                    <Input type="date" value={filters.from} onChange={(event) => update('from', event.target.value)} />
                    <Input type="date" value={filters.to} onChange={(event) => update('to', event.target.value)} />
                    {hasActive && (
                        <Button variant="ghost" onClick={reset}>
                            Limpiar
                        </Button>
                    )}
                </div>
            </Card>

            {transfers.data.length === 0 ? (
                <EmptyState
                    title="Todavía no hay traslados"
                    description="Cuando muevas mercancía de una bodega a otra, quedará aquí con su documento."
                    icon={<ArrowsLeftRightIcon size={24} />}
                    action={
                        can('inventory.transfers.create') ? (
                            <ButtonLink href={route('transfers.create')}>Crear el primero</ButtonLink>
                        ) : undefined
                    }
                />
            ) : (
                <Card padded={false}>
                    <Table>
                        <THead>
                            <TR>
                                <TH>Documento</TH>
                                <TH>Ruta</TH>
                                <TH>Estado</TH>
                                <TH align="right">Artículos</TH>
                                <TH align="right">Unidades</TH>
                                <TH align="right">Valor</TH>
                                <TH align="right" />
                            </TR>
                        </THead>
                        <TBody>
                            {transfers.data.map((transfer) => (
                                <TR key={transfer.id}>
                                    <TD>
                                        <p className="font-mono text-[0.8125rem] font-medium">{transfer.number}</p>
                                        {transfer.request_number && (
                                            <p className="text-[0.6875rem] text-[var(--muted)]">
                                                de <span className="font-mono">{transfer.request_number}</span>
                                            </p>
                                        )}
                                        <p className="text-[0.71875rem] text-[var(--muted)]">
                                            {transfer.created_at ? dateTime(transfer.created_at) : '—'}
                                        </p>
                                    </TD>
                                    <TD className="text-[0.78125rem]">
                                        <span className="text-[var(--text)]">{transfer.origin ?? '—'}</span>
                                        <ArrowsLeftRightIcon size={12} className="mx-1.5 inline text-[var(--muted)]" />
                                        <span className="text-[var(--text)]">{transfer.destination ?? '—'}</span>
                                        {!transfer.is_internal && (
                                            <span className="ml-1.5 text-[0.6875rem] text-[var(--muted)]">entre sedes</span>
                                        )}
                                    </TD>
                                    <TD>
                                        <Badge tone={TONES[transfer.status]}>{transfer.status_label}</Badge>
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                        {number(transfer.items)}
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                        {number(transfer.units)}
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                        {money(transfer.value)}
                                    </TD>
                                    <TD align="right">
                                        <ButtonLink href={route('transfers.show', transfer.id)} size="sm" variant="ghost">
                                            Ver
                                        </ButtonLink>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                    <Pagination meta={transfers} />
                </Card>
            )}
        </AppLayout>
    );
}

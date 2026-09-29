import { ArrowLeftIcon, PlusIcon, TrayArrowDownIcon } from '@phosphor-icons/react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input, Select } from '@/components/ui/Field';
import { Badge, PageHeader, Tabs } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { dateTime, number } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { Paginated } from '@/types';
import type { TransferRequestRow, TransferRequestStatus, TransferWarehouse } from '@/types/transfers';

type Side = '' | 'incoming' | 'outgoing';

interface TransferRequestsIndexProps {
    requests: Paginated<TransferRequestRow>;
    filters: { side: string; status: string; warehouse: string; from: string; to: string };
    warehouses: TransferWarehouse[];
    statuses: { value: string; label: string }[];
    counts: { incoming: number; outgoing: number };
}

const TONES: Record<TransferRequestStatus, 'neutral' | 'warn' | 'accent' | 'danger'> = {
    pending: 'warn',
    partial: 'warn',
    fulfilled: 'accent',
    rejected: 'danger',
    closed: 'neutral',
    cancelled: 'neutral',
};

/**
 * Solicitudes de traslado.
 *
 * Se parte en dos pestañas porque cada lado tiene un trabajo distinto: lo que **me piden** hay
 * que atenderlo, lo que **pido** hay que esperarlo y recibirlo. Mezclados, la bodega que surte
 * no distingue su pendiente del ajeno.
 */
export default function TransferRequestsIndex({
    requests,
    filters: initialFilters,
    warehouses,
    statuses,
    counts,
}: TransferRequestsIndexProps) {
    const { can } = usePermission();
    const { filters, update, reset, hasActive } = useFilters('transfer_requests.index', initialFilters);

    return (
        <AppLayout title="Solicitudes de traslado">
            <PageHeader
                title="Solicitudes de traslado"
                description="Lo que una bodega le pide a otra. Pedir no mueve inventario: lo mueve el traslado que se genera al atenderla."
                breadcrumb={
                    can('inventory.transfers.view') ? (
                        <ButtonLink href={route('transfers.index')} size="sm" variant="ghost" icon={<ArrowLeftIcon size={14} />}>
                            Traslados
                        </ButtonLink>
                    ) : undefined
                }
                actions={
                    can('inventory.transfer_requests.create') ? (
                        <ButtonLink href={route('transfer_requests.create')} icon={<PlusIcon size={16} />}>
                            Nueva solicitud
                        </ButtonLink>
                    ) : undefined
                }
            />

            <Tabs<Side>
                className="mb-4"
                active={(filters.side as Side) ?? ''}
                onChange={(value) => update('side', value)}
                tabs={[
                    { value: '', label: 'Todas' },
                    { value: 'incoming', label: 'Me piden', count: counts.incoming },
                    { value: 'outgoing', label: 'Pido', count: counts.outgoing },
                ]}
            />

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

            {requests.data.length === 0 ? (
                <EmptyState
                    title="No hay solicitudes que mostrar"
                    description="Cuando una bodega le pida mercancía a otra, la solicitud quedará aquí hasta que la atiendan."
                    icon={<TrayArrowDownIcon size={24} />}
                    action={
                        can('inventory.transfer_requests.create') ? (
                            <ButtonLink href={route('transfer_requests.create')}>Pedir mercancía</ButtonLink>
                        ) : undefined
                    }
                />
            ) : (
                <Card padded={false}>
                    <Table>
                        <THead>
                            <TR>
                                <TH>Solicitud</TH>
                                <TH>Pide</TH>
                                <TH>Se le pide a</TH>
                                <TH>Estado</TH>
                                <TH align="right">Artículos</TH>
                                <TH align="right">Atendido</TH>
                                <TH align="right" />
                            </TR>
                        </THead>
                        <TBody>
                            {requests.data.map((request) => (
                                <TR key={request.id}>
                                    <TD>
                                        <p className="font-mono text-[0.8125rem] font-medium">{request.number}</p>
                                        <p className="text-[0.71875rem] text-[var(--muted)]">
                                            {request.created_at ? dateTime(request.created_at) : '—'}
                                            {request.created_by ? ` · ${request.created_by}` : ''}
                                        </p>
                                    </TD>
                                    <TD className="text-[0.78125rem]">{request.destination ?? '—'}</TD>
                                    <TD className="text-[0.78125rem]">
                                        {request.origin ?? '—'}
                                        {!request.is_internal && (
                                            <span className="ml-1.5 text-[0.6875rem] text-[var(--muted)]">otra sede</span>
                                        )}
                                    </TD>
                                    <TD>
                                        <Badge tone={TONES[request.status]}>{request.status_label}</Badge>
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                        {number(request.items)}
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                        {number(request.sent)} / {number(request.units)}
                                    </TD>
                                    <TD align="right">
                                        <ButtonLink href={route('transfer_requests.show', request.id)} size="sm" variant="ghost">
                                            Ver
                                        </ButtonLink>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                    <Pagination meta={requests} />
                </Card>
            )}
        </AppLayout>
    );
}

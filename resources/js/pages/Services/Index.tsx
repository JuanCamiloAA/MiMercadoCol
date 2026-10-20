import { router } from '@inertiajs/react';
import { CopyIcon, DownloadSimpleIcon, PencilSimpleIcon, PlusIcon, TrashIcon, UploadSimpleIcon, WarningCircleIcon, WrenchIcon } from '@phosphor-icons/react';
import { useState } from 'react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Select, Toggle } from '@/components/ui/Field';
import { ConfirmDialog } from '@/components/ui/Modal';
import { Badge, PageHeader, SearchInput } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { money, number, percent } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { Paginated } from '@/types';
import type { ServiceOption, ServiceRow } from '@/types/services';

interface Filters {
    search: string;
    category: string;
    status: string;
    online: string;
    kind: string;
    mode: string;
    [key: string]: string;
}

interface ServicesIndexProps {
    services: Paginated<ServiceRow>;
    filters: Filters;
    categories: ServiceOption[];
    options: { status: ServiceOption[]; online: ServiceOption[]; kind: ServiceOption[]; mode: ServiceOption[] };
    canViewCost: boolean;
    counts: { total: number; active: number; online: number };
}

/**
 * Servicios de la empresa: lo que se vende sin estar en un estante.
 *
 * Un servicio con un artículo inactivo o eliminado no se puede cobrar hasta que se corrija el
 * maestro: por eso sale marcado aquí, antes de que el cajero se entere en la caja.
 */
export default function ServicesIndex({ services, filters: initial, categories, options, canViewCost, counts }: ServicesIndexProps) {
    const { can } = usePermission();
    const { filters, update, reset, hasActive } = useFilters<Filters>('services.index', initial);
    const [deleting, setDeleting] = useState<ServiceRow | null>(null);

    const exportUrl = () => `${route('services.export')}?${new URLSearchParams(Object.entries(filters).filter(([, value]) => value !== '')).toString()}`;

    return (
        <AppLayout title="Servicios">
            <PageHeader
                title="Servicios"
                description={`${number(counts.total)} servicios · ${number(counts.active)} activos · ${number(counts.online)} en la tienda en línea`}
                actions={
                    <>
                        {can('services.index.export') && (
                            <a
                                href={exportUrl()}
                                className="inline-flex h-[calc(2.5rem*var(--density))] items-center gap-2 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-4 text-sm font-medium text-[var(--text)] hover:bg-[var(--surface-3)]"
                            >
                                <DownloadSimpleIcon size={16} />
                                Exportar
                            </a>
                        )}
                        {can('services.index.import') && (
                            <ButtonLink href={route('services.import.form')} variant="secondary" icon={<UploadSimpleIcon size={16} />}>
                                Importar
                            </ButtonLink>
                        )}
                        {can('services.index.create') && (
                            <ButtonLink href={route('services.create')} icon={<PlusIcon size={16} weight="bold" />}>
                                Nuevo servicio
                            </ButtonLink>
                        )}
                    </>
                }
            />

            <Card className="mb-4">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
                    <SearchInput
                        placeholder="Nombre, código o código de barras"
                        value={filters.search}
                        onChange={(event) => update('search', event.target.value)}
                        containerClassName="lg:col-span-2"
                    />
                    <Select options={categories} placeholder="Cualquier categoría" value={filters.category} onChange={(event) => update('category', event.target.value)} />
                    <Select options={options.status} placeholder="Cualquier estado" value={filters.status} onChange={(event) => update('status', event.target.value)} />
                    <Select options={options.online} placeholder="En línea u ocultos" value={filters.online} onChange={(event) => update('online', event.target.value)} />
                    <Select options={options.kind} placeholder="Con artículos o solo valor" value={filters.kind} onChange={(event) => update('kind', event.target.value)} />
                    <Select options={options.mode} placeholder="Cualquier modo de precio" value={filters.mode} onChange={(event) => update('mode', event.target.value)} />
                    {hasActive && (
                        <Button variant="ghost" onClick={reset}>
                            Limpiar
                        </Button>
                    )}
                </div>
            </Card>

            {services.data.length === 0 ? (
                <EmptyState
                    title={hasActive ? 'Nada con esos filtros' : 'Todavía no hay servicios'}
                    description="Armado de anchetas, porcionado al vacío, recargas: lo que la tienda cobra sin tenerlo en un estante."
                    icon={<WrenchIcon size={24} />}
                    action={can('services.index.create') && !hasActive ? <ButtonLink href={route('services.create')}>Crear el primero</ButtonLink> : undefined}
                />
            ) : (
                <Card padded={false}>
                    <Table>
                        <THead>
                            <TR>
                                <TH>Servicio</TH>
                                <TH>Categoría</TH>
                                <TH align="right">Precio</TH>
                                <TH>Modo</TH>
                                <TH align="center">Artículos</TH>
                                {canViewCost && <TH align="right">Costo y margen</TH>}
                                <TH align="center">En línea</TH>
                                <TH align="center">Estado</TH>
                                <TH align="right" />
                            </TR>
                        </THead>
                        <TBody>
                            {services.data.map((service) => (
                                <TR key={service.id}>
                                    <TD>
                                        <div className="flex items-center gap-3">
                                            {service.thumb_url ? (
                                                <img src={service.thumb_url} alt="" className="size-10 shrink-0 rounded-chip object-cover" />
                                            ) : (
                                                <span className="grid size-10 shrink-0 place-items-center rounded-chip bg-[var(--surface-2)] text-[var(--muted)]">
                                                    <WrenchIcon size={18} />
                                                </span>
                                            )}
                                            <div className="min-w-0">
                                                <p className="text-[0.84375rem] font-medium">{service.name}</p>
                                                <p className="font-mono text-[0.71875rem] text-[var(--muted)]">{service.code}</p>
                                                {service.blocked.length > 0 && (
                                                    <p className="mt-0.5 flex items-start gap-1 text-[0.6875rem] font-medium text-[var(--danger)]" title={service.blocked.join(', ')}>
                                                        <WarningCircleIcon size={12} weight="fill" className="mt-px shrink-0" />
                                                        No se puede cobrar: {service.blocked.join(', ')}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    </TD>
                                    <TD className="text-[0.8125rem]">{service.category?.name ?? '—'}</TD>
                                    <TD align="right" className="whitespace-nowrap font-mono text-[0.8125rem] tabular">
                                        {service.open_price ? (
                                            <span className="font-sans text-[var(--muted)]">Variable</span>
                                        ) : service.pricing_mode === 'itemized' && service.components_count > 0 ? (
                                            <>
                                                <span className="font-sans text-[0.71875rem] text-[var(--muted)]">desde </span>
                                                {money(service.price_total)}
                                            </>
                                        ) : (
                                            money(service.price_total)
                                        )}
                                    </TD>
                                    <TD className="text-[0.78125rem]">{service.components_count > 0 ? service.pricing_label : 'Solo valor'}</TD>
                                    <TD align="center" className="font-mono text-[0.8125rem] tabular">
                                        {service.components_count > 0 ? number(service.components_count) : '—'}
                                    </TD>
                                    {canViewCost && (
                                        <TD align="right" className="whitespace-nowrap font-mono text-[0.8125rem] tabular">
                                            {money(service.cost_total ?? 0)}
                                            <span className="block text-[0.6875rem] text-[var(--muted)]">
                                                {service.margin === null || service.margin === undefined ? '—' : `${percent(service.margin)} margen`}
                                            </span>
                                        </TD>
                                    )}
                                    <TD align="center">
                                        {service.open_price ? (
                                            <span className="text-[0.71875rem] text-[var(--muted)]">No aplica</span>
                                        ) : can('services.index.edit') ? (
                                            <Toggle
                                                checked={service.is_online_visible}
                                                onChange={(checked) => router.patch(route('services.online', service.id), { value: checked }, { preserveScroll: true })}
                                            />
                                        ) : service.is_online_visible ? (
                                            <Badge tone="info">Publicado</Badge>
                                        ) : (
                                            <span className="text-[0.71875rem] text-[var(--muted)]">Oculto</span>
                                        )}
                                    </TD>
                                    <TD align="center">
                                        {service.is_active ? <Badge tone="accent">Activo</Badge> : <Badge tone="neutral">Inactivo</Badge>}
                                    </TD>
                                    <TD align="right">
                                        <div className="flex items-center justify-end gap-1">
                                            {can('services.show.view') && (
                                                <ButtonLink href={route('services.show', service.id)} size="sm" variant="ghost">
                                                    Ver
                                                </ButtonLink>
                                            )}
                                            {can('services.index.edit') && (
                                                <ButtonLink href={route('services.edit', service.id)} size="sm" variant="ghost" icon={<PencilSimpleIcon size={14} />}>
                                                    <span className="sr-only">Editar</span>
                                                </ButtonLink>
                                            )}
                                            {can('services.index.create') && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    aria-label="Duplicar"
                                                    icon={<CopyIcon size={14} />}
                                                    onClick={() => router.post(route('services.duplicate', service.id))}
                                                />
                                            )}
                                            {can('services.index.delete') && (
                                                <Button size="sm" variant="ghost" aria-label="Eliminar" icon={<TrashIcon size={14} />} onClick={() => setDeleting(service)} />
                                            )}
                                        </div>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                    <Pagination meta={services} />
                </Card>
            )}

            <ConfirmDialog
                open={deleting !== null}
                onClose={() => setDeleting(null)}
                onConfirm={() => {
                    if (deleting) {
                        router.delete(route('services.destroy', deleting.id), { onFinish: () => setDeleting(null) });
                    }
                }}
                title="Eliminar el servicio"
                message={`«${deleting?.name}» deja de aparecer en la caja y en la tienda. Las ventas que ya lo tienen no cambian.`}
                confirmLabel="Eliminar"
            />
        </AppLayout>
    );
}

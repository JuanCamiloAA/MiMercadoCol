import { Link, router, usePage } from '@inertiajs/react';
import { ArrowCounterClockwiseIcon, DownloadSimpleIcon, IdentificationCardIcon, PencilSimpleIcon, PlusIcon, ProhibitIcon, UploadSimpleIcon } from '@phosphor-icons/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, StatCard } from '@/components/ui/Card';
import { Select } from '@/components/ui/Field';
import { Badge, PageHeader, SearchInput } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { date } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { Paginated, PageProps } from '@/types';

interface EmployeeRow {
    id: number;
    full_name: string;
    document_type: string;
    document_number: string;
    phone: string | null;
    position: string;
    hired_at: string | null;
    status: string;
    store: { id: number; name: string } | null;
    user: {
        id: number;
        email: string;
        is_active: boolean;
        has_pin: boolean;
        role: { name: string; display_name: string; color: string } | null;
    } | null;
}

interface EmployeesIndexProps {
    employees: Paginated<EmployeeRow>;
    filters: { search: string; position: string; store: string; status: string };
    metrics: { active: number; with_access: number; without_pin: number; inactive: number };
    positions: { value: string; label: string }[];
    stores: { value: number; label: string }[];
}

export default function EmployeesIndex({ employees, filters: initialFilters, metrics, positions, stores }: EmployeesIndexProps) {
    const { can } = usePermission();
    const { flash } = usePage<PageProps>().props;
    const { filters, update, reset, hasActive } = useFilters('employees.index', initialFilters);

    useEffect(() => {
        if (flash?.temporary_password) {
            toast.success(`Contraseña temporal: ${flash.temporary_password}`, {
                duration: 20000,
                description: 'Copiala ahora: no se vuelve a mostrar.',
            });
        }
    }, [flash?.temporary_password]);

    return (
        <AppLayout title="Empleados">
            <PageHeader
                title="Empleados"
                description="Fichas laborales y las cuentas de acceso asociadas."
                actions={
                    <>
                        {can('employees.index.import') && (
                            <ButtonLink href={route('employees.import.form')} variant="secondary" icon={<UploadSimpleIcon size={16} />}>
                                Importar
                            </ButtonLink>
                        )}
                        {can('employees.index.export') && (
                            <a
                                href={route('employees.export')}
                                className="inline-flex h-10 items-center gap-2 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-4 text-sm font-medium text-[var(--text)] hover:bg-[var(--surface-3)]"
                            >
                                <DownloadSimpleIcon size={16} />
                                Exportar
                            </a>
                        )}
                        {can('employees.index.create') && (
                            <ButtonLink href={route('employees.create')} icon={<PlusIcon size={16} weight="bold" />}>
                                Nuevo empleado
                            </ButtonLink>
                        )}
                    </>
                }
            />

            <div className="mb-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Activos" value={metrics.active} icon={<IdentificationCardIcon size={16} weight="bold" />} />
                <StatCard label="Con acceso" value={metrics.with_access} tone="neutral" />
                <StatCard label="Sin PIN de caja" value={metrics.without_pin} tone={metrics.without_pin > 0 ? 'warn' : 'neutral'} />
                <StatCard label="Inactivos" value={metrics.inactive} tone="neutral" />
            </div>

            <Card className="mb-4">
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <SearchInput
                        placeholder="Nombre, documento o cargo"
                        value={filters.search}
                        onChange={(event) => update('search', event.target.value)}
                    />
                    <Select options={positions} placeholder="Todos los cargos" value={filters.position} onChange={(event) => update('position', event.target.value)} />
                    <Select options={stores} placeholder="Todas las sedes" value={filters.store} onChange={(event) => update('store', event.target.value)} />
                    <div className="flex items-start gap-2">
                        <div className="flex-1">
                            <Select
                                options={[
                                    { value: 'active', label: 'Activos' },
                                    { value: 'inactive', label: 'Inactivos' },
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

            {employees.data.length === 0 ? (
                <EmptyState title="Ningún empleado coincide" description="Cambia los filtros o registra una ficha nueva." />
            ) : (
                <>
                    <Table>
                        <THead>
                            <TR>
                                <TH>Empleado</TH>
                                <TH>Cargo</TH>
                                <TH>Sede</TH>
                                <TH>Ingreso</TH>
                                <TH>Acceso</TH>
                                <TH align="center">Estado</TH>
                                <TH align="right">Acciones</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {employees.data.map((employee) => (
                                <TR key={employee.id}>
                                    <TD>
                                        {can('employees.show.view') ? (
                                            <Link
                                                href={route('employees.show', employee.id)}
                                                className="text-[0.84375rem] font-medium text-[var(--text)] hover:text-[var(--accent)]"
                                            >
                                                {employee.full_name}
                                            </Link>
                                        ) : (
                                            <span className="text-[0.84375rem] font-medium text-[var(--text)]">{employee.full_name}</span>
                                        )}
                                        <p className="font-mono text-[0.75rem] text-[var(--muted)] tabular">
                                            {employee.document_type} {employee.document_number}
                                        </p>
                                    </TD>
                                    <TD className="text-[0.8125rem]">{employee.position}</TD>
                                    <TD className="text-[0.8125rem] text-[var(--muted)]">{employee.store?.name ?? '—'}</TD>
                                    <TD className="text-[0.8125rem] text-[var(--muted)]">{date(employee.hired_at)}</TD>
                                    <TD>
                                        {employee.user ? (
                                            <div className="flex flex-col gap-1">
                                                {employee.user.role && (
                                                    <Badge dotColor={employee.user.role.color} tone="neutral">
                                                        {employee.user.role.display_name}
                                                    </Badge>
                                                )}
                                                <span className="text-[0.71875rem] text-[var(--muted)]">{employee.user.email}</span>
                                            </div>
                                        ) : (
                                            <span className="text-[0.78125rem] text-[var(--muted)]">Sin cuenta</span>
                                        )}
                                    </TD>
                                    <TD align="center">
                                        {employee.status === 'active' ? <Badge tone="accent">Activo</Badge> : <Badge tone="neutral">Inactivo</Badge>}
                                    </TD>
                                    <TD align="right">
                                        <div className="flex items-center justify-end gap-1">
                                            {can('employees.index.edit') && (
                                                <ButtonLink
                                                    href={route('employees.edit', employee.id)}
                                                    size="sm"
                                                    variant="ghost"
                                                    icon={<PencilSimpleIcon size={15} />}
                                                />
                                            )}
                                            {employee.status === 'active' && can('employees.index.deactivate') && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    title="Desactivar"
                                                    icon={<ProhibitIcon size={15} />}
                                                    onClick={() => router.patch(route('employees.deactivate', employee.id), {}, { preserveScroll: true })}
                                                />
                                            )}
                                            {employee.status !== 'active' && can('employees.index.reactivate') && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    title="Reactivar"
                                                    icon={<ArrowCounterClockwiseIcon size={15} />}
                                                    onClick={() => router.patch(route('employees.reactivate', employee.id), {}, { preserveScroll: true })}
                                                />
                                            )}
                                        </div>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>

                    <Pagination meta={employees} />
                </>
            )}
        </AppLayout>
    );
}

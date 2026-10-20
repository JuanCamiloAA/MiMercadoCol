import { router, usePage } from '@inertiajs/react';
import { BuildingsIcon, DownloadSimpleIcon, PencilSimpleIcon, PlusIcon, PowerIcon, SignInIcon } from '@phosphor-icons/react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input, Select } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { Badge, PageHeader, SearchInput } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import { date } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { MembershipStatus, PageProps, Paginated } from '@/types';
import { MembershipBadge, UsageMeter } from '@/components/membership/MembershipParts';

interface CompanyRow {
    id: number;
    name: string;
    tax_id: string;
    city: string | null;
    category: string | null;
    is_active: boolean;
    plan: string | null;
    cycle: string | null;
    membership_status: MembershipStatus;
    membership_label: string;
    membership_ends_at: string | null;
    days_left: number | null;
    stores: { used: number; limit: number | null };
    users: { used: number; limit: number | null };
}

interface Option {
    value: string | number;
    label: string;
}

interface CompaniesIndexProps {
    companies: Paginated<CompanyRow>;
    filters: { q: string; estado: string; plan: string; clasificacion: string; orden: string };
    counts: Record<string, number>;
    statusOptions: Option[];
    planOptions: Option[];
    categoryOptions: Option[];
    sortOptions: Option[];
    activeCompanyId: number | null;
}

/**
 * Plataforma > Empresas: todas las empresas cliente, su membresia y su uso.
 */
export default function CompaniesIndex({ companies, filters: initialFilters, counts, statusOptions, planOptions, categoryOptions, sortOptions, activeCompanyId }: CompaniesIndexProps) {
    const { can } = usePermission();
    const { flash } = usePage<PageProps>().props;
    const { filters, update, reset, hasActive } = useFilters('companies.index', initialFilters);
    const [toggling, setToggling] = useState<CompanyRow | null>(null);

    useEffect(() => {
        if (flash?.temporary_password) {
            toast.success(`Contraseña temporal del administrador: ${flash.temporary_password}`, {
                duration: 30000,
                description: 'Cópiala ahora: no se vuelve a mostrar.',
            });
        }
    }, [flash?.temporary_password]);

    const chips: Option[] = [{ value: '', label: 'Todas' }, ...statusOptions, { value: 'inactive', label: 'Desactivadas' }];
    const exportQuery = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));

    return (
        <AppLayout title="Empresas">
            <PageHeader
                title="Empresas"
                description="Las empresas cliente de la plataforma: su plan, su membresía y lo que usan."
                actions={
                    <>
                        <a
                            href={route('companies.export', exportQuery)}
                            className="inline-flex h-10 items-center gap-2 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-4 text-sm font-medium text-[var(--text)] hover:bg-[var(--surface-3)]"
                        >
                            <DownloadSimpleIcon size={16} />
                            Exportar CSV
                        </a>
                        {can('companies.index.create') && (
                            <ButtonLink href={route('companies.create')} icon={<PlusIcon size={16} weight="bold" />}>
                                Nueva empresa
                            </ButtonLink>
                        )}
                    </>
                }
            />

            <div className="mb-3 flex flex-wrap gap-1.5">
                {chips.map((chip) => {
                    const value = String(chip.value);
                    const active = filters.estado === value;

                    return (
                        <button
                            key={value || 'all'}
                            type="button"
                            onClick={() => update('estado', value)}
                            className={cn(
                                'inline-flex h-8 items-center gap-1.5 rounded-chip border px-3 text-[0.8125rem] font-medium transition-colors',
                                active
                                    ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]'
                                    : 'border-[var(--line)] bg-[var(--surface)] text-[var(--text)] hover:bg-[var(--surface-2)]',
                            )}
                        >
                            {chip.label}
                            <span className="font-mono text-[0.71875rem] tabular text-[var(--muted)]">{counts[value || 'all'] ?? 0}</span>
                        </button>
                    );
                })}
            </div>

            <Card className="mb-4">
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto]">
                    <SearchInput placeholder="Nombre, NIT, ciudad o correo" value={filters.q} onChange={(event) => update('q', event.target.value)} />
                    <Select options={planOptions} placeholder="Todos los planes" value={filters.plan} onChange={(event) => update('plan', event.target.value)} />
                    <Select options={categoryOptions} placeholder="Todas las clasificaciones" value={filters.clasificacion} onChange={(event) => update('clasificacion', event.target.value)} />
                    <Select options={sortOptions.filter((option) => option.value !== '')} placeholder="Por nombre" value={filters.orden} onChange={(event) => update('orden', event.target.value)} />
                    {hasActive && (
                        <Button variant="ghost" onClick={reset}>
                            Limpiar
                        </Button>
                    )}
                </div>
            </Card>

            {companies.data.length === 0 ? (
                <EmptyState title="Ninguna empresa coincide" description="Cambia los filtros o crea una empresa nueva." icon={<BuildingsIcon size={24} />} />
            ) : (
                <>
                    <Table>
                        <THead>
                            <TR>
                                <TH>Empresa</TH>
                                <TH>Plan</TH>
                                <TH>Estado</TH>
                                <TH>Vence</TH>
                                <TH>Sedes</TH>
                                <TH>Usuarios</TH>
                                <TH align="right">Acciones</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {companies.data.map((company) => (
                                <TR key={company.id} className={cn(!company.is_active && 'opacity-60')}>
                                    <TD>
                                        <p className="text-[0.84375rem] font-medium text-[var(--text)]">
                                            {company.name}
                                            {company.id === activeCompanyId && <span className="ml-1.5 text-[0.71875rem] font-semibold text-[var(--accent)]">· elegida</span>}
                                        </p>
                                        <p className="font-mono text-[0.75rem] text-[var(--muted)] tabular">
                                            NIT {company.tax_id}
                                            {company.city ? ` · ${company.city}` : ''}
                                        </p>
                                        {company.category && <p className="text-[0.75rem] text-[var(--muted)]">{company.category}</p>}
                                    </TD>
                                    <TD>
                                        <p className="text-[0.8125rem]">{company.plan ?? 'Sin plan'}</p>
                                        {company.cycle && <p className="text-[0.75rem] text-[var(--muted)]">{company.cycle}</p>}
                                    </TD>
                                    <TD>
                                        <MembershipBadge status={company.membership_status} label={company.membership_label} />
                                        {!company.is_active && (
                                            <span className="mt-1 block">
                                                <Badge tone="neutral">Desactivada</Badge>
                                            </span>
                                        )}
                                    </TD>
                                    <TD className="text-[0.8125rem]">
                                        {company.membership_ends_at ? (
                                            <>
                                                <p>{date(company.membership_ends_at, 'd MMM yyyy')}</p>
                                                {company.days_left !== null && (
                                                    <p className={cn('text-[0.75rem]', company.days_left < 0 ? 'text-[var(--danger)]' : company.days_left <= 7 ? 'text-[var(--warn)]' : 'text-[var(--muted)]')}>
                                                        {company.days_left < 0 ? `Venció hace ${-company.days_left} d` : company.days_left === 0 ? 'Vence hoy' : `En ${company.days_left} d`}
                                                    </p>
                                                )}
                                            </>
                                        ) : (
                                            <span className="text-[var(--muted)]">Sin vencimiento</span>
                                        )}
                                    </TD>
                                    <TD>
                                        <UsageMeter used={company.stores.used} limit={company.stores.limit} />
                                    </TD>
                                    <TD>
                                        <UsageMeter used={company.users.used} limit={company.users.limit} />
                                    </TD>
                                    <TD align="right">
                                        <div className="flex items-center justify-end gap-1">
                                            {can('companies.index.switch') && (
                                                <Button size="sm" variant="secondary" icon={<SignInIcon size={15} />} onClick={() => router.post(route('companies.enter', company.id))}>
                                                    Entrar
                                                </Button>
                                            )}
                                            {can('companies.index.edit') && (
                                                <ButtonLink href={route('companies.edit', company.id)} size="sm" variant="ghost" icon={<PencilSimpleIcon size={15} />}>
                                                    Editar
                                                </ButtonLink>
                                            )}
                                            {can('companies.index.delete') && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    icon={<PowerIcon size={15} />}
                                                    onClick={() => setToggling(company)}
                                                    aria-label={company.is_active ? `Desactivar ${company.name}` : `Activar ${company.name}`}
                                                >
                                                    {company.is_active ? 'Desactivar' : 'Activar'}
                                                </Button>
                                            )}
                                        </div>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                    <Pagination meta={companies} />
                </>
            )}

            <ToggleStatusModal company={toggling} onClose={() => setToggling(null)} />
        </AppLayout>
    );
}

/**
 * Activar o desactivar. Desactivar pide motivo: sus usuarios dejan de poder entrar, pero no se
 * borra nada.
 */
export function ToggleStatusModal({ company, onClose }: { company: { id: number; name: string; is_active: boolean } | null; onClose: () => void }) {
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        setReason('');
        setError(null);
    }, [company]);

    if (!company) {
        return null;
    }

    const submit = () => {
        setBusy(true);
        router.post(
            route('companies.status', company.id),
            { is_active: !company.is_active, reason: reason.trim() || null },
            {
                preserveScroll: true,
                onSuccess: onClose,
                onError: (errors) => setError(errors.reason ?? 'No se pudo cambiar el estado.'),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={company.is_active ? `Desactivar ${company.name}` : `Activar ${company.name}`}
            description={
                company.is_active
                    ? 'Sus usuarios dejan de poder entrar y su tienda deja de vender. No se borra nada: se puede volver a activar.'
                    : 'Sus usuarios vuelven a poder entrar.'
            }
            size="sm"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button variant={company.is_active ? 'danger' : 'primary'} loading={busy} onClick={submit}>
                        {company.is_active ? 'Desactivar' : 'Activar'}
                    </Button>
                </>
            }
        >
            {company.is_active && (
                <Input label="Motivo" required value={reason} onChange={(event) => setReason(event.target.value)} maxLength={250} error={error ?? undefined} placeholder="Queda en la bitácora" />
            )}
        </Modal>
    );
}

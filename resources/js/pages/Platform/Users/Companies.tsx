import { router, useForm } from '@inertiajs/react';
import { CheckCircleIcon, KeyIcon, PencilSimpleIcon, PlusIcon, ProhibitIcon, ShieldCheckIcon, UsersThreeIcon } from '@phosphor-icons/react';
import { useEffect, useState } from 'react';
import { ICON_BUTTON, TemporaryPasswordDialog, UsersTabs, type UserTabCounts } from '@/components/platform/users';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Checkbox, Input, Select } from '@/components/ui/Field';
import { ConfirmDialog, Modal } from '@/components/ui/Modal';
import { Badge, PageHeader, SearchInput } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { relative } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { Paginated } from '@/types';

interface CompanyUserRow {
    id: number;
    full_name: string;
    name: string;
    last_name: string | null;
    email: string;
    document: string | null;
    phone: string | null;
    job_title: string | null;
    is_active: boolean;
    password_change_required: boolean;
    last_login_at: string | null;
    company: { id: number; name: string; is_active: boolean } | null;
    role: { name: string; display_name: string; color: string } | null;
    stores: { id: number; name: string }[];
}

interface Option<T extends string | number> {
    value: T;
    label: string;
}

interface CompanyUsersProps {
    users: Paginated<CompanyUserRow>;
    filters: { search: string; company: string; status: string };
    companies: Option<number>[];
    tabCounts: UserTabCounts;
}

type Pending = { kind: 'deactivate' | 'reset'; user: CompanyUserRow } | null;

/**
 * Plataforma > Usuarios > De empresas: las cuentas de todas las empresas. Crear y editar aquí deja
 * lo mismo que en Usuarios dentro de la empresa: tope del plan, rol con su plantilla y sedes.
 */
export default function PlatformCompanyUsers({ users, filters: initialFilters, companies, tabCounts }: CompanyUsersProps) {
    const { can } = usePermission();
    const { filters, update, reset, hasActive } = useFilters('platform.company_users.index', initialFilters);
    const [editing, setEditing] = useState<CompanyUserRow | 'new' | null>(null);
    const [pending, setPending] = useState<Pending>(null);
    const [busy, setBusy] = useState(false);

    const confirm = () => {
        if (!pending) {
            return;
        }

        const name = pending.kind === 'reset' ? 'platform.company_users.reset_password' : 'platform.company_users.toggle';

        setBusy(true);
        router.post(
            route(name, pending.user.id),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(false);
                    setPending(null);
                },
            },
        );
    };

    const toggle = (user: CompanyUserRow) => {
        if (user.is_active) {
            setPending({ kind: 'deactivate', user });

            return;
        }

        router.post(route('platform.company_users.toggle', user.id), {}, { preserveScroll: true });
    };

    return (
        <AppLayout title="Usuarios de empresas">
            <PageHeader
                title="Usuarios"
                description="Los de empresas son las cuentas de cada empresa. Lo que hagas aquí es lo mismo que haría la empresa en su módulo de Usuarios: respeta el tope de su plan y la plantilla de su rol."
                actions={
                    can('platform.company_users.create') && (
                        <Button onClick={() => setEditing('new')}>
                            <PlusIcon size={16} /> Nuevo usuario de empresa
                        </Button>
                    )
                }
            />

            <UsersTabs active="companies" counts={tabCounts} />

            <Card className="mb-4" padded>
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-[1fr_16rem_12rem_auto] xl:items-start">
                    <SearchInput placeholder="Nombre, correo o documento" value={filters.search} onChange={(event) => update('search', event.target.value)} />
                    <Select options={companies} placeholder="Todas las empresas" value={filters.company} onChange={(event) => update('company', event.target.value)} />
                    <Select
                        options={[
                            { value: 'active', label: 'Activos' },
                            { value: 'inactive', label: 'Desactivados' },
                        ]}
                        placeholder="Cualquier estado"
                        value={filters.status}
                        onChange={(event) => update('status', event.target.value)}
                    />
                    {hasActive && (
                        <Button variant="ghost" onClick={reset}>
                            Limpiar
                        </Button>
                    )}
                </div>
            </Card>

            {users.data.length === 0 ? (
                <Card padded={false}>
                    <EmptyState title="Ningún usuario coincide" description="Cambia los filtros o crea una cuenta nueva." icon={<UsersThreeIcon size={24} />} />
                </Card>
            ) : (
                <>
                    <Table>
                        <THead>
                            <TR>
                                <TH>Usuario</TH>
                                <TH>Empresa</TH>
                                <TH>Rol</TH>
                                <TH>Sedes</TH>
                                <TH>Última conexión</TH>
                                <TH align="center">Estado</TH>
                                <TH align="right">Acciones</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {users.data.map((user) => (
                                <TR key={user.id}>
                                    <TD>
                                        <span className="block font-medium text-[var(--text)]">{user.full_name}</span>
                                        <span className="block truncate text-[0.75rem] text-[var(--muted)]">{user.email}</span>
                                    </TD>
                                    <TD>
                                        {user.company ? (
                                            <span className="flex flex-wrap items-center gap-1.5 text-[0.8125rem]">
                                                {user.company.name}
                                                {!user.company.is_active && <Badge tone="neutral">Desactivada</Badge>}
                                            </span>
                                        ) : (
                                            '—'
                                        )}
                                    </TD>
                                    <TD>
                                        {user.role ? (
                                            <Badge dotColor={user.role.color} tone="neutral">
                                                {user.role.display_name}
                                            </Badge>
                                        ) : (
                                            <span className="text-[var(--muted)]">Sin rol</span>
                                        )}
                                    </TD>
                                    <TD className="text-[0.78125rem] text-[var(--muted)]">{user.stores.length > 0 ? user.stores.map((store) => store.name).join(', ') : '—'}</TD>
                                    <TD className="text-[0.78125rem] text-[var(--muted)]">{user.last_login_at ? relative(user.last_login_at) : 'Nunca'}</TD>
                                    <TD align="center">
                                        {user.is_active ? <Badge tone="accent">Activo</Badge> : <Badge tone="neutral">Desactivado</Badge>}
                                        {user.password_change_required && (
                                            <span className="mt-1 block">
                                                <Badge tone="warn">Clave temporal</Badge>
                                            </span>
                                        )}
                                    </TD>
                                    <TD align="right">
                                        <div className="flex justify-end gap-1">
                                            {can('platform.company_users.edit') && (
                                                <button type="button" title="Editar datos, rol y sedes" onClick={() => setEditing(user)} className={ICON_BUTTON}>
                                                    <PencilSimpleIcon size={16} />
                                                </button>
                                            )}
                                            {can('companies.index.switch') && user.company && (
                                                <button
                                                    type="button"
                                                    title={`Permisos uno a uno (entra a ${user.company.name})`}
                                                    onClick={() => router.post(route('platform.company_users.permissions', user.id))}
                                                    className={ICON_BUTTON}
                                                >
                                                    <ShieldCheckIcon size={16} />
                                                </button>
                                            )}
                                            {can('platform.company_users.reset_password') && (
                                                <button type="button" title="Restablecer contraseña" onClick={() => setPending({ kind: 'reset', user })} className={ICON_BUTTON}>
                                                    <KeyIcon size={16} />
                                                </button>
                                            )}
                                            {can('platform.company_users.deactivate') && (
                                                <button type="button" title={user.is_active ? 'Desactivar' : 'Activar'} onClick={() => toggle(user)} className={ICON_BUTTON}>
                                                    {user.is_active ? <ProhibitIcon size={16} /> : <CheckCircleIcon size={16} />}
                                                </button>
                                            )}
                                        </div>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>

                    <Pagination meta={users} />
                </>
            )}

            {editing !== null && <CompanyUserDialog user={editing === 'new' ? null : editing} companies={companies} onClose={() => setEditing(null)} />}

            <ConfirmDialog
                open={pending !== null}
                onClose={() => setPending(null)}
                onConfirm={confirm}
                loading={busy}
                tone={pending?.kind === 'reset' ? 'primary' : 'danger'}
                title={pending?.kind === 'reset' ? 'Restablecer la contraseña' : 'Desactivar el usuario'}
                confirmLabel={pending?.kind === 'reset' ? 'Restablecer' : 'Desactivar'}
                message={
                    pending?.kind === 'reset' ? (
                        <>
                            Se genera una contraseña temporal para <strong>{pending.user.full_name}</strong> y la actual deja de servir. Al entrar tendrá
                            que cambiarla.
                        </>
                    ) : (
                        <>
                            <strong>{pending?.user.full_name}</strong> no podrá volver a entrar a {pending?.user.company?.name ?? 'su empresa'} y su sesión se
                            cierra en la próxima acción. Sus ventas y movimientos se conservan, y libera un cupo del plan.
                        </>
                    )
                }
            />

            <TemporaryPasswordDialog />
        </AppLayout>
    );
}

interface CompanyOptions {
    roles: Option<string>[];
    stores: Option<number>[];
}

/** Roles y sedes de la empresa elegida: cada empresa tiene los suyos. */
function useCompanyOptions(companyId: number | null) {
    // Cada respuesta queda marcada con su empresa: al cambiar de empresa no se pintan, ni por un
    // instante, los roles de la anterior.
    const [loaded, setLoaded] = useState<{ companyId: number; data: CompanyOptions } | null>(null);
    const [failedFor, setFailedFor] = useState<number | null>(null);

    useEffect(() => {
        if (companyId === null) {
            return;
        }

        let cancelled = false;

        fetch(route('platform.company_users.options', companyId), { headers: { Accept: 'application/json' } })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                return response.json() as Promise<CompanyOptions>;
            })
            .then((data) => {
                if (!cancelled) {
                    setLoaded({ companyId, data });
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setFailedFor(companyId);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [companyId]);

    return {
        options: loaded !== null && loaded.companyId === companyId ? loaded.data : null,
        failed: companyId !== null && failedFor === companyId,
    };
}

function CompanyUserDialog({ user, companies, onClose }: { user: CompanyUserRow | null; companies: Option<number>[]; onClose: () => void }) {
    const form = useForm({
        // Solo al crear: un usuario no se cambia de empresa.
        company_id: '',
        name: user?.name ?? '',
        last_name: user?.last_name ?? '',
        email: user?.email ?? '',
        document: user?.document ?? '',
        phone: user?.phone ?? '',
        job_title: user?.job_title ?? '',
        role: user?.role?.name ?? '',
        store_ids: user?.stores.map((store) => store.id) ?? [],
    });
    const { data, setData, errors } = form;
    const companyId = user?.company?.id ?? (data.company_id !== '' ? Number(data.company_id) : null);
    const { options, failed } = useCompanyOptions(companyId);

    const chooseCompany = (value: string) => {
        // El rol y las sedes son de cada empresa: lo marcado en otra no vale aquí.
        setData((current) => ({ ...current, company_id: value, role: '', store_ids: [] }));
    };

    const toggleStore = (id: number) => {
        setData('store_ids', data.store_ids.includes(id) ? data.store_ids.filter((value) => value !== id) : [...data.store_ids, id]);
    };

    const submit = () => {
        const visit = { preserveScroll: true, onSuccess: () => onClose() };

        if (user) {
            form.put(route('platform.company_users.update', user.id), visit);
        } else {
            form.post(route('platform.company_users.store'), visit);
        }
    };

    return (
        <Modal
            open
            onClose={onClose}
            size="lg"
            title={user ? user.full_name : 'Nuevo usuario de empresa'}
            description={user ? user.company?.name : 'El rol define sus permisos iniciales; después se ajustan uno a uno dentro de la empresa.'}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button onClick={submit} loading={form.processing}>
                        {user ? 'Guardar' : 'Crear usuario'}
                    </Button>
                </>
            }
        >
            {!user && (
                <div className="mb-4">
                    <Select
                        label="Empresa"
                        required
                        options={companies}
                        placeholder="Elige la empresa"
                        value={data.company_id}
                        onChange={(event) => chooseCompany(event.target.value)}
                        error={errors.company_id}
                    />
                </div>
            )}

            <div className="grid gap-3 sm:grid-cols-2">
                <Input label="Nombre" required value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} />
                <Input label="Apellido" value={data.last_name} onChange={(e) => setData('last_name', e.target.value)} error={errors.last_name} />
                <Input
                    label="Correo"
                    required
                    type="email"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    error={errors.email}
                    containerClassName="sm:col-span-2"
                />
                <Input
                    label="Documento"
                    value={data.document}
                    onChange={(e) => setData('document', e.target.value)}
                    error={errors.document}
                    hint="Sirve para entrar con documento en vez de correo."
                />
                <Input label="Teléfono" inputMode="tel" value={data.phone} onChange={(e) => setData('phone', e.target.value)} error={errors.phone} />
                <Input label="Cargo" value={data.job_title} onChange={(e) => setData('job_title', e.target.value)} error={errors.job_title} containerClassName="sm:col-span-2" />
            </div>

            <div className="mt-4 grid gap-3 border-t border-[var(--line)] pt-4">
                {companyId === null ? (
                    <p className="text-[0.78125rem] text-[var(--muted)]">Elige la empresa para ver sus roles y sedes.</p>
                ) : failed ? (
                    <p className="text-[0.78125rem] font-medium text-[var(--danger)]">No se pudieron cargar los roles y las sedes de la empresa. Cierra y vuelve a intentar.</p>
                ) : options === null ? (
                    <p className="text-[0.78125rem] text-[var(--muted)]">Cargando roles y sedes…</p>
                ) : (
                    <>
                        <Select
                            label="Rol"
                            required
                            options={options.roles}
                            placeholder="Elige un rol"
                            value={data.role}
                            onChange={(event) => setData('role', event.target.value)}
                            error={errors.role}
                        />
                        <div className="flex flex-col gap-2">
                            <span className="text-[0.8125rem] font-medium text-[var(--text)]">Sedes donde puede operar</span>
                            {options.stores.length === 0 ? (
                                <p className="text-[0.78125rem] text-[var(--muted)]">La empresa no tiene sedes activas.</p>
                            ) : (
                                <div className="flex flex-col gap-2 rounded-control border border-[var(--line)] p-3">
                                    {options.stores.map((store) => (
                                        <Checkbox key={store.value} label={store.label} checked={data.store_ids.includes(store.value)} onChange={() => toggleStore(store.value)} />
                                    ))}
                                </div>
                            )}
                            {(errors.store_ids ?? (errors as Record<string, string>)['store_ids.0']) && (
                                <p className="text-[0.75rem] text-[var(--danger)]">{errors.store_ids ?? (errors as Record<string, string>)['store_ids.0']}</p>
                            )}
                        </div>
                    </>
                )}
            </div>

            {!user && (
                <p className="mt-4 rounded-control bg-[var(--surface-2)] px-3 py-2.5 text-[0.78125rem] text-[var(--muted)]">
                    Al crearlo verás una contraseña temporal una sola vez. Al entrar tendrá que cambiarla.
                </p>
            )}
        </Modal>
    );
}

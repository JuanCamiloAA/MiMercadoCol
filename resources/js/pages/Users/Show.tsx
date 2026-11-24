import { Link } from '@inertiajs/react';
import { ArrowLeftIcon, PencilSimpleIcon, ShieldCheckIcon } from '@phosphor-icons/react';
import { ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Avatar, Badge, PageHeader } from '@/components/ui/Misc';
import AppLayout from '@/layouts/AppLayout';
import { dateTime } from '@/lib/format';
import { usePermission } from '@/lib/permissions';

interface UserShowProps {
    user: {
        id: number;
        name: string;
        email: string;
        document: string | null;
        phone: string | null;
        job_title: string | null;
        is_active: boolean;
        last_login_at: string | null;
        password_change_required: boolean;
        has_pin: boolean;
        role: { name: string; display_name: string; color: string } | null;
        stores: string[];
        permissions: string[];
        employee: { id: number; name: string; position: string } | null;
    };
}

export default function UserShow({ user }: UserShowProps) {
    const { can } = usePermission();

    return (
        <AppLayout title={user.name}>
            <PageHeader
                title={user.name}
                description={user.job_title ?? 'Cuenta de acceso'}
                breadcrumb={
                    <Link href={route('users.index')} className="hover:text-[var(--accent)]">
                        Usuarios
                    </Link>
                }
                actions={
                    <>
                        <ButtonLink href={route('users.index')} variant="secondary" icon={<ArrowLeftIcon size={16} />}>
                            Volver
                        </ButtonLink>
                        {can('users.edit.permissions') && (
                            <ButtonLink href={route('users.permissions', user.id)} variant="secondary" icon={<ShieldCheckIcon size={16} />}>
                                Permisos
                            </ButtonLink>
                        )}
                        {can('users.index.edit') && (
                            <ButtonLink href={route('users.edit', user.id)} icon={<PencilSimpleIcon size={16} />}>
                                Editar
                            </ButtonLink>
                        )}
                    </>
                }
            />

            <div className="grid gap-4 lg:grid-cols-[320px_1fr]">
                <Card>
                    <div className="flex items-center gap-3">
                        <Avatar name={user.name} size={52} color={user.role?.color} />
                        <div className="min-w-0">
                            <p className="truncate text-[0.9375rem] font-semibold text-[var(--text)]">{user.name}</p>
                            <p className="truncate text-[0.78125rem] text-[var(--muted)]">{user.email}</p>
                        </div>
                    </div>

                    <dl className="mt-4 flex flex-col gap-2.5 border-t border-[var(--line)] pt-4 text-[0.8125rem]">
                        <div className="flex items-center justify-between gap-3">
                            <dt className="text-[var(--muted)]">Rol</dt>
                            <dd>
                                {user.role ? (
                                    <Badge dotColor={user.role.color} tone="neutral">
                                        {user.role.display_name}
                                    </Badge>
                                ) : (
                                    '—'
                                )}
                            </dd>
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <dt className="text-[var(--muted)]">Documento</dt>
                            <dd className="font-mono text-[0.78125rem] tabular">{user.document ?? '—'}</dd>
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <dt className="text-[var(--muted)]">Teléfono</dt>
                            <dd className="font-mono text-[0.78125rem] tabular">{user.phone ?? '—'}</dd>
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <dt className="text-[var(--muted)]">Sedes</dt>
                            <dd className="text-right text-[0.78125rem]">{user.stores.length > 0 ? user.stores.join(', ') : '—'}</dd>
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <dt className="text-[var(--muted)]">PIN de caja</dt>
                            <dd>{user.has_pin ? <Badge tone="accent">Definido</Badge> : <Badge tone="neutral">Sin PIN</Badge>}</dd>
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <dt className="text-[var(--muted)]">Última conexión</dt>
                            <dd className="text-[0.78125rem]">{user.last_login_at ? dateTime(user.last_login_at) : 'Nunca'}</dd>
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <dt className="text-[var(--muted)]">Estado</dt>
                            <dd>{user.is_active ? <Badge tone="accent">Activo</Badge> : <Badge tone="neutral">Inactivo</Badge>}</dd>
                        </div>
                    </dl>

                    {user.employee && (
                        <div className="mt-4 rounded-control border border-[var(--line)] bg-[var(--surface-2)] p-3">
                            <p className="text-[0.75rem] uppercase tracking-wide text-[var(--muted)]">Ficha laboral</p>
                            {can('employees.show.view') ? (
                                <Link
                                    href={route('employees.show', user.employee.id)}
                                    className="mt-1 block text-[0.84375rem] font-medium text-[var(--accent)] hover:underline"
                                >
                                    {user.employee.name}
                                </Link>
                            ) : (
                                <p className="mt-1 text-[0.84375rem] font-medium text-[var(--text)]">{user.employee.name}</p>
                            )}
                            <p className="text-[0.78125rem] text-[var(--muted)]">{user.employee.position}</p>
                        </div>
                    )}
                </Card>

                <Card>
                    <CardHeader
                        title="Permisos efectivos"
                        description={`${user.permissions.length} acciones habilitadas para esta cuenta.`}
                    />

                    {user.permissions.length === 0 ? (
                        <p className="text-[0.8125rem] text-[var(--muted)]">Esta cuenta no tiene ningún permiso asignado.</p>
                    ) : (
                        <div className="flex flex-wrap gap-1.5">
                            {user.permissions.map((permission) => (
                                <span
                                    key={permission}
                                    className="rounded-full bg-[var(--surface-2)] px-2 py-0.5 font-mono text-[0.71875rem] text-[var(--muted)]"
                                >
                                    {permission}
                                </span>
                            ))}
                        </div>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}

import { Link, usePage } from '@inertiajs/react';
import { ArrowLeftIcon, PencilSimpleIcon } from '@phosphor-icons/react';
import { ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Badge, PageHeader } from '@/components/ui/Misc';
import AppLayout from '@/layouts/AppLayout';
import { usePermission } from '@/lib/permissions';
import type { PageProps } from '@/types';

interface RoleShowProps {
    role: {
        id: number;
        name: string;
        display_name: string;
        description: string | null;
        color: string;
        is_system: boolean;
        permissions_count: number;
        users_count: number;
    };
    rolePermissions: string[];
    users: { id: number; name: string; email: string; is_active: boolean }[];
}

export default function RoleShow({ role, rolePermissions, users }: RoleShowProps) {
    const { permissionMatrix } = usePage<PageProps>().props;
    const { can } = usePermission();

    const granted = new Set(rolePermissions);

    return (
        <AppLayout title={role.display_name}>
            <PageHeader
                title={role.display_name}
                description={role.description ?? 'Sin descripción.'}
                breadcrumb={
                    <Link href={route('roles.index')} className="hover:text-[var(--accent)]">
                        Roles
                    </Link>
                }
                actions={
                    <>
                        <ButtonLink href={route('roles.index')} variant="secondary" icon={<ArrowLeftIcon size={16} />}>
                            Volver
                        </ButtonLink>
                        {can('roles.index.edit') && !role.is_system && (
                            <ButtonLink href={route('roles.edit', role.id)} icon={<PencilSimpleIcon size={16} />}>
                                Editar
                            </ButtonLink>
                        )}
                    </>
                }
            />

            <div className="grid gap-4 lg:grid-cols-[300px_1fr]">
                <div className="flex flex-col gap-4">
                    <Card>
                        <CardHeader title="Resumen" />
                        <dl className="flex flex-col gap-2.5 text-[0.8125rem]">
                            <div className="flex items-center justify-between">
                                <dt className="text-[var(--muted)]">Identificador</dt>
                                <dd className="font-mono text-[0.75rem] text-[var(--text)]">{role.name}</dd>
                            </div>
                            <div className="flex items-center justify-between">
                                <dt className="text-[var(--muted)]">Color</dt>
                                <dd className="flex items-center gap-1.5">
                                    <span className="size-4 rounded-chip" style={{ backgroundColor: role.color }} />
                                    <span className="font-mono text-[0.75rem]">{role.color}</span>
                                </dd>
                            </div>
                            <div className="flex items-center justify-between">
                                <dt className="text-[var(--muted)]">Permisos</dt>
                                <dd className="font-mono tabular">{role.permissions_count}</dd>
                            </div>
                            <div className="flex items-center justify-between">
                                <dt className="text-[var(--muted)]">Usuarios</dt>
                                <dd className="font-mono tabular">{role.users_count}</dd>
                            </div>
                            {role.is_system && (
                                <div className="pt-1">
                                    <Badge tone="neutral">Rol del sistema</Badge>
                                </div>
                            )}
                        </dl>
                    </Card>

                    <Card>
                        <CardHeader title="Usuarios con el rol" />
                        {users.length === 0 ? (
                            <p className="text-[0.8125rem] text-[var(--muted)]">Todavía nadie tiene este rol.</p>
                        ) : (
                            <ul className="flex flex-col gap-2">
                                {users.map((user) => (
                                    <li key={user.id} className="flex items-center justify-between gap-2">
                                        <div className="min-w-0">
                                            {can('users.show.view') ? (
                                                <Link
                                                    href={route('users.show', user.id)}
                                                    className="block truncate text-[0.8125rem] font-medium text-[var(--text)] hover:text-[var(--accent)]"
                                                >
                                                    {user.name}
                                                </Link>
                                            ) : (
                                                <span className="block truncate text-[0.8125rem] font-medium text-[var(--text)]">{user.name}</span>
                                            )}
                                            <p className="truncate text-[0.75rem] text-[var(--muted)]">{user.email}</p>
                                        </div>
                                        {!user.is_active && <Badge tone="neutral">Inactivo</Badge>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>

                <Card>
                    <CardHeader title="Permisos de la plantilla" description="Lo marcado es lo que este rol otorga al asignarse." />

                    {permissionMatrix ? (
                        <div className="flex flex-col gap-3">
                            {permissionMatrix.map((module) => {
                                const moduleGranted = module.groups.flatMap((group) =>
                                    group.permissions.filter((permission) => granted.has(permission.name)),
                                );

                                if (moduleGranted.length === 0) {
                                    return null;
                                }

                                return (
                                    <section key={module.key} className="rounded-card border border-[var(--line)] p-3">
                                        <header className="mb-2 flex items-center justify-between">
                                            <h3 className="text-[0.84375rem] font-semibold text-[var(--text)]">{module.display}</h3>
                                            <span className="font-mono text-[0.75rem] text-[var(--muted)] tabular">
                                                {moduleGranted.length}/{module.total}
                                            </span>
                                        </header>
                                        <div className="flex flex-wrap gap-1.5">
                                            {moduleGranted.map((permission) => (
                                                <span
                                                    key={permission.name}
                                                    className="rounded-full bg-[var(--surface-2)] px-2 py-0.5 text-[0.75rem] text-[var(--text)]"
                                                >
                                                    {permission.label}
                                                </span>
                                            ))}
                                        </div>
                                    </section>
                                );
                            })}
                        </div>
                    ) : (
                        <p className="text-[0.8125rem] text-[var(--muted)]">No se pudo cargar el catálogo de permisos.</p>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}

import { Link, router } from '@inertiajs/react';
import { LockSimpleIcon, PencilSimpleIcon, PlusIcon, ShieldCheckIcon, TrashIcon, UsersIcon } from '@phosphor-icons/react';
import { useState } from 'react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/Modal';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { EmptyState } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { usePermission } from '@/lib/permissions';

interface RoleRow {
    id: number;
    name: string;
    display_name: string;
    description: string | null;
    color: string;
    is_system: boolean;
    permissions_count: number;
    users_count: number;
}

interface RolesIndexProps {
    roles: RoleRow[];
}

export default function RolesIndex({ roles }: RolesIndexProps) {
    const { can } = usePermission();
    const [toDelete, setToDelete] = useState<RoleRow | null>(null);
    const [deleting, setDeleting] = useState(false);

    const confirmDelete = () => {
        if (!toDelete) {
            return;
        }

        setDeleting(true);
        router.delete(route('roles.destroy', toDelete.id), {
            preserveScroll: true,
            onFinish: () => {
                setDeleting(false);
                setToDelete(null);
            },
        });
    };

    return (
        <AppLayout title="Roles">
            <PageHeader
                title="Roles"
                description="Cada rol es una plantilla de permisos. Cambiarlo no altera a nadie hasta que decidas propagarlo."
                actions={
                    can('roles.index.create') ? (
                        <ButtonLink href={route('roles.create')} icon={<PlusIcon size={16} weight="bold" />}>
                            Nuevo rol
                        </ButtonLink>
                    ) : undefined
                }
            />

            {roles.length === 0 ? (
                <EmptyState title="Todavia no hay roles" description="Crea el primero para repartir permisos." icon={<ShieldCheckIcon size={24} />} />
            ) : (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {roles.map((role) => (
                        <article
                            key={role.id}
                            className="flex flex-col rounded-card border border-[var(--line)] bg-[var(--surface)] p-4"
                            style={{ borderTopColor: role.color, borderTopWidth: 3 }}
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <h2 className="truncate text-[0.9375rem] font-semibold text-[var(--text)]">{role.display_name}</h2>
                                    <p className="truncate font-mono text-[0.71875rem] text-[var(--muted)]">{role.name}</p>
                                </div>
                                {role.is_system && (
                                    <Badge tone="neutral">
                                        <LockSimpleIcon size={12} /> Del sistema
                                    </Badge>
                                )}
                            </div>

                            <p className="mt-2 min-h-[2.25rem] text-[0.8125rem] leading-snug text-[var(--muted)]">
                                {role.description ?? 'Sin descripcion.'}
                            </p>

                            <div className="mt-3 flex items-center gap-4 text-[0.78125rem] text-[var(--muted)]">
                                <span className="inline-flex items-center gap-1.5">
                                    <ShieldCheckIcon size={14} />
                                    <span className="font-mono tabular">{role.permissions_count}</span> permisos
                                </span>
                                <span className="inline-flex items-center gap-1.5">
                                    <UsersIcon size={14} />
                                    <span className="font-mono tabular">{role.users_count}</span> usuarios
                                </span>
                            </div>

                            <div className="mt-4 flex items-center gap-2 border-t border-[var(--line)] pt-3">
                                <Link
                                    href={route('roles.show', role.id)}
                                    className="text-[0.78125rem] font-medium text-[var(--accent)] hover:underline"
                                >
                                    Ver detalle
                                </Link>

                                <div className="ml-auto flex items-center gap-1">
                                    {can('roles.index.edit') && !role.is_system && (
                                        <ButtonLink
                                            href={route('roles.edit', role.id)}
                                            size="sm"
                                            variant="ghost"
                                            icon={<PencilSimpleIcon size={15} />}
                                        >
                                            Editar
                                        </ButtonLink>
                                    )}
                                    {can('roles.index.delete') && !role.is_system && role.users_count === 0 && (
                                        <Button size="sm" variant="ghost" onClick={() => setToDelete(role)} icon={<TrashIcon size={15} />}>
                                            Eliminar
                                        </Button>
                                    )}
                                </div>
                            </div>
                        </article>
                    ))}
                </div>
            )}

            <ConfirmDialog
                open={toDelete !== null}
                onClose={() => setToDelete(null)}
                onConfirm={confirmDelete}
                loading={deleting}
                title="Eliminar el rol"
                confirmLabel="Eliminar"
                message={
                    <>
                        Vas a eliminar el rol <strong>{toDelete?.display_name}</strong>. Los permisos que ya tengan asignados sus usuarios no
                        cambian.
                    </>
                }
            />
        </AppLayout>
    );
}

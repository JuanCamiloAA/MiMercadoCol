import { Link, router, usePage } from '@inertiajs/react';
import { KeyIcon, PencilSimpleIcon, PlusIcon, ProhibitIcon, ShieldCheckIcon, TrashIcon, UserGearIcon } from '@phosphor-icons/react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Select } from '@/components/ui/Field';
import { ConfirmDialog } from '@/components/ui/Modal';
import { Badge, PageHeader, SearchInput } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { relative } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { Paginated, PageProps } from '@/types';

interface UserRow {
    id: number;
    name: string;
    email: string;
    document: string | null;
    job_title: string | null;
    is_active: boolean;
    last_login_at: string | null;
    password_change_required: boolean;
    role: { name: string; display_name: string; color: string } | null;
    stores: string[];
}

interface UsersIndexProps {
    users: Paginated<UserRow>;
    filters: { search: string; role: string; store: string; status: string };
    roles: { value: string; label: string; color: string }[];
    stores: { value: number; label: string }[];
}

export default function UsersIndex({ users, filters: initialFilters, roles, stores }: UsersIndexProps) {
    const { can } = usePermission();
    const { flash } = usePage<PageProps>().props;
    const { filters, update, reset, hasActive } = useFilters('users.index', initialFilters);
    const [toDelete, setToDelete] = useState<UserRow | null>(null);
    const [busy, setBusy] = useState(false);

    // La contrasena temporal se muestra una sola vez: despues ya no se puede volver a leer.
    useEffect(() => {
        if (flash?.temporary_password) {
            toast.success(`Contraseña temporal: ${flash.temporary_password}`, {
                duration: 20000,
                description: 'Copiala ahora: no se vuelve a mostrar.',
            });
        }
    }, [flash?.temporary_password]);

    const confirmDelete = () => {
        if (!toDelete) {
            return;
        }

        setBusy(true);
        router.delete(route('users.destroy', toDelete.id), {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setToDelete(null);
            },
        });
    };

    return (
        <AppLayout title="Usuarios">
            <PageHeader
                title="Usuarios"
                description="Cuentas con acceso al sistema y los permisos que tiene cada una."
                actions={
                    can('users.index.create') ? (
                        <ButtonLink href={route('users.create')} icon={<PlusIcon size={16} weight="bold" />}>
                            Nuevo usuario
                        </ButtonLink>
                    ) : undefined
                }
            />

            <Card className="mb-4" padded>
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <SearchInput
                        placeholder="Nombre, correo o documento"
                        value={filters.search}
                        onChange={(event) => update('search', event.target.value)}
                    />
                    <Select
                        options={roles.map((role) => ({ value: role.value, label: role.label }))}
                        placeholder="Todos los roles"
                        value={filters.role}
                        onChange={(event) => update('role', event.target.value)}
                    />
                    <Select
                        options={stores.map((store) => ({ value: store.value, label: store.label }))}
                        placeholder="Todas las sedes"
                        value={filters.store}
                        onChange={(event) => update('store', event.target.value)}
                    />
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

            {users.data.length === 0 ? (
                <EmptyState
                    title="Ningún usuario coincide"
                    description="Cambia los filtros o crea una cuenta nueva."
                    icon={<UserGearIcon size={24} />}
                />
            ) : (
                <>
                    <Table>
                        <THead>
                            <TR>
                                <TH>Usuario</TH>
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
                                        {can('users.show.view') ? (
                                            <Link
                                                href={route('users.show', user.id)}
                                                className="text-[0.84375rem] font-medium text-[var(--text)] hover:text-[var(--accent)]"
                                            >
                                                {user.name}
                                            </Link>
                                        ) : (
                                            <span className="text-[0.84375rem] font-medium text-[var(--text)]">{user.name}</span>
                                        )}
                                        <p className="text-[0.75rem] text-[var(--muted)]">{user.email}</p>
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
                                    <TD className="text-[0.78125rem] text-[var(--muted)]">
                                        {user.stores.length > 0 ? user.stores.join(', ') : '—'}
                                    </TD>
                                    <TD className="text-[0.78125rem] text-[var(--muted)]">
                                        {user.last_login_at ? relative(user.last_login_at) : 'Nunca'}
                                    </TD>
                                    <TD align="center">
                                        {user.is_active ? <Badge tone="accent">Activo</Badge> : <Badge tone="neutral">Inactivo</Badge>}
                                        {user.password_change_required && (
                                            <span className="mt-1 block">
                                                <Badge tone="warn">Clave temporal</Badge>
                                            </span>
                                        )}
                                    </TD>
                                    <TD align="right">
                                        <div className="flex items-center justify-end gap-1">
                                            {can('users.edit.permissions') && (
                                                <ButtonLink
                                                    href={route('users.permissions', user.id)}
                                                    size="sm"
                                                    variant="ghost"
                                                    icon={<ShieldCheckIcon size={15} />}
                                                >
                                                    Permisos
                                                </ButtonLink>
                                            )}
                                            {can('users.index.edit') && (
                                                <ButtonLink
                                                    href={route('users.edit', user.id)}
                                                    size="sm"
                                                    variant="ghost"
                                                    icon={<PencilSimpleIcon size={15} />}
                                                />
                                            )}
                                            {can('users.index.toggle') && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    title={user.is_active ? 'Desactivar' : 'Activar'}
                                                    icon={user.is_active ? <ProhibitIcon size={15} /> : <KeyIcon size={15} />}
                                                    onClick={() =>
                                                        router.patch(route('users.toggle', user.id), {}, { preserveScroll: true })
                                                    }
                                                />
                                            )}
                                            {can('users.index.delete') && (
                                                <Button size="sm" variant="ghost" icon={<TrashIcon size={15} />} onClick={() => setToDelete(user)} />
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

            <ConfirmDialog
                open={toDelete !== null}
                onClose={() => setToDelete(null)}
                onConfirm={confirmDelete}
                loading={busy}
                title="Eliminar el usuario"
                confirmLabel="Eliminar"
                message={
                    <>
                        Vas a eliminar la cuenta de <strong>{toDelete?.name}</strong>. Si ya registro ventas, desactivala en vez de eliminarla.
                    </>
                }
            />
        </AppLayout>
    );
}

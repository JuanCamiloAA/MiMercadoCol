import { router, useForm } from '@inertiajs/react';
import { CheckCircleIcon, KeyIcon, PencilSimpleIcon, PlusIcon, ProhibitIcon, UserGearIcon } from '@phosphor-icons/react';
import { useState } from 'react';
import { ICON_BUTTON, TemporaryPasswordDialog, UsersTabs, type UserTabCounts } from '@/components/platform/users';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input, Select } from '@/components/ui/Field';
import { ConfirmDialog, Modal } from '@/components/ui/Modal';
import { Badge, PageHeader, SearchInput } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { relative } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { Paginated } from '@/types';

interface PlatformUserRow {
    id: number;
    full_name: string;
    initials: string;
    name: string;
    last_name: string | null;
    email: string;
    document: string | null;
    phone: string | null;
    job_title: string | null;
    is_active: boolean;
    is_me: boolean;
    password_change_required: boolean;
    last_login_at: string | null;
}

interface PlatformUsersProps {
    users: Paginated<PlatformUserRow>;
    filters: { search: string; status: string };
    active: number;
    tabCounts: UserTabCounts;
}

type Pending = { kind: 'deactivate' | 'reset'; user: PlatformUserRow } | null;

/**
 * Plataforma > Usuarios > De plataforma: los super usuarios. No pertenecen a ninguna empresa ni
 * sede: entran a todas y eligen con cuál trabajar en el selector.
 */
export default function PlatformUsersIndex({ users, filters: initialFilters, active, tabCounts }: PlatformUsersProps) {
    const { can } = usePermission();
    const { filters, update, reset, hasActive } = useFilters('platform.users.index', initialFilters);
    const [editing, setEditing] = useState<PlatformUserRow | 'new' | null>(null);
    const [pending, setPending] = useState<Pending>(null);
    const [busy, setBusy] = useState(false);

    const confirm = () => {
        if (!pending) {
            return;
        }

        const name = pending.kind === 'reset' ? 'platform.users.reset_password' : 'platform.users.toggle';

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

    const toggle = (user: PlatformUserRow) => {
        if (user.is_active) {
            setPending({ kind: 'deactivate', user });

            return;
        }

        router.post(route('platform.users.toggle', user.id), {}, { preserveScroll: true });
    };

    return (
        <AppLayout title="Usuarios de plataforma">
            <PageHeader
                title="Usuarios"
                description="Los de plataforma son super usuarios: no pertenecen a ninguna empresa ni sede, entran a todas y administran la plataforma."
                actions={
                    can('platform.users.create') && (
                        <Button onClick={() => setEditing('new')}>
                            <PlusIcon size={16} /> Nuevo super usuario
                        </Button>
                    )
                }
            />

            <UsersTabs active="platform" counts={tabCounts} />

            <Card className="mb-4" padded>
                <div className="grid gap-3 sm:grid-cols-[1fr_14rem_auto] sm:items-start">
                    <SearchInput placeholder="Nombre, correo o documento" value={filters.search} onChange={(event) => update('search', event.target.value)} />
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
                <p className="mt-3 text-[0.78125rem] text-[var(--muted)]">
                    {active} {active === 1 ? 'activo' : 'activos'} de {tabCounts.platform}. Nadie puede desactivarse a sí mismo, así que siempre queda uno.
                </p>
            </Card>

            {users.data.length === 0 ? (
                <Card padded={false}>
                    <EmptyState title="Ningún super usuario coincide" description="Cambia los filtros de búsqueda." icon={<UserGearIcon size={24} />} />
                </Card>
            ) : (
                <>
                    <Table>
                        <THead>
                            <TR>
                                <TH>Usuario</TH>
                                <TH>Cargo</TH>
                                <TH>Documento</TH>
                                <TH>Última conexión</TH>
                                <TH align="center">Estado</TH>
                                <TH align="right">Acciones</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {users.data.map((user) => (
                                <TR key={user.id}>
                                    <TD>
                                        <span className="flex items-center gap-2.5">
                                            <span className="grid size-9 shrink-0 place-items-center rounded-full bg-[var(--accent-soft)] text-[0.75rem] font-semibold text-[var(--accent)]">
                                                {user.initials}
                                            </span>
                                            <span className="min-w-0">
                                                <span className="flex items-center gap-1.5 font-medium text-[var(--text)]">
                                                    {user.full_name}
                                                    {user.is_me && <Badge tone="neutral">Tú</Badge>}
                                                </span>
                                                <span className="block truncate text-[0.75rem] text-[var(--muted)]">{user.email}</span>
                                            </span>
                                        </span>
                                    </TD>
                                    <TD className="text-[0.78125rem] text-[var(--muted)]">{user.job_title ?? '—'}</TD>
                                    <TD>
                                        <span className="font-mono text-[0.78125rem] tabular">{user.document ?? '—'}</span>
                                    </TD>
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
                                            {can('platform.users.edit') && (
                                                <button type="button" title="Editar datos" onClick={() => setEditing(user)} className={ICON_BUTTON}>
                                                    <PencilSimpleIcon size={16} />
                                                </button>
                                            )}
                                            {can('platform.users.reset_password') && !user.is_me && (
                                                <button type="button" title="Restablecer contraseña" onClick={() => setPending({ kind: 'reset', user })} className={ICON_BUTTON}>
                                                    <KeyIcon size={16} />
                                                </button>
                                            )}
                                            {can('platform.users.deactivate') && !user.is_me && (
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

            {editing !== null && <PlatformUserDialog user={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}

            <ConfirmDialog
                open={pending !== null}
                onClose={() => setPending(null)}
                onConfirm={confirm}
                loading={busy}
                tone={pending?.kind === 'reset' ? 'primary' : 'danger'}
                title={pending?.kind === 'reset' ? 'Restablecer la contraseña' : 'Desactivar el super usuario'}
                confirmLabel={pending?.kind === 'reset' ? 'Restablecer' : 'Desactivar'}
                message={
                    pending?.kind === 'reset' ? (
                        <>
                            Se genera una contraseña temporal para <strong>{pending.user.full_name}</strong> y la actual deja de servir. Al entrar tendrá
                            que cambiarla.
                        </>
                    ) : (
                        <>
                            <strong>{pending?.user.full_name}</strong> no podrá volver a entrar y su sesión se cierra en la próxima acción. Lo que firmó en
                            la plataforma se conserva, y puedes activarlo de nuevo cuando quieras.
                        </>
                    )
                }
            />

            <TemporaryPasswordDialog />
        </AppLayout>
    );
}

function PlatformUserDialog({ user, onClose }: { user: PlatformUserRow | null; onClose: () => void }) {
    const form = useForm({
        name: user?.name ?? '',
        last_name: user?.last_name ?? '',
        email: user?.email ?? '',
        document: user?.document ?? '',
        phone: user?.phone ?? '',
        job_title: user?.job_title ?? '',
    });
    const { data, setData, errors } = form;

    const submit = () => {
        const options = { preserveScroll: true, onSuccess: () => onClose() };

        if (user) {
            form.put(route('platform.users.update', user.id), options);
        } else {
            form.post(route('platform.users.store'), options);
        }
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={user ? user.full_name : 'Nuevo super usuario'}
            description={user ? undefined : 'Tendrá acceso total: todas las empresas, sus datos y la configuración de la plataforma.'}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button onClick={submit} loading={form.processing}>
                        {user ? 'Guardar' : 'Crear super usuario'}
                    </Button>
                </>
            }
        >
            <div className="grid gap-3 sm:grid-cols-2">
                <Input label="Nombre" required value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} autoFocus />
                <Input label="Apellido" value={data.last_name} onChange={(e) => setData('last_name', e.target.value)} error={errors.last_name} />
                <Input
                    label="Correo"
                    required
                    type="email"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    error={errors.email}
                    hint="Con este correo entra."
                    containerClassName="sm:col-span-2"
                />
                <Input label="Documento" value={data.document} onChange={(e) => setData('document', e.target.value)} error={errors.document} />
                <Input label="Teléfono" inputMode="tel" value={data.phone} onChange={(e) => setData('phone', e.target.value)} error={errors.phone} />
                <Input
                    label="Cargo"
                    value={data.job_title}
                    onChange={(e) => setData('job_title', e.target.value)}
                    error={errors.job_title}
                    placeholder="Soporte, socio, contador…"
                    containerClassName="sm:col-span-2"
                />
            </div>

            {!user && (
                <p className="mt-4 rounded-control bg-[var(--surface-2)] px-3 py-2.5 text-[0.78125rem] text-[var(--muted)]">
                    Al crearlo verás una contraseña temporal una sola vez. Al entrar tendrá que cambiarla.
                </p>
            )}
        </Modal>
    );
}

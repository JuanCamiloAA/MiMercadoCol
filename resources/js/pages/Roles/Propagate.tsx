import { useForm } from '@inertiajs/react';
import { ArrowRightIcon, MinusCircleIcon, PlusCircleIcon } from '@phosphor-icons/react';
import type { FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Checkbox } from '@/components/ui/Field';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { EmptyState, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';

interface PropagateProps {
    role: {
        id: number;
        display_name: string;
        color: string;
    };
    diff: {
        added: string[];
        removed: string[];
    };
    users: {
        id: number;
        name: string;
        email: string;
        is_active: boolean;
        permissions_count: number;
        will_gain: number;
        will_lose: number;
    }[];
}

export default function RolePropagate({ role, diff, users }: PropagateProps) {
    const { data, setData, post, processing } = useForm<{ user_ids: number[] }>({
        user_ids: users.filter((user) => user.will_gain > 0 || user.will_lose > 0).map((user) => user.id),
    });

    const toggle = (id: number) => {
        setData(
            'user_ids',
            data.user_ids.includes(id) ? data.user_ids.filter((value) => value !== id) : [...data.user_ids, id],
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('roles.propagate', role.id));
    };

    const hasChanges = diff.added.length > 0 || diff.removed.length > 0;

    return (
        <AppLayout title="Aplicar el cambio del rol">
            <PageHeader
                title={`Cambios en «${role.display_name}»`}
                description="El rol ya quedó guardado. Elige a quien le aplicas la diferencia; a quien no marques no se le cambia nada."
                actions={
                    <ButtonLink href={route('roles.index')} variant="secondary">
                        Dejarlo así
                    </ButtonLink>
                }
            />

            <div className="grid gap-4 lg:grid-cols-[360px_1fr]">
                <Card className="h-fit">
                    <CardHeader title="Que cambio en la plantilla" />

                    {!hasChanges && <p className="text-[0.8125rem] text-[var(--muted)]">No hay ninguna diferencia pendiente.</p>}

                    {diff.added.length > 0 && (
                        <div className="mb-4">
                            <p className="mb-2 inline-flex items-center gap-1.5 text-[0.78125rem] font-semibold text-[var(--accent)]">
                                <PlusCircleIcon size={15} weight="fill" /> Gana {diff.added.length} permisos
                            </p>
                            <ul className="flex flex-col gap-1">
                                {diff.added.map((permission) => (
                                    <li key={permission} className="rounded-chip bg-[var(--accent-soft)] px-2 py-1 font-mono text-[0.71875rem] text-[var(--accent)]">
                                        {permission}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {diff.removed.length > 0 && (
                        <div>
                            <p className="mb-2 inline-flex items-center gap-1.5 text-[0.78125rem] font-semibold text-[var(--danger)]">
                                <MinusCircleIcon size={15} weight="fill" /> Pierde {diff.removed.length} permisos
                            </p>
                            <ul className="flex flex-col gap-1">
                                {diff.removed.map((permission) => (
                                    <li key={permission} className="rounded-chip bg-[var(--danger-soft)] px-2 py-1 font-mono text-[0.71875rem] text-[var(--danger)]">
                                        {permission}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </Card>

                <Card padded={false}>
                    <div className="px-5 pt-5">
                        <CardHeader
                            title="Usuarios con este rol"
                            description="Se aplica solo la diferencia, respetando los ajustes propios de cada persona."
                        />
                    </div>

                    {users.length === 0 ? (
                        <EmptyState title="Nadie tiene este rol" description="El cambio quedó guardado en la plantilla." />
                    ) : (
                        <form onSubmit={submit}>
                            <Table className="rounded-none border-x-0 border-b-0">
                                <THead>
                                    <TR>
                                        <TH className="w-10">
                                            <Checkbox
                                                label=""
                                                checked={data.user_ids.length === users.length}
                                                onChange={(event) => setData('user_ids', event.target.checked ? users.map((user) => user.id) : [])}
                                            />
                                        </TH>
                                        <TH>Usuario</TH>
                                        <TH align="center">Permisos hoy</TH>
                                        <TH align="center">Gana</TH>
                                        <TH align="center">Pierde</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {users.map((user) => (
                                        <TR key={user.id}>
                                            <TD>
                                                <Checkbox label="" checked={data.user_ids.includes(user.id)} onChange={() => toggle(user.id)} />
                                            </TD>
                                            <TD>
                                                <p className="text-[0.84375rem] font-medium text-[var(--text)]">{user.name}</p>
                                                <p className="text-[0.75rem] text-[var(--muted)]">{user.email}</p>
                                                {!user.is_active && <Badge tone="neutral">Inactivo</Badge>}
                                            </TD>
                                            <TD align="center" className="font-mono tabular">
                                                {user.permissions_count}
                                            </TD>
                                            <TD align="center">
                                                {user.will_gain > 0 ? <Badge tone="accent">+{user.will_gain}</Badge> : <span className="text-[var(--muted)]">—</span>}
                                            </TD>
                                            <TD align="center">
                                                {user.will_lose > 0 ? <Badge tone="danger">−{user.will_lose}</Badge> : <span className="text-[var(--muted)]">—</span>}
                                            </TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>

                            <div className="flex items-center justify-between gap-3 border-t border-[var(--line)] px-5 py-3.5">
                                <p className="text-[0.78125rem] text-[var(--muted)]">
                                    Se aplicará a <span className="font-mono tabular">{data.user_ids.length}</span> de {users.length} usuarios.
                                </p>
                                <Button type="submit" loading={processing} iconRight={<ArrowRightIcon size={15} weight="bold" />}>
                                    Aplicar el cambio
                                </Button>
                            </div>
                        </form>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}

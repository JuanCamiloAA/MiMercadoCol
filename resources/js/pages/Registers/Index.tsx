import { router, useForm } from '@inertiajs/react';
import { CashRegisterIcon, PencilSimpleIcon, PlusIcon, TrashIcon, UploadSimpleIcon } from '@phosphor-icons/react';
import { type FormEvent, useState } from 'react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Checkbox, Input, Select } from '@/components/ui/Field';
import { ConfirmDialog, Modal } from '@/components/ui/Modal';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { EmptyState, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { dateTime, money, time } from '@/lib/format';
import { usePermission } from '@/lib/permissions';

interface RegisterRow {
    id: number;
    name: string;
    code: string;
    store: { id: number; name: string } | null;
    is_active: boolean;
    open_session: { id: number; cashier: string | null; opened_at: string } | null;
}

interface SessionRow {
    id: number;
    register: string | null;
    store: string | null;
    cashier: string | null;
    opened_at: string;
    closed_at: string | null;
    expected_amount: number | null;
    counted_amount: number | null;
    difference: number | null;
}

interface RegistersIndexProps {
    registers: RegisterRow[];
    recentSessions: SessionRow[];
    stores: { value: number; label: string }[];
}

export default function RegistersIndex({ registers, recentSessions, stores }: RegistersIndexProps) {
    const { can } = usePermission();
    const [editing, setEditing] = useState<RegisterRow | null>(null);
    const [creating, setCreating] = useState(false);
    const [toDelete, setToDelete] = useState<RegisterRow | null>(null);
    const [busy, setBusy] = useState(false);

    const form = useForm({
        store_id: stores[0]?.value ?? 0,
        name: '',
        code: '',
        is_active: true as boolean,
    });

    const openCreate = () => {
        form.setData({ store_id: stores[0]?.value ?? 0, name: '', code: '', is_active: true });
        form.clearErrors();
        setCreating(true);
    };

    const openEdit = (register: RegisterRow) => {
        form.setData({
            store_id: register.store?.id ?? (stores[0]?.value ?? 0),
            name: register.name,
            code: register.code,
            is_active: register.is_active,
        });
        form.clearErrors();
        setEditing(register);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (editing) {
            form.put(route('registers.update', editing.id), { preserveScroll: true, onSuccess: () => setEditing(null) });
        } else {
            form.post(route('registers.store'), { preserveScroll: true, onSuccess: () => setCreating(false) });
        }
    };

    const confirmDelete = () => {
        if (!toDelete) {
            return;
        }

        setBusy(true);
        router.delete(route('registers.destroy', toDelete.id), {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setToDelete(null);
            },
        });
    };

    return (
        <AppLayout title="Cajas">
            <PageHeader
                title="Cajas"
                description="Puntos de cobro de cada sede y sus últimos turnos."
                actions={
                    <>
                        {can('registers.index.import') && (
                            <ButtonLink href={route('registers.import.form')} variant="secondary" icon={<UploadSimpleIcon size={16} />}>
                                Importar
                            </ButtonLink>
                        )}
                        {can('registers.open.view') && (
                            <ButtonLink href={route('registers.opening.create')} variant="secondary">
                                Abrir caja
                            </ButtonLink>
                        )}
                        {can('registers.index.manage') && (
                            <Button onClick={openCreate} icon={<PlusIcon size={16} weight="bold" />}>
                                Nueva caja
                            </Button>
                        )}
                    </>
                }
            />

            {registers.length === 0 ? (
                <EmptyState
                    title="Todavía no hay cajas"
                    description="Crea la primera para poder abrir turno y vender."
                    icon={<CashRegisterIcon size={24} />}
                />
            ) : (
                <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {registers.map((register) => (
                        <article key={register.id} className="rounded-card border border-[var(--line)] bg-[var(--surface)] p-4">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <h2 className="truncate text-[0.9375rem] font-semibold">{register.name}</h2>
                                    <p className="truncate text-[0.75rem] text-[var(--muted)]">
                                        {register.store?.name} · prefijo <span className="font-mono">{register.code}</span>
                                    </p>
                                </div>
                                {register.is_active ? <Badge tone="accent">Activa</Badge> : <Badge tone="neutral">Inactiva</Badge>}
                            </div>

                            <div className="mt-3 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 py-2.5">
                                {register.open_session ? (
                                    <>
                                        <p className="text-[0.78125rem] font-medium text-[var(--accent)]">Turno abierto</p>
                                        <p className="text-[0.75rem] text-[var(--muted)]">
                                            {register.open_session.cashier} · desde {time(register.open_session.opened_at)}
                                        </p>
                                    </>
                                ) : (
                                    <p className="text-[0.78125rem] text-[var(--muted)]">Sin turno abierto</p>
                                )}
                            </div>

                            {can('registers.index.manage') && (
                                <div className="mt-3 flex items-center gap-1 border-t border-[var(--line)] pt-3">
                                    <Button size="sm" variant="ghost" icon={<PencilSimpleIcon size={15} />} onClick={() => openEdit(register)}>
                                        Editar
                                    </Button>
                                    {!register.open_session && (
                                        <Button size="sm" variant="ghost" icon={<TrashIcon size={15} />} onClick={() => setToDelete(register)}>
                                            Eliminar
                                        </Button>
                                    )}
                                </div>
                            )}
                        </article>
                    ))}
                </div>
            )}

            <Card padded={false}>
                <div className="px-5 pt-5">
                    <CardHeader title="Últimos turnos cerrados" description="Con su arqueo y la diferencia que quedó." />
                </div>
                <Table className="rounded-none border-x-0 border-b-0">
                    <THead>
                        <TR>
                            <TH>Caja</TH>
                            <TH>Cajero</TH>
                            <TH>Apertura</TH>
                            <TH>Cierre</TH>
                            <TH align="right">Esperado</TH>
                            <TH align="right">Contado</TH>
                            <TH align="right">Diferencia</TH>
                            <TH align="right">Informe</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {recentSessions.length === 0 && (
                            <TR>
                                <TD colSpan={8} className="py-6 text-center text-[0.8125rem] text-[var(--muted)]">
                                    Todavía no se ha cerrado ningún turno.
                                </TD>
                            </TR>
                        )}
                        {recentSessions.map((session) => (
                            <TR key={session.id}>
                                <TD className="text-[0.8125rem] font-medium">
                                    {session.register}
                                    <span className="block text-[0.71875rem] text-[var(--muted)]">{session.store}</span>
                                </TD>
                                <TD className="text-[0.8125rem]">{session.cashier}</TD>
                                <TD className="text-[0.78125rem] text-[var(--muted)]">{dateTime(session.opened_at)}</TD>
                                <TD className="text-[0.78125rem] text-[var(--muted)]">{session.closed_at ? dateTime(session.closed_at) : '—'}</TD>
                                <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                    {money(session.expected_amount ?? 0)}
                                </TD>
                                <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                    {money(session.counted_amount ?? 0)}
                                </TD>
                                <TD
                                    align="right"
                                    className="font-mono text-[0.8125rem] font-semibold tabular"
                                    style={{ color: (session.difference ?? 0) === 0 ? 'var(--accent)' : 'var(--danger)' }}
                                >
                                    {(session.difference ?? 0) > 0 ? '+' : ''}
                                    {money(session.difference ?? 0)}
                                </TD>
                                <TD align="right">
                                    <a
                                        href={route('registers.closing.report', session.id)}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="text-[0.78125rem] font-medium text-[var(--accent)] hover:underline"
                                    >
                                        Informe Z
                                    </a>
                                </TD>
                            </TR>
                        ))}
                    </TBody>
                </Table>
            </Card>

            <Modal
                open={creating || editing !== null}
                onClose={() => {
                    setCreating(false);
                    setEditing(null);
                }}
                title={editing ? 'Editar la caja' : 'Nueva caja'}
                size="sm"
                footer={
                    <>
                        <Button
                            variant="secondary"
                            onClick={() => {
                                setCreating(false);
                                setEditing(null);
                            }}
                        >
                            Cancelar
                        </Button>
                        <Button onClick={submit} loading={form.processing}>
                            Guardar
                        </Button>
                    </>
                }
            >
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <Select
                        label="Sede"
                        required
                        options={stores}
                        value={form.data.store_id}
                        onChange={(event) => form.setData('store_id', Number(event.target.value))}
                        error={form.errors.store_id}
                    />
                    <Input label="Nombre" required value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} error={form.errors.name} />
                    <Input
                        label="Código"
                        required
                        value={form.data.code}
                        onChange={(event) => form.setData('code', event.target.value.toUpperCase())}
                        error={form.errors.code}
                        hint="Es el prefijo del número de tiquete. Por ejemplo C1 genera C1-000482."
                    />
                    <Checkbox label="Caja activa" checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} />
                </form>
            </Modal>

            <ConfirmDialog
                open={toDelete !== null}
                onClose={() => setToDelete(null)}
                onConfirm={confirmDelete}
                loading={busy}
                title="Eliminar la caja"
                confirmLabel="Eliminar"
                message={
                    <>
                        Vas a eliminar <strong>{toDelete?.name}</strong>. Solo se puede si nunca tuvo turnos.
                    </>
                }
            />
        </AppLayout>
    );
}

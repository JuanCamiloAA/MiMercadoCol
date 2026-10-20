import { router, useForm } from '@inertiajs/react';
import { DeviceMobileIcon, PencilSimpleIcon, PlusIcon } from '@phosphor-icons/react';
import { type FormEvent, useEffect, useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input, Select, Toggle } from '@/components/ui/Field';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { Modal } from '@/components/ui/Modal';
import { EmptyState, TBody, TD, TH, THead, TR, Table } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { relative } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import PaymentTabs from '@/pages/PaymentMethods/components/PaymentTabs';

interface Terminal {
    id: number;
    name: string;
    device_id: string;
    model: string | null;
    store_id: number;
    store: string | null;
    register_id: number | null;
    register: string | null;
    gateway_account_id: number;
    account: string | null;
    sandbox: boolean;
    is_active: boolean;
    last_seen_at: string | null;
}

interface Props {
    terminals: Terminal[];
    accounts: { value: number; label: string }[];
    stores: { id: number; name: string; registers: { id: number; name: string }[] }[];
}

/**
 * Los datáfonos integrados con la pasarela. La caja les manda el cobro y el aparato lo cobra; sin
 * caja fija, atienden a cualquier caja de su sede.
 */
export default function PaymentTerminals({ terminals, accounts, stores }: Props) {
    const { can } = usePermission();
    const canEdit = can('payment_methods.terminals.edit');
    const [editing, setEditing] = useState<Terminal | 'new' | null>(null);

    return (
        <AppLayout title="Datáfonos integrados">
            <PageHeader
                title="Medios de pago"
                description="Datáfonos conectados a la pasarela: el cobro sale de la caja y el resultado vuelve solo."
                actions={
                    canEdit && accounts.length > 0 ? (
                        <Button icon={<PlusIcon size={16} />} onClick={() => setEditing('new')}>
                            Registrar datáfono
                        </Button>
                    ) : undefined
                }
            />

            <PaymentTabs active="terminals" />

            <Card padded={false}>
                {terminals.length === 0 ? (
                    <EmptyState
                        icon={<DeviceMobileIcon size={24} />}
                        title="No hay datáfonos integrados"
                        description={accounts.length === 0 ? 'Primero conecta una pasarela que maneje datáfonos (Bold, por ejemplo) en la pestaña Pasarelas.' : 'Registra el datáfono con el identificador que muestra el aparato o el panel de la pasarela.'}
                    />
                ) : (
                    <Table>
                        <THead>
                            <TH>Datáfono</TH>
                            <TH>Sede y caja</TH>
                            <TH>Cuenta</TH>
                            <TH>Última conexión</TH>
                            <TH align="right">{''}</TH>
                        </THead>
                        <TBody>
                            {terminals.map((terminal) => (
                                <TR key={terminal.id}>
                                    <TD>
                                        <span className="block font-medium">{terminal.name}</span>
                                        <span className="block font-mono text-[0.75rem] text-[var(--muted)]">
                                            {terminal.device_id}
                                            {terminal.model ? ` · ${terminal.model}` : ''}
                                        </span>
                                    </TD>
                                    <TD>
                                        {terminal.store}
                                        <span className="block text-[0.75rem] text-[var(--muted)]">{terminal.register ?? 'Cualquier caja de la sede'}</span>
                                    </TD>
                                    <TD>
                                        <span className="block">{terminal.account}</span>
                                        {!terminal.is_active && <Badge tone="warn">Apagado</Badge>}
                                    </TD>
                                    <TD>{terminal.last_seen_at ? relative(terminal.last_seen_at) : 'Nunca'}</TD>
                                    <TD align="right">
                                        {canEdit && (
                                            <div className="flex justify-end gap-1.5">
                                                {terminal.sandbox && (
                                                    <Button size="sm" variant="secondary" onClick={() => router.post(route('payment_methods.terminals.test', terminal.id), {}, { preserveScroll: true })}>
                                                        Cobro de prueba
                                                    </Button>
                                                )}
                                                <Button size="sm" variant="ghost" icon={<PencilSimpleIcon size={15} />} onClick={() => setEditing(terminal)} aria-label={`Editar ${terminal.name}`} />
                                            </div>
                                        )}
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                )}
            </Card>

            {canEdit && <TerminalModal terminal={editing} onClose={() => setEditing(null)} accounts={accounts} stores={stores} />}
        </AppLayout>
    );
}

function TerminalModal({ terminal, onClose, accounts, stores }: { terminal: Terminal | 'new' | null; onClose: () => void; accounts: Props['accounts']; stores: Props['stores'] }) {
    const current = terminal === 'new' ? null : terminal;
    const blank = {
        name: '',
        gateway_account_id: (accounts[0]?.value ?? '') as number | '',
        store_id: (stores[0]?.id ?? '') as number | '',
        register_id: '' as number | '',
        device_id: '',
        model: '',
        is_active: true,
    };
    const form = useForm(blank);

    useEffect(() => {
        if (terminal !== null) {
            form.setData(
                current
                    ? {
                          name: current.name,
                          gateway_account_id: current.gateway_account_id,
                          store_id: current.store_id,
                          register_id: current.register_id ?? '',
                          device_id: current.device_id,
                          model: current.model ?? '',
                          is_active: current.is_active,
                      }
                    : blank,
            );
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [terminal]);

    const registers = stores.find((store) => store.id === form.data.store_id)?.registers ?? [];

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, register_id: data.register_id === '' ? null : data.register_id }));

        if (current) {
            form.put(route('payment_methods.terminals.update', current.id), { preserveScroll: true, onSuccess: () => onClose() });
        } else {
            form.post(route('payment_methods.terminals.store'), { preserveScroll: true, onSuccess: () => onClose() });
        }
    };

    return (
        <Modal
            open={terminal !== null}
            onClose={onClose}
            title={current ? `Editar «${current.name}»` : 'Registrar datáfono'}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button type="submit" form="terminal-form" loading={form.processing}>
                        Guardar
                    </Button>
                </>
            }
        >
            <form id="terminal-form" onSubmit={submit} className="flex flex-col gap-3">
                <Input label="Nombre" required maxLength={80} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} error={form.errors.name} placeholder="Datáfono caja 1" />
                <Select
                    label="Cuenta de pasarela"
                    required
                    options={accounts}
                    value={form.data.gateway_account_id}
                    onChange={(event) => form.setData('gateway_account_id', Number(event.target.value))}
                    error={form.errors.gateway_account_id}
                />
                <div className="grid gap-3 sm:grid-cols-2">
                    <Select
                        label="Sede"
                        required
                        options={stores.map((store) => ({ value: store.id, label: store.name }))}
                        value={form.data.store_id}
                        onChange={(event) => form.setData((data) => ({ ...data, store_id: Number(event.target.value), register_id: '' }))}
                        error={form.errors.store_id}
                    />
                    <Select
                        label="Caja"
                        options={registers.map((register) => ({ value: register.id, label: register.name }))}
                        placeholder="Cualquier caja de la sede"
                        value={form.data.register_id}
                        onChange={(event) => form.setData('register_id', event.target.value === '' ? '' : Number(event.target.value))}
                        error={form.errors.register_id}
                    />
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Input label="Identificador del aparato" required maxLength={120} value={form.data.device_id} onChange={(event) => form.setData('device_id', event.target.value)} error={form.errors.device_id} />
                    <Input label="Modelo" maxLength={60} value={form.data.model} onChange={(event) => form.setData('model', event.target.value)} error={form.errors.model} />
                </div>
                <Toggle checked={form.data.is_active} onChange={(checked) => form.setData('is_active', checked)} label="Encendido" hint="Apagado, la caja no le manda cobros." />
            </form>
        </Modal>
    );
}

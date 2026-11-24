import { Link, router, useForm } from '@inertiajs/react';
import { PencilSimpleIcon, PlusIcon, TrashIcon, UploadSimpleIcon, UsersIcon } from '@phosphor-icons/react';
import { type FormEvent, useState } from 'react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, StatCard } from '@/components/ui/Card';
import { Checkbox, Input, Select, Textarea } from '@/components/ui/Field';
import { ConfirmDialog, Modal } from '@/components/ui/Modal';
import { Badge, PageHeader, SearchInput } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { money, number } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { Paginated } from '@/types';

interface CustomerRow {
    id: number;
    name: string;
    document_type: string;
    document_number: string;
    phone: string | null;
    email: string | null;
    address: string | null;
    neighborhood: string | null;
    delivery_notes: string | null;
    business_name: string | null;
    credit_enabled: boolean;
    credit_blocked: boolean;
    credit_limit: number;
    credit_term_days: number;
    payment_term_id: number | null;
    is_online_user: boolean;
    is_active: boolean;
    balance: number;
    overdue_balance: number;
    available_credit: number;
}

interface CustomersIndexProps {
    customers: Paginated<CustomerRow>;
    filters: { search: string; credit: string; status: string };
    metrics: { total: number; with_credit: number; blocked: number; online: number };
    documentTypes: string[];
    paymentTerms: { value: number; label: string; days: number }[];
    defaultPaymentTermId: number | null;
}

export default function CustomersIndex({ customers, filters: initialFilters, metrics, documentTypes, paymentTerms, defaultPaymentTermId }: CustomersIndexProps) {
    const { can } = usePermission();
    const { filters, update, reset, hasActive } = useFilters('customers.index', initialFilters);
    const [editing, setEditing] = useState<CustomerRow | null>(null);
    const [creating, setCreating] = useState(false);
    const [toDelete, setToDelete] = useState<CustomerRow | null>(null);
    const [busy, setBusy] = useState(false);

    const form = useForm({
        document_type: 'CC',
        document_number: '',
        name: '',
        business_name: '',
        email: '',
        phone: '',
        address: '',
        neighborhood: '',
        delivery_notes: '',
        credit_enabled: false as boolean,
        credit_limit: 0,
        payment_term_id: '' as string,
        is_online_user: false as boolean,
        password: '',
        is_active: true as boolean,
    });

    const openCreate = () => {
        form.setData({
            document_type: 'CC',
            document_number: '',
            name: '',
            business_name: '',
            email: '',
            phone: '',
            address: '',
            neighborhood: '',
            delivery_notes: '',
            credit_enabled: false,
            credit_limit: 0,
            payment_term_id: String(defaultPaymentTermId ?? ''),
            is_online_user: false,
            password: '',
            is_active: true,
        });
        form.clearErrors();
        setCreating(true);
    };

    const openEdit = (customer: CustomerRow) => {
        form.setData({
            document_type: customer.document_type,
            document_number: customer.document_number,
            name: customer.name,
            business_name: customer.business_name ?? '',
            email: customer.email ?? '',
            phone: customer.phone ?? '',
            address: customer.address ?? '',
            neighborhood: customer.neighborhood ?? '',
            delivery_notes: customer.delivery_notes ?? '',
            credit_enabled: customer.credit_enabled,
            credit_limit: customer.credit_limit,
            payment_term_id: String(customer.payment_term_id ?? defaultPaymentTermId ?? ''),
            is_online_user: customer.is_online_user,
            password: '',
            is_active: customer.is_active,
        });
        form.clearErrors();
        setEditing(customer);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (editing) {
            form.put(route('customers.update', editing.id), { preserveScroll: true, onSuccess: () => setEditing(null) });
        } else {
            form.post(route('customers.store'), { preserveScroll: true, onSuccess: () => setCreating(false) });
        }
    };

    const confirmDelete = () => {
        if (!toDelete) {
            return;
        }

        setBusy(true);
        router.delete(route('customers.destroy', toDelete.id), {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setToDelete(null);
            },
        });
    };

    return (
        <AppLayout title="Clientes">
            <PageHeader
                title="Clientes"
                description="Quien compra, quien tiene crédito y quien pide a domicilio."
                actions={
                    <>
                        {can('customers.index.import') && (
                            <ButtonLink href={route('customers.import.form')} variant="secondary" icon={<UploadSimpleIcon size={16} />}>
                                Importar
                            </ButtonLink>
                        )}
                        {can('customers.index.create') && (
                            <Button onClick={openCreate} icon={<PlusIcon size={16} weight="bold" />}>
                                Nuevo cliente
                            </Button>
                        )}
                    </>
                }
            />

            <div className="mb-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Clientes" value={number(metrics.total)} icon={<UsersIcon size={16} weight="bold" />} tone="neutral" />
                <StatCard label="Con crédito" value={number(metrics.with_credit)} />
                <StatCard label="Crédito bloqueado" value={number(metrics.blocked)} tone={metrics.blocked > 0 ? 'danger' : 'neutral'} />
                <StatCard label="Con acceso en línea" value={number(metrics.online)} tone="neutral" />
            </div>

            <Card className="mb-4">
                <div className="grid gap-3 sm:grid-cols-3">
                    <SearchInput
                        placeholder="Nombre, documento o teléfono"
                        value={filters.search}
                        onChange={(event) => update('search', event.target.value)}
                    />
                    <Select
                        options={[
                            { value: 'enabled', label: 'Con crédito' },
                            { value: 'blocked', label: 'Crédito bloqueado' },
                        ]}
                        placeholder="Cualquier crédito"
                        value={filters.credit}
                        onChange={(event) => update('credit', event.target.value)}
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

            {customers.data.length === 0 ? (
                <EmptyState title="Ningún cliente coincide" description="Cambia los filtros o registra uno nuevo." icon={<UsersIcon size={24} />} />
            ) : (
                <>
                    <Table>
                        <THead>
                            <TR>
                                <TH>Cliente</TH>
                                <TH>Contacto</TH>
                                <TH align="right">Cupo</TH>
                                <TH align="right">Saldo</TH>
                                <TH align="right">Disponible</TH>
                                <TH align="center">Estado</TH>
                                <TH align="right">Acciones</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {customers.data.map((customer) => (
                                <TR key={customer.id}>
                                    <TD>
                                        {can('customers.show.view') ? (
                                            <Link
                                                href={route('customers.show', customer.id)}
                                                className="text-[0.84375rem] font-medium text-[var(--text)] hover:text-[var(--accent)]"
                                            >
                                                {customer.name}
                                            </Link>
                                        ) : (
                                            <span className="text-[0.84375rem] font-medium text-[var(--text)]">{customer.name}</span>
                                        )}
                                        <p className="font-mono text-[0.71875rem] text-[var(--muted)] tabular">
                                            {customer.document_type} {customer.document_number}
                                        </p>
                                    </TD>
                                    <TD>
                                        <p className="font-mono text-[0.78125rem] tabular">{customer.phone ?? '—'}</p>
                                        <p className="text-[0.71875rem] text-[var(--muted)]">{customer.neighborhood ?? ''}</p>
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                        {customer.credit_enabled ? money(customer.credit_limit) : '—'}
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                        {customer.balance > 0 ? (
                                            <span className={customer.overdue_balance > 0 ? 'text-[var(--danger)]' : 'text-[var(--text)]'}>
                                                {money(customer.balance)}
                                            </span>
                                        ) : (
                                            <span className="text-[var(--muted)]">—</span>
                                        )}
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] text-[var(--accent)] tabular">
                                        {customer.credit_enabled ? money(customer.available_credit) : '—'}
                                    </TD>
                                    <TD align="center">
                                        <div className="flex flex-col items-center gap-1">
                                            {customer.is_active ? <Badge tone="accent">Activo</Badge> : <Badge tone="neutral">Inactivo</Badge>}
                                            {customer.credit_blocked && <Badge tone="danger">Bloqueado</Badge>}
                                            {customer.is_online_user && <Badge tone="info">En línea</Badge>}
                                        </div>
                                    </TD>
                                    <TD align="right">
                                        <div className="flex items-center justify-end gap-1">
                                            {can('customers.index.edit') && (
                                                <Button size="sm" variant="ghost" icon={<PencilSimpleIcon size={15} />} onClick={() => openEdit(customer)} />
                                            )}
                                            {can('customers.index.delete') && (
                                                <Button size="sm" variant="ghost" icon={<TrashIcon size={15} />} onClick={() => setToDelete(customer)} />
                                            )}
                                        </div>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>

                    <Pagination meta={customers} />
                </>
            )}

            <Modal
                open={creating || editing !== null}
                onClose={() => {
                    setCreating(false);
                    setEditing(null);
                }}
                title={editing ? 'Editar el cliente' : 'Nuevo cliente'}
                size="lg"
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
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Select
                        label="Tipo de documento"
                        required
                        options={documentTypes.map((type) => ({ value: type, label: type }))}
                        value={form.data.document_type}
                        onChange={(event) => form.setData('document_type', event.target.value)}
                        error={form.errors.document_type}
                    />
                    <Input
                        label="Número de documento"
                        required
                        value={form.data.document_number}
                        onChange={(event) => form.setData('document_number', event.target.value)}
                        error={form.errors.document_number}
                    />
                    <Input
                        label="Nombre"
                        required
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        error={form.errors.name}
                        containerClassName="sm:col-span-2"
                    />
                    <Input
                        label="Razón social"
                        value={form.data.business_name}
                        onChange={(event) => form.setData('business_name', event.target.value)}
                        error={form.errors.business_name}
                    />
                    <Input label="Teléfono" value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} error={form.errors.phone} />
                    <Input
                        label="Correo"
                        type="email"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                        error={form.errors.email}
                    />
                    <Input
                        label="Barrio"
                        value={form.data.neighborhood}
                        onChange={(event) => form.setData('neighborhood', event.target.value)}
                        error={form.errors.neighborhood}
                    />
                    <Input
                        label="Dirección"
                        value={form.data.address}
                        onChange={(event) => form.setData('address', event.target.value)}
                        error={form.errors.address}
                        containerClassName="sm:col-span-2"
                    />
                    <Textarea
                        label="Indicaciones de entrega"
                        value={form.data.delivery_notes}
                        onChange={(event) => form.setData('delivery_notes', event.target.value)}
                        error={form.errors.delivery_notes}
                        className="sm:col-span-2"
                    />

                    <div className="sm:col-span-2">
                        <Checkbox
                            label="Habilitar crédito"
                            checked={form.data.credit_enabled}
                            onChange={(event) => form.setData('credit_enabled', event.target.checked)}
                        />
                    </div>

                    {form.data.credit_enabled && (
                        <>
                            <Input
                                label="Cupo"
                                type="number"
                                min={0}
                                value={form.data.credit_limit}
                                onChange={(event) => form.setData('credit_limit', Number(event.target.value))}
                                error={form.errors.credit_limit}
                                prefix="$"
                            />
                            <Select
                                label="Plazo de pago"
                                value={form.data.payment_term_id}
                                onChange={(event) => form.setData('payment_term_id', event.target.value)}
                                options={paymentTerms.map((term) => ({ value: String(term.value), label: `${term.label} (${term.days} días)` }))}
                                placeholder="Elige el plazo"
                                error={form.errors.payment_term_id}
                            />
                        </>
                    )}

                    <div className="sm:col-span-2">
                        <Checkbox
                            label="Puede entrar a la tienda en línea"
                            checked={form.data.is_online_user}
                            onChange={(event) => form.setData('is_online_user', event.target.checked)}
                        />
                    </div>

                    {form.data.is_online_user && (
                        <Input
                            label={editing ? 'Nueva contraseña de la tienda' : 'Contraseña de la tienda'}
                            type="password"
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                            error={form.errors.password}
                            hint={editing ? 'Déjala vacía para no cambiarla.' : 'Mínimo 8 caracteres.'}
                            containerClassName="sm:col-span-2"
                        />
                    )}

                    <div className="sm:col-span-2">
                        <Checkbox
                            label="Cliente activo"
                            checked={form.data.is_active}
                            onChange={(event) => form.setData('is_active', event.target.checked)}
                        />
                    </div>
                </form>
            </Modal>

            <ConfirmDialog
                open={toDelete !== null}
                onClose={() => setToDelete(null)}
                onConfirm={confirmDelete}
                loading={busy}
                title="Eliminar el cliente"
                confirmLabel="Eliminar"
                message={
                    <>
                        Vas a eliminar a <strong>{toDelete?.name}</strong>. Solo se puede si nunca compró ni pidió a domicilio.
                    </>
                }
            />
        </AppLayout>
    );
}

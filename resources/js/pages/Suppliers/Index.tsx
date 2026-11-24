import { Link, router, useForm } from '@inertiajs/react';
import { PencilSimpleIcon, PlusIcon, TrashIcon, TruckIcon, UploadSimpleIcon } from '@phosphor-icons/react';
import { type FormEvent, useState } from 'react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Checkbox, Input, Select } from '@/components/ui/Field';
import { ConfirmDialog, Modal } from '@/components/ui/Modal';
import { Badge, Chip, PageHeader, SearchInput } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { money } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { Paginated } from '@/types';

interface SupplierRow {
    id: number;
    name: string;
    tax_id: string | null;
    contact_name: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    lead_time_days: number;
    delivery_days: string[];
    payment_term_id: number | null;
    payment_term: string | null;
    is_active: boolean;
    products_count: number;
    /** Lo que debe por devoluciones despachadas: nota crédito, reembolso o reposición pendiente. */
    returns_pending: number;
}

interface SuppliersIndexProps {
    suppliers: Paginated<SupplierRow>;
    filters: { search: string };
    deliveryDays: string[];
    paymentTerms: { value: number; label: string; days: number }[];
    defaultPaymentTermId: number | null;
}

const DAY_LABELS: Record<string, string> = {
    lun: 'Lun',
    mar: 'Mar',
    mie: 'Mie',
    jue: 'Jue',
    vie: 'Vie',
    sab: 'Sab',
    dom: 'Dom',
};

export default function SuppliersIndex({ suppliers, filters: initialFilters, deliveryDays, paymentTerms, defaultPaymentTermId }: SuppliersIndexProps) {
    const { can } = usePermission();
    const { filters, update } = useFilters('suppliers.index', initialFilters);
    const [editing, setEditing] = useState<SupplierRow | null>(null);
    const [creating, setCreating] = useState(false);
    const [toDelete, setToDelete] = useState<SupplierRow | null>(null);
    const [busy, setBusy] = useState(false);

    const form = useForm({
        name: '',
        tax_id: '',
        contact_name: '',
        phone: '',
        email: '',
        address: '',
        lead_time_days: 2,
        delivery_days: [] as string[],
        payment_term_id: '' as string,
        is_active: true as boolean,
    });

    const openCreate = () => {
        form.setData({
            name: '',
            tax_id: '',
            contact_name: '',
            phone: '',
            email: '',
            address: '',
            lead_time_days: 2,
            delivery_days: [],
            payment_term_id: String(defaultPaymentTermId ?? ''),
            is_active: true,
        });
        form.clearErrors();
        setCreating(true);
    };

    const openEdit = (supplier: SupplierRow) => {
        form.setData({
            name: supplier.name,
            tax_id: supplier.tax_id ?? '',
            contact_name: supplier.contact_name ?? '',
            phone: supplier.phone ?? '',
            email: supplier.email ?? '',
            address: supplier.address ?? '',
            lead_time_days: supplier.lead_time_days,
            delivery_days: supplier.delivery_days,
            payment_term_id: String(supplier.payment_term_id ?? ''),
            is_active: supplier.is_active,
        });
        form.clearErrors();
        setEditing(supplier);
    };

    const toggleDay = (day: string) => {
        form.setData(
            'delivery_days',
            form.data.delivery_days.includes(day) ? form.data.delivery_days.filter((value) => value !== day) : [...form.data.delivery_days, day],
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (editing) {
            form.put(route('suppliers.update', editing.id), { preserveScroll: true, onSuccess: () => setEditing(null) });
        } else {
            form.post(route('suppliers.store'), { preserveScroll: true, onSuccess: () => setCreating(false) });
        }
    };

    const confirmDelete = () => {
        if (!toDelete) {
            return;
        }

        setBusy(true);
        router.delete(route('suppliers.destroy', toDelete.id), {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setToDelete(null);
            },
        });
    };

    return (
        <AppLayout title="Proveedores">
            <PageHeader
                title="Proveedores"
                description="A quien se le compra, cada cuanto entrega y con quien se habla."
                actions={
                    <>
                        {can('suppliers.index.import') && (
                            <ButtonLink href={route('suppliers.import.form')} variant="secondary" icon={<UploadSimpleIcon size={16} />}>
                                Importar
                            </ButtonLink>
                        )}
                        {can('suppliers.index.create') && (
                            <Button onClick={openCreate} icon={<PlusIcon size={16} weight="bold" />}>
                                Nuevo proveedor
                            </Button>
                        )}
                    </>
                }
            />

            <Card className="mb-4">
                <SearchInput
                    placeholder="Nombre, NIT o contacto"
                    value={filters.search}
                    onChange={(event) => update('search', event.target.value)}
                    containerClassName="max-w-md"
                />
            </Card>

            {suppliers.data.length === 0 ? (
                <EmptyState title="Ningún proveedor coincide" description="Crea uno nuevo o cambia la búsqueda." icon={<TruckIcon size={24} />} />
            ) : (
                <>
                    <Table>
                        <THead>
                            <TR>
                                <TH>Proveedor</TH>
                                <TH>Contacto</TH>
                                <TH>Entrega</TH>
                                <TH align="center">Artículos</TH>
                                <TH align="right">A favor pendiente</TH>
                                <TH align="center">Estado</TH>
                                <TH align="right">Acciones</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {suppliers.data.map((supplier) => (
                                <TR key={supplier.id}>
                                    <TD>
                                        <p className="text-[0.84375rem] font-medium text-[var(--text)]">{supplier.name}</p>
                                        <p className="font-mono text-[0.71875rem] text-[var(--muted)]">{supplier.tax_id ?? 'Sin NIT'}</p>
                                    </TD>
                                    <TD>
                                        <p className="text-[0.8125rem]">{supplier.contact_name ?? '—'}</p>
                                        <p className="font-mono text-[0.71875rem] text-[var(--muted)] tabular">{supplier.phone ?? ''}</p>
                                    </TD>
                                    <TD>
                                        <p className="text-[0.78125rem] text-[var(--muted)]">
                                            {supplier.lead_time_days} días de entrega{supplier.payment_term ? ` · paga a ${supplier.payment_term}` : ''}
                                        </p>
                                        <div className="mt-1 flex flex-wrap gap-1">
                                            {supplier.delivery_days.map((day) => (
                                                <span key={day} className="rounded-chip bg-[var(--surface-2)] px-1.5 py-0.5 text-[0.6875rem] text-[var(--muted)]">
                                                    {DAY_LABELS[day] ?? day}
                                                </span>
                                            ))}
                                        </div>
                                    </TD>
                                    <TD align="center" className="font-mono text-[0.8125rem] tabular">
                                        {supplier.products_count}
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                        {supplier.returns_pending > 0 ? (
                                            can('purchases.returns.view') ? (
                                                <Link
                                                    href={route('supplier_returns.index', { supplier: supplier.id, status: 'dispatched' })}
                                                    className="font-medium text-[var(--warn)] hover:underline"
                                                >
                                                    {money(supplier.returns_pending)}
                                                </Link>
                                            ) : (
                                                <span className="font-medium text-[var(--warn)]">{money(supplier.returns_pending)}</span>
                                            )
                                        ) : (
                                            <span className="text-[var(--muted)]">—</span>
                                        )}
                                    </TD>
                                    <TD align="center">
                                        {supplier.is_active ? <Badge tone="accent">Activo</Badge> : <Badge tone="neutral">Inactivo</Badge>}
                                    </TD>
                                    <TD align="right">
                                        <div className="flex items-center justify-end gap-1">
                                            {can('suppliers.index.edit') && (
                                                <Button size="sm" variant="ghost" icon={<PencilSimpleIcon size={15} />} onClick={() => openEdit(supplier)} />
                                            )}
                                            {can('suppliers.index.delete') && supplier.products_count === 0 && (
                                                <Button size="sm" variant="ghost" icon={<TrashIcon size={15} />} onClick={() => setToDelete(supplier)} />
                                            )}
                                        </div>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>

                    <Pagination meta={suppliers} />
                </>
            )}

            <Modal
                open={creating || editing !== null}
                onClose={() => {
                    setCreating(false);
                    setEditing(null);
                }}
                title={editing ? 'Editar el proveedor' : 'Nuevo proveedor'}
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
                    <Input
                        label="Nombre"
                        required
                        autoFocus
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        error={form.errors.name}
                        containerClassName="sm:col-span-2"
                    />
                    <Input label="NIT" value={form.data.tax_id} onChange={(event) => form.setData('tax_id', event.target.value)} error={form.errors.tax_id} />
                    <Input
                        label="Contacto"
                        value={form.data.contact_name}
                        onChange={(event) => form.setData('contact_name', event.target.value)}
                        error={form.errors.contact_name}
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
                        label="Dirección"
                        value={form.data.address}
                        onChange={(event) => form.setData('address', event.target.value)}
                        error={form.errors.address}
                        containerClassName="sm:col-span-2"
                    />
                    <Input
                        label="Días de entrega"
                        type="number"
                        min={0}
                        required
                        value={form.data.lead_time_days}
                        onChange={(event) => form.setData('lead_time_days', Number(event.target.value))}
                        error={form.errors.lead_time_days}
                    />
                    <Select
                        label="Plazo de pago"
                        hint="Lo toman sus órdenes de compra; se puede cambiar en cada orden."
                        value={form.data.payment_term_id}
                        onChange={(event) => form.setData('payment_term_id', event.target.value)}
                        options={paymentTerms.map((term) => ({ value: String(term.value), label: `${term.label} (${term.days} días)` }))}
                        placeholder="El de proveedores por defecto"
                        error={form.errors.payment_term_id}
                    />
                    <div className="flex flex-col gap-1.5 sm:col-span-2">
                        <span className="text-[0.8125rem] font-medium text-[var(--text)]">Días en que despacha</span>
                        <div className="flex flex-wrap gap-1.5">
                            {deliveryDays.map((day) => (
                                <Chip key={day} active={form.data.delivery_days.includes(day)} onClick={() => toggleDay(day)}>
                                    {DAY_LABELS[day] ?? day}
                                </Chip>
                            ))}
                        </div>
                    </div>
                    <div className="sm:col-span-2">
                        <Checkbox
                            label="Proveedor activo"
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
                title="Eliminar el proveedor"
                confirmLabel="Eliminar"
                message={
                    <>
                        Vas a eliminar a <strong>{toDelete?.name}</strong>. Solo se puede si no tiene artículos ni órdenes de compra.
                    </>
                }
            />
        </AppLayout>
    );
}

import { router, useForm } from '@inertiajs/react';
import { PencilSimpleIcon, PlusIcon, PowerIcon } from '@phosphor-icons/react';
import { useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Checkbox, Input } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { EmptyState, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { usePermission } from '@/lib/permissions';

interface TermRow {
    id: number;
    name: string;
    days: number;
    is_default_customers: boolean;
    is_default_suppliers: boolean;
    is_active: boolean;
    sort_order: number;
    customers_count: number;
    suppliers_count: number;
}

/**
 * Administracion > Empresa > Plazos de pago. Los eligen el credito de clientes, los proveedores
 * y las ordenes de compra.
 */
export default function PaymentTermsIndex({ terms }: { terms: TermRow[] }) {
    const { can } = usePermission();
    const [editing, setEditing] = useState<TermRow | 'new' | null>(null);

    return (
        <AppLayout title="Plazos de pago">
            <PageHeader
                title="Plazos de pago"
                description="Los que das a tus clientes a crédito y los que te dan tus proveedores. Uno por defecto de cada lado."
                actions={
                    can('payment_terms.index.create') && (
                        <Button onClick={() => setEditing('new')}>
                            <PlusIcon size={16} /> Nuevo plazo
                        </Button>
                    )
                }
            />

            <Card padded={false}>
                {terms.length === 0 ? (
                    <EmptyState title="No hay plazos" description="Crea el primero para asignarlo a clientes y proveedores." />
                ) : (
                    <Table>
                        <THead>
                            <TR>
                                <TH>Plazo</TH>
                                <TH align="right">Días</TH>
                                <TH>Por defecto</TH>
                                <TH align="right">Clientes</TH>
                                <TH align="right">Proveedores</TH>
                                <TH>Estado</TH>
                                <TH />
                            </TR>
                        </THead>
                        <TBody>
                            {terms.map((term) => (
                                <TR key={term.id}>
                                    <TD>
                                        <span className="font-semibold">{term.name}</span>
                                    </TD>
                                    <TD align="right">
                                        <span className="font-mono tabular">{term.days}</span>
                                    </TD>
                                    <TD>
                                        <span className="flex flex-wrap gap-1">
                                            {term.is_default_customers && <Badge tone="accent">Clientes</Badge>}
                                            {term.is_default_suppliers && <Badge tone="info">Proveedores</Badge>}
                                            {!term.is_default_customers && !term.is_default_suppliers && <span className="text-[var(--muted)]">—</span>}
                                        </span>
                                    </TD>
                                    <TD align="right">
                                        <span className="font-mono tabular">{term.customers_count}</span>
                                    </TD>
                                    <TD align="right">
                                        <span className="font-mono tabular">{term.suppliers_count}</span>
                                    </TD>
                                    <TD>{term.is_active ? <Badge tone="accent">Activo</Badge> : <Badge tone="neutral">Desactivado</Badge>}</TD>
                                    <TD align="right">
                                        <div className="flex justify-end gap-1">
                                            {can('payment_terms.index.edit') && (
                                                <button
                                                    type="button"
                                                    title="Editar"
                                                    onClick={() => setEditing(term)}
                                                    className="grid size-8 place-items-center rounded-control text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]"
                                                >
                                                    <PencilSimpleIcon size={16} />
                                                </button>
                                            )}
                                            {can('payment_terms.index.deactivate') && (
                                                <button
                                                    type="button"
                                                    title={term.is_active ? 'Desactivar' : 'Activar'}
                                                    onClick={() => router.post(route('payment_terms.toggle', term.id), {}, { preserveScroll: true })}
                                                    className="grid size-8 place-items-center rounded-control text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]"
                                                >
                                                    <PowerIcon size={16} />
                                                </button>
                                            )}
                                        </div>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                )}
            </Card>

            {editing !== null && <TermDialog term={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
        </AppLayout>
    );
}

function TermDialog({ term, onClose }: { term: TermRow | null; onClose: () => void }) {
    const form = useForm({
        name: term?.name ?? '',
        days: term ? String(term.days) : '',
        is_default_customers: term?.is_default_customers ?? false,
        is_default_suppliers: term?.is_default_suppliers ?? false,
    });
    const { data, setData, errors } = form;

    const submit = () => {
        form.transform((values) => ({ ...values, days: Number(values.days || 0) }));

        const options = { preserveScroll: true, onSuccess: () => onClose() };

        if (term) {
            form.put(route('payment_terms.update', term.id), options);
        } else {
            form.post(route('payment_terms.store'), options);
        }
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={term ? `Plazo ${term.name}` : 'Nuevo plazo'}
            description={term && term.customers_count > 0 ? `Si cambias los días, cambian también para sus ${term.customers_count} clientes.` : undefined}
            size="sm"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button onClick={submit} loading={form.processing}>
                        Guardar
                    </Button>
                </>
            }
        >
            <div className="flex flex-col gap-3">
                <Input label="Nombre" placeholder="45 días" required value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} />
                <Input label="Días" hint="0 es de contado." inputMode="numeric" required value={data.days} onChange={(e) => setData('days', e.target.value.replace(/\D/g, ''))} error={errors.days} />
                <Checkbox
                    checked={data.is_default_customers}
                    onChange={(e) => setData('is_default_customers', e.target.checked)}
                    label="Por defecto para clientes"
                    hint="El que se propone al darle crédito a un cliente."
                />
                {errors.is_default_customers && <p className="text-[0.78125rem] font-medium text-[var(--danger)]">{errors.is_default_customers}</p>}
                <Checkbox
                    checked={data.is_default_suppliers}
                    onChange={(e) => setData('is_default_suppliers', e.target.checked)}
                    label="Por defecto para proveedores"
                    hint="El que toman las compras a un proveedor sin plazo propio."
                />
                {errors.is_default_suppliers && <p className="text-[0.78125rem] font-medium text-[var(--danger)]">{errors.is_default_suppliers}</p>}
            </div>
        </Modal>
    );
}

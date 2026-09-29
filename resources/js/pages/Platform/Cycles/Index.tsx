import { router, useForm } from '@inertiajs/react';
import { PencilSimpleIcon, PlusIcon, PowerIcon } from '@phosphor-icons/react';
import { useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input, Toggle } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { EmptyState, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { usePermission } from '@/lib/permissions';

interface CycleRow {
    id: number;
    name: string;
    code: string;
    months: number;
    discount_percent: number;
    is_active: boolean;
    sort_order: number;
    companies_count: number;
}

/**
 * Plataforma > Ciclos de cobro. Siempre queda al menos uno activo.
 */
export default function CyclesIndex({ cycles }: { cycles: CycleRow[] }) {
    const { can } = usePermission();
    const [editing, setEditing] = useState<CycleRow | 'new' | null>(null);

    return (
        <AppLayout title="Ciclos de cobro">
            <PageHeader
                title="Ciclos de cobro"
                description="Cada cuánto se cobra la membresía y con qué descuento. El precio sale del mensual del plan."
                actions={
                    can('platform.billing_cycles.create') && (
                        <Button onClick={() => setEditing('new')}>
                            <PlusIcon size={16} /> Nuevo ciclo
                        </Button>
                    )
                }
            />

            <Card padded={false}>
                {cycles.length === 0 ? (
                    <EmptyState title="No hay ciclos" description="Crea al menos uno para poder contratar." />
                ) : (
                    <Table>
                        <THead>
                            <TR>
                                <TH>Ciclo</TH>
                                <TH align="right">Meses</TH>
                                <TH align="right">Descuento</TH>
                                <TH align="right">Empresas</TH>
                                <TH>Estado</TH>
                                <TH />
                            </TR>
                        </THead>
                        <TBody>
                            {cycles.map((cycle) => (
                                <TR key={cycle.id}>
                                    <TD>
                                        <span className="font-semibold">{cycle.name}</span>
                                        <span className="block font-mono text-[0.75rem] text-[var(--muted)]">{cycle.code}</span>
                                    </TD>
                                    <TD align="right">
                                        <span className="font-mono tabular">{cycle.months}</span>
                                    </TD>
                                    <TD align="right">
                                        <span className="font-mono tabular">{cycle.discount_percent} %</span>
                                    </TD>
                                    <TD align="right">
                                        <span className="font-mono tabular">{cycle.companies_count}</span>
                                    </TD>
                                    <TD>{cycle.is_active ? <Badge tone="accent">Activo</Badge> : <Badge tone="neutral">Desactivado</Badge>}</TD>
                                    <TD align="right">
                                        <div className="flex justify-end gap-1">
                                            {can('platform.billing_cycles.edit') && (
                                                <button
                                                    type="button"
                                                    title="Editar"
                                                    onClick={() => setEditing(cycle)}
                                                    className="grid size-8 place-items-center rounded-control text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]"
                                                >
                                                    <PencilSimpleIcon size={16} />
                                                </button>
                                            )}
                                            {can('platform.billing_cycles.deactivate') && (
                                                <button
                                                    type="button"
                                                    title={cycle.is_active ? 'Desactivar' : 'Activar'}
                                                    onClick={() => router.post(route('platform.billing_cycles.toggle', cycle.id), {}, { preserveScroll: true })}
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

            {editing !== null && <CycleDialog cycle={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
        </AppLayout>
    );
}

function CycleDialog({ cycle, onClose }: { cycle: CycleRow | null; onClose: () => void }) {
    const form = useForm({
        name: cycle?.name ?? '',
        code: cycle?.code ?? '',
        months: cycle ? String(cycle.months) : '1',
        discount_percent: cycle ? String(cycle.discount_percent) : '0',
        is_active: cycle?.is_active ?? true,
        sort_order: cycle ? String(cycle.sort_order) : '0',
    });
    const { data, setData, errors } = form;

    const submit = () => {
        form.transform((values) => ({
            ...values,
            months: Number(values.months || 0),
            discount_percent: Number(values.discount_percent || 0),
            sort_order: Number(values.sort_order || 0),
        }));

        const options = { preserveScroll: true, onSuccess: () => onClose() };

        if (cycle) {
            form.put(route('platform.billing_cycles.update', cycle.id), options);
        } else {
            form.post(route('platform.billing_cycles.store'), options);
        }
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={cycle ? `Ciclo ${cycle.name}` : 'Nuevo ciclo'}
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
            <div className="grid gap-3 sm:grid-cols-2">
                <Input label="Nombre" required value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} containerClassName="sm:col-span-2" />
                <Input label="Código" hint="Si lo dejas vacío sale del nombre." value={data.code} onChange={(e) => setData('code', e.target.value)} error={errors.code} containerClassName="sm:col-span-2" />
                <Input label="Meses" inputMode="numeric" value={data.months} onChange={(e) => setData('months', e.target.value.replace(/\D/g, ''))} error={errors.months} />
                <Input label="Descuento (%)" inputMode="numeric" value={data.discount_percent} onChange={(e) => setData('discount_percent', e.target.value.replace(/\D/g, ''))} error={errors.discount_percent} />
                <Input label="Orden" inputMode="numeric" value={data.sort_order} onChange={(e) => setData('sort_order', e.target.value.replace(/\D/g, ''))} error={errors.sort_order} />
                <div className="sm:col-span-2">
                    <Toggle checked={data.is_active} onChange={(value) => setData('is_active', value)} label="Activo" />
                    {errors.is_active && <p className="mt-2 text-[0.78125rem] font-medium text-[var(--danger)]">{errors.is_active}</p>}
                </div>
            </div>
        </Modal>
    );
}

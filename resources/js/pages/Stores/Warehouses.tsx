import { useForm } from '@inertiajs/react';
import { ArrowLeftIcon, PlusIcon, StackIcon, StorefrontIcon, TruckIcon } from '@phosphor-icons/react';
import { type FormEvent, useState } from 'react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input, Select } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { EmptyState, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { money, number } from '@/lib/format';
import { useFeature, usePermission } from '@/lib/permissions';

interface WarehouseRow {
    id: number;
    name: string;
    code: string;
    type: string;
    type_label: string;
    is_pos_source: boolean;
    is_online_source: boolean;
    is_receiving_default: boolean;
    is_active: boolean;
    flags: string[];
    items: number;
    value: number;
    movements: number;
}

interface WarehousesProps {
    store: { id: number; name: string; code: string };
    warehouses: WarehouseRow[];
    types: { value: string; label: string }[];
    flags: { value: string; label: string }[];
}

const FLAG_ICONS: Record<string, React.ReactNode> = {
    is_pos_source: <StorefrontIcon size={13} weight="bold" />,
    is_online_source: <StackIcon size={13} weight="bold" />,
    is_receiving_default: <TruckIcon size={13} weight="bold" />,
};

/**
 * Bodegas de una sede.
 *
 * Lo que de verdad cambia el comportamiento del sistema son las tres banderas, y de cada una
 * hay exactamente una encendida: marcarla aquí la apaga en las demás. Por eso se pintan como
 * casillas y no como un interruptor por bodega, que dejaría pensar que pueden convivir dos.
 */
export default function StoreWarehouses({ store, warehouses, types, flags }: WarehousesProps) {
    const { can } = usePermission();
    const hasFeature = useFeature();
    const [editing, setEditing] = useState<WarehouseRow | null>(null);
    const [open, setOpen] = useState(false);

    const form = useForm({
        name: '',
        code: '',
        type: types[0]?.value ?? 'storage',
        flags: [] as string[],
    });

    const startCreate = () => {
        form.setData({ name: '', code: '', type: 'storage', flags: [] });
        form.clearErrors();
        setEditing(null);
        setOpen(true);
    };

    const startEdit = (warehouse: WarehouseRow) => {
        form.setData({ name: warehouse.name, code: warehouse.code, type: warehouse.type, flags: warehouse.flags });
        form.clearErrors();
        setEditing(warehouse);
        setOpen(true);
    };

    const toggleFlag = (flag: string) =>
        form.setData(
            'flags',
            form.data.flags.includes(flag) ? form.data.flags.filter((item) => item !== flag) : [...form.data.flags, flag],
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const done = { preserveScroll: true, onSuccess: () => setOpen(false) };

        if (editing) {
            form.put(route('stores.warehouses.update', [store.id, editing.id]), done);

            return;
        }

        form.post(route('stores.warehouses.store', store.id), done);
    };

    const toggleActive = (warehouse: WarehouseRow) =>
        form.post(route('stores.warehouses.deactivate', [store.id, warehouse.id]), { preserveScroll: true });

    return (
        <AppLayout title={`Bodegas de ${store.name}`}>
            <PageHeader
                title={`Bodegas de ${store.name}`}
                description="Dónde está físicamente la mercancía de esta sede y qué bodega atiende cada canal."
                breadcrumb={
                    <ButtonLink href={route('stores.index')} size="sm" variant="ghost" icon={<ArrowLeftIcon size={14} />}>
                        Volver a sedes
                    </ButtonLink>
                }
                actions={
                    can('stores.warehouses.create') && hasFeature('multi_warehouse') ? (
                        <Button onClick={startCreate} icon={<PlusIcon size={16} />}>
                            Nueva bodega
                        </Button>
                    ) : undefined
                }
            />

            <Card className="mb-4">
                <p className="text-[0.8125rem] text-[var(--muted)]">
                    De cada función hay <strong className="text-[var(--text)]">una sola</strong> bodega encendida por sede. Al
                    marcársela a una, se le quita a la que la tenía. Las demás bodegas guardan mercancía, pero no venden: lo que
                    está en ellas no aparece en caja ni en la tienda hasta que se traslade.
                </p>
            </Card>

            {warehouses.length === 0 ? (
                <EmptyState
                    title="Esta sede no tiene bodegas"
                    description="Algo raro pasó: toda sede nace con su bodega principal."
                    icon={<StackIcon size={24} />}
                />
            ) : (
                <Card padded={false}>
                    <Table>
                        <THead>
                            <TR>
                                <TH>Bodega</TH>
                                <TH>Tipo</TH>
                                <TH>Atiende</TH>
                                <TH align="right">Referencias</TH>
                                <TH align="right">Valor al costo</TH>
                                <TH align="right">Acciones</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {warehouses.map((warehouse) => (
                                <TR key={warehouse.id}>
                                    <TD>
                                        <p className="text-[0.84375rem] font-medium">{warehouse.name}</p>
                                        <p className="font-mono text-[0.71875rem] text-[var(--muted)]">
                                            {warehouse.code}
                                            {!warehouse.is_active ? ' · desactivada' : ''}
                                        </p>
                                    </TD>
                                    <TD className="text-[0.78125rem] text-[var(--muted)]">{warehouse.type_label}</TD>
                                    <TD>
                                        {warehouse.flags.length === 0 ? (
                                            <span className="text-[0.78125rem] text-[var(--muted)]">Solo guarda</span>
                                        ) : (
                                            <div className="flex flex-wrap gap-1">
                                                {warehouse.flags.map((flag) => (
                                                    <Badge key={flag} tone="accent">
                                                        <span className="inline-flex items-center gap-1">
                                                            {FLAG_ICONS[flag]}
                                                            {flags.find((item) => item.value === flag)?.label ?? flag}
                                                        </span>
                                                    </Badge>
                                                ))}
                                            </div>
                                        )}
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                        {number(warehouse.items)}
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                        {money(warehouse.value)}
                                    </TD>
                                    <TD align="right">
                                        <div className="flex justify-end gap-1">
                                            {can('stores.warehouses.edit') && (
                                                <Button size="sm" variant="ghost" onClick={() => startEdit(warehouse)}>
                                                    Editar
                                                </Button>
                                            )}
                                            {can('stores.warehouses.deactivate') && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => toggleActive(warehouse)}
                                                    disabled={warehouse.is_active && warehouse.flags.length > 0}
                                                >
                                                    {warehouse.is_active ? 'Desactivar' : 'Activar'}
                                                </Button>
                                            )}
                                        </div>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </Card>
            )}

            <Modal
                open={open}
                onClose={() => setOpen(false)}
                title={editing ? 'Editar la bodega' : 'Nueva bodega'}
                description={store.name}
                size="sm"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setOpen(false)}>
                            Cancelar
                        </Button>
                        <Button onClick={submit} loading={form.processing}>
                            Guardar
                        </Button>
                    </>
                }
            >
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <Input
                        label="Nombre"
                        required
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        error={form.errors.name}
                        placeholder="Bodega de almacenaje"
                    />
                    <Input
                        label="Código"
                        required
                        value={form.data.code}
                        onChange={(event) => form.setData('code', event.target.value.toUpperCase())}
                        error={form.errors.code}
                        placeholder="ALMACEN"
                    />
                    <Select
                        label="Tipo"
                        options={types}
                        value={form.data.type}
                        onChange={(event) => form.setData('type', event.target.value)}
                        error={form.errors.type}
                        hint="Solo es una etiqueta para entenderse: lo que decide son las funciones."
                    />

                    <fieldset className="flex flex-col gap-2">
                        <legend className="mb-1 text-[0.8125rem] font-medium">Qué atiende esta bodega</legend>
                        {flags.map((flag) => (
                            <label
                                key={flag.value}
                                className="flex cursor-pointer items-center gap-2.5 rounded-control border border-[var(--line)] px-3 py-2.5 text-[0.8125rem]"
                            >
                                <input
                                    type="checkbox"
                                    checked={form.data.flags.includes(flag.value)}
                                    onChange={() => toggleFlag(flag.value)}
                                    className="size-4 accent-[var(--accent)]"
                                />
                                {flag.label}
                            </label>
                        ))}
                        <p className="text-[0.71875rem] text-[var(--muted)]">
                            Marcar una función se la quita a la bodega que la tenía.
                        </p>
                    </fieldset>
                </form>
            </Modal>
        </AppLayout>
    );
}

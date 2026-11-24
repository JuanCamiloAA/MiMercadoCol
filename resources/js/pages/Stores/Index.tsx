import { router, useForm } from '@inertiajs/react';
import { BuildingsIcon, CrosshairIcon, MapPinIcon, PencilSimpleIcon, PlusIcon, StackIcon, TrashIcon, UploadSimpleIcon } from '@phosphor-icons/react';
import { type FormEvent, useState } from 'react';
import { LocationPicker } from '@/components/maps/LocationPicker';
import type { MapConfig } from '@/components/maps/types';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Checkbox, Input } from '@/components/ui/Field';
import { ConfirmDialog, Modal } from '@/components/ui/Modal';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { EmptyState } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { money, number } from '@/lib/format';
import { usePermission } from '@/lib/permissions';

interface StoreRow {
    id: number;
    name: string;
    code: string;
    address: string | null;
    city: string | null;
    latitude: number | null;
    longitude: number | null;
    phone: string | null;
    opens_at: string;
    closes_at: string;
    suggested_cash_base: number;
    is_active: boolean;
    registers_count: number;
    employees_count: number;
}

interface StoresIndexProps {
    stores: StoreRow[];
    map: MapConfig;
}

export default function StoresIndex({ stores, map }: StoresIndexProps) {
    const { can } = usePermission();
    // Una sede nueva arranca el mapa donde ya hay otra de la empresa, no en el centro del país.
    const located = stores.find((store) => store.latitude !== null && store.longitude !== null);
    const otherStore = located && located.latitude !== null && located.longitude !== null ? { lat: located.latitude, lng: located.longitude } : null;
    const [editing, setEditing] = useState<StoreRow | null>(null);
    const [creating, setCreating] = useState(false);
    const [toDelete, setToDelete] = useState<StoreRow | null>(null);
    const [busy, setBusy] = useState(false);
    const [locating, setLocating] = useState(false);
    const [geoError, setGeoError] = useState<string | null>(null);

    /** La ubicacion se pide solo al tocar el boton, nunca al abrir el formulario. */
    const locateMe = () => {
        setGeoError(null);

        if (!('geolocation' in navigator)) {
            setGeoError('Este navegador no da la ubicación.');

            return;
        }

        setLocating(true);
        navigator.geolocation.getCurrentPosition(
            (position) => {
                form.setData((data) => ({
                    ...data,
                    latitude: position.coords.latitude.toFixed(7),
                    longitude: position.coords.longitude.toFixed(7),
                }));
                setLocating(false);
            },
            () => {
                setGeoError('No se pudo leer la ubicación. Revisa el permiso del navegador.');
                setLocating(false);
            },
            { enableHighAccuracy: true, timeout: 15000 },
        );
    };

    const form = useForm({
        name: '',
        code: '',
        address: '',
        city: '',
        latitude: '',
        longitude: '',
        phone: '',
        opens_at: '07:00',
        closes_at: '21:00',
        suggested_cash_base: 200000,
        is_active: true as boolean,
    });

    const openCreate = () => {
        form.setData({
            name: '',
            code: '',
            address: '',
            city: '',
            latitude: '',
            longitude: '',
            phone: '',
            opens_at: '07:00',
            closes_at: '21:00',
            suggested_cash_base: 200000,
            is_active: true,
        });
        form.clearErrors();
        setCreating(true);
    };

    const openEdit = (store: StoreRow) => {
        form.setData({
            name: store.name,
            code: store.code,
            address: store.address ?? '',
            city: store.city ?? '',
            latitude: store.latitude !== null ? String(store.latitude) : '',
            longitude: store.longitude !== null ? String(store.longitude) : '',
            phone: store.phone ?? '',
            opens_at: store.opens_at,
            closes_at: store.closes_at,
            suggested_cash_base: store.suggested_cash_base,
            is_active: store.is_active,
        });
        form.clearErrors();
        setEditing(store);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (editing) {
            form.put(route('stores.update', editing.id), { preserveScroll: true, onSuccess: () => setEditing(null) });
        } else {
            form.post(route('stores.store'), { preserveScroll: true, onSuccess: () => setCreating(false) });
        }
    };

    const confirmDelete = () => {
        if (!toDelete) {
            return;
        }

        setBusy(true);
        router.delete(route('stores.destroy', toDelete.id), {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setToDelete(null);
            },
        });
    };

    return (
        <AppLayout title="Sedes">
            <PageHeader
                title="Sedes"
                description="Donde se opera: cada sede tiene sus cajas, su inventario y sus turnos."
                actions={
                    <>
                        {can('stores.index.import') && (
                            <ButtonLink href={route('stores.import.form')} variant="secondary" icon={<UploadSimpleIcon size={16} />}>
                                Importar
                            </ButtonLink>
                        )}
                        {can('stores.index.create') && (
                            <Button onClick={openCreate} icon={<PlusIcon size={16} weight="bold" />}>
                                Nueva sede
                            </Button>
                        )}
                    </>
                }
            />

            {stores.length === 0 ? (
                <EmptyState title="Todavía no hay sedes" description="Crea la primera para empezar a operar." icon={<BuildingsIcon size={24} />} />
            ) : (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {stores.map((store) => (
                        <article key={store.id} className="rounded-card border border-[var(--line)] bg-[var(--surface)] p-4">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <h2 className="truncate text-[0.9375rem] font-semibold">{store.name}</h2>
                                    <p className="font-mono text-[0.71875rem] text-[var(--muted)]">{store.code}</p>
                                </div>
                                {store.is_active ? <Badge tone="accent">Activa</Badge> : <Badge tone="neutral">Inactiva</Badge>}
                            </div>

                            <dl className="mt-3 flex flex-col gap-1.5 text-[0.78125rem]">
                                <Row label="Dirección" value={[store.address, store.city].filter(Boolean).join(', ') || null} />
                                <Row label="Buscador" value={store.latitude !== null ? 'Con ubicación' : 'Sin ubicación'} />
                                <Row label="Teléfono" value={store.phone} mono />
                                <Row label="Horario" value={`${store.opens_at} – ${store.closes_at}`} mono />
                                <Row label="Base sugerida" value={money(store.suggested_cash_base)} mono />
                                <Row label="Cajas" value={number(store.registers_count)} mono />
                                <Row label="Empleados" value={number(store.employees_count)} mono />
                            </dl>

                            {(can('stores.index.edit') || can('stores.index.delete') || can('stores.warehouses.view')) && (
                                <div className="mt-3 flex flex-wrap items-center gap-1 border-t border-[var(--line)] pt-3">
                                    {can('stores.warehouses.view') && (
                                        <ButtonLink
                                            href={route('stores.warehouses.index', store.id)}
                                            size="sm"
                                            variant="ghost"
                                            icon={<StackIcon size={15} />}
                                        >
                                            Bodegas
                                        </ButtonLink>
                                    )}
                                    {can('stores.index.edit') && (
                                        <Button size="sm" variant="ghost" icon={<PencilSimpleIcon size={15} />} onClick={() => openEdit(store)}>
                                            Editar
                                        </Button>
                                    )}
                                    {can('stores.index.delete') && store.registers_count === 0 && (
                                        <Button size="sm" variant="ghost" icon={<TrashIcon size={15} />} onClick={() => setToDelete(store)}>
                                            Eliminar
                                        </Button>
                                    )}
                                </div>
                            )}
                        </article>
                    ))}
                </div>
            )}

            <Modal
                open={creating || editing !== null}
                onClose={() => {
                    setCreating(false);
                    setEditing(null);
                }}
                title={editing ? 'Editar la sede' : 'Nueva sede'}
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
                    <Input label="Nombre" required value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} error={form.errors.name} />
                    <Input
                        label="Código"
                        required
                        value={form.data.code}
                        onChange={(event) => form.setData('code', event.target.value.toUpperCase())}
                        error={form.errors.code}
                    />
                    <Input
                        label="Dirección"
                        value={form.data.address}
                        onChange={(event) => form.setData('address', event.target.value)}
                        error={form.errors.address}
                        containerClassName="sm:col-span-2"
                    />
                    <Input label="Ciudad" value={form.data.city} onChange={(event) => form.setData('city', event.target.value)} error={form.errors.city} />
                    <Input label="Teléfono" value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} error={form.errors.phone} />
                    <div className="flex flex-col gap-2 rounded-control border border-[var(--line)] bg-[var(--surface-2)] p-3 sm:col-span-2">
                        <p className="text-[0.8125rem] font-medium">Ubicación para el buscador de la landing</p>
                        <LocationPicker
                            config={map}
                            latitude={form.data.latitude}
                            longitude={form.data.longitude}
                            onChange={(latitude, longitude) => form.setData((data) => ({ ...data, latitude, longitude }))}
                            fallback={otherStore}
                        />
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Input
                                label="Latitud"
                                inputMode="decimal"
                                placeholder="6.2442"
                                value={form.data.latitude}
                                onChange={(event) => form.setData('latitude', event.target.value.replace(',', '.'))}
                                error={form.errors.latitude}
                            />
                            <Input
                                label="Longitud"
                                inputMode="decimal"
                                placeholder="-75.5812"
                                value={form.data.longitude}
                                onChange={(event) => form.setData('longitude', event.target.value.replace(',', '.'))}
                                error={form.errors.longitude}
                            />
                        </div>
                        <div className="flex flex-wrap items-center gap-3 text-[0.8125rem]">
                            <Button type="button" size="sm" variant="secondary" icon={<CrosshairIcon size={15} />} onClick={locateMe} loading={locating}>
                                Usar mi ubicación actual
                            </Button>
                            {(form.data.latitude || form.data.longitude) && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    icon={<MapPinIcon size={15} />}
                                    onClick={() => form.setData((data) => ({ ...data, latitude: '', longitude: '' }))}
                                >
                                    Quitar la ubicación
                                </Button>
                            )}
                            {geoError && <span className="text-[var(--danger)]">{geoError}</span>}
                        </div>
                        <p className="text-[0.75rem] text-[var(--muted)]">Se pide el permiso solo al tocar el botón; úsalo estando en la sede.</p>
                    </div>
                    <Input
                        label="Base sugerida de caja"
                        type="number"
                        min={0}
                        required
                        value={form.data.suggested_cash_base}
                        onChange={(event) => form.setData('suggested_cash_base', Number(event.target.value))}
                        error={form.errors.suggested_cash_base}
                        prefix="$"
                    />
                    <Input
                        label="Abre"
                        type="time"
                        required
                        value={form.data.opens_at}
                        onChange={(event) => form.setData('opens_at', event.target.value)}
                        error={form.errors.opens_at}
                    />
                    <Input
                        label="Cierra"
                        type="time"
                        required
                        value={form.data.closes_at}
                        onChange={(event) => form.setData('closes_at', event.target.value)}
                        error={form.errors.closes_at}
                    />
                    <div className="sm:col-span-2">
                        <Checkbox label="Sede activa" checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} />
                    </div>
                </form>
            </Modal>

            <ConfirmDialog
                open={toDelete !== null}
                onClose={() => setToDelete(null)}
                onConfirm={confirmDelete}
                loading={busy}
                title="Eliminar la sede"
                confirmLabel="Eliminar"
                message={
                    <>
                        Vas a eliminar <strong>{toDelete?.name}</strong>. Solo se puede si no tiene cajas registradas.
                    </>
                }
            />
        </AppLayout>
    );
}

function Row({ label, value, mono }: { label: string; value: string | null | undefined; mono?: boolean }) {
    return (
        <div className="flex items-start justify-between gap-3">
            <dt className="shrink-0 text-[var(--muted)]">{label}</dt>
            <dd className={`text-right ${mono ? 'font-mono tabular' : ''}`}>{value || '—'}</dd>
        </div>
    );
}

import { useForm } from '@inertiajs/react';
import { ArrowLeftIcon } from '@phosphor-icons/react';
import type { FormEvent } from 'react';
import { LocationPicker } from '@/components/maps/LocationPicker';
import type { MapConfig } from '@/components/maps/types';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Input, Select } from '@/components/ui/Field';
import { PageHeader } from '@/components/ui/Misc';
import AppLayout from '@/layouts/AppLayout';

interface PlanOption {
    value: number;
    label: string;
    trial_days: number;
    is_active: boolean;
}

interface CycleOption {
    value: number;
    label: string;
    months: number;
    is_active: boolean;
}

interface CategoryOption {
    value: number;
    label: string;
}

interface LeadPrefill {
    id: number;
    company_name: string | null;
    tax_id: string | null;
    city: string | null;
    business_category_id: number | null;
    contact_name: string;
    email: string;
    phone: string | null;
    membership_plan_id: number | null;
    billing_cycle_id: number | null;
}

interface CompaniesCreateProps {
    plans: PlanOption[];
    cycles: CycleOption[];
    /** Alimentos, textiles, belleza…: las activas. */
    categories: CategoryOption[];
    lead: LeadPrefill | null;
    map: MapConfig;
}

/**
 * Crear una empresa lista para trabajar: datos, primera sede, administrador y membresia. Al
 * guardar se aprovisiona completa (roles, bodega principal, caja, tienda apagada, plazos).
 */
export default function CompaniesCreate({ plans, cycles, categories, lead, map }: CompaniesCreateProps) {
    const activePlans = plans.filter((plan) => plan.is_active);
    const activeCycles = cycles.filter((cycle) => cycle.is_active);
    const [firstName, ...rest] = (lead?.contact_name ?? '').split(' ');

    const { data, setData, post, processing, errors } = useForm({
        name: lead?.company_name ?? '',
        legal_name: '',
        tax_id: lead?.tax_id ?? '',
        email: lead?.email ?? '',
        phone: lead?.phone ?? '',
        address: '',
        city: lead?.city ?? '',
        business_category_id: lead?.business_category_id ? String(lead.business_category_id) : '',
        store_name: 'Principal',
        store_code: 'PRI',
        store_address: '',
        store_city: lead?.city ?? '',
        store_latitude: '',
        store_longitude: '',
        admin_name: firstName ?? '',
        admin_last_name: rest.join(' '),
        admin_email: lead?.email ?? '',
        admin_document: '',
        membership_plan_id: String(lead?.membership_plan_id ?? activePlans[0]?.value ?? ''),
        billing_cycle_id: String(lead?.billing_cycle_id ?? activeCycles[0]?.value ?? ''),
        lead_id: lead?.id ?? null,
    });

    const plan = plans.find((option) => String(option.value) === data.membership_plan_id);
    const cycle = cycles.find((option) => String(option.value) === data.billing_cycle_id);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('companies.store'), { preserveScroll: true });
    };

    return (
        <AppLayout title="Nueva empresa">
            <PageHeader
                title="Nueva empresa"
                description={lead ? `Desde la solicitud de ${lead.contact_name}.` : 'Queda lista para trabajar: roles, sede con bodega, caja, tienda apagada y su administrador.'}
                actions={
                    <ButtonLink href={route('companies.index')} variant="ghost" icon={<ArrowLeftIcon size={16} />}>
                        Volver
                    </ButtonLink>
                }
            />

            <form onSubmit={submit} className="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader title="Empresa" description="Salen en tiquetes, informes y en la landing." />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Input label="Nombre comercial" required value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} containerClassName="sm:col-span-2" />
                        <Input label="Razón social" value={data.legal_name} onChange={(e) => setData('legal_name', e.target.value)} error={errors.legal_name} hint="Si la dejas vacía, se usa el nombre." containerClassName="sm:col-span-2" />
                        <Input label="NIT" required value={data.tax_id} onChange={(e) => setData('tax_id', e.target.value)} error={errors.tax_id} />
                        <Input label="Ciudad" value={data.city} onChange={(e) => setData('city', e.target.value)} error={errors.city} />
                        <div className="sm:col-span-2">
                            <Select
                                label="Clasificación"
                                required
                                options={categories}
                                placeholder="¿Qué tipo de comercio es?"
                                value={data.business_category_id}
                                onChange={(e) => setData('business_category_id', e.target.value)}
                                error={errors.business_category_id}
                                hint="Con ella la landing agrupa sus tiendas."
                            />
                        </div>
                        <Input label="Correo" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} error={errors.email} />
                        <Input label="Teléfono" value={data.phone} onChange={(e) => setData('phone', e.target.value)} error={errors.phone} />
                        <Input label="Dirección" value={data.address} onChange={(e) => setData('address', e.target.value)} error={errors.address} containerClassName="sm:col-span-2" />
                    </div>
                </Card>

                <Card>
                    <CardHeader title="Primera sede" description="Nace con su «Bodega principal», una caja y su tienda en línea apagada." />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Input label="Nombre" required value={data.store_name} onChange={(e) => setData('store_name', e.target.value)} error={errors.store_name} />
                        <Input label="Código" required value={data.store_code} onChange={(e) => setData('store_code', e.target.value.toUpperCase())} error={errors.store_code} maxLength={20} />
                        <Input label="Dirección" value={data.store_address} onChange={(e) => setData('store_address', e.target.value)} error={errors.store_address} containerClassName="sm:col-span-2" />
                        <Input label="Ciudad" value={data.store_city} onChange={(e) => setData('store_city', e.target.value)} error={errors.store_city} containerClassName="sm:col-span-2" />
                        <LocationPicker
                            config={map}
                            latitude={data.store_latitude}
                            longitude={data.store_longitude}
                            onChange={(latitude, longitude) => setData((current) => ({ ...current, store_latitude: latitude, store_longitude: longitude }))}
                            className="sm:col-span-2"
                        />
                        <Input
                            label="Latitud"
                            inputMode="decimal"
                            value={data.store_latitude}
                            onChange={(e) => setData('store_latitude', e.target.value.replace(',', '.'))}
                            error={errors.store_latitude}
                            placeholder="6.2442"
                        />
                        <Input
                            label="Longitud"
                            inputMode="decimal"
                            value={data.store_longitude}
                            onChange={(e) => setData('store_longitude', e.target.value.replace(',', '.'))}
                            error={errors.store_longitude}
                            placeholder="-75.5812"
                        />
                    </div>
                    <p className="mt-2 text-[0.75rem] text-[var(--muted)]">Con coordenadas, la sede aparece por cercanía en la landing. Se pueden poner después en Sedes y bodegas.</p>
                </Card>

                <Card>
                    <CardHeader title="Administrador" description="Entra con una contraseña temporal y la cambia en el primer ingreso." />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Input label="Nombre" required value={data.admin_name} onChange={(e) => setData('admin_name', e.target.value)} error={errors.admin_name} />
                        <Input label="Apellido" value={data.admin_last_name} onChange={(e) => setData('admin_last_name', e.target.value)} error={errors.admin_last_name} />
                        <Input label="Correo" type="email" required value={data.admin_email} onChange={(e) => setData('admin_email', e.target.value)} error={errors.admin_email} />
                        <Input label="Documento" value={data.admin_document} onChange={(e) => setData('admin_document', e.target.value)} error={errors.admin_document} />
                    </div>
                </Card>

                <Card>
                    <CardHeader title="Membresía" />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Select label="Plan" required options={activePlans} value={data.membership_plan_id} onChange={(e) => setData('membership_plan_id', e.target.value)} error={errors.membership_plan_id} />
                        <Select label="Ciclo de cobro" required options={activeCycles} value={data.billing_cycle_id} onChange={(e) => setData('billing_cycle_id', e.target.value)} error={errors.billing_cycle_id} />
                    </div>
                    <p className="mt-3 text-[0.8125rem] text-[var(--muted)]">
                        {plan && plan.trial_days > 0
                            ? `Arranca en prueba gratis por ${plan.trial_days} días.`
                            : cycle
                              ? `Arranca activa por ${cycle.months} ${cycle.months === 1 ? 'mes' : 'meses'}.`
                              : 'Elige plan y ciclo.'}
                    </p>
                    {(errors as Record<string, string>).plan && (
                        <p className="mt-2 text-[0.8125rem] font-medium text-[var(--danger)]">{(errors as Record<string, string>).plan}</p>
                    )}

                    <Button type="submit" size="lg" className="mt-5" loading={processing}>
                        Crear la empresa
                    </Button>
                </Card>
            </form>
        </AppLayout>
    );
}

import { Link, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Checkbox, Input, Textarea, Toggle } from '@/components/ui/Field';
import { PageHeader } from '@/components/ui/Misc';
import AppLayout from '@/layouts/AppLayout';

interface PlanData {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    monthly_price: number;
    trial_days: number;
    max_stores: number | null;
    max_users: number | null;
    max_registers: number | null;
    features: string[];
    is_public: boolean;
    is_featured: boolean;
    is_active: boolean;
    sort_order: number;
    companies_count: number;
}

interface PlanFormProps {
    plan: PlanData | null;
    featureOptions: { value: string; label: string; description: string }[];
}

/** Un tope vacio es «sin tope». */
const toLimit = (value: string) => (value.trim() === '' ? null : Number(value));

/**
 * Crear o editar un plan de membresia.
 */
export default function PlanForm({ plan, featureOptions }: PlanFormProps) {
    const form = useForm({
        name: plan?.name ?? '',
        slug: plan?.slug ?? '',
        description: plan?.description ?? '',
        monthly_price: plan ? String(plan.monthly_price) : '',
        trial_days: plan ? String(plan.trial_days) : '0',
        max_stores: plan?.max_stores != null ? String(plan.max_stores) : '',
        max_users: plan?.max_users != null ? String(plan.max_users) : '',
        max_registers: plan?.max_registers != null ? String(plan.max_registers) : '',
        features: plan?.features ?? [],
        is_public: plan?.is_public ?? true,
        is_featured: plan?.is_featured ?? false,
        is_active: plan?.is_active ?? true,
        sort_order: plan ? String(plan.sort_order) : '0',
    });
    const { data, setData, errors } = form;

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.transform((values) => ({
            ...values,
            monthly_price: Number(values.monthly_price || 0),
            trial_days: Number(values.trial_days || 0),
            max_stores: toLimit(values.max_stores),
            max_users: toLimit(values.max_users),
            max_registers: toLimit(values.max_registers),
            sort_order: Number(values.sort_order || 0),
        }));

        if (plan) {
            form.put(route('platform.plans.update', plan.id));
        } else {
            form.post(route('platform.plans.store'));
        }
    };

    const toggleFeature = (value: string, checked: boolean) => {
        setData('features', checked ? [...data.features, value] : data.features.filter((feature) => feature !== value));
    };

    return (
        <AppLayout title={plan ? `Plan ${plan.name}` : 'Nuevo plan'}>
            <PageHeader
                title={plan ? `Plan ${plan.name}` : 'Nuevo plan'}
                description={plan && plan.companies_count > 0 ? `Lo usan ${plan.companies_count} empresas: los cambios de precio aplican en su próxima renovación.` : 'El precio de cada ciclo se calcula del mensual con el descuento del ciclo.'}
                breadcrumb={<Link href={route('platform.plans.index')} className="hover:underline">Planes de membresía</Link>}
            />

            <form onSubmit={submit} className="grid max-w-[60rem] gap-5 lg:grid-cols-2">
                <Card>
                    <CardHeader title="Datos" />
                    <div className="flex flex-col gap-3">
                        <Input label="Nombre" required value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} />
                        <Input label="Dirección (slug)" hint="Se usa en /contratar?plan=…; si la dejas vacía sale del nombre." value={data.slug} onChange={(e) => setData('slug', e.target.value)} error={errors.slug} />
                        <Textarea label="Descripción" value={data.description} onChange={(e) => setData('description', e.target.value)} error={errors.description} rows={3} />
                        <Input label="Precio mensual" prefix="$" inputMode="numeric" required value={data.monthly_price} onChange={(e) => setData('monthly_price', e.target.value.replace(/\D/g, ''))} error={errors.monthly_price} />
                        <Input label="Días de prueba gratis" hint="0 = sin prueba: se paga al contratar." inputMode="numeric" value={data.trial_days} onChange={(e) => setData('trial_days', e.target.value.replace(/\D/g, ''))} error={errors.trial_days} />
                        <Input label="Orden en la landing" inputMode="numeric" value={data.sort_order} onChange={(e) => setData('sort_order', e.target.value.replace(/\D/g, ''))} error={errors.sort_order} />
                    </div>
                </Card>

                <div className="flex flex-col gap-5">
                    <Card>
                        <CardHeader title="Topes" description="Vacío es sin tope. Se cuentan los activos." />
                        <div className="grid gap-3 sm:grid-cols-3">
                            <Input label="Sedes" inputMode="numeric" value={data.max_stores} onChange={(e) => setData('max_stores', e.target.value.replace(/\D/g, ''))} error={errors.max_stores} />
                            <Input label="Usuarios" inputMode="numeric" value={data.max_users} onChange={(e) => setData('max_users', e.target.value.replace(/\D/g, ''))} error={errors.max_users} />
                            <Input label="Cajas" inputMode="numeric" value={data.max_registers} onChange={(e) => setData('max_registers', e.target.value.replace(/\D/g, ''))} error={errors.max_registers} />
                        </div>
                    </Card>

                    <Card>
                        <CardHeader title="Funciones" description="Lo que el plan no incluye no se pinta y el servidor lo rechaza." />
                        <div className="flex flex-col gap-3">
                            {featureOptions.map((feature) => (
                                <Checkbox
                                    key={feature.value}
                                    checked={data.features.includes(feature.value)}
                                    onChange={(e) => toggleFeature(feature.value, e.target.checked)}
                                    label={feature.label}
                                    hint={feature.description}
                                />
                            ))}
                        </div>
                    </Card>

                    <Card>
                        <CardHeader title="Visibilidad" />
                        <div className="flex flex-col gap-4">
                            <Toggle checked={data.is_active} onChange={(value) => setData('is_active', value)} label="Activo" hint="Desactivado no se ofrece; quien lo tiene lo conserva." />
                            <Toggle checked={data.is_public} onChange={(value) => setData('is_public', value)} label="Público" hint="Sale en la landing y en /contratar." />
                            <Toggle checked={data.is_featured} onChange={(value) => setData('is_featured', value)} label="Destacado" hint="«Más elegido» en la landing." />
                        </div>
                    </Card>

                    <div className="flex gap-2">
                        <Button type="submit" loading={form.processing}>
                            {plan ? 'Guardar el plan' : 'Crear el plan'}
                        </Button>
                        <ButtonLink href={route('platform.plans.index')} variant="secondary">
                            Cancelar
                        </ButtonLink>
                    </div>
                </div>
            </form>
        </AppLayout>
    );
}

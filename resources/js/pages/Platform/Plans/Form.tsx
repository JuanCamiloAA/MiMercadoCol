import { Link, useForm } from '@inertiajs/react';
import { ArrowsClockwiseIcon, PlusIcon, TrashIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { type FormEvent, useMemo, useState } from 'react';
import { PermissionMatrix } from '@/components/PermissionMatrix';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Input, Textarea, Toggle } from '@/components/ui/Field';
import { PageHeader } from '@/components/ui/Misc';
import { Modal } from '@/components/ui/Modal';
import AppLayout from '@/layouts/AppLayout';
import { csrfHeaders } from '@/lib/http';
import type { PermissionCatalogueModule } from '@/types';

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
    /** Las acciones que cubren sus nodos hoy. */
    permissions: string[];
    entitlements: string[];
    highlights: string[];
    is_public: boolean;
    is_featured: boolean;
    is_active: boolean;
    sort_order: number;
    companies_count: number;
}

interface PlanFormProps {
    plan: PlanData | null;
    /** El arbol que puede entrar en un plan: sin lo de la plataforma. */
    catalogue: PermissionCatalogueModule[];
    /** Lo que todo plan incluye y no se puede quitar. */
    always: string[];
    maxHighlights: number;
}

/** Un tope vacio es «sin tope». */
const toLimit = (value: string) => (value.trim() === '' ? null : Number(value));

/**
 * Crear o editar un plan de membresia: datos y precio, topes, lo que incluye (el arbol de
 * permisos) y los destacados para la landing.
 */
export default function PlanForm({ plan, catalogue, always, maxHighlights }: PlanFormProps) {
    const form = useForm({
        name: plan?.name ?? '',
        slug: plan?.slug ?? '',
        description: plan?.description ?? '',
        monthly_price: plan ? String(plan.monthly_price) : '',
        trial_days: plan ? String(plan.trial_days) : '0',
        max_stores: plan?.max_stores != null ? String(plan.max_stores) : '',
        max_users: plan?.max_users != null ? String(plan.max_users) : '',
        max_registers: plan?.max_registers != null ? String(plan.max_registers) : '',
        permissions: plan?.permissions ?? [],
        highlights: plan?.highlights ?? [],
        is_public: plan?.is_public ?? true,
        is_featured: plan?.is_featured ?? false,
        is_active: plan?.is_active ?? true,
        sort_order: plan ? String(plan.sort_order) : '0',
    });
    const { data, setData, errors } = form;
    const [confirming, setConfirming] = useState(false);
    const [generating, setGenerating] = useState(false);
    const [generateError, setGenerateError] = useState<string | null>(null);

    // Lo que el plan deja de incluir respecto a como estaba: se avisa antes de guardar.
    const lost = useMemo(() => {
        if (!plan) {
            return [];
        }

        const now = new Set([...data.permissions, ...always]);
        const labels = new Map(catalogue.flatMap((module) => module.groups.flatMap((group) => group.permissions.map((permission) => [permission.name, `${module.display} · ${group.display}: ${permission.label}`] as const))));

        return plan.permissions.filter((permission) => !now.has(permission)).map((permission) => labels.get(permission) ?? permission);
    }, [plan, data.permissions, always, catalogue]);

    const send = (confirmRemovals: boolean) => {
        form.transform((values) => ({
            ...values,
            // Al servidor van las acciones marcadas; el arma los comodines.
            entitlements: values.permissions,
            permissions: undefined,
            confirm_removals: confirmRemovals,
            monthly_price: Number(values.monthly_price || 0),
            trial_days: Number(values.trial_days || 0),
            max_stores: toLimit(values.max_stores),
            max_users: toLimit(values.max_users),
            max_registers: toLimit(values.max_registers),
            sort_order: Number(values.sort_order || 0),
        }));

        const options = { onFinish: () => setConfirming(false) };

        if (plan) {
            form.put(route('platform.plans.update', plan.id), options);
        } else {
            form.post(route('platform.plans.store'), options);
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (plan && plan.companies_count > 0 && lost.length > 0) {
            setConfirming(true);

            return;
        }

        send(false);
    };

    const generate = async () => {
        setGenerating(true);
        setGenerateError(null);

        try {
            const response = await fetch(route('platform.plans.highlights'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...csrfHeaders() },
                credentials: 'same-origin',
                body: JSON.stringify({ entitlements: data.permissions }),
            });
            const body = (await response.json().catch(() => ({}))) as { highlights?: string[]; message?: string };

            if (!response.ok || !Array.isArray(body.highlights)) {
                throw new Error(body.message ?? 'No se pudieron generar los destacados.');
            }

            setData('highlights', body.highlights.slice(0, maxHighlights));
        } catch (reason) {
            setGenerateError(reason instanceof Error ? reason.message : 'No se pudieron generar los destacados.');
        } finally {
            setGenerating(false);
        }
    };

    const setHighlight = (index: number, value: string) => setData('highlights', data.highlights.map((line, position) => (position === index ? value : line)));
    const entitlementErrors = Object.entries(errors).filter(([key]) => key === 'entitlements' || key.startsWith('entitlements.'));

    return (
        <AppLayout title={plan ? `Plan ${plan.name}` : 'Nuevo plan'}>
            <PageHeader
                title={plan ? `Plan ${plan.name}` : 'Nuevo plan'}
                description={
                    plan && plan.companies_count > 0
                        ? `Lo usan ${plan.companies_count} empresas: lo que incluye cambia de inmediato; el precio, en su próxima renovación.`
                        : 'El precio de cada ciclo se calcula del mensual con el descuento del ciclo.'
                }
                breadcrumb={<Link href={route('platform.plans.index')} className="hover:underline">Planes de membresía</Link>}
            />

            <form onSubmit={submit} className="flex max-w-[75rem] flex-col gap-5">
                <div className="grid gap-5 lg:grid-cols-2">
                    <Card>
                        <CardHeader title="Datos y precio" />
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
                            <CardHeader title="Topes" description="Vacío es sin tope. Se cuentan los activos. Son solo del plan: no tienen excepción por empresa." />
                            <div className="grid gap-3 sm:grid-cols-3">
                                <Input label="Sedes" inputMode="numeric" value={data.max_stores} onChange={(e) => setData('max_stores', e.target.value.replace(/\D/g, ''))} error={errors.max_stores} />
                                <Input label="Usuarios" inputMode="numeric" value={data.max_users} onChange={(e) => setData('max_users', e.target.value.replace(/\D/g, ''))} error={errors.max_users} />
                                <Input label="Cajas" inputMode="numeric" value={data.max_registers} onChange={(e) => setData('max_registers', e.target.value.replace(/\D/g, ''))} error={errors.max_registers} />
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
                    </div>
                </div>

                <Card>
                    <CardHeader
                        title="Qué incluye"
                        description="Es un techo: nadie de la empresa puede usar lo que el plan no trae, aunque lo tenga asignado. Marcar un módulo o una página completos incluye también lo que se agregue después."
                    />
                    {entitlementErrors.length > 0 && (
                        <p className="mb-3 flex items-start gap-2 rounded-control bg-[var(--danger-soft)] px-3 py-2 text-[0.8125rem] font-medium text-[var(--danger)]">
                            <WarningCircleIcon size={16} className="mt-px shrink-0" />
                            {entitlementErrors[0][1]}
                        </p>
                    )}
                    <PermissionMatrix
                        modules={catalogue}
                        selected={data.permissions}
                        onChange={(permissions) => setData('permissions', permissions)}
                        baseline={plan?.permissions}
                        locked={always}
                        wildcards
                    />
                </Card>

                <Card>
                    <CardHeader
                        title="Destacados para la landing"
                        description={`Lo que la landing y «Contratar» muestran del plan, máximo ${maxHighlights}. Si la lista queda vacía, se arma con los módulos que trae completos.`}
                        action={
                            <Button type="button" variant="secondary" size="sm" icon={<ArrowsClockwiseIcon size={14} />} loading={generating} onClick={generate}>
                                Generar a partir de los módulos
                            </Button>
                        }
                    />
                    <div className="flex flex-col gap-2">
                        {data.highlights.map((line, index) => (
                            <div key={index} className="flex items-start gap-2">
                                <Input
                                    containerClassName="flex-1"
                                    value={line}
                                    maxLength={80}
                                    onChange={(e) => setHighlight(index, e.target.value)}
                                    error={(errors as Record<string, string | undefined>)[`highlights.${index}`]}
                                    aria-label={`Destacado ${index + 1}`}
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    aria-label={`Quitar el destacado ${index + 1}`}
                                    icon={<TrashIcon size={15} />}
                                    onClick={() => setData('highlights', data.highlights.filter((_, position) => position !== index))}
                                />
                            </div>
                        ))}
                        {data.highlights.length < maxHighlights && (
                            <Button type="button" variant="ghost" size="sm" icon={<PlusIcon size={14} />} className="w-fit" onClick={() => setData('highlights', [...data.highlights, ''])}>
                                Agregar destacado
                            </Button>
                        )}
                        {(generateError || errors.highlights) && <p className="text-[0.78125rem] font-medium text-[var(--danger)]">{generateError ?? errors.highlights}</p>}
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
            </form>

            {confirming && plan && (
                <Modal
                    open
                    onClose={() => !form.processing && setConfirming(false)}
                    title="Este cambio le quita funciones a empresas"
                    description={`Lo usan ${plan.companies_count} ${plan.companies_count === 1 ? 'empresa' : 'empresas'}. El acceso se corta de inmediato; sus datos se conservan y queda en la bitácora de cada una.`}
                    footer={
                        <>
                            <Button variant="secondary" onClick={() => setConfirming(false)} disabled={form.processing}>
                                Revisar
                            </Button>
                            <Button variant="danger" onClick={() => send(true)} loading={form.processing}>
                                Guardar y quitar {lost.length}
                            </Button>
                        </>
                    }
                >
                    <p className="mb-2 text-[0.8125rem] font-medium">Pierden:</p>
                    <ul className="flex max-h-[18rem] flex-col gap-1 overflow-y-auto text-[0.8125rem] text-[var(--muted)]">
                        {lost.map((label) => (
                            <li key={label}>· {label}</li>
                        ))}
                    </ul>
                </Modal>
            )}
        </AppLayout>
    );
}

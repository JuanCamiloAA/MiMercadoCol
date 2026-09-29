import { Link, router } from '@inertiajs/react';
import { PencilSimpleIcon, PlusIcon, PowerIcon, StarIcon, TrashIcon } from '@phosphor-icons/react';
import { useState } from 'react';
import { ButtonLink } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { ConfirmDialog } from '@/components/ui/Modal';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { EmptyState, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { money } from '@/lib/format';
import { usePermission } from '@/lib/permissions';

interface PlanRow {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    monthly_price: number;
    trial_days: number;
    limits: { stores: number | null; users: number | null; registers: number | null };
    features: string[];
    prices: Record<string, number>;
    is_public: boolean;
    is_featured: boolean;
    is_active: boolean;
    companies_count: number;
}

interface PlansIndexProps {
    plans: PlanRow[];
    cycles: { id: number; name: string }[];
}

const limit = (value: number | null) => (value === null ? '∞' : String(value));

/**
 * Plataforma > Planes de membresia. El precio de cada ciclo lo calcula el servidor.
 */
export default function PlansIndex({ plans, cycles }: PlansIndexProps) {
    const { can } = usePermission();
    const [deleting, setDeleting] = useState<PlanRow | null>(null);

    return (
        <AppLayout title="Planes de membresía">
            <PageHeader
                title="Planes de membresía"
                description="Lo que cuesta cada plan en cada ciclo, sus topes y sus funciones."
                actions={
                    can('platform.plans.create') && (
                        <ButtonLink href={route('platform.plans.create')}>
                            <PlusIcon size={16} /> Nuevo plan
                        </ButtonLink>
                    )
                }
            />

            <Card padded={false}>
                {plans.length === 0 ? (
                    <EmptyState title="No hay planes" description="Crea el primero para poder contratar." />
                ) : (
                    <Table>
                        <THead>
                            <TR>
                                <TH>Plan</TH>
                                {cycles.map((cycle) => (
                                    <TH key={cycle.id} align="right">
                                        {cycle.name}
                                    </TH>
                                ))}
                                <TH>Topes (sedes · usuarios · cajas)</TH>
                                <TH>Funciones</TH>
                                <TH align="right">Empresas</TH>
                                <TH />
                            </TR>
                        </THead>
                        <TBody>
                            {plans.map((plan) => (
                                <TR key={plan.id}>
                                    <TD>
                                        <span className="flex items-center gap-1.5 font-semibold">
                                            {plan.name}
                                            {plan.is_featured && <StarIcon size={14} weight="fill" className="text-[var(--warn)]" />}
                                        </span>
                                        <span className="flex flex-wrap gap-1 pt-1">
                                            {!plan.is_active && <Badge tone="neutral">Desactivado</Badge>}
                                            {plan.is_active && !plan.is_public && <Badge tone="info">Privado</Badge>}
                                            {plan.trial_days > 0 && <Badge tone="accent">{plan.trial_days} días gratis</Badge>}
                                        </span>
                                    </TD>
                                    {cycles.map((cycle) => (
                                        <TD key={cycle.id} align="right">
                                            <span className="font-mono tabular">{money(plan.prices[String(cycle.id)] ?? 0)}</span>
                                        </TD>
                                    ))}
                                    <TD>
                                        <span className="font-mono tabular">
                                            {limit(plan.limits.stores)} · {limit(plan.limits.users)} · {limit(plan.limits.registers)}
                                        </span>
                                    </TD>
                                    <TD>
                                        <span className="text-[0.8125rem]">{plan.features.length > 0 ? plan.features.join(', ') : '—'}</span>
                                    </TD>
                                    <TD align="right">
                                        <span className="font-mono tabular">{plan.companies_count}</span>
                                    </TD>
                                    <TD align="right">
                                        <div className="flex justify-end gap-1">
                                            {can('platform.plans.edit') && (
                                                <Link href={route('platform.plans.edit', plan.id)} title="Editar" className="grid size-8 place-items-center rounded-control text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]">
                                                    <PencilSimpleIcon size={16} />
                                                </Link>
                                            )}
                                            {can('platform.plans.deactivate') && (
                                                <button
                                                    type="button"
                                                    title={plan.is_active ? 'Desactivar' : 'Activar'}
                                                    onClick={() => router.post(route('platform.plans.toggle', plan.id), {}, { preserveScroll: true })}
                                                    className="grid size-8 place-items-center rounded-control text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]"
                                                >
                                                    <PowerIcon size={16} />
                                                </button>
                                            )}
                                            {can('platform.plans.deactivate') && plan.companies_count === 0 && (
                                                <button
                                                    type="button"
                                                    title="Borrar"
                                                    onClick={() => setDeleting(plan)}
                                                    className="grid size-8 place-items-center rounded-control text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--danger)]"
                                                >
                                                    <TrashIcon size={16} />
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

            <ConfirmDialog
                open={deleting !== null}
                onClose={() => setDeleting(null)}
                onConfirm={() => deleting && router.delete(route('platform.plans.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) })}
                title={`¿Borrar el plan ${deleting?.name ?? ''}?`}
                message="Solo se puede borrar un plan que nunca se usó. Si ya tiene cobros, se desactiva."
                confirmLabel="Borrar"
                tone="danger"
            />
        </AppLayout>
    );
}

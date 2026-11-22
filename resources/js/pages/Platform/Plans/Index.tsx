import { Link, router } from '@inertiajs/react';
import { CaretDownIcon, CaretRightIcon, PencilSimpleIcon, PlusIcon, PowerIcon, StarIcon, TrashIcon } from '@phosphor-icons/react';
import { Fragment, type ReactNode, useState } from 'react';
import { ButtonLink } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { ConfirmDialog } from '@/components/ui/Modal';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { EmptyState, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
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
    /** Los modulos que trae, completos o en parte. */
    modules: { key: string; display: string; state: 'all' | 'some' }[];
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

const ACTION = 'grid size-8 place-items-center rounded-control text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]';

/**
 * Plataforma > Planes de membresia. El precio de cada ciclo lo calcula el servidor.
 *
 * Cada plan va en una sola linea (precios, topes, cuantos modulos y empresas); lo que incluye
 * modulo por modulo se despliega debajo al tocar la fila.
 */
export default function PlansIndex({ plans, cycles }: PlansIndexProps) {
    const { can } = usePermission();
    const [deleting, setDeleting] = useState<PlanRow | null>(null);
    const [open, setOpen] = useState<number[]>([]);
    // Plan, un precio por ciclo, topes, que incluye, empresas y acciones.
    const columns = cycles.length + 5;

    const toggle = (id: number) => setOpen((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]));

    return (
        <AppLayout title="Planes de membresía">
            <PageHeader
                title="Planes de membresía"
                description="Lo que cuesta cada plan en cada ciclo, sus topes y lo que incluye."
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
                                <TH>
                                    <span title="Sedes · usuarios · cajas">Topes</span>
                                </TH>
                                <TH>Qué incluye</TH>
                                <TH align="right">Empresas</TH>
                                <TH />
                            </TR>
                        </THead>
                        <TBody>
                            {plans.map((plan) => {
                                const expanded = open.includes(plan.id);
                                const partial = plan.modules.filter((module) => module.state === 'some').length;
                                const detailId = `plan-${plan.id}-detalle`;

                                return (
                                    <Fragment key={plan.id}>
                                        <TR onClick={() => toggle(plan.id)} className={cn(expanded && 'border-b-0 bg-[var(--surface-2)]/60')}>
                                            <TD>
                                                <div className="flex items-center gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={(event) => {
                                                            // La fila tambien despliega: sin esto se abriria y cerraria en el mismo toque.
                                                            event.stopPropagation();
                                                            toggle(plan.id);
                                                        }}
                                                        aria-expanded={expanded}
                                                        aria-controls={detailId}
                                                        aria-label={expanded ? `Ocultar el detalle de ${plan.name}` : `Ver el detalle de ${plan.name}`}
                                                        className="grid size-7 shrink-0 place-items-center rounded-chip text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]"
                                                    >
                                                        {expanded ? <CaretDownIcon size={14} weight="bold" /> : <CaretRightIcon size={14} weight="bold" />}
                                                    </button>
                                                    <div className="min-w-0">
                                                        <span className="flex items-center gap-1.5 whitespace-nowrap font-semibold">
                                                            {plan.name}
                                                            {plan.is_featured && <StarIcon size={14} weight="fill" className="text-[var(--warn)]" />}
                                                        </span>
                                                        {(!plan.is_active || !plan.is_public || plan.trial_days > 0) && (
                                                            <span className="flex flex-wrap gap-1 pt-1">
                                                                {!plan.is_active && <Badge tone="neutral">Desactivado</Badge>}
                                                                {plan.is_active && !plan.is_public && <Badge tone="info">Privado</Badge>}
                                                                {plan.trial_days > 0 && <Badge tone="accent">{plan.trial_days} días gratis</Badge>}
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                            </TD>
                                            {cycles.map((cycle) => (
                                                <TD key={cycle.id} align="right">
                                                    <span className="whitespace-nowrap font-mono tabular">{money(plan.prices[String(cycle.id)] ?? 0)}</span>
                                                </TD>
                                            ))}
                                            <TD>
                                                <span
                                                    className="whitespace-nowrap font-mono tabular"
                                                    title={`${limitText(plan.limits.stores, 'sede', 'sedes')} · ${limitText(plan.limits.users, 'usuario', 'usuarios')} · ${limitText(plan.limits.registers, 'caja', 'cajas')}`}
                                                >
                                                    {limit(plan.limits.stores)} · {limit(plan.limits.users)} · {limit(plan.limits.registers)}
                                                </span>
                                            </TD>
                                            <TD>
                                                <span className="block whitespace-nowrap">{plan.modules.length === 1 ? '1 módulo' : `${plan.modules.length} módulos`}</span>
                                                {partial > 0 && <span className="block whitespace-nowrap text-[0.75rem] text-[#1D6FA5]">{partial} en parte</span>}
                                            </TD>
                                            <TD align="right">
                                                <span className="font-mono tabular">{plan.companies_count}</span>
                                            </TD>
                                            <TD align="right">
                                                {/* Los botones hacen lo suyo sin desplegar la fila. */}
                                                <div className="flex justify-end gap-1" onClick={(event) => event.stopPropagation()}>
                                                    {can('platform.plans.edit') && (
                                                        <Link href={route('platform.plans.edit', plan.id)} title="Editar" className={ACTION}>
                                                            <PencilSimpleIcon size={16} />
                                                        </Link>
                                                    )}
                                                    {can('platform.plans.deactivate') && (
                                                        <button
                                                            type="button"
                                                            title={plan.is_active ? 'Desactivar' : 'Activar'}
                                                            onClick={() => router.post(route('platform.plans.toggle', plan.id), {}, { preserveScroll: true })}
                                                            className={ACTION}
                                                        >
                                                            <PowerIcon size={16} />
                                                        </button>
                                                    )}
                                                    {can('platform.plans.deactivate') && plan.companies_count === 0 && (
                                                        <button type="button" title="Borrar" onClick={() => setDeleting(plan)} className={cn(ACTION, 'hover:text-[var(--danger)]')}>
                                                            <TrashIcon size={16} />
                                                        </button>
                                                    )}
                                                </div>
                                            </TD>
                                        </TR>
                                        {expanded && (
                                            <tr id={detailId} className="border-b border-[var(--line)] bg-[var(--surface-2)]/60 last:border-b-0">
                                                <td colSpan={columns} className="px-4 pb-4 pt-1">
                                                    <PlanDetail plan={plan} />
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                );
                            })}
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

/** Un tope dicho con palabras: «1 sede», «5 usuarios», «sin tope de cajas». */
function limitText(value: number | null, one: string, many: string): string {
    if (value === null) {
        return `sin tope de ${many}`;
    }

    return `${value} ${value === 1 ? one : many}`;
}

/** Lo que se despliega bajo la fila: los modulos uno por uno y los datos que no caben en la linea. */
function PlanDetail({ plan }: { plan: PlanRow }) {
    const full = plan.modules.filter((module) => module.state === 'all');
    const partial = plan.modules.filter((module) => module.state === 'some');

    return (
        // En tarjetas esta fila va centrada (`data-card='full'`): el detalle se lee alineado a la izquierda.
        // Con la tabla mas ancha que la pantalla, se queda a la vista a la izquierda al desplazarla.
        <div className="sticky left-4 grid max-w-[56rem] gap-4 text-left md:grid-cols-[minmax(0,1fr)_17rem] md:pl-9">
            <div className="grid content-start gap-3">
                {plan.description && <p className="text-[0.8125rem] text-[var(--muted)]">{plan.description}</p>}
                <DetailGroup title={`Completos (${full.length})`}>
                    {full.length > 0 ? full.map((module) => <Badge key={module.key}>{module.display}</Badge>) : <span className="text-[0.8125rem] text-[var(--muted)]">Ninguno.</span>}
                </DetailGroup>
                {partial.length > 0 && (
                    <DetailGroup title={`En parte (${partial.length})`} hint="Solo algunas de sus páginas o acciones.">
                        {partial.map((module) => (
                            <Badge key={module.key} tone="info">
                                {module.display}
                            </Badge>
                        ))}
                    </DetailGroup>
                )}
            </div>

            <dl className="grid content-start gap-1.5 rounded-control border border-[var(--line)] bg-[var(--surface)] p-3 text-[0.8125rem]">
                <Fact label="Sedes" value={plan.limits.stores === null ? 'Sin tope' : String(plan.limits.stores)} />
                <Fact label="Usuarios" value={plan.limits.users === null ? 'Sin tope' : String(plan.limits.users)} />
                <Fact label="Cajas" value={plan.limits.registers === null ? 'Sin tope' : String(plan.limits.registers)} />
                <Fact label="Prueba gratis" value={plan.trial_days > 0 ? `${plan.trial_days} días` : 'No'} />
                <Fact label="En la landing y /contratar" value={plan.is_public ? 'Sí' : 'No, es privado'} />
                <Fact label="Dirección" value={plan.slug} mono />
            </dl>
        </div>
    );
}

function DetailGroup({ title, hint, children }: { title: string; hint?: string; children: ReactNode }) {
    return (
        <div>
            <p className="pb-1.5 text-[0.6875rem] font-semibold uppercase tracking-wide text-[var(--muted)]">
                {title}
                {hint && <span className="pl-1.5 font-normal normal-case tracking-normal">· {hint}</span>}
            </p>
            <div className="flex flex-wrap gap-1">{children}</div>
        </div>
    );
}

function Fact({ label, value, mono = false }: { label: string; value: string; mono?: boolean }) {
    return (
        <div className="flex items-baseline justify-between gap-3">
            <dt className="text-[var(--muted)]">{label}</dt>
            <dd className={cn('truncate text-right font-medium text-[var(--text)]', mono && 'font-mono tabular')}>{value}</dd>
        </div>
    );
}

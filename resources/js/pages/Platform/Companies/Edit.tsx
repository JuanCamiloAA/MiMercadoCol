import { router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeftIcon, CreditCardIcon, PowerIcon, SignInIcon } from '@phosphor-icons/react';
import { type FormEvent, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Checkbox, Input, Select, Textarea } from '@/components/ui/Field';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { Modal } from '@/components/ui/Modal';
import { EmptyState, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { date, dateTime, money } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { MembershipStatus, PageProps } from '@/types';
import { ToggleStatusModal } from './Index';
import { MembershipBadge, UsageMeter } from '@/components/membership/MembershipParts';

interface Option {
    value: string | number;
    label: string;
}

interface CompanyDetail {
    id: number;
    name: string;
    slug: string;
    legal_name: string;
    tax_id: string;
    email: string | null;
    phone: string | null;
    address: string | null;
    city: string | null;
    is_active: boolean;
    show_on_landing: boolean;
    deactivated_at: string | null;
    deactivation_reason: string | null;
    membership_plan_id: number | null;
    billing_cycle_id: number | null;
    membership_status: MembershipStatus;
    membership_label: string;
    membership_started_at: string | null;
    membership_ends_at: string | null;
    grace_ends_at: string | null;
    next_charge_at: string | null;
    days_left: number | null;
    auto_renew: boolean;
    next_plan: string | null;
    next_cycle: string | null;
    card: { brand: string | null; last4: string | null; exp: string } | null;
}

interface CompaniesEditProps {
    company: CompanyDetail;
    plans: Option[];
    cycles: Option[];
    statusOptions: Option[];
    usage: Record<'stores' | 'users' | 'registers', { used: number; limit: number | null }>;
    admins: { id: number; name: string; email: string; is_active: boolean; pending_password: boolean }[];
    events: { id: number; type: string; label: string; data: Record<string, unknown> | null; user: string | null; created_at: string | null }[];
    charges: { id: number; reference: string; concept: string; amount: number; status: string; status_label: string; method: string; charged_at: string | null }[];
}

const USAGE_LABELS = { stores: 'Sedes', users: 'Usuarios', registers: 'Cajas' } as const;

/**
 * Editar una empresa: datos legales y, a mano y con motivo, su membresia. Todo cambio de
 * membresia queda en la bitacora de abajo.
 */
export default function CompaniesEdit({ company, plans, cycles, statusOptions, usage, admins, events, charges }: CompaniesEditProps) {
    const { can } = usePermission();
    const { flash } = usePage<PageProps>().props;
    const [toggling, setToggling] = useState(false);
    const [paying, setPaying] = useState(false);

    useEffect(() => {
        if (flash?.temporary_password) {
            toast.success(`Contraseña temporal del administrador: ${flash.temporary_password}`, {
                duration: 30000,
                description: 'Cópiala ahora: no se vuelve a mostrar.',
            });
        }
    }, [flash?.temporary_password]);

    const { data, setData, put, processing, errors, isDirty } = useForm({
        name: company.name,
        legal_name: company.legal_name,
        tax_id: company.tax_id,
        email: company.email ?? '',
        phone: company.phone ?? '',
        address: company.address ?? '',
        city: company.city ?? '',
        show_on_landing: company.show_on_landing,
        membership_plan_id: company.membership_plan_id !== null ? String(company.membership_plan_id) : '',
        billing_cycle_id: company.billing_cycle_id !== null ? String(company.billing_cycle_id) : '',
        membership_status: company.membership_status as string,
        membership_ends_at: company.membership_ends_at ?? '',
        grace_ends_at: company.grace_ends_at ?? '',
        membership_reason: '',
    });

    const membershipTouched =
        data.membership_plan_id !== (company.membership_plan_id !== null ? String(company.membership_plan_id) : '') ||
        data.billing_cycle_id !== (company.billing_cycle_id !== null ? String(company.billing_cycle_id) : '') ||
        data.membership_status !== company.membership_status ||
        data.membership_ends_at !== (company.membership_ends_at ?? '') ||
        data.grace_ends_at !== (company.grace_ends_at ?? '');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        put(route('companies.update', company.id), { preserveScroll: true, onSuccess: () => setData('membership_reason', '') });
    };

    return (
        <AppLayout title={company.name}>
            <PageHeader
                title={company.name}
                description={`NIT ${company.tax_id} · tienda en /tienda/${company.slug}`}
                actions={
                    <>
                        <ButtonLink href={route('companies.index')} variant="ghost" icon={<ArrowLeftIcon size={16} />}>
                            Empresas
                        </ButtonLink>
                        {can('companies.index.delete') && (
                            <Button variant="secondary" icon={<PowerIcon size={16} />} onClick={() => setToggling(true)}>
                                {company.is_active ? 'Desactivar' : 'Activar'}
                            </Button>
                        )}
                        {can('companies.index.switch') && (
                            <Button icon={<SignInIcon size={16} />} onClick={() => router.post(route('companies.enter', company.id))}>
                                Entrar a la empresa
                            </Button>
                        )}
                    </>
                }
            />

            {!company.is_active && (
                <p className="mb-4 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 py-2 text-[0.8125rem] text-[var(--muted)]">
                    Desactivada {company.deactivated_at ? `el ${date(company.deactivated_at, 'd MMM yyyy')}` : ''}
                    {company.deactivation_reason ? `: ${company.deactivation_reason}` : ''}. Sus usuarios no pueden entrar.
                </p>
            )}

            <form onSubmit={submit} className="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader title="Datos de la empresa" description="El logo y el color se cambian en Ajustes > Marca de la empresa." />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Input label="Nombre comercial" required value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} containerClassName="sm:col-span-2" />
                        <Input label="Razón social" required value={data.legal_name} onChange={(e) => setData('legal_name', e.target.value)} error={errors.legal_name} containerClassName="sm:col-span-2" />
                        <Input label="NIT" required value={data.tax_id} onChange={(e) => setData('tax_id', e.target.value)} error={errors.tax_id} />
                        <Input label="Ciudad" value={data.city} onChange={(e) => setData('city', e.target.value)} error={errors.city} />
                        <Input label="Correo" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} error={errors.email} />
                        <Input label="Teléfono" value={data.phone} onChange={(e) => setData('phone', e.target.value)} error={errors.phone} />
                        <Input label="Dirección" value={data.address} onChange={(e) => setData('address', e.target.value)} error={errors.address} containerClassName="sm:col-span-2" />
                    </div>
                    <div className="mt-4">
                        <Checkbox
                            label="Mostrar en la landing"
                            hint="Su logo aparece entre las empresas presentes y sus tiendas en el buscador."
                            checked={data.show_on_landing}
                            onChange={(e) => setData('show_on_landing', e.target.checked)}
                        />
                    </div>
                </Card>

                <Card>
                    <CardHeader
                        title="Membresía"
                        description="Un cambio aquí aplica de inmediato y queda en la bitácora con su motivo."
                        action={<MembershipBadge status={company.membership_status} label={company.membership_label} />}
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Select label="Plan" options={plans} placeholder="Sin plan" value={data.membership_plan_id} onChange={(e) => setData('membership_plan_id', e.target.value)} error={errors.membership_plan_id} />
                        <Select label="Ciclo" options={cycles} placeholder="Sin ciclo" value={data.billing_cycle_id} onChange={(e) => setData('billing_cycle_id', e.target.value)} error={errors.billing_cycle_id} />
                        <Select label="Estado" options={statusOptions} value={data.membership_status} onChange={(e) => setData('membership_status', e.target.value)} error={errors.membership_status} />
                        <Input label="Vence" type="date" value={data.membership_ends_at} onChange={(e) => setData('membership_ends_at', e.target.value)} error={errors.membership_ends_at} />
                        <Input label="Gracia hasta" type="date" value={data.grace_ends_at} onChange={(e) => setData('grace_ends_at', e.target.value)} error={errors.grace_ends_at} />
                    </div>
                    {membershipTouched && (
                        <Textarea
                            label="Motivo del cambio"
                            required
                            className="mt-3 min-h-[4rem]"
                            value={data.membership_reason}
                            onChange={(e) => setData('membership_reason', e.target.value)}
                            error={errors.membership_reason}
                            maxLength={250}
                        />
                    )}

                    <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-[0.8125rem]">
                        <dt className="text-[var(--muted)]">Desde</dt>
                        <dd className="text-right">{company.membership_started_at ? date(company.membership_started_at, 'd MMM yyyy') : '—'}</dd>
                        <dt className="text-[var(--muted)]">Días restantes</dt>
                        <dd className="text-right font-mono tabular">{company.days_left ?? '—'}</dd>
                        <dt className="text-[var(--muted)]">Renovación automática</dt>
                        <dd className="text-right">{company.auto_renew ? 'Encendida' : 'Apagada'}</dd>
                        <dt className="text-[var(--muted)]">Tarjeta</dt>
                        <dd className="flex items-center justify-end gap-1.5">
                            {company.card ? (
                                <>
                                    <CreditCardIcon size={14} />
                                    {company.card.brand} •••• {company.card.last4} · {company.card.exp}
                                </>
                            ) : (
                                'Sin tarjeta'
                            )}
                        </dd>
                        {company.next_plan && (
                            <>
                                <dt className="text-[var(--muted)]">Cambio programado</dt>
                                <dd className="text-right">
                                    {company.next_plan} · {company.next_cycle}
                                </dd>
                            </>
                        )}
                    </dl>

                    <Button type="submit" size="lg" className="mt-5" loading={processing} disabled={!isDirty}>
                        Guardar cambios
                    </Button>
                </Card>
            </form>

            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader title="Uso del plan" />
                    <div className="grid gap-3 sm:grid-cols-3">
                        {(Object.keys(USAGE_LABELS) as (keyof typeof USAGE_LABELS)[]).map((key) => (
                            <UsageMeter key={key} label={USAGE_LABELS[key]} used={usage[key].used} limit={usage[key].limit} />
                        ))}
                    </div>

                    <h3 className="mt-5 mb-2 text-[0.875rem]">Administradores</h3>
                    <ul className="flex flex-col gap-1.5">
                        {admins.map((admin) => (
                            <li key={admin.id} className="flex items-center justify-between gap-2 text-[0.8125rem]">
                                <span className="min-w-0">
                                    <span className="block truncate font-medium">{admin.name}</span>
                                    <span className="block truncate text-[var(--muted)]">{admin.email}</span>
                                </span>
                                <span className="flex gap-1">
                                    {admin.pending_password && <Badge tone="warn">Clave temporal</Badge>}
                                    {!admin.is_active && <Badge tone="neutral">Inactivo</Badge>}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Card>

                <Card>
                    <CardHeader
                        title="Cobros"
                        action={
                            can('companies.index.edit') && company.membership_plan_id ? (
                                <Button size="sm" variant="secondary" onClick={() => setPaying(true)}>
                                    <CreditCardIcon size={14} /> Registrar pago
                                </Button>
                            ) : undefined
                        }
                    />
                    {charges.length === 0 ? (
                        <p className="text-[0.8125rem] text-[var(--muted)]">Todavía no hay cobros.</p>
                    ) : (
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Fecha</TH>
                                    <TH>Concepto</TH>
                                    <TH align="right">Monto</TH>
                                    <TH>Estado</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {charges.map((charge) => (
                                    <TR key={charge.id}>
                                        <TD className="text-[0.78125rem]">{charge.charged_at ? date(charge.charged_at, 'd MMM yyyy') : '—'}</TD>
                                        <TD className="text-[0.78125rem]">
                                            {charge.concept}
                                            <span className="block font-mono text-[0.71875rem] text-[var(--muted)]">{charge.reference}</span>
                                        </TD>
                                        <TD align="right" className="font-mono tabular">
                                            {money(charge.amount)}
                                        </TD>
                                        <TD>
                                            <Badge tone={charge.status === 'approved' ? 'accent' : charge.status === 'pending' ? 'info' : 'danger'}>{charge.status_label}</Badge>
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    )}
                </Card>
            </div>

            <Card className="mt-4">
                <CardHeader title="Bitácora de membresía" />
                {events.length === 0 ? (
                    <EmptyState title="Sin movimientos" />
                ) : (
                    <ol className="flex flex-col divide-y divide-[var(--line)]">
                        {events.map((event) => (
                            <li key={event.id} className="flex flex-wrap items-baseline justify-between gap-2 py-2 text-[0.8125rem]">
                                <span className="min-w-0">
                                    <span className="font-medium">{event.label}</span>
                                    {typeof event.data?.reason === 'string' && event.data.reason !== '' && <span className="text-[var(--muted)]"> — {event.data.reason}</span>}
                                    {event.user && <span className="text-[var(--muted)]"> · {event.user}</span>}
                                </span>
                                <span className="text-[0.75rem] text-[var(--muted)]">{event.created_at ? dateTime(event.created_at) : ''}</span>
                            </li>
                        ))}
                    </ol>
                )}
            </Card>

            <ToggleStatusModal company={toggling ? company : null} onClose={() => setToggling(false)} />
            {paying && <ManualPaymentModal company={company} onClose={() => setPaying(false)} />}
        </AppLayout>
    );
}

/**
 * Un pago recibido por fuera de la pasarela. Renueva el periodo igual que uno en linea y queda
 * como cobro aprobado con su nota.
 */
function ManualPaymentModal({ company, onClose }: { company: CompanyDetail; onClose: () => void }) {
    const form = useForm({ note: '' });

    return (
        <Modal
            open
            onClose={onClose}
            title={`Registrar pago de ${company.name}`}
            description="Renueva un periodo del plan y ciclo actuales (o del cambio programado)."
            size="sm"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button onClick={() => form.post(route('companies.payments.store', company.id), { preserveScroll: true, onSuccess: () => onClose() })} loading={form.processing}>
                        Registrar
                    </Button>
                </>
            }
        >
            <Input
                label="Cómo llegó el pago"
                placeholder="Transferencia Bancolombia 12/10"
                value={form.data.note}
                onChange={(e) => form.setData('note', e.target.value)}
                error={form.errors.note ?? (form.errors as Record<string, string | undefined>).membership ?? (form.errors as Record<string, string | undefined>).method}
            />
        </Modal>
    );
}

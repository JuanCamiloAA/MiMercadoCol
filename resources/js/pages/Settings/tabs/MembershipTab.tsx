import { router, useForm } from '@inertiajs/react';
import { CreditCardIcon, DownloadSimpleIcon, LockSimpleIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { type FormEvent, useEffect, useState } from 'react';
import { MembershipBadge, UsageMeter } from '@/components/membership/MembershipParts';
import { EntitlementTree } from '@/components/membership/PlanTree';
import { Button } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Checkbox, Input, Select, Toggle } from '@/components/ui/Field';
import { Badge, type BadgeTone } from '@/components/ui/Misc';
import { Modal } from '@/components/ui/Modal';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { cn } from '@/lib/cn';
import { date, money } from '@/lib/format';
import { TabHeader } from '../SettingField';
import type { MembershipChargeRow, MembershipOverview, PlanDiff, TabProps } from '../types';

const CHARGE_TONES: Record<MembershipChargeRow['status'], BadgeTone> = {
    pending: 'info',
    approved: 'accent',
    declined: 'danger',
    error: 'danger',
    voided: 'neutral',
};

const USAGE_LABELS = { stores: 'Sedes', users: 'Usuarios', registers: 'Cajas' } as const;

/**
 * Ajustes > Membresia: el plan, el uso, la tarjeta, la renovacion y los pagos.
 *
 * Sin pasarela habilitada no se pinta ningun boton que la use: se dice a quien escribir.
 */
export function MembershipTab({ membership }: TabProps) {
    if (!membership) {
        return <p className="text-[0.875rem] text-[var(--muted)]">No tienes acceso a la membresía de la empresa.</p>;
    }

    return <MembershipPanel membership={membership} />;
}

function MembershipPanel({ membership: m }: { membership: MembershipOverview }) {
    const [cardOpen, setCardOpen] = useState(false);
    const [payOpen, setPayOpen] = useState(false);
    const [planOpen, setPlanOpen] = useState(false);
    const [renewError, setRenewError] = useState<string | null>(null);
    const gatewayOn = m.gateway.enabled;
    const canPay = m.can_manage && gatewayOn && m.quote !== null && m.quote.amount > 0;

    const toggleRenew = (value: boolean) => {
        setRenewError(null);
        router.put(
            route('settings.membership.auto_renew'),
            { auto_renew: value },
            { preserveScroll: true, onError: (errors) => setRenewError(errors.auto_renew ?? null) },
        );
    };

    return (
        <div className="flex flex-col gap-5">
            <TabHeader scope="Membresía · lo que la empresa paga por usar la plataforma" />

            {!gatewayOn && (
                <p className="flex items-start gap-2 rounded-control border border-[var(--line)] bg-[var(--surface)] px-3 py-2.5 text-[0.8125rem] text-[var(--muted)]">
                    <WarningCircleIcon size={18} className="mt-px shrink-0" />
                    <span>
                        El pago en línea no está disponible; comunícate con soporte
                        {m.gateway.support_email ? (
                            <>
                                {' '}
                                (<a className="font-medium text-[var(--accent)] hover:underline" href={`mailto:${m.gateway.support_email}`}>{m.gateway.support_email}</a>)
                            </>
                        ) : null}
                        .
                    </span>
                </p>
            )}

            <div className="grid gap-5 xl:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
                {/* 1. Tu plan */}
                <Card>
                    <CardHeader
                        title={m.plan ? `Plan ${m.plan.name}` : 'Sin plan asignado'}
                        description={m.cycle ? `${m.cycle.name}${m.price !== null ? ` · ${money(m.price)}` : ''}` : 'La empresa no tiene un plan con cobro: tiene todas las funciones.'}
                        action={<MembershipBadge status={m.status} label={m.status_label} />}
                    />

                    <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-[0.8125rem] sm:grid-cols-3">
                        <Fact label="Inicio" value={m.started_at ? date(m.started_at) : '—'} />
                        <Fact label="Vence" value={m.ends_at ? date(m.ends_at) : 'Sin vencimiento'} />
                        <Fact
                            label="Días restantes"
                            value={m.days_left === null ? '—' : m.days_left < 0 ? `Vencida hace ${Math.abs(m.days_left)}` : String(m.days_left)}
                            tone={m.days_left !== null && m.days_left <= 7 ? 'warn' : undefined}
                        />
                        {m.grace_ends_at && m.status === 'grace' && <Fact label="En gracia hasta" value={date(m.grace_ends_at)} tone="warn" />}
                        <Fact label="Próxima renovación" value={m.auto_renew && m.next_charge_at ? date(m.next_charge_at) : 'Manual'} />
                    </dl>

                    {m.scheduled_change && (
                        <p className="mt-4 rounded-control bg-[var(--accent-soft)] px-3 py-2 text-[0.8125rem] text-[var(--accent)]">
                            Cambiará a {m.scheduled_change.plan} · {m.scheduled_change.cycle}
                            {m.scheduled_change.applies_at ? ` el ${date(m.scheduled_change.applies_at)}` : ' en la próxima renovación'}.
                        </p>
                    )}

                    {m.special_price && (
                        <p className="mt-4 rounded-control bg-[var(--accent-soft)] px-3 py-2 text-[0.8125rem] font-medium text-[var(--accent)]">
                            Precio especial ({m.special_price.description}){m.special_price.ends_at ? ` hasta el ${date(m.special_price.ends_at)}` : ''}.
                        </p>
                    )}

                    {m.plan && m.plan.modules.length > 0 && (
                        <div className="mt-4 flex flex-wrap gap-1.5">
                            {m.plan.modules.map((module) => (
                                <Badge key={module.key} tone={module.state === 'all' ? 'neutral' : 'info'}>
                                    {module.display}
                                    {module.state === 'some' ? ' (en parte)' : ''}
                                </Badge>
                            ))}
                        </div>
                    )}

                    {(canPay || (m.can_manage && m.plans.length > 0)) && (
                        <div className="mt-5 flex flex-wrap gap-2 border-t border-[var(--line)] pt-4">
                            {canPay && (
                                <Button onClick={() => setPayOpen(true)} disabled={m.in_flight}>
                                    {m.in_flight ? 'Pago en proceso…' : 'Pagar ahora'}
                                </Button>
                            )}
                            {m.can_manage && m.plans.length > 0 && (
                                <Button variant="secondary" onClick={() => setPlanOpen(true)}>
                                    Cambiar plan o ciclo
                                </Button>
                            )}
                        </div>
                    )}
                </Card>

                {/* 2. Uso */}
                <Card>
                    <CardHeader title="Uso del plan" description="Lo activo contra el tope. Desactivar libera cupo." />
                    <div className="flex flex-col gap-4">
                        {(Object.keys(USAGE_LABELS) as (keyof typeof USAGE_LABELS)[]).map((key) => (
                            <UsageMeter key={key} label={USAGE_LABELS[key]} used={m.usage[key].used} limit={m.usage[key].limit} />
                        ))}
                    </div>
                </Card>
            </div>

            {/* Lo que incluye: el arbol en solo lectura, con lo agregado y su vencimiento. */}
            <Card>
                <CardHeader
                    title="Qué incluye tu plan"
                    description="Lo que no está marcado no lo trae tu plan: nadie de la empresa lo puede usar, aunque lo tenga asignado. Los datos se conservan."
                />
                {m.entitlements.exceptions.length > 0 && (
                    <ul className="mb-3 flex flex-col gap-1 text-[0.8125rem]">
                        {m.entitlements.exceptions.map((exception) => (
                            <li key={exception.id} className="flex flex-wrap items-center gap-1.5">
                                <Badge tone={exception.effect === 'grant' ? 'accent' : 'danger'}>{exception.effect === 'grant' ? 'Agregado' : 'Quitado'}</Badge>
                                <span>{exception.label}</span>
                                <span className="text-[var(--muted)]">
                                    {exception.starts_at && new Date(exception.starts_at) > new Date() ? `desde el ${date(exception.starts_at)} ` : ''}
                                    {exception.ends_at ? `hasta el ${date(exception.ends_at)}` : 'sin vencimiento'}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
                <EntitlementTree tree={m.entitlements.tree} />
            </Card>

            {/* 3 y 4. Tarjeta y renovacion automatica */}
            {(gatewayOn || m.card) && (
                <Card>
                    <CardHeader title="Tarjeta y renovación" description="La tarjeta se guarda en la pasarela: aquí solo quedan la marca y los últimos cuatro." />

                    <div className="flex flex-wrap items-start justify-between gap-4">
                        {m.card ? (
                            <div className="flex items-center gap-3">
                                <span className="grid size-11 place-items-center rounded-control border border-[var(--line)] bg-[var(--surface-2)]">
                                    <CreditCardIcon size={22} />
                                </span>
                                <div className="text-[0.875rem]">
                                    <p className="font-semibold">
                                        {m.card.brand ?? 'Tarjeta'} <span className="font-mono tabular">•••• {m.card.last4 ?? '····'}</span>
                                    </p>
                                    <p className="text-[0.8125rem] text-[var(--muted)]">
                                        {m.card.exp_month && m.card.exp_year
                                            ? `Vence ${String(m.card.exp_month).padStart(2, '0')}/${String(m.card.exp_year).slice(-2)}`
                                            : 'Sin fecha de vencimiento'}
                                        {m.card.holder ? ` · ${m.card.holder}` : ''}
                                    </p>
                                    {m.card.expires_before_renewal && (
                                        <p className="mt-1 text-[0.8125rem] font-medium text-[var(--warn)]">Vence antes de la próxima renovación: cámbiala.</p>
                                    )}
                                    {m.card.other_environment && (
                                        <p className="mt-1 text-[0.8125rem] font-medium text-[var(--warn)]">
                                            Se guardó en otro entorno de la pasarela y no sirve para cobrar: vuelve a registrarla.
                                        </p>
                                    )}
                                </div>
                            </div>
                        ) : (
                            <p className="text-[0.875rem] text-[var(--muted)]">No hay tarjeta guardada.</p>
                        )}

                        {m.can_manage && gatewayOn && (
                            <Button variant="secondary" onClick={() => setCardOpen(true)}>
                                {m.card ? 'Cambiar tarjeta' : 'Agregar tarjeta'}
                            </Button>
                        )}
                    </div>

                    {m.can_manage && gatewayOn && (
                        <div className="mt-5 border-t border-[var(--line)] pt-4">
                            <Toggle
                                checked={m.auto_renew}
                                onChange={toggleRenew}
                                label="Renovación automática"
                                hint="El día del vencimiento se cobra a la tarjeta guardada el plan y ciclo que tengas."
                            />
                            {renewError && <p className="mt-2 text-[0.78125rem] font-medium text-[var(--danger)]">{renewError}</p>}
                        </div>
                    )}
                </Card>
            )}

            {/* 7. Historial */}
            <Card padded={false}>
                <div className="p-5 pb-3">
                    <CardHeader title="Historial de cobros" description="Los aprobados tienen comprobante en PDF." className="mb-0" />
                </div>
                {m.charges.length === 0 ? (
                    <p className="px-5 pb-5 text-[0.875rem] text-[var(--muted)]">Todavía no hay cobros.</p>
                ) : (
                    <Table>
                        <THead>
                            <TR>
                                <TH>Fecha</TH>
                                <TH>Concepto</TH>
                                <TH>Periodo</TH>
                                <TH align="right">Monto</TH>
                                <TH>Estado</TH>
                                <TH>Referencia</TH>
                                <TH />
                            </TR>
                        </THead>
                        <TBody>
                            {m.charges.map((charge) => (
                                <TR key={charge.id}>
                                    <TD>{date(charge.date)}</TD>
                                    <TD>
                                        <span className="block">{charge.concept}</span>
                                        <span className="text-[0.75rem] text-[var(--muted)]">{charge.method_label}</span>
                                    </TD>
                                    <TD>
                                        {charge.period_starts_at && charge.period_ends_at ? `${date(charge.period_starts_at)} – ${date(charge.period_ends_at)}` : '—'}
                                    </TD>
                                    <TD align="right">
                                        <span className="font-mono tabular">{money(charge.amount)}</span>
                                    </TD>
                                    <TD>
                                        <Badge tone={CHARGE_TONES[charge.status]}>{charge.status_label}</Badge>
                                        {charge.failure_reason && charge.status !== 'approved' && (
                                            <span className="mt-0.5 block text-[0.75rem] text-[var(--muted)]">{charge.failure_reason}</span>
                                        )}
                                    </TD>
                                    <TD>
                                        <span className="font-mono text-[0.75rem] tabular">{charge.reference}</span>
                                    </TD>
                                    <TD align="right">
                                        {charge.status === 'approved' && (
                                            <a
                                                href={route('settings.membership.receipt', charge.id)}
                                                className="inline-flex items-center gap-1 text-[0.8125rem] font-medium text-[var(--accent)] hover:underline"
                                            >
                                                <DownloadSimpleIcon size={14} /> PDF
                                            </a>
                                        )}
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                )}
            </Card>

            {cardOpen && <CardDialog onClose={() => setCardOpen(false)} />}
            {payOpen && m.quote && <PayDialog membership={m} onClose={() => setPayOpen(false)} />}
            {planOpen && <PlanDialog membership={m} onClose={() => setPlanOpen(false)} />}
        </div>
    );
}

function Fact({ label, value, tone }: { label: string; value: string; tone?: 'warn' }) {
    return (
        <div>
            <dt className="text-[0.75rem] text-[var(--muted)]">{label}</dt>
            <dd className={cn('font-medium', tone === 'warn' && 'text-[var(--warn)]')}>{value}</dd>
        </div>
    );
}

interface Acceptance {
    public_key: string;
    api_base: string;
    acceptance_token: string;
    acceptance_permalink: string | null;
    personal_auth_token: string;
    personal_auth_permalink: string | null;
}

/**
 * La tarjeta se tokeniza AQUI, en el navegador, directo contra la pasarela con la llave publica.
 * A nuestro servidor solo viaja el token de un solo uso y la aceptacion: ni el numero ni el CVC.
 */
function CardDialog({ onClose }: { onClose: () => void }) {
    const [acceptance, setAcceptance] = useState<Acceptance | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);
    const [card, setCard] = useState({ number: '', month: '', year: '', cvc: '', holder: '' });
    const [email, setEmail] = useState('');
    const [accepted, setAccepted] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [serverErrors, setServerErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        fetch(route('settings.membership.acceptance'), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(async (response) => {
                const body = await response.json().catch(() => ({}));

                if (!response.ok) {
                    throw new Error(body.message ?? 'No pudimos abrir el formulario de la pasarela.');
                }

                setAcceptance(body as Acceptance);
            })
            .catch((reason: Error) => setLoadError(reason.message))
            .finally(() => setLoading(false));
    }, []);

    const digits = card.number.replace(/\D/g, '');

    const submit = async (event: FormEvent) => {
        event.preventDefault();

        if (!acceptance) {
            return;
        }

        setError(null);
        setServerErrors({});

        if (digits.length < 13 || digits.length > 19 || !/^\d{2}$/.test(card.month) || !/^\d{2}$/.test(card.year) || !/^\d{3,4}$/.test(card.cvc)) {
            setError('Revisa el número, la fecha (MM/AA) y el código de seguridad.');

            return;
        }

        setBusy(true);

        try {
            const response = await fetch(`${acceptance.api_base}/tokens/cards`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${acceptance.public_key}` },
                body: JSON.stringify({ number: digits, cvc: card.cvc, exp_month: card.month, exp_year: card.year, card_holder: card.holder.trim() }),
            });
            const body = await response.json().catch(() => ({}));
            const token: unknown = body?.data?.id;

            if (!response.ok || typeof token !== 'string') {
                setBusy(false);
                setError('La pasarela no aceptó la tarjeta. Revisa los datos.');

                return;
            }

            // Lo sensible se borra de la memoria de la pantalla apenas hay token.
            setCard({ number: '', month: '', year: '', cvc: '', holder: '' });

            router.put(
                route('settings.membership.card'),
                { token, acceptance_token: acceptance.acceptance_token, personal_auth_token: acceptance.personal_auth_token, accepted, email },
                {
                    preserveScroll: true,
                    onSuccess: () => onClose(),
                    onError: (errors) => setServerErrors(errors),
                    onFinish: () => setBusy(false),
                },
            );
        } catch {
            setBusy(false);
            setError('No hubo respuesta de la pasarela. Revisa la conexión e intenta de nuevo.');
        }
    };

    return (
        <Modal
            open
            onClose={() => !busy && onClose()}
            title="Tarjeta para la membresía"
            description="Los datos van directo a la pasarela de pagos; este sistema no los recibe."
            footer={
                <>
                    <Button variant="secondary" onClick={onClose} disabled={busy}>
                        Cancelar
                    </Button>
                    {acceptance && (
                        <Button type="submit" form="card-form" loading={busy} disabled={!accepted}>
                            Guardar tarjeta
                        </Button>
                    )}
                </>
            }
        >
            {loading && <p className="text-[0.875rem] text-[var(--muted)]">Conectando con la pasarela…</p>}
            {loadError && <p className="text-[0.875rem] font-medium text-[var(--danger)]">{loadError}</p>}

            {acceptance && (
                <form id="card-form" onSubmit={submit} className="flex flex-col gap-3" autoComplete="on">
                    <Input
                        label="Número de la tarjeta"
                        inputMode="numeric"
                        autoComplete="cc-number"
                        value={card.number}
                        onChange={(e) => setCard({ ...card, number: e.target.value.replace(/[^\d ]/g, '').slice(0, 23) })}
                        required
                    />
                    <div className="grid grid-cols-3 gap-3">
                        <Input label="Mes (MM)" inputMode="numeric" autoComplete="cc-exp-month" value={card.month} onChange={(e) => setCard({ ...card, month: e.target.value.replace(/\D/g, '').slice(0, 2) })} required />
                        <Input label="Año (AA)" inputMode="numeric" autoComplete="cc-exp-year" value={card.year} onChange={(e) => setCard({ ...card, year: e.target.value.replace(/\D/g, '').slice(0, 2) })} required />
                        <Input label="CVC" inputMode="numeric" autoComplete="cc-csc" type="password" value={card.cvc} onChange={(e) => setCard({ ...card, cvc: e.target.value.replace(/\D/g, '').slice(0, 4) })} required />
                    </div>
                    <Input label="Titular (como aparece en la tarjeta)" autoComplete="cc-name" value={card.holder} onChange={(e) => setCard({ ...card, holder: e.target.value.slice(0, 60) })} required />
                    <Input label="Correo del titular" type="email" autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} error={serverErrors.email} required />

                    <Checkbox
                        checked={accepted}
                        onChange={(e) => setAccepted(e.target.checked)}
                        label={
                            <span>
                                Acepto el{' '}
                                {acceptance.acceptance_permalink ? (
                                    <a href={acceptance.acceptance_permalink} target="_blank" rel="noreferrer" className="font-medium text-[var(--accent)] underline">reglamento de la pasarela</a>
                                ) : (
                                    'reglamento de la pasarela'
                                )}{' '}
                                y autorizo el{' '}
                                {acceptance.personal_auth_permalink ? (
                                    <a href={acceptance.personal_auth_permalink} target="_blank" rel="noreferrer" className="font-medium text-[var(--accent)] underline">tratamiento de mis datos</a>
                                ) : (
                                    'tratamiento de mis datos'
                                )}
                                .
                            </span>
                        }
                    />

                    {(error || serverErrors.token || serverErrors.accepted) && (
                        <p className="text-[0.8125rem] font-medium text-[var(--danger)]">{error ?? serverErrors.token ?? serverErrors.accepted}</p>
                    )}

                    <p className="flex items-center gap-1.5 text-[0.75rem] text-[var(--muted)]">
                        <LockSimpleIcon size={14} /> Solo se guardan la marca, los últimos cuatro números y el vencimiento.
                    </p>
                </form>
            )}
        </Modal>
    );
}

function PayDialog({ membership: m, onClose }: { membership: MembershipOverview; onClose: () => void }) {
    const hasCard = m.card?.usable ?? false;
    const form = useForm({ method: hasCard ? 'saved_card' : 'checkout' });

    const submit = () => {
        form.post(route('settings.membership.pay'), { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Modal
            open
            onClose={() => !form.processing && onClose()}
            title="Pagar la membresía"
            description={m.quote ? `${m.quote.concept} · del ${date(m.quote.starts_at)} al ${date(m.quote.ends_at)}` : undefined}
            size="sm"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose} disabled={form.processing}>
                        Cancelar
                    </Button>
                    <Button onClick={submit} loading={form.processing}>
                        {form.data.method === 'checkout' ? 'Ir a pagar' : `Pagar ${money(m.quote?.amount ?? 0)}`}
                    </Button>
                </>
            }
        >
            <p className="mb-3 font-mono text-[1.5rem] font-semibold text-[var(--accent)] tabular">{money(m.quote?.amount ?? 0)}</p>
            <div className="flex flex-col gap-2">
                {hasCard && (
                    <PayOption
                        selected={form.data.method === 'saved_card'}
                        onSelect={() => form.setData('method', 'saved_card')}
                        title="Con mi tarjeta guardada"
                        hint={`${m.card?.brand ?? 'Tarjeta'} •••• ${m.card?.last4 ?? ''} · se cobra en el acto`}
                    />
                )}
                <PayOption
                    selected={form.data.method === 'checkout'}
                    onSelect={() => form.setData('method', 'checkout')}
                    title="Con otro medio (PSE, Nequi, tarjeta)"
                    hint="Te llevamos a la página segura de la pasarela."
                />
            </div>
            {form.errors.method && <p className="mt-3 text-[0.8125rem] font-medium text-[var(--danger)]">{form.errors.method}</p>}
            {(form.errors as Record<string, string | undefined>).membership && (
                <p className="mt-3 text-[0.8125rem] font-medium text-[var(--danger)]">{(form.errors as Record<string, string | undefined>).membership}</p>
            )}
        </Modal>
    );
}

function PayOption({ selected, onSelect, title, hint }: { selected: boolean; onSelect: () => void; title: string; hint: string }) {
    return (
        <button
            type="button"
            onClick={onSelect}
            aria-pressed={selected}
            className={cn(
                'rounded-control border px-3 py-2.5 text-left transition-colors',
                selected ? 'border-[var(--accent)] bg-[var(--accent-soft)]' : 'border-[var(--line)] bg-[var(--surface-2)] hover:border-[var(--line-2)]',
            )}
        >
            <span className={cn('block text-[0.875rem] font-semibold', selected && 'text-[var(--accent)]')}>{title}</span>
            <span className="text-[0.78125rem] text-[var(--muted)]">{hint}</span>
        </button>
    );
}

function PlanDialog({ membership: m, onClose }: { membership: MembershipOverview; onClose: () => void }) {
    const form = useForm({
        membership_plan_id: String(m.plan?.id ?? m.plans[0]?.id ?? ''),
        billing_cycle_id: String(m.cycle?.id ?? m.cycles[0]?.id ?? ''),
    });
    const plan = m.plans.find((item) => String(item.id) === form.data.membership_plan_id);
    const price = plan ? plan.prices[form.data.billing_cycle_id] : undefined;

    const submit = () => {
        form.put(route('settings.membership.plan'), { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Modal
            open
            onClose={() => !form.processing && onClose()}
            title="Cambiar plan o ciclo"
            description="El cambio se aplica en la próxima renovación; hasta entonces sigues con lo que tienes."
            footer={
                <>
                    <Button variant="secondary" onClick={onClose} disabled={form.processing}>
                        Cancelar
                    </Button>
                    <Button onClick={submit} loading={form.processing}>
                        Programar el cambio
                    </Button>
                </>
            }
        >
            <div className="grid gap-3 sm:grid-cols-2">
                <Select
                    label="Plan"
                    value={form.data.membership_plan_id}
                    onChange={(e) => form.setData('membership_plan_id', e.target.value)}
                    options={m.plans.map((item) => ({ value: String(item.id), label: item.name }))}
                    error={form.errors.membership_plan_id}
                />
                <Select
                    label="Ciclo"
                    value={form.data.billing_cycle_id}
                    onChange={(e) => form.setData('billing_cycle_id', e.target.value)}
                    options={m.cycles.map((cycle) => ({ value: String(cycle.id), label: cycle.discount_percent > 0 ? `${cycle.name} (−${cycle.discount_percent} %)` : cycle.name }))}
                    error={form.errors.billing_cycle_id}
                />
            </div>

            {plan && (
                <div className="mt-4 rounded-control border border-[var(--line)] bg-[var(--surface-2)] p-3 text-[0.8125rem]">
                    <p className="font-mono text-[1.125rem] font-semibold text-[var(--accent)] tabular">{price !== undefined ? money(price) : '—'}</p>
                    <p className="mt-1 text-[var(--muted)]">
                        Hasta {plan.limits.stores ?? 'ilimitadas'} sedes · {plan.limits.users ?? 'ilimitados'} usuarios · {plan.limits.registers ?? 'ilimitadas'} cajas
                    </p>
                    {plan.modules.length > 0 && <p className="mt-1">{plan.modules.map((module) => module.display).join(' · ')}</p>}
                </div>
            )}

            {plan && plan.id !== m.plan?.id && <PlanDiffSummary diff={plan.diff} />}
        </Modal>
    );
}

/** Que gana y que pierde la empresa con el plan elegido, comparando los dos arboles. */
function PlanDiffSummary({ diff }: { diff: PlanDiff }) {
    if (diff.gained === 0 && diff.lost === 0) {
        return <p className="mt-3 text-[0.8125rem] text-[var(--muted)]">Incluye exactamente lo mismo que tu plan actual.</p>;
    }

    return (
        <div className="mt-3 grid gap-3 sm:grid-cols-2">
            {diff.gained > 0 && (
                <div className="rounded-control border border-[var(--line)] p-3 text-[0.8125rem]">
                    <p className="mb-1 font-semibold text-[var(--accent)]">Ganas {diff.gained}</p>
                    <ul className="flex max-h-[11rem] flex-col gap-1 overflow-y-auto">
                        {diff.gains.map((group) => (
                            <li key={group.module}>
                                <span className="font-medium">{group.module}</span>
                                <span className="block text-[var(--muted)]">{group.items.join(' · ')}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {diff.lost > 0 && (
                <div className="rounded-control border border-[var(--danger)]/40 bg-[var(--danger-soft)] p-3 text-[0.8125rem]">
                    <p className="mb-1 font-semibold text-[var(--danger)]">Pierdes {diff.lost}</p>
                    <ul className="flex max-h-[11rem] flex-col gap-1 overflow-y-auto">
                        {diff.losses.map((group) => (
                            <li key={group.module}>
                                <span className="font-medium">{group.module}</span>
                                <span className="block text-[var(--muted)]">{group.items.join(' · ')}</span>
                            </li>
                        ))}
                    </ul>
                    <p className="mt-2 text-[0.75rem] text-[var(--muted)]">Los datos se conservan; lo que se corta es el acceso.</p>
                </div>
            )}
        </div>
    );
}

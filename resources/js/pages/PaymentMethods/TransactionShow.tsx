import { Link, useForm } from '@inertiajs/react';
import { ArrowLeftIcon } from '@phosphor-icons/react';
import { type FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Input, Select, Textarea } from '@/components/ui/Field';
import { Badge, PageHeader } from '@/components/ui/Misc';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import { dateTime, money, number } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import PaymentTabs from '@/pages/PaymentMethods/components/PaymentTabs';
import { STATUS_TONE, type TransactionRow } from '@/pages/PaymentMethods/Transactions';

interface Detail extends TransactionRow {
    status_detail: string | null;
    gateway_transaction_id: string | null;
    checkout_url: string | null;
    terminal: string | null;
    expires_at: string | null;
    cancelled_at: string | null;
    resolution_note: string | null;
    resolved_at: string | null;
    refunded_amount: number | null;
    payload: Record<string, unknown>;
    items: { description: string; quantity: number }[];
}

interface Props {
    transaction: Detail;
    reconcilable: boolean;
    canRefundThroughGateway: boolean;
    sessions: { value: number; label: string }[];
    orders: { value: number; label: string }[];
}

type Action = 'apply_sale' | 'apply_order' | 'store_credit' | 'refunded_outside' | 'refund_gateway';

/**
 * El detalle de un cobro por pasarela. Si quedó aprobado sin venta ni pedido, aquí se concilia:
 * se registra la venta en una caja abierta, se aplica a un pedido, se abona como saldo a favor
 * o se deja constancia de que se devolvió. Conciliar documenta una decisión; nunca cobra de nuevo.
 */
export default function TransactionShow({ transaction, reconcilable, canRefundThroughGateway, sessions, orders }: Props) {
    const { can } = usePermission();
    const form = useForm({
        action: '' as Action | '',
        register_session_id: (sessions[0]?.value ?? '') as number | '',
        order_id: (orders[0]?.value ?? '') as number | '',
        customer_document: '',
        note: '',
    });

    const actions: { value: Action; label: string; hint: string; enabled: boolean }[] = [
        { value: 'apply_sale', label: 'Registrar la venta', hint: 'Crea la venta del tiquete en una caja abierta de la sede.', enabled: transaction.channel === 'pos' && sessions.length > 0 },
        { value: 'apply_order', label: 'Aplicar a un pedido', hint: 'El pedido queda pagado con este cobro.', enabled: orders.length > 0 },
        { value: 'store_credit', label: 'Abonar como saldo a favor', hint: 'El cliente lo gasta en su próxima compra.', enabled: true },
        { value: 'refund_gateway', label: 'Reembolsar por la pasarela', hint: 'Le pide a la pasarela que le devuelva la plata al cliente.', enabled: canRefundThroughGateway },
        { value: 'refunded_outside', label: 'Ya se devolvió por fuera', hint: 'Solo deja constancia: la devolución se hizo en el panel de la pasarela o en efectivo.', enabled: true },
    ];

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('payment_methods.transactions.reconcile', transaction.id), { preserveScroll: true });
    };

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <AppLayout title={`Transacción ${transaction.reference}`}>
            <PageHeader
                title={transaction.reference}
                description={`${transaction.method} · ${transaction.provider} · ${transaction.channel_label}`}
                actions={
                    <ButtonLink href={route('payment_methods.transactions.index')} variant="secondary" icon={<ArrowLeftIcon size={16} />}>
                        Volver
                    </ButtonLink>
                }
            />

            <PaymentTabs active="transactions" />

            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_24rem]">
                <div className="flex flex-col gap-5">
                    <Card>
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-mono text-[1.75rem] font-semibold tabular">{money(transaction.amount)}</span>
                            <Badge tone={STATUS_TONE[transaction.status] ?? 'neutral'}>{transaction.status_label}</Badge>
                            {transaction.to_reconcile && <Badge tone="warn">Por conciliar</Badge>}
                            {transaction.resolution_label && <Badge>{transaction.resolution_label}</Badge>}
                            {transaction.environment === 'sandbox' && <Badge tone="info">Pruebas</Badge>}
                        </div>
                        {transaction.status_detail && <p className="mt-2 text-[0.8125rem] text-[var(--muted)]">{transaction.status_detail}</p>}

                        <dl className="mt-4 grid gap-2 text-[0.8125rem] sm:grid-cols-2">
                            <Row label="Creada" value={dateTime(transaction.created_at)} />
                            <Row label="Aprobada" value={transaction.approved_at ? dateTime(transaction.approved_at) : '—'} />
                            <Row label="Aplicada" value={transaction.applied_at ? dateTime(transaction.applied_at) : '—'} />
                            <Row label="Vence" value={transaction.expires_at ? dateTime(transaction.expires_at) : '—'} />
                            <Row label="Id en la pasarela" value={transaction.gateway_transaction_id ?? '—'} mono />
                            <Row label="Sede" value={transaction.store ?? '—'} />
                            {transaction.terminal && <Row label="Datáfono" value={transaction.terminal} />}
                            {transaction.cancelled_at && <Row label="Cancelada en la caja" value={dateTime(transaction.cancelled_at)} />}
                            {transaction.refunded_amount ? <Row label="Reembolsado" value={money(transaction.refunded_amount)} /> : null}
                            <div className="flex justify-between gap-3">
                                <dt className="text-[var(--muted)]">Quedó en</dt>
                                <dd>
                                    {transaction.payable ? (
                                        <Link href={transaction.payable.url} className="font-medium text-[var(--accent)] hover:underline">
                                            {transaction.payable.label}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </dd>
                            </div>
                        </dl>

                        {transaction.resolution_note && (
                            <p className="mt-4 rounded-control bg-[var(--surface-2)] px-3 py-2 text-[0.8125rem]">
                                <span className="font-medium">Conciliación:</span> {transaction.resolution_note}
                                {transaction.resolved_at && <span className="text-[var(--muted)]"> · {dateTime(transaction.resolved_at)}</span>}
                            </p>
                        )}
                    </Card>

                    {transaction.items.length > 0 && (
                        <Card>
                            <CardHeader title="Lo que se iba a cobrar" description="El tiquete que tenía la caja cuando abrió el cobro." />
                            <ul className="flex flex-col gap-1 text-[0.8125rem]">
                                {transaction.items.map((item, index) => (
                                    <li key={index} className="flex justify-between gap-3">
                                        <span>{item.description}</span>
                                        <span className="font-mono tabular">{number(item.quantity)}</span>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}

                    <Card>
                        <CardHeader title="Respuesta de la pasarela" description="Saneada al guardarla: sin datos de tarjeta ni llaves." />
                        <pre className="max-h-80 overflow-auto rounded-control bg-[var(--surface-2)] p-3 font-mono text-[0.75rem]">{JSON.stringify(transaction.payload, null, 2)}</pre>
                    </Card>
                </div>

                {reconcilable && can('payment_methods.transactions.reconcile') && (
                    <Card>
                        <CardHeader title="Conciliar" description="La pasarela aprobó este pago y no quedó en ninguna venta ni pedido. Decide qué se hace con la plata." />
                        <form onSubmit={submit} className="flex flex-col gap-3">
                            <div className="flex flex-col gap-1.5" role="radiogroup" aria-label="Qué hacer">
                                {actions
                                    .filter((action) => action.enabled)
                                    .map((action) => (
                                        <button
                                            key={action.value}
                                            type="button"
                                            role="radio"
                                            aria-checked={form.data.action === action.value}
                                            onClick={() => form.setData('action', action.value)}
                                            className={cn(
                                                'rounded-control border px-3 py-2 text-left transition-colors',
                                                form.data.action === action.value ? 'border-[var(--accent)] bg-[var(--accent-soft)]' : 'border-[var(--line)] bg-[var(--surface-2)]',
                                            )}
                                        >
                                            <span className="block text-[0.8125rem] font-semibold">{action.label}</span>
                                            <span className="block text-[0.75rem] text-[var(--muted)]">{action.hint}</span>
                                        </button>
                                    ))}
                            </div>
                            {errors.action && <p className="text-[0.75rem] text-[var(--danger)]">{errors.action}</p>}

                            {form.data.action === 'apply_sale' && (
                                <Select
                                    label="Caja"
                                    required
                                    options={sessions}
                                    value={form.data.register_session_id}
                                    onChange={(event) => form.setData('register_session_id', Number(event.target.value))}
                                    error={errors.register_session_id}
                                />
                            )}
                            {form.data.action === 'apply_order' && (
                                <Select
                                    label="Pedido"
                                    required
                                    options={orders}
                                    value={form.data.order_id}
                                    onChange={(event) => form.setData('order_id', Number(event.target.value))}
                                    error={errors.order_id}
                                    hint="Solo los pedidos sin pagar que valen lo mismo."
                                />
                            )}
                            {form.data.action === 'store_credit' && (
                                <Input
                                    label="Documento del cliente"
                                    required
                                    maxLength={30}
                                    value={form.data.customer_document}
                                    onChange={(event) => form.setData('customer_document', event.target.value)}
                                    error={errors.customer_document}
                                />
                            )}

                            <Textarea
                                label="Nota"
                                required
                                rows={3}
                                maxLength={200}
                                value={form.data.note}
                                onChange={(event) => form.setData('note', event.target.value)}
                                error={errors.note ?? errors.payment}
                                hint="Queda en la transacción: quién lo pidió, cómo se confirmó."
                            />

                            <Button type="submit" loading={form.processing} disabled={form.data.action === ''}>
                                Conciliar
                            </Button>
                        </form>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}

function Row({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
    return (
        <div className="flex justify-between gap-3">
            <dt className="text-[var(--muted)]">{label}</dt>
            <dd className={cn('truncate', mono && 'font-mono text-[0.75rem]')}>{value}</dd>
        </div>
    );
}

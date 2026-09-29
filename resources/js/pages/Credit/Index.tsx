import { router, useForm } from '@inertiajs/react';
import { CreditCardIcon, DownloadSimpleIcon, LockKeyIcon, LockKeyOpenIcon, PlusIcon, SlidersIcon } from '@phosphor-icons/react';
import { type FormEvent, useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Card, CardHeader, StatCard } from '@/components/ui/Card';
import { Checkbox, Input, Select, Textarea } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { Badge, PageHeader, SearchInput } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { date, dateTime, money, number } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { Paginated } from '@/types';

interface CreditRow {
    id: number;
    name: string;
    document_number: string;
    phone: string | null;
    credit_limit: number;
    credit_term_days: number;
    credit_blocked: boolean;
    blocked_reason: string | null;
    balance: number;
    overdue_balance: number;
    available: number;
    oldest_due_date: string | null;
    days_overdue: number;
}

interface Selected {
    id: number;
    name: string;
    document_number: string;
    phone: string | null;
    credit_limit: number;
    credit_term_days: number;
    payment_term_id: number | null;
    credit_blocked: boolean;
    blocked_reason: string | null;
    balance: number;
    overdue_balance: number;
    available: number;
    pending_sales: {
        id: number;
        number: string;
        total: number;
        due_total: number;
        due_date: string | null;
        sold_at: string;
        is_overdue: boolean;
    }[];
    payments: { id: number; amount: number; method: string; note: string | null; received_by: string | null; paid_at: string }[];
}

interface CreditIndexProps {
    customers: Paginated<CreditRow>;
    filters: { search: string; state: string };
    metrics: { total: number; overdue: number; customers_with_debt: number; payments_today: number };
    selected: Selected | null;
    paymentTerms: { value: number; label: string; days: number }[];
    defaultPaymentTermId: number | null;
}

const METHOD_LABELS: Record<string, string> = {
    cash: 'Efectivo',
    card: 'Tarjeta',
    transfer: 'Transferencia',
};

export default function CreditIndex({ customers, filters: initialFilters, metrics, selected, paymentTerms, defaultPaymentTermId }: CreditIndexProps) {
    const { can } = usePermission();
    const { filters, update, reset, hasActive } = useFilters('credit.index', initialFilters);
    const [modal, setModal] = useState<'payment' | 'limit' | 'block' | null>(null);

    const paymentForm = useForm({ amount: 0, method: 'cash', note: '' });
    const limitForm = useForm({ credit_limit: 0, payment_term_id: '' as string, credit_enabled: true as boolean });
    const blockForm = useForm({ reason: '' });

    const select = (customerId: number) => {
        router.get(route('credit.index'), { ...filters, customer: customerId }, { preserveState: true, preserveScroll: true, replace: true });
    };

    const openPayment = () => {
        paymentForm.setData({ amount: selected?.balance ?? 0, method: 'cash', note: '' });
        paymentForm.clearErrors();
        setModal('payment');
    };

    const openLimit = () => {
        limitForm.setData({
            credit_limit: selected?.credit_limit ?? 0,
            payment_term_id: String(selected?.payment_term_id ?? defaultPaymentTermId ?? ''),
            credit_enabled: true,
        });
        limitForm.clearErrors();
        setModal('limit');
    };

    const submitPayment = (event: FormEvent) => {
        event.preventDefault();

        if (!selected) {
            return;
        }

        paymentForm.post(route('credit.payments.store', selected.id), {
            preserveScroll: true,
            onSuccess: () => setModal(null),
        });
    };

    const submitLimit = (event: FormEvent) => {
        event.preventDefault();

        if (!selected) {
            return;
        }

        limitForm.post(route('credit.limit', selected.id), { preserveScroll: true, onSuccess: () => setModal(null) });
    };

    const submitBlock = (event: FormEvent) => {
        event.preventDefault();

        if (!selected) {
            return;
        }

        blockForm.post(route('credit.block', selected.id), {
            preserveScroll: true,
            onSuccess: () => {
                blockForm.reset();
                setModal(null);
            },
        });
    };

    return (
        <AppLayout title="Cartera">
            <PageHeader
                title="Cartera"
                description="Quien debe, cuanto y desde cuando."
                actions={
                    can('credit.index.export') ? (
                        <a
                            href={route('credit.export')}
                            className="inline-flex h-10 items-center gap-2 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-4 text-sm font-medium text-[var(--text)] hover:bg-[var(--surface-3)]"
                        >
                            <DownloadSimpleIcon size={16} />
                            Exportar
                        </a>
                    ) : undefined
                }
            />

            <div className="mb-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Cartera total" value={money(metrics.total)} icon={<CreditCardIcon size={16} weight="bold" />} />
                <StatCard label="En mora" value={money(metrics.overdue)} tone={metrics.overdue > 0 ? 'danger' : 'neutral'} />
                <StatCard label="Clientes con deuda" value={number(metrics.customers_with_debt)} tone="neutral" />
                <StatCard label="Abonos de hoy" value={money(metrics.payments_today)} />
            </div>

            <div className="grid gap-4 xl:grid-cols-[1fr_400px]">
                <div>
                    <Card className="mb-4">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <SearchInput
                                placeholder="Nombre o documento"
                                value={filters.search}
                                onChange={(event) => update('search', event.target.value)}
                            />
                            <div className="flex items-start gap-2">
                                <div className="flex-1">
                                    <Select
                                        options={[
                                            { value: 'overdue', label: 'En mora' },
                                            { value: 'current', label: 'Al dia con saldo' },
                                            { value: 'blocked', label: 'Bloqueados' },
                                        ]}
                                        placeholder="Toda la cartera"
                                        value={filters.state}
                                        onChange={(event) => update('state', event.target.value)}
                                    />
                                </div>
                                {hasActive && (
                                    <Button variant="ghost" onClick={reset}>
                                        Limpiar
                                    </Button>
                                )}
                            </div>
                        </div>
                    </Card>

                    {customers.data.length === 0 ? (
                        <EmptyState title="Ningun cliente coincide" description="Cambia los filtros para ver otra parte de la cartera." />
                    ) : (
                        <>
                            <Table>
                                <THead>
                                    <TR>
                                        <TH>Cliente</TH>
                                        <TH align="right">Cupo</TH>
                                        <TH align="right">Saldo</TH>
                                        <TH align="right">Disponible</TH>
                                        <TH align="center">Mora</TH>
                                        <TH align="center">Estado</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {customers.data.map((row) => (
                                        <TR key={row.id} onClick={() => select(row.id)} className={selected?.id === row.id ? 'bg-[var(--accent-soft)]/40' : ''}>
                                            <TD>
                                                <p className="text-[0.84375rem] font-medium">{row.name}</p>
                                                <p className="font-mono text-[0.71875rem] text-[var(--muted)] tabular">{row.document_number}</p>
                                            </TD>
                                            <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                                {money(row.credit_limit)}
                                            </TD>
                                            <TD align="right" className="font-mono text-[0.8125rem] font-semibold tabular">
                                                {money(row.balance)}
                                            </TD>
                                            <TD align="right" className="font-mono text-[0.8125rem] text-[var(--accent)] tabular">
                                                {money(row.available)}
                                            </TD>
                                            <TD align="center">
                                                {row.days_overdue > 0 ? (
                                                    <Badge tone="danger">{row.days_overdue} dias</Badge>
                                                ) : (
                                                    <span className="text-[var(--muted)]">—</span>
                                                )}
                                            </TD>
                                            <TD align="center">
                                                {row.credit_blocked ? (
                                                    <Badge tone="danger">Bloqueado</Badge>
                                                ) : row.overdue_balance > 0 ? (
                                                    <Badge tone="warn">En mora</Badge>
                                                ) : (
                                                    <Badge tone="accent">Al dia</Badge>
                                                )}
                                            </TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>

                            <Pagination meta={customers} />
                        </>
                    )}
                </div>

                <aside>
                    {selected ? (
                        <div className="sticky top-20 flex flex-col gap-4">
                            <Card>
                                <CardHeader title={selected.name} description={selected.document_number} />

                                <dl className="grid grid-cols-2 gap-3">
                                    <Box label="Cupo" value={money(selected.credit_limit)} />
                                    <Box label="Saldo" value={money(selected.balance)} tone={selected.balance > 0 ? 'warn' : undefined} />
                                    <Box label="Disponible" value={money(selected.available)} tone="accent" />
                                    <Box label="En mora" value={money(selected.overdue_balance)} tone={selected.overdue_balance > 0 ? 'danger' : undefined} />
                                </dl>

                                {selected.credit_blocked && selected.blocked_reason && (
                                    <p className="mt-3 rounded-control bg-[var(--danger-soft)] px-3 py-2 text-[0.78125rem] text-[var(--danger)]">
                                        {selected.blocked_reason}
                                    </p>
                                )}

                                <div className="mt-4 flex flex-wrap gap-2 border-t border-[var(--line)] pt-4">
                                    {can('credit.payments.create') && selected.balance > 0 && (
                                        <Button size="sm" icon={<PlusIcon size={15} weight="bold" />} onClick={openPayment}>
                                            Registrar abono
                                        </Button>
                                    )}
                                    {can('credit.index.set_limit') && (
                                        <Button size="sm" variant="secondary" icon={<SlidersIcon size={15} />} onClick={openLimit}>
                                            Cambiar cupo
                                        </Button>
                                    )}
                                    {can('credit.index.block') && (
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            icon={selected.credit_blocked ? <LockKeyOpenIcon size={15} /> : <LockKeyIcon size={15} />}
                                            onClick={() => (selected.credit_blocked ? submitBlock({ preventDefault: () => {} } as FormEvent) : setModal('block'))}
                                        >
                                            {selected.credit_blocked ? 'Desbloquear' : 'Bloquear'}
                                        </Button>
                                    )}
                                </div>
                            </Card>

                            <Card>
                                <CardHeader title="Facturas pendientes" description="Los abonos se aplican de la mas antigua a la mas nueva." />
                                {selected.pending_sales.length === 0 ? (
                                    <p className="text-[0.8125rem] text-[var(--muted)]">Este cliente esta al dia.</p>
                                ) : (
                                    <ul className="flex flex-col gap-2">
                                        {selected.pending_sales.map((sale) => (
                                            <li key={sale.id} className="flex items-center justify-between gap-3 rounded-control border border-[var(--line)] px-3 py-2">
                                                <span className="min-w-0">
                                                    <span className="block font-mono text-[0.8125rem] font-medium">{sale.number}</span>
                                                    <span className="block text-[0.71875rem] text-[var(--muted)]">
                                                        vence {sale.due_date ? date(sale.due_date) : '—'}
                                                    </span>
                                                </span>
                                                <span className="text-right">
                                                    <span
                                                        className="block font-mono text-[0.84375rem] font-semibold tabular"
                                                        style={{ color: sale.is_overdue ? 'var(--danger)' : undefined }}
                                                    >
                                                        {money(sale.due_total)}
                                                    </span>
                                                    <span className="block font-mono text-[0.6875rem] text-[var(--muted)] tabular">de {money(sale.total)}</span>
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Card>

                            {can('credit.payments.view') && (
                                <Card>
                                    <CardHeader title="Abonos recientes" />
                                    {selected.payments.length === 0 ? (
                                        <p className="text-[0.8125rem] text-[var(--muted)]">Sin abonos registrados.</p>
                                    ) : (
                                        <ul className="flex flex-col gap-2">
                                            {selected.payments.map((payment) => (
                                                <li key={payment.id} className="flex items-center justify-between gap-3">
                                                    <span className="min-w-0">
                                                        <span className="block text-[0.78125rem]">{dateTime(payment.paid_at)}</span>
                                                        <span className="block text-[0.71875rem] text-[var(--muted)]">
                                                            {METHOD_LABELS[payment.method] ?? payment.method}
                                                            {payment.received_by ? ` · ${payment.received_by}` : ''}
                                                        </span>
                                                    </span>
                                                    <span className="font-mono text-[0.84375rem] font-semibold text-[var(--accent)] tabular">
                                                        {money(payment.amount)}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </Card>
                            )}
                        </div>
                    ) : (
                        <Card>
                            <EmptyState
                                title="Elige un cliente"
                                description="Toca una fila para ver su estado de cuenta y registrar abonos."
                                icon={<CreditCardIcon size={24} />}
                            />
                        </Card>
                    )}
                </aside>
            </div>

            <Modal
                open={modal === 'payment'}
                onClose={() => setModal(null)}
                title="Registrar abono"
                description={selected ? `${selected.name} · debe ${money(selected.balance)}` : undefined}
                size="sm"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setModal(null)}>
                            Cancelar
                        </Button>
                        <Button onClick={submitPayment} loading={paymentForm.processing}>
                            Registrar abono
                        </Button>
                    </>
                }
            >
                <form onSubmit={submitPayment} className="flex flex-col gap-4">
                    <Input
                        label="Valor del abono"
                        type="number"
                        min={0}
                        required
                        autoFocus
                        value={paymentForm.data.amount}
                        onChange={(event) => paymentForm.setData('amount', Number(event.target.value))}
                        error={paymentForm.errors.amount}
                        prefix="$"
                        big
                    />
                    <Select
                        label="Medio de pago"
                        options={[
                            { value: 'cash', label: 'Efectivo' },
                            { value: 'card', label: 'Tarjeta' },
                            { value: 'transfer', label: 'Transferencia' },
                        ]}
                        value={paymentForm.data.method}
                        onChange={(event) => paymentForm.setData('method', event.target.value)}
                        error={paymentForm.errors.method}
                    />
                    <Textarea
                        label="Nota"
                        value={paymentForm.data.note}
                        onChange={(event) => paymentForm.setData('note', event.target.value)}
                        error={paymentForm.errors.note}
                    />
                    {selected && (
                        <p className="rounded-control bg-[var(--surface-2)] px-3 py-2 text-[0.78125rem] text-[var(--muted)]">
                            Saldo tras el abono:{' '}
                            <span className="font-mono tabular">{money(Math.max(0, selected.balance - paymentForm.data.amount))}</span> · cupo
                            liberado <span className="font-mono tabular">{money(paymentForm.data.amount)}</span>
                        </p>
                    )}
                </form>
            </Modal>

            <Modal
                open={modal === 'limit'}
                onClose={() => setModal(null)}
                title="Cambiar el cupo"
                description={selected?.name}
                size="sm"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setModal(null)}>
                            Cancelar
                        </Button>
                        <Button onClick={submitLimit} loading={limitForm.processing}>
                            Guardar
                        </Button>
                    </>
                }
            >
                <form onSubmit={submitLimit} className="flex flex-col gap-4">
                    <Input
                        label="Cupo"
                        type="number"
                        min={0}
                        required
                        value={limitForm.data.credit_limit}
                        onChange={(event) => limitForm.setData('credit_limit', Number(event.target.value))}
                        error={limitForm.errors.credit_limit}
                        prefix="$"
                    />
                    <Select
                        label="Plazo de pago"
                        hint="Los plazos se administran en Empresa > Plazos de pago."
                        required
                        value={limitForm.data.payment_term_id}
                        onChange={(event) => limitForm.setData('payment_term_id', event.target.value)}
                        options={paymentTerms.map((term) => ({ value: String(term.value), label: `${term.label} (${term.days} días)` }))}
                        placeholder="Elige el plazo"
                        error={limitForm.errors.payment_term_id}
                    />
                    <Checkbox
                        label="Credito habilitado"
                        checked={limitForm.data.credit_enabled}
                        onChange={(event) => limitForm.setData('credit_enabled', event.target.checked)}
                    />
                </form>
            </Modal>

            <Modal
                open={modal === 'block'}
                onClose={() => setModal(null)}
                title="Bloquear el credito"
                description={selected?.name}
                size="sm"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setModal(null)}>
                            Cancelar
                        </Button>
                        <Button variant="danger" onClick={submitBlock} loading={blockForm.processing}>
                            Bloquear
                        </Button>
                    </>
                }
            >
                <form onSubmit={submitBlock}>
                    <Input
                        label="Motivo"
                        autoFocus
                        value={blockForm.data.reason}
                        onChange={(event) => blockForm.setData('reason', event.target.value)}
                        error={blockForm.errors.reason}
                        hint="Se muestra en el punto de venta cuando se intente cobrar a credito."
                    />
                </form>
            </Modal>
        </AppLayout>
    );
}

function Box({ label, value, tone }: { label: string; value: string; tone?: 'accent' | 'warn' | 'danger' }) {
    const colors = { accent: 'var(--accent)', warn: 'var(--warn)', danger: 'var(--danger)' };

    return (
        <div className="rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 py-2">
            <dt className="text-[0.71875rem] uppercase tracking-wide text-[var(--muted)]">{label}</dt>
            <dd className="font-mono text-[1rem] font-semibold tabular" style={{ color: tone ? colors[tone] : undefined }}>
                {value}
            </dd>
        </div>
    );
}

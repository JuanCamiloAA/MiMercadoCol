import { Link, router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input, Select } from '@/components/ui/Field';
import { Badge, type BadgeTone, PageHeader } from '@/components/ui/Misc';
import { EmptyState, Pagination, TBody, TD, TH, THead, TR, Table } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { dateTime, money } from '@/lib/format';
import PaymentTabs from '@/pages/PaymentMethods/components/PaymentTabs';
import type { Paginated } from '@/types';

export interface TransactionRow {
    id: number;
    reference: string;
    provider: string;
    environment: string;
    method: string;
    store: string | null;
    amount: number;
    currency: string;
    channel: string;
    channel_label: string;
    mode: string;
    status: string;
    status_label: string;
    to_reconcile: boolean;
    resolution: string | null;
    resolution_label: string | null;
    payable: { label: string; url: string } | null;
    created_at: string | null;
    approved_at: string | null;
    applied_at: string | null;
}

interface Filters {
    estado: string;
    medio: string | number;
    canal: string;
    desde: string;
    hasta: string;
    q: string;
}

interface Props {
    transactions: Paginated<TransactionRow>;
    filters: Filters;
    toReconcile: number;
    methods: { value: number; label: string }[];
    statusOptions: { value: string; label: string }[];
}

export const STATUS_TONE: Record<string, BadgeTone> = {
    pending: 'info',
    approved: 'accent',
    declined: 'danger',
    voided: 'neutral',
    expired: 'neutral',
    error: 'danger',
};

/**
 * Todo lo que pasó por una pasarela. «Por conciliar» son los pagos aprobados que no quedaron en
 * ninguna venta ni pedido: plata que entró y hay que decidir qué hacer con ella.
 */
export default function PaymentTransactions({ transactions, filters, toReconcile, methods, statusOptions }: Props) {
    const [form, setForm] = useState<Filters>(filters);

    const apply = (next: Partial<Filters>) => {
        const merged = { ...form, ...next };
        setForm(merged);
        router.get(route('payment_methods.transactions.index'), Object.fromEntries(Object.entries(merged).filter(([, value]) => value !== '' && value !== null)), { preserveState: true, preserveScroll: true });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        apply({});
    };

    return (
        <AppLayout title="Transacciones de pasarela">
            <PageHeader title="Medios de pago" description="Los cobros que pasaron por una pasarela, en la caja y en la tienda." />

            <PaymentTabs active="transactions" counts={{ transactions: toReconcile }} />

            {toReconcile > 0 && (
                <button
                    type="button"
                    onClick={() => apply({ estado: 'conciliar' })}
                    className="mb-4 flex w-full items-center justify-between gap-3 rounded-card border border-[var(--warn)]/40 bg-[var(--warn-soft)] px-4 py-3 text-left"
                >
                    <span>
                        <span className="block text-[0.875rem] font-semibold text-[var(--warn)]">
                            {toReconcile} pago{toReconcile === 1 ? '' : 's'} por conciliar
                        </span>
                        <span className="block text-[0.75rem] text-[var(--muted)]">La pasarela los aprobó pero no quedaron en ninguna venta ni pedido.</span>
                    </span>
                    <span className="text-[0.8125rem] font-medium text-[var(--warn)]">Ver</span>
                </button>
            )}

            <Card className="mb-4">
                <form onSubmit={submit} className="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
                    <Select
                        label="Estado"
                        options={[{ value: 'conciliar', label: 'Por conciliar' }, ...statusOptions]}
                        placeholder="Todos"
                        value={form.estado}
                        onChange={(event) => apply({ estado: event.target.value })}
                    />
                    <Select label="Medio" options={methods} placeholder="Todos" value={form.medio} onChange={(event) => apply({ medio: event.target.value })} />
                    <Select
                        label="Canal"
                        options={[
                            { value: 'pos', label: 'Caja' },
                            { value: 'online', label: 'Tienda en línea' },
                        ]}
                        placeholder="Los dos"
                        value={form.canal}
                        onChange={(event) => apply({ canal: event.target.value })}
                    />
                    <Input label="Desde" type="date" value={form.desde} onChange={(event) => apply({ desde: event.target.value })} />
                    <Input label="Hasta" type="date" value={form.hasta} onChange={(event) => apply({ hasta: event.target.value })} />
                    <Input label="Referencia" value={form.q} onChange={(event) => setForm({ ...form, q: event.target.value })} placeholder="PAY-… o id de la pasarela" />
                    <div className="md:col-span-3 xl:col-span-6">
                        <Button type="submit" variant="secondary" size="sm">
                            Buscar
                        </Button>
                    </div>
                </form>
            </Card>

            <Card padded={false}>
                {transactions.data.length === 0 ? (
                    <EmptyState title="No hay transacciones" description="Con estos filtros no aparece ningún cobro por pasarela." />
                ) : (
                    <Table>
                        <THead>
                            <TH>Referencia</TH>
                            <TH>Medio</TH>
                            <TH>Canal</TH>
                            <TH>Estado</TH>
                            <TH>Quedó en</TH>
                            <TH align="right">Valor</TH>
                        </THead>
                        <TBody>
                            {transactions.data.map((transaction) => (
                                <TR key={transaction.id} onClick={() => router.visit(route('payment_methods.transactions.show', transaction.id))} className="cursor-pointer">
                                    <TD>
                                        <Link href={route('payment_methods.transactions.show', transaction.id)} className="font-mono text-[0.8125rem] font-medium hover:underline">
                                            {transaction.reference}
                                        </Link>
                                        <span className="block text-[0.75rem] text-[var(--muted)]">{dateTime(transaction.created_at)}</span>
                                    </TD>
                                    <TD>
                                        {transaction.method}
                                        <span className="block text-[0.75rem] text-[var(--muted)]">
                                            {transaction.provider}
                                            {transaction.environment === 'sandbox' ? ' · pruebas' : ''}
                                        </span>
                                    </TD>
                                    <TD>
                                        {transaction.channel_label}
                                        {transaction.store && <span className="block text-[0.75rem] text-[var(--muted)]">{transaction.store}</span>}
                                    </TD>
                                    <TD>
                                        <div className="flex flex-wrap gap-1">
                                            <Badge tone={STATUS_TONE[transaction.status] ?? 'neutral'}>{transaction.status_label}</Badge>
                                            {transaction.to_reconcile && <Badge tone="warn">Por conciliar</Badge>}
                                            {transaction.resolution_label && <Badge>{transaction.resolution_label}</Badge>}
                                        </div>
                                    </TD>
                                    <TD>{transaction.payable ? transaction.payable.label : '—'}</TD>
                                    <TD align="right" className="font-mono tabular">
                                        {money(transaction.amount)}
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                )}
            </Card>

            <Pagination meta={transactions} className="mt-4" />
        </AppLayout>
    );
}

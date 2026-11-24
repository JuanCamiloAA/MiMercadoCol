import { Link } from '@inertiajs/react';
import { ArrowLeftIcon, CreditCardIcon } from '@phosphor-icons/react';
import { ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader, StatCard } from '@/components/ui/Card';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { date, dateTime, money } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { BadgeTone } from '@/components/ui/Misc';

interface CustomerShowProps {
    customer: {
        id: number;
        name: string;
        business_name: string | null;
        document_type: string;
        document_number: string;
        phone: string | null;
        email: string | null;
        address: string | null;
        neighborhood: string | null;
        delivery_notes: string | null;
        credit_enabled: boolean;
        credit_blocked: boolean;
        blocked_reason: string | null;
        credit_limit: number;
        credit_term_days: number;
        is_online_user: boolean;
        is_active: boolean;
        balance: number;
        overdue_balance: number;
        available_credit: number;
    };
    sales: {
        id: number;
        number: string;
        status: string;
        status_label: string;
        total: number;
        due_total: number;
        due_date: string | null;
        sold_at: string;
    }[];
    orders: { id: number; number: string; status: string; status_label: string; total: number; placed_at: string }[];
    storeCredit: {
        available: number;
        next_expiry: { remaining: number; expires_at: string } | null;
        movements: {
            id: number;
            type_label: string;
            amount: number;
            occurred_at: string;
            expires_at: string | null;
            note: string | null;
            return: { id: number; number: string } | null;
        }[];
    };
    returns: { id: number; number: string; status_label: string; resolution_label: string | null; total: number; created_at: string }[];
}

const SALE_TONES: Record<string, BadgeTone> = {
    paid: 'accent',
    credit: 'warn',
    cancelled: 'danger',
    refunded: 'neutral',
};

export default function CustomerShow({ customer, sales, orders, storeCredit, returns }: CustomerShowProps) {
    const { can } = usePermission();

    return (
        <AppLayout title={customer.name}>
            <PageHeader
                title={customer.name}
                description={`${customer.document_type} ${customer.document_number}${customer.neighborhood ? ` · ${customer.neighborhood}` : ''}`}
                breadcrumb={
                    <Link href={route('customers.index')} className="hover:text-[var(--accent)]">
                        Clientes
                    </Link>
                }
                actions={
                    <>
                        <ButtonLink href={route('customers.index')} variant="secondary" icon={<ArrowLeftIcon size={16} />}>
                            Volver
                        </ButtonLink>
                        {customer.credit_enabled && can('credit.index.view') && (
                            <ButtonLink href={route('credit.show', customer.id)} icon={<CreditCardIcon size={16} />}>
                                Ver cartera
                            </ButtonLink>
                        )}
                    </>
                }
            />

            {customer.credit_enabled && (
                <div className="mb-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard label="Cupo" value={money(customer.credit_limit)} tone="neutral" />
                    <StatCard label="Saldo" value={money(customer.balance)} tone={customer.balance > 0 ? 'warn' : 'neutral'} />
                    <StatCard label="En mora" value={money(customer.overdue_balance)} tone={customer.overdue_balance > 0 ? 'danger' : 'neutral'} />
                    <StatCard label="Disponible" value={money(customer.available_credit)} />
                </div>
            )}

            <div className="grid gap-4 lg:grid-cols-[320px_1fr]">
                <Card>
                    <CardHeader title="Datos del cliente" />
                    <dl className="flex flex-col gap-2.5 text-[0.8125rem]">
                        <Row label="Razón social" value={customer.business_name} />
                        <Row label="Teléfono" value={customer.phone} mono />
                        <Row label="Correo" value={customer.email} />
                        <Row label="Dirección" value={customer.address} />
                        <Row label="Barrio" value={customer.neighborhood} />
                        <Row label="Plazo de crédito" value={customer.credit_enabled ? `${customer.credit_term_days} dias` : 'Sin credito'} />
                    </dl>

                    {customer.delivery_notes && (
                        <p className="mt-3 rounded-control bg-[var(--surface-2)] px-3 py-2 text-[0.78125rem] text-[var(--muted)]">{customer.delivery_notes}</p>
                    )}

                    <div className="mt-4 flex flex-wrap gap-1.5 border-t border-[var(--line)] pt-4">
                        {customer.is_active ? <Badge tone="accent">Activo</Badge> : <Badge tone="neutral">Inactivo</Badge>}
                        {customer.credit_enabled && <Badge tone="info">Con crédito</Badge>}
                        {customer.credit_blocked && <Badge tone="danger">Crédito bloqueado</Badge>}
                        {customer.is_online_user && <Badge tone="neutral">Tienda en línea</Badge>}
                    </div>

                    {customer.credit_blocked && customer.blocked_reason && (
                        <p className="mt-3 rounded-control bg-[var(--danger-soft)] px-3 py-2 text-[0.78125rem] text-[var(--danger)]">{customer.blocked_reason}</p>
                    )}
                </Card>

                <div className="flex flex-col gap-4">
                    <Card padded={false}>
                        <div className="px-5 pt-5">
                            <CardHeader title="Últimas compras" />
                        </div>
                        <Table className="rounded-none border-x-0 border-b-0">
                            <THead>
                                <TR>
                                    <TH>Tiquete</TH>
                                    <TH>Fecha</TH>
                                    <TH align="right">Total</TH>
                                    <TH align="right">Pendiente</TH>
                                    <TH>Vence</TH>
                                    <TH align="center">Estado</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {sales.length === 0 && (
                                    <TR>
                                        <TD colSpan={6} className="py-6 text-center text-[0.8125rem] text-[var(--muted)]">
                                            Este cliente todavía no ha comprado.
                                        </TD>
                                    </TR>
                                )}
                                {sales.map((sale) => (
                                    <TR key={sale.id}>
                                        <TD className="font-mono text-[0.8125rem]">{sale.number}</TD>
                                        <TD className="text-[0.78125rem] text-[var(--muted)]">{dateTime(sale.sold_at)}</TD>
                                        <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                            {money(sale.total)}
                                        </TD>
                                        <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                            {sale.due_total > 0 ? <span className="text-[var(--warn)]">{money(sale.due_total)}</span> : '—'}
                                        </TD>
                                        <TD className="text-[0.78125rem] text-[var(--muted)]">{sale.due_date ? date(sale.due_date) : '—'}</TD>
                                        <TD align="center">
                                            <Badge tone={SALE_TONES[sale.status] ?? 'neutral'}>{sale.status_label}</Badge>
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </Card>

                    <Card padded={false}>
                        <div className="px-5 pt-5">
                            <CardHeader title="Pedidos a domicilio" />
                        </div>
                        <Table className="rounded-none border-x-0 border-b-0">
                            <THead>
                                <TR>
                                    <TH>Pedido</TH>
                                    <TH>Fecha</TH>
                                    <TH align="right">Total</TH>
                                    <TH align="center">Estado</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {orders.length === 0 && (
                                    <TR>
                                        <TD colSpan={4} className="py-6 text-center text-[0.8125rem] text-[var(--muted)]">
                                            Sin pedidos registrados.
                                        </TD>
                                    </TR>
                                )}
                                {orders.map((order) => (
                                    <TR key={order.id}>
                                        <TD>
                                            {can('orders.show.view') ? (
                                                <Link href={route('orders.show', order.id)} className="font-mono text-[0.8125rem] hover:text-[var(--accent)]">
                                                    #{order.number}
                                                </Link>
                                            ) : (
                                                <span className="font-mono text-[0.8125rem]">#{order.number}</span>
                                            )}
                                        </TD>
                                        <TD className="text-[0.78125rem] text-[var(--muted)]">{dateTime(order.placed_at)}</TD>
                                        <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                            {money(order.total)}
                                        </TD>
                                        <TD align="center">
                                            <Badge tone="neutral">{order.status_label}</Badge>
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </Card>

                    <Card>
                        <CardHeader
                            title="Saldo a favor"
                            description="Lo que se le abonó por devoluciones. Se usa como medio de pago en caja y en la tienda en línea."
                            action={<span className="font-mono text-[1.25rem] font-semibold text-[var(--accent)] tabular">{money(storeCredit.available)}</span>}
                        />
                        {storeCredit.next_expiry && (
                            <p className="mb-2 text-[0.78125rem] text-[var(--muted)]">
                                {money(storeCredit.next_expiry.remaining)} vencen el {date(storeCredit.next_expiry.expires_at)}.
                            </p>
                        )}
                        {storeCredit.movements.length === 0 ? (
                            <p className="text-[0.8125rem] text-[var(--muted)]">Nunca ha tenido saldo a favor.</p>
                        ) : (
                            <ul className="flex flex-col divide-y divide-[var(--line)]">
                                {storeCredit.movements.map((movement) => (
                                    <li key={movement.id} className="flex items-start justify-between gap-3 py-2 text-[0.8125rem]">
                                        <div className="min-w-0">
                                            <p className="font-medium">
                                                {movement.type_label}
                                                {movement.return &&
                                                    (can('sales.returns.view') ? (
                                                        <Link href={route('customer_returns.show', movement.return.id)} className="ml-1.5 font-mono text-[var(--accent)] hover:underline">
                                                            {movement.return.number}
                                                        </Link>
                                                    ) : (
                                                        <span className="ml-1.5 font-mono text-[var(--muted)]">{movement.return.number}</span>
                                                    ))}
                                            </p>
                                            <p className="text-[0.71875rem] text-[var(--muted)]">
                                                {dateTime(movement.occurred_at)}
                                                {movement.note ? ` · ${movement.note}` : ''}
                                                {movement.expires_at ? ` · vence ${date(movement.expires_at)}` : ''}
                                            </p>
                                        </div>
                                        <span className={`shrink-0 font-mono tabular ${movement.amount < 0 ? 'text-[var(--danger)]' : 'text-[var(--accent)]'}`}>
                                            {movement.amount > 0 ? '+' : '−'} {money(Math.abs(movement.amount))}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    {returns.length > 0 && (
                        <Card padded={false}>
                            <div className="px-5 pt-5">
                                <CardHeader title="Devoluciones" />
                            </div>
                            <Table className="rounded-none border-x-0 border-b-0">
                                <THead>
                                    <TR>
                                        <TH>Devolución</TH>
                                        <TH>Fecha</TH>
                                        <TH>Respuesta</TH>
                                        <TH align="right">Valor</TH>
                                        <TH align="center">Estado</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {returns.map((row) => (
                                        <TR key={row.id}>
                                            <TD>
                                                {can('sales.returns.view') ? (
                                                    <Link href={route('customer_returns.show', row.id)} className="font-mono text-[0.8125rem] hover:text-[var(--accent)]">
                                                        {row.number}
                                                    </Link>
                                                ) : (
                                                    <span className="font-mono text-[0.8125rem]">{row.number}</span>
                                                )}
                                            </TD>
                                            <TD className="text-[0.78125rem] text-[var(--muted)]">{dateTime(row.created_at)}</TD>
                                            <TD className="text-[0.78125rem]">{row.resolution_label ?? '—'}</TD>
                                            <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                                {money(row.total)}
                                            </TD>
                                            <TD align="center">
                                                <Badge tone="neutral">{row.status_label}</Badge>
                                            </TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        </Card>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}

function Row({ label, value, mono }: { label: string; value: string | null | undefined; mono?: boolean }) {
    return (
        <div className="flex items-start justify-between gap-3">
            <dt className="shrink-0 text-[var(--muted)]">{label}</dt>
            <dd className={`text-right ${mono ? 'font-mono text-[0.78125rem] tabular' : ''}`}>{value || '—'}</dd>
        </div>
    );
}

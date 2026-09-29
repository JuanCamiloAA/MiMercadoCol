import { Link } from '@inertiajs/react';
import { ArrowUUpLeftIcon, PrinterIcon } from '@phosphor-icons/react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { dateTime, money, number } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { BadgeTone } from '@/components/ui/Misc';

interface SaleItem {
    id: number;
    description: string;
    quantity: number;
    unit_price: number;
    discount: number;
    line_total: number;
    is_service: boolean;
    /** Lo que el cajero quitó del servicio en esta venta. */
    removed: string[];
    components: { id: number; description: string; quantity: number; line_total: number; change: string | null }[];
}

interface SalePayment {
    id: number;
    method_label: string;
    amount: number;
    received_amount: number | null;
    change_amount: number | null;
    reference: string | null;
}

interface Refund {
    id: number;
    number: string;
    total: number;
    sold_at: string;
    reason: string;
    /** El documento DV, si la devolucion se registro con el modulo de devoluciones. */
    return: { id: number; number: string } | null;
}

interface OpenReturn {
    id: number;
    number: string;
    status_label: string;
    total: number;
    created_at: string;
}

interface SaleDetail {
    id: number;
    number: string;
    status: string;
    status_label: string;
    channel_label: string;
    subtotal: number;
    tax_total: number;
    discount_total: number;
    rounding_total: number;
    total: number;
    paid_total: number;
    due_total: number;
    due_date: string | null;
    note: string | null;
    sold_at: string;
    cancelled_at: string | null;
    cancel_reason: string | null;
    customer: string | null;
    customer_document: string | null;
    cashier: string | null;
    seller: string | null;
    seller_position: string | null;
    authorized_by: string | null;
    register: string | null;
    store: string | null;
    items: SaleItem[];
    payments: SalePayment[];
}

interface SalesShowProps {
    sale: SaleDetail;
    refunds: Refund[];
    openReturns: OpenReturn[];
    canRegisterReturn: boolean;
    canPrint: boolean;
}

const STATUS_TONES: Record<string, BadgeTone> = {
    paid: 'accent',
    credit: 'warn',
    cancelled: 'danger',
    refunded: 'info',
};

function Line({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className="flex items-baseline justify-between gap-4 py-1">
            <span className={strong ? 'text-[0.84375rem] font-semibold text-[var(--text)]' : 'text-[0.8125rem] text-[var(--muted)]'}>{label}</span>
            <span
                className={
                    strong
                        ? 'font-mono text-[1rem] font-semibold tabular text-[var(--text)]'
                        : 'font-mono text-[0.8125rem] tabular text-[var(--text)]'
                }
            >
                {value}
            </span>
        </div>
    );
}

function Fact({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <p className="text-[0.71875rem] uppercase tracking-wide text-[var(--muted)]">{label}</p>
            <p className="text-[0.84375rem] text-[var(--text)]">{value}</p>
        </div>
    );
}

export default function SalesShow({ sale, refunds, openReturns, canRegisterReturn, canPrint }: SalesShowProps) {
    const { can } = usePermission();

    return (
        <AppLayout title={`Venta ${sale.number}`}>
            <PageHeader
                breadcrumb={
                    <Link href={route('sales.index')} className="hover:text-[var(--accent)]">
                        Ventas
                    </Link>
                }
                title={sale.number}
                description={`${sale.channel_label} · ${dateTime(sale.sold_at)}`}
                actions={
                    <>
                        <Badge tone={STATUS_TONES[sale.status] ?? 'neutral'}>{sale.status_label}</Badge>
                        {canRegisterReturn && (
                            <ButtonLink
                                href={`${route('customer_returns.create')}?venta=${sale.id}`}
                                variant="secondary"
                                icon={<ArrowUUpLeftIcon size={16} weight="bold" />}
                            >
                                Registrar devolución
                            </ButtonLink>
                        )}
                        {canPrint && (
                            <Button
                                variant="secondary"
                                icon={<PrinterIcon size={16} weight="bold" />}
                                onClick={() => window.open(route('sales.print', sale.id), '_blank', 'width=420,height=640')}
                            >
                                Imprimir tiquete
                            </Button>
                        )}
                    </>
                }
            />

            {sale.cancelled_at && (
                <Card className="mb-4 border-[var(--danger)]/40 bg-[var(--danger-soft)]">
                    <p className="text-[0.8125rem] text-[var(--danger)]">
                        Anulada el {dateTime(sale.cancelled_at)}
                        {sale.cancel_reason ? `: ${sale.cancel_reason}` : '.'}
                    </p>
                </Card>
            )}

            <div className="grid gap-4 lg:grid-cols-[1fr_340px]">
                <div className="flex flex-col gap-4">
                    <Card padded={false}>
                        <CardHeader title="Articulos" className="px-4 pt-4" />
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Articulo</TH>
                                    <TH align="center">Cantidad</TH>
                                    <TH align="right">Precio</TH>
                                    <TH align="right">Descuento</TH>
                                    <TH align="right">Total</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {sale.items.map((item) => (
                                    <TR key={item.id}>
                                        <TD className="text-[0.8125rem]">
                                            <span className="flex flex-wrap items-center gap-1.5">
                                                {item.description}
                                                {item.is_service && <Badge tone="info">Servicio</Badge>}
                                                {(item.removed.length > 0 || item.components.some((component) => component.change !== null)) && <Badge tone="warn">Ajustado</Badge>}
                                            </span>
                                            {item.components.length > 0 && (
                                                <ul className="mt-1 border-l-2 border-[var(--line)] pl-2 text-[0.75rem] text-[var(--muted)]">
                                                    {item.components.map((component) => (
                                                        <li key={component.id}>
                                                            {number(component.quantity)} × {component.description}
                                                            {component.change ? ' (ajustado)' : ''}
                                                            {' · '}
                                                            {component.line_total !== 0 ? money(component.line_total) : 'incluido'}
                                                        </li>
                                                    ))}
                                                    {item.removed.map((name) => (
                                                        <li key={`removed-${name}`} className="line-through">
                                                            {name}
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </TD>
                                        <TD align="center" className="font-mono text-[0.8125rem] tabular">
                                            {number(item.quantity)}
                                        </TD>
                                        <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                            {money(item.unit_price)}
                                        </TD>
                                        <TD align="right" className="font-mono text-[0.8125rem] tabular text-[var(--muted)]">
                                            {item.discount > 0 ? `− ${money(item.discount)}` : '—'}
                                        </TD>
                                        <TD align="right" className="font-mono text-[0.8125rem] tabular">
                                            {money(item.line_total)}
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </Card>

                    {openReturns.length > 0 && (
                        <Card>
                            <CardHeader title="Devoluciones en curso" description="Todavía no han devuelto plata ni movido inventario." />
                            <ul className="mt-2 flex flex-col gap-2">
                                {openReturns.map((open) => (
                                    <li key={open.id} className="flex items-center justify-between gap-3 rounded-control border border-[var(--line)] px-3 py-2">
                                        <div className="min-w-0">
                                            {can('sales.returns.view') ? (
                                                <Link
                                                    href={route('customer_returns.show', open.id)}
                                                    className="font-mono text-[0.8125rem] font-medium text-[var(--text)] hover:text-[var(--accent)]"
                                                >
                                                    {open.number}
                                                </Link>
                                            ) : (
                                                <span className="font-mono text-[0.8125rem] font-medium">{open.number}</span>
                                            )}
                                            <p className="truncate text-[0.71875rem] text-[var(--muted)]">
                                                {open.status_label} · {dateTime(open.created_at)}
                                            </p>
                                        </div>
                                        <span className="shrink-0 font-mono text-[0.8125rem] tabular text-[var(--warn)]">{money(open.total)}</span>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}

                    {refunds.length > 0 && (
                        <Card>
                            <CardHeader
                                title="Devoluciones"
                                description="Cada devolucion es una venta en negativo, fechada el dia en que el cliente la trajo."
                            />
                            <ul className="mt-2 flex flex-col gap-2">
                                {refunds.map((refund) => (
                                    <li
                                        key={refund.id}
                                        className="flex items-center justify-between gap-3 rounded-control border border-[var(--line)] px-3 py-2"
                                    >
                                        <div className="min-w-0">
                                            <Link
                                                href={route('sales.show', refund.id)}
                                                className="font-mono text-[0.8125rem] font-medium text-[var(--text)] hover:text-[var(--accent)]"
                                            >
                                                {refund.number}
                                            </Link>
                                            <p className="truncate text-[0.71875rem] text-[var(--muted)]">
                                                {dateTime(refund.sold_at)}
                                                {refund.reason ? ` · ${refund.reason}` : ''}
                                            </p>
                                            {refund.return &&
                                                (can('sales.returns.view') ? (
                                                    <Link
                                                        href={route('customer_returns.show', refund.return.id)}
                                                        className="font-mono text-[0.71875rem] font-medium text-[var(--accent)] hover:underline"
                                                    >
                                                        {refund.return.number}
                                                    </Link>
                                                ) : (
                                                    <span className="font-mono text-[0.71875rem] text-[var(--muted)]">{refund.return.number}</span>
                                                ))}
                                        </div>
                                        <span className="shrink-0 font-mono text-[0.8125rem] tabular text-[var(--danger)]">
                                            {money(refund.total)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}
                </div>

                <div className="flex flex-col gap-4">
                    <Card>
                        <CardHeader title="Totales" />
                        <div className="mt-1">
                            <Line label="Subtotal" value={money(sale.subtotal)} />
                            <Line label="IVA incluido" value={money(sale.tax_total)} />
                            {sale.discount_total > 0 && <Line label="Descuento" value={`− ${money(sale.discount_total)}`} />}
                            {sale.rounding_total !== 0 && <Line label="Redondeo" value={money(sale.rounding_total)} />}
                            <div className="mt-1 border-t border-[var(--line)] pt-1">
                                <Line label="Total" value={money(sale.total)} strong />
                            </div>
                            {sale.due_total > 0 && (
                                <p className="mt-2 rounded-chip bg-[var(--warn-soft)] px-2.5 py-1.5 text-[0.78125rem] text-[var(--warn)]">
                                    Queda debiendo {money(sale.due_total)}
                                    {sale.due_date ? `, con plazo al ${sale.due_date}` : ''}.
                                </p>
                            )}
                        </div>
                    </Card>

                    <Card>
                        <CardHeader title="Cobro" />
                        <ul className="mt-1 flex flex-col gap-1.5">
                            {sale.payments.map((payment) => (
                                <li key={payment.id} className="flex items-baseline justify-between gap-3">
                                    <span className="text-[0.8125rem] text-[var(--muted)]">
                                        {payment.method_label}
                                        {payment.reference ? ` · ${payment.reference}` : ''}
                                    </span>
                                    <span className="font-mono text-[0.8125rem] tabular text-[var(--text)]">{money(payment.amount)}</span>
                                </li>
                            ))}
                            {sale.payments.some((payment) => payment.change_amount !== null && payment.change_amount > 0) && (
                                <li className="flex items-baseline justify-between gap-3 border-t border-[var(--line)] pt-1.5">
                                    <span className="flex items-center gap-1.5 text-[0.8125rem] text-[var(--muted)]">
                                        <ArrowUUpLeftIcon size={14} />
                                        Cambio entregado
                                    </span>
                                    <span className="font-mono text-[0.8125rem] tabular text-[var(--text)]">
                                        {money(sale.payments.reduce((sum, payment) => sum + (payment.change_amount ?? 0), 0))}
                                    </span>
                                </li>
                            )}
                        </ul>
                    </Card>

                    <Card>
                        <CardHeader title="Quien y donde" />
                        <div className="mt-1 grid grid-cols-2 gap-3">
                            <Fact label="Cliente" value={sale.customer ?? 'Consumidor final'} />
                            <Fact label="Documento" value={sale.customer_document ?? '—'} />
                            <Fact label="Cajero" value={sale.cashier ?? '—'} />
                            {/* Quien vendio, que no siempre es quien cobro. */}
                            <Fact
                                label="Vendedor"
                                value={sale.seller ? (sale.seller_position ? `${sale.seller} · ${sale.seller_position}` : sale.seller) : 'Sin asignar'}
                            />
                            <Fact label="Caja" value={sale.register ?? '—'} />
                            <Fact label="Sede" value={sale.store ?? '—'} />
                            {sale.authorized_by && <Fact label="Autorizo" value={sale.authorized_by} />}
                        </div>
                        {sale.note && <p className="mt-3 text-[0.78125rem] text-[var(--muted)]">{sale.note}</p>}
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}

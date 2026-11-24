import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import type { BadgeTone } from '@/components/ui/Misc';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { Modal } from '@/components/ui/Modal';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { date, dateTime, money, number, UNIT_SHORT } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import { RETURN_TONES } from '@/lib/supplier-returns';
import type { SupplierReturnStatus } from '@/types/supplier-returns';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeftIcon, ArrowsSplitIcon, ArrowUUpLeftIcon, CheckCircleIcon, PaperPlaneTiltIcon, ProhibitIcon } from '@phosphor-icons/react';
import { Fragment, useState, type FormEvent } from 'react';

interface Item {
    id: number;
    product_id: number;
    name: string | null;
    sku: string | null;
    unit: string | null;
    quantity: number;
    received_quantity: number;
    /** Lo que el proveedor se quedó en devoluciones despachadas. No cambia lo recibido. */
    returned_quantity: number;
    pending_quantity: number;
    unit_cost: number;
    line_total: number;
    expires_at: string | null;
    lot_code: string | null;
    supplier: string | null;
    is_off_supplier: boolean;
    suggested_quantity: number;
    stock_at_order: number;
    min_at_order: number;
    family_id: number | null;
    family_name: string | null;
    variant_label: string | null;
    variant_sort: number;
}

/**
 * Los renglones con los de un mismo artículo principal juntos y en el orden de sus valores, para
 * leerlos (y recibirlos) como una matriz. Cada uno sigue siendo un renglón de la orden.
 */
function groupByFamily(items: Item[]): { family: { id: number; name: string } | null; items: Item[] }[] {
    const groups: { family: { id: number; name: string } | null; items: Item[] }[] = [];

    items.forEach((item) => {
        if (item.family_id === null) {
            groups.push({ family: null, items: [item] });

            return;
        }

        const group = groups.find((entry) => entry.family?.id === item.family_id);

        if (group) {
            group.items.push(item);
        } else {
            groups.push({ family: { id: item.family_id, name: item.family_name ?? '' }, items: [item] });
        }
    });

    groups.forEach((group) => group.items.sort((a, b) => a.variant_sort - b.variant_sort));

    return groups;
}

interface PurchaseShowProps {
    order: {
        id: number;
        code: string;
        status: string;
        status_label: string;
        supplier: { id: number; name: string; contact_name: string | null; phone: string | null };
        store: string | null;
        user: string | null;
        expected_at: string | null;
        received_at: string | null;
        payment_term: { id: number; name: string; days: number } | null;
        due_at: string | null;
        is_overdue: boolean;
        subtotal: number;
        tax_total: number;
        total: number;
        note: string | null;
        source_label: string | null;
        can_receive: boolean;
        can_return: boolean;
        received_value: number;
        returned_value: number;
        net_value: number;
        returns: {
            id: number;
            number: string;
            status: SupplierReturnStatus;
            status_label: string;
            at: string | null;
            units: number;
            total: number;
        }[];
        replacement_of: { id: number; number: string } | null;
        pending_quantity: number;
        /** Cada remesa que entró, con su fecha. */
        receipts: { at: string; units: number; lines: number; user: string | null }[];
        /** Cuántos renglones no son del proveedor de la orden. */
        off_supplier_count: number;
        parent: { id: number; code: string } | null;
        children: { id: number; code: string; supplier: string | null; total: number }[];
        created_at: string;
        items: Item[];
    };
    paymentTerms: { value: number; label: string; days: number }[];
}

const STATUS_TONES: Record<string, BadgeTone> = {
    draft: 'neutral',
    sent: 'info',
    partial: 'warn',
    received: 'accent',
    cancelled: 'danger',
};

export default function PurchaseShow({ order, paymentTerms }: PurchaseShowProps) {
    const { can } = usePermission();
    const [receiveOpen, setReceiveOpen] = useState(false);

    /*
     * Lo que llega **ahora**, no el acumulado.
     *
     * Un pedido de 120 del que llegaron 100 deja 20 pendientes: al recibir la segunda remesa
     * se escribe 20, no 120. Por eso arranca en lo que falta, que es lo normal.
     */
    const receiveForm = useForm({
        items: order.items.map((item) => ({
            id: item.id,
            received_quantity: item.pending_quantity,
            expires_at: '',
            lot_code: '',
        })),
    });

    const updateLine = (id: number, key: 'received_quantity' | 'expires_at' | 'lot_code', value: string | number) => {
        receiveForm.setData(
            'items',
            receiveForm.data.items.map((line) => (line.id === id ? { ...line, [key]: value } : line)),
        );
    };

    const submitReceive = (event: FormEvent) => {
        event.preventDefault();
        receiveForm.post(route('purchases.receive', order.id), {
            preserveScroll: true,
            onSuccess: () => setReceiveOpen(false),
        });
    };

    const canReceive = can('purchases.index.receive') && order.can_receive;

    return (
        <AppLayout title={`Orden ${order.code}`}>
            <PageHeader
                title={`Orden ${order.code}`}
                description={`${order.supplier.name}${order.store ? ` · Sede ${order.store}` : ''}`}
                breadcrumb={
                    <Link href={route('purchases.index')} className="hover:text-[var(--accent)]">
                        Compras
                    </Link>
                }
                actions={
                    <>
                        <ButtonLink href={route('purchases.index')} variant="secondary" icon={<ArrowLeftIcon size={16} />}>
                            Volver
                        </ButtonLink>
                        {order.status === 'draft' && can('purchases.index.create') && (
                            <Button
                                variant="secondary"
                                icon={<PaperPlaneTiltIcon size={16} />}
                                onClick={() => router.post(route('purchases.send', order.id), {}, { preserveScroll: true })}
                            >
                                Enviar al proveedor
                            </Button>
                        )}
                        {order.status !== 'received' && order.status !== 'cancelled' && can('purchases.index.cancel') && (
                            <Button
                                variant="secondary"
                                icon={<ProhibitIcon size={16} />}
                                onClick={() => router.post(route('purchases.cancel', order.id), {}, { preserveScroll: true })}
                            >
                                Anular
                            </Button>
                        )}
                        {order.off_supplier_count > 0 &&
                            order.status !== 'received' &&
                            order.status !== 'cancelled' &&
                            can('purchases.index.create') && (
                                <Button
                                    variant="secondary"
                                    icon={<ArrowsSplitIcon size={16} />}
                                    onClick={() => router.post(route('purchases.split', order.id), {}, { preserveScroll: true })}
                                >
                                    Separar por proveedor
                                </Button>
                            )}
                        {order.status === 'partial' && can('purchases.index.receive') && (
                            <Button
                                variant="secondary"
                                icon={<ProhibitIcon size={16} />}
                                onClick={() => router.post(route('purchases.close', order.id), {}, { preserveScroll: true })}
                                title="El resto ya no va a llegar: la orden se cierra con lo que entró"
                            >
                                Cerrar con lo que llegó
                            </Button>
                        )}
                        {order.can_return && can('purchases.returns.create') && (
                            <ButtonLink
                                href={route('supplier_returns.create', { orden: order.id })}
                                variant="secondary"
                                icon={<ArrowUUpLeftIcon size={16} />}
                            >
                                Devolver
                            </ButtonLink>
                        )}
                        {canReceive && (
                            <Button icon={<CheckCircleIcon size={16} weight="bold" />} onClick={() => setReceiveOpen(true)}>
                                {order.status === 'partial' ? 'Recibir lo que falta' : 'Recibir mercancía'}
                            </Button>
                        )}
                    </>
                }
            />

            <div className="grid gap-4 lg:grid-cols-[320px_1fr]">
                <div className="flex flex-col gap-4">
                    <Card>
                        <CardHeader title="Estado" />
                        <Badge tone={STATUS_TONES[order.status] ?? 'neutral'}>{order.status_label}</Badge>

                        <dl className="mt-4 flex flex-col gap-2.5 border-t border-[var(--line)] pt-4 text-[0.8125rem]">
                            <Row label="Creada" value={date(order.created_at)} />
                            <Row label="Entrega esperada" value={order.expected_at ? date(order.expected_at) : '—'} />
                            <Row label="Recibida" value={order.received_at ? dateTime(order.received_at) : '—'} />
                            <Row label="Registro" value={order.user ?? '—'} />
                        </dl>
                    </Card>

                    <Card>
                        <CardHeader title="Pago al proveedor" />
                        <dl className="flex flex-col gap-2.5 text-[0.8125rem]">
                            <Row
                                label="Plazo"
                                value={order.payment_term ? `${order.payment_term.name} (${order.payment_term.days} días)` : 'Sin plazo'}
                            />
                            <div className="flex items-center justify-between gap-3">
                                <dt className="text-[var(--muted)]">Vence</dt>
                                <dd className={order.is_overdue ? 'font-semibold text-[var(--danger)]' : ''}>
                                    {order.due_at ? date(order.due_at) : order.received_at ? '—' : 'Al recibir todo'}
                                </dd>
                            </div>
                        </dl>
                        {can('purchases.index.create') && order.status !== 'cancelled' && (
                            <div className="mt-3 border-t border-[var(--line)] pt-3">
                                <select
                                    aria-label="Cambiar el plazo"
                                    value={order.payment_term?.id ?? ''}
                                    onChange={(event) =>
                                        router.patch(
                                            route('purchases.term', order.id),
                                            { payment_term_id: event.target.value === '' ? null : Number(event.target.value) },
                                            { preserveScroll: true },
                                        )
                                    }
                                    className="rounded-control h-10 w-full border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.8125rem] focus:border-[var(--accent)] focus:outline-none"
                                >
                                    <option value="">Sin plazo</option>
                                    {paymentTerms.map((term) => (
                                        <option key={term.value} value={term.value}>
                                            {term.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}
                    </Card>

                    <Card>
                        <CardHeader title="Proveedor" />
                        <p className="text-[0.875rem] font-medium text-[var(--text)]">{order.supplier.name}</p>
                        {order.supplier.contact_name && <p className="text-[0.78125rem] text-[var(--muted)]">{order.supplier.contact_name}</p>}
                        {order.supplier.phone && <p className="tabular font-mono text-[0.78125rem] text-[var(--muted)]">{order.supplier.phone}</p>}
                        {order.note && (
                            <p className="rounded-control mt-3 bg-[var(--surface-2)] px-3 py-2 text-[0.78125rem] text-[var(--muted)]">{order.note}</p>
                        )}
                    </Card>

                    <Card>
                        <CardHeader title="Totales" />
                        <dl className="flex flex-col gap-2 text-[0.8125rem]">
                            <Row label="Base" value={money(order.subtotal)} mono />
                            <Row label="IVA" value={money(order.tax_total)} mono />
                            <div className="flex items-center justify-between border-t border-[var(--line)] pt-2">
                                <dt className="font-semibold">Total</dt>
                                <dd className="tabular font-mono text-[1.125rem] font-semibold text-[var(--accent)]">{money(order.total)}</dd>
                            </div>
                        </dl>
                        {order.returned_value > 0 && (
                            <p className="mt-3 border-t border-[var(--line)] pt-3 text-[0.78125rem] text-[var(--muted)]">
                                Recibido <span className="tabular font-mono text-[var(--text)]">{money(order.received_value)}</span> · Devuelto{' '}
                                <span className="tabular font-mono text-[var(--danger)]">{money(order.returned_value)}</span> · Neto{' '}
                                <span className="tabular font-mono font-semibold text-[var(--text)]">{money(order.net_value)}</span>
                            </p>
                        )}
                    </Card>
                </div>

                {/* La columna derecha va entera: si no, la grilla reparte sus tarjetas en dos columnas. */}
                <div className="flex min-w-0 flex-col">
                    {order.replacement_of && (
                        <Card className="mb-4">
                            <p className="text-[0.8125rem] text-[var(--muted)]">
                                Reposición de{' '}
                                {can('purchases.returns.view') ? (
                                    <Link
                                        href={route('supplier_returns.show', order.replacement_of.id)}
                                        className="font-mono font-medium text-[var(--accent)] hover:underline"
                                    >
                                        {order.replacement_of.number}
                                    </Link>
                                ) : (
                                    <span className="font-mono font-medium text-[var(--text)]">{order.replacement_of.number}</span>
                                )}
                                : el proveedor manda esta mercancía a cambio de la devuelta. Ya estaba pagada, así que no lleva plazo.
                            </p>
                        </Card>
                    )}

                    {order.returns.length > 0 && can('purchases.returns.view') && (
                        <Card className="mb-4">
                            <CardHeader title="Devoluciones de esta orden" />
                            <ul className="flex flex-col divide-y divide-[var(--line)]">
                                {order.returns.map((entry) => (
                                    <li key={entry.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-[0.8125rem]">
                                        <span className="flex items-center gap-2">
                                            <Link
                                                href={route('supplier_returns.show', entry.id)}
                                                className="font-mono font-medium text-[var(--accent)] hover:underline"
                                            >
                                                {entry.number}
                                            </Link>
                                            <span className="text-[var(--muted)]">{entry.at ? date(entry.at) : '—'}</span>
                                            <Badge tone={RETURN_TONES[entry.status]}>{entry.status_label}</Badge>
                                        </span>
                                        <span className="tabular font-mono">
                                            {number(entry.units)} unidades · {money(entry.total)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}

                    {order.status === 'partial' && (
                        <Card className="mb-4">
                            <p className="text-[0.8125rem] font-medium text-[var(--warn)]">
                                Llegó parte del pedido. Faltan <span className="tabular font-mono">{number(order.pending_quantity)}</span> unidades;
                                la orden sigue abierta para cuando lleguen.
                            </p>
                        </Card>
                    )}

                    {order.receipts.length > 1 && (
                        <Card className="mb-4">
                            <CardHeader title="Recepciones" description="Este pedido llegó por partes." />
                            <ul className="flex flex-col gap-1.5">
                                {order.receipts.map((receipt, index) => (
                                    <li key={receipt.at} className="flex flex-wrap items-center justify-between gap-2 text-[0.8125rem]">
                                        <span>
                                            <span className="font-medium">Remesa {index + 1}</span>{' '}
                                            <span className="text-[var(--muted)]">
                                                · {dateTime(receipt.at)}
                                                {receipt.user ? ` · ${receipt.user}` : ''}
                                            </span>
                                        </span>
                                        <span className="tabular font-mono">
                                            {number(receipt.units)} unidades en {receipt.lines} {receipt.lines === 1 ? 'artículo' : 'artículos'}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}

                    {(order.off_supplier_count > 0 || order.parent || order.children.length > 0) && (
                        <Card className="mb-4">
                            {order.off_supplier_count > 0 && (
                                <p className="text-[0.8125rem] font-medium text-[var(--warn)]">
                                    {order.off_supplier_count} {order.off_supplier_count === 1 ? 'artículo no es' : 'artículos no son'} de{' '}
                                    {order.supplier.name}. Puedes separar la orden en una por proveedor.
                                </p>
                            )}

                            {order.parent && (
                                <p className="text-[0.8125rem] text-[var(--muted)]">
                                    Salió de la orden{' '}
                                    <Link
                                        href={route('purchases.show', order.parent.id)}
                                        className="font-medium text-[var(--accent)] hover:underline"
                                    >
                                        {order.parent.code}
                                    </Link>{' '}
                                    al separarla por proveedor.
                                </p>
                            )}

                            {order.children.length > 0 && (
                                <div className={order.off_supplier_count > 0 || order.parent ? 'mt-2' : undefined}>
                                    <p className="mb-1 text-[0.8125rem] text-[var(--muted)]">Al separarla salieron:</p>
                                    <ul className="flex flex-col gap-1">
                                        {order.children.map((child) => (
                                            <li key={child.id} className="text-[0.8125rem]">
                                                <Link
                                                    href={route('purchases.show', child.id)}
                                                    className="font-medium text-[var(--accent)] hover:underline"
                                                >
                                                    {child.code}
                                                </Link>{' '}
                                                <span className="text-[var(--muted)]">
                                                    · {child.supplier} · <span className="tabular font-mono">{money(child.total)}</span>
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </Card>
                    )}

                    <Card padded={false}>
                        <div className="px-5 pt-5">
                            <CardHeader title="Artículos" />
                        </div>
                        <Table className="rounded-none border-x-0 border-b-0">
                            <THead>
                                <TR>
                                    <TH>Artículo</TH>
                                    <TH align="right">Pedido</TH>
                                    <TH align="right">Recibido</TH>
                                    <TH align="right">Falta</TH>
                                    <TH align="right">Devuelto</TH>
                                    <TH align="right">Costo unitario</TH>
                                    <TH align="right">Subtotal</TH>
                                    <TH>Lote</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {groupByFamily(order.items).map((group) => (
                                    <Fragment key={group.family ? `f${group.family.id}` : `i${group.items[0].id}`}>
                                        {group.family && (
                                            <TR className="bg-[var(--surface-2)]">
                                                <TD className="text-[0.8125rem] font-semibold">
                                                    {group.family.name}{' '}
                                                    <span className="font-normal text-[var(--muted)]">· {group.items.length} subartículos</span>
                                                </TD>
                                                <TD align="right" className="tabular font-mono text-[0.8125rem]">
                                                    {number(group.items.reduce((sum, item) => sum + item.quantity, 0))}
                                                </TD>
                                                <TD align="right" className="tabular font-mono text-[0.8125rem]">
                                                    {number(group.items.reduce((sum, item) => sum + item.received_quantity, 0))}
                                                </TD>
                                                <TD />
                                                <TD />
                                                <TD />
                                                <TD align="right" className="tabular font-mono text-[0.8125rem]">
                                                    {money(group.items.reduce((sum, item) => sum + item.line_total, 0))}
                                                </TD>
                                                <TD />
                                            </TR>
                                        )}
                                        {group.items.map((item) => (
                                            <TR key={item.id}>
                                                <TD>
                                                    <p
                                                        className={
                                                            group.family ? 'pl-4 text-[0.84375rem] font-medium' : 'text-[0.84375rem] font-medium'
                                                        }
                                                    >
                                                        {group.family && item.variant_label ? item.variant_label : item.name}
                                                    </p>
                                                    <p className="font-mono text-[0.71875rem] text-[var(--muted)]">{item.sku}</p>
                                                    {item.is_off_supplier && (
                                                        <p className="text-[0.71875rem] font-medium text-[var(--warn)]">Es de {item.supplier}</p>
                                                    )}
                                                </TD>
                                                <TD align="right" className="tabular font-mono text-[0.8125rem]">
                                                    {number(item.quantity)} {UNIT_SHORT[item.unit ?? 'unit'] ?? ''}
                                                    {/* La foto del momento: explica por que se pidio esto. */}
                                                    {item.min_at_order > 0 && (
                                                        <span className="block text-[0.6875rem] font-normal text-[var(--muted)]">
                                                            había {number(item.stock_at_order)} · mín {number(item.min_at_order)}
                                                        </span>
                                                    )}
                                                </TD>
                                                <TD align="right" className="tabular font-mono text-[0.8125rem]">
                                                    {item.received_quantity > 0 ? (
                                                        <span
                                                            className={
                                                                item.received_quantity < item.quantity ? 'text-[var(--warn)]' : 'text-[var(--accent)]'
                                                            }
                                                        >
                                                            {number(item.received_quantity)}
                                                        </span>
                                                    ) : (
                                                        <span className="text-[var(--muted)]">—</span>
                                                    )}
                                                </TD>
                                                <TD align="right" className="tabular font-mono text-[0.8125rem]">
                                                    {item.pending_quantity > 0 ? (
                                                        <span className="text-[var(--warn)]">{number(item.pending_quantity)}</span>
                                                    ) : (
                                                        <span className="text-[var(--muted)]">—</span>
                                                    )}
                                                </TD>
                                                <TD align="right" className="tabular font-mono text-[0.8125rem]">
                                                    {item.returned_quantity > 0 ? (
                                                        <span className="text-[var(--danger)]">{number(item.returned_quantity)}</span>
                                                    ) : (
                                                        <span className="text-[var(--muted)]">—</span>
                                                    )}
                                                </TD>
                                                <TD align="right" className="tabular font-mono text-[0.8125rem] whitespace-nowrap">
                                                    {money(item.unit_cost)}
                                                </TD>
                                                <TD align="right" className="tabular font-mono text-[0.8125rem] whitespace-nowrap">
                                                    {money(item.line_total)}
                                                </TD>
                                                <TD className="text-[0.75rem] text-[var(--muted)]">
                                                    {item.lot_code ?? '—'}
                                                    {item.expires_at && <span className="block">vence {date(item.expires_at)}</span>}
                                                </TD>
                                            </TR>
                                        ))}
                                    </Fragment>
                                ))}
                            </TBody>
                        </Table>
                    </Card>
                </div>
            </div>

            <Modal
                open={receiveOpen}
                onClose={() => setReceiveOpen(false)}
                title="Recibir mercancía"
                description="Escribe lo que llega en esta remesa, no el total del pedido. Si llega de a poco, la orden queda abierta hasta que entre todo."
                size="lg"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setReceiveOpen(false)}>
                            Cancelar
                        </Button>
                        <Button onClick={submitReceive} loading={receiveForm.processing}>
                            Confirmar recepción
                        </Button>
                    </>
                }
            >
                <form onSubmit={submitReceive}>
                    <Table className="border-0">
                        <THead>
                            <TR>
                                <TH>Artículo</TH>
                                <TH align="right">Pedido</TH>
                                <TH align="right">Ya llegó</TH>
                                <TH align="right">Llega ahora</TH>
                                <TH>Lote</TH>
                                <TH>Vence</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {groupByFamily(order.items).map((group) => (
                                <Fragment key={group.family ? `f${group.family.id}` : `i${group.items[0].id}`}>
                                    {group.family && (
                                        <TR className="bg-[var(--surface-2)]">
                                            <TD className="text-[0.8125rem] font-semibold">{group.family.name}</TD>
                                            <TD align="right" className="tabular font-mono text-[0.8125rem]">
                                                {number(group.items.reduce((sum, item) => sum + item.quantity, 0))}
                                            </TD>
                                            <TD />
                                            <TD align="right" colSpan={3}>
                                                <Button
                                                    size="sm"
                                                    variant="secondary"
                                                    onClick={() =>
                                                        group.items.forEach((item) => updateLine(item.id, 'received_quantity', item.pending_quantity))
                                                    }
                                                >
                                                    Llegó todo lo de este artículo
                                                </Button>
                                            </TD>
                                        </TR>
                                    )}
                                    {group.items.map((item) => {
                                        const line = receiveForm.data.items.find((entry) => entry.id === item.id);

                                        return (
                                            <TR key={item.id}>
                                                <TD>
                                                    <p
                                                        className={
                                                            group.family ? 'pl-4 text-[0.8125rem] font-medium' : 'text-[0.8125rem] font-medium'
                                                        }
                                                    >
                                                        {group.family && item.variant_label ? item.variant_label : item.name}
                                                    </p>
                                                    <p className="font-mono text-[0.6875rem] text-[var(--muted)]">{item.sku}</p>
                                                </TD>
                                                <TD align="right" className="tabular font-mono text-[0.8125rem]">
                                                    {number(item.quantity)}
                                                </TD>
                                                <TD align="right" className="tabular font-mono text-[0.8125rem]">
                                                    {item.received_quantity > 0 ? (
                                                        <span className="text-[var(--accent)]">{number(item.received_quantity)}</span>
                                                    ) : (
                                                        <span className="text-[var(--muted)]">—</span>
                                                    )}
                                                </TD>
                                                <TD align="right">
                                                    <input
                                                        type="number"
                                                        min={0}
                                                        step="0.001"
                                                        value={line?.received_quantity ?? 0}
                                                        onChange={(event) => updateLine(item.id, 'received_quantity', Number(event.target.value))}
                                                        className="rounded-control tabular h-9 w-24 border border-[var(--line)] bg-[var(--surface)] px-2 text-right font-mono text-[0.8125rem] focus:border-[var(--accent)] focus:outline-none"
                                                    />
                                                </TD>
                                                <TD>
                                                    <input
                                                        type="text"
                                                        value={line?.lot_code ?? ''}
                                                        onChange={(event) => updateLine(item.id, 'lot_code', event.target.value)}
                                                        className="rounded-control h-9 w-24 border border-[var(--line)] bg-[var(--surface)] px-2 font-mono text-[0.78125rem] focus:border-[var(--accent)] focus:outline-none"
                                                    />
                                                </TD>
                                                <TD>
                                                    <input
                                                        type="date"
                                                        value={line?.expires_at ?? ''}
                                                        onChange={(event) => updateLine(item.id, 'expires_at', event.target.value)}
                                                        className="rounded-control h-9 border border-[var(--line)] bg-[var(--surface)] px-2 font-mono text-[0.78125rem] focus:border-[var(--accent)] focus:outline-none"
                                                    />
                                                </TD>
                                            </TR>
                                        );
                                    })}
                                </Fragment>
                            ))}
                        </TBody>
                    </Table>
                </form>
            </Modal>
        </AppLayout>
    );
}

function Row({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
    return (
        <div className="flex items-center justify-between gap-3">
            <dt className="text-[var(--muted)]">{label}</dt>
            <dd className={mono ? 'tabular font-mono' : ''}>{value}</dd>
        </div>
    );
}

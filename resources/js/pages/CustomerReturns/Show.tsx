import { Link, router, useForm } from '@inertiajs/react';
import { CheckIcon, FilePdfIcon, PackageIcon, ProhibitIcon, WarningCircleIcon, XIcon } from '@phosphor-icons/react';
import { useState } from 'react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Select, Textarea } from '@/components/ui/Field';
import { ConfirmDialog, Modal } from '@/components/ui/Modal';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import { CUSTOMER_RETURN_TONES, DISPOSITION_TONES } from '@/lib/customer-returns';
import { dateTime, money, number } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { CustomerReturnDetail, Disposition, Option } from '@/types/customer-returns';

interface CustomerReturnShowProps {
    customerReturn: CustomerReturnDetail;
    can: { approve: boolean; reject: boolean; receive: boolean; resolve: boolean; cancel: boolean };
    dispositions: Option[];
    refundMethods: Option[];
    hasCashSession: boolean;
}

/**
 * Una devolución de venta con su historia. Las de la tienda en línea pasan por aprobar, recibir
 * (se decide a dónde va cada artículo) y resolver; las de caja llegan resueltas.
 */
export default function CustomerReturnShow({ customerReturn: ret, can: allowed, dispositions, refundMethods, hasCashSession }: CustomerReturnShowProps) {
    const { can } = usePermission();
    const [approving, setApproving] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const [rejecting, setRejecting] = useState(false);
    const [receiving, setReceiving] = useState(false);
    const [resolving, setResolving] = useState(false);
    const [busy, setBusy] = useState(false);

    const topLines = ret.items.filter((item) => item.parent_id === null);
    const componentsOf = (id: number) => ret.items.filter((item) => item.parent_id === id);

    const rejectForm = useForm({ reason: '' });
    const receiveForm = useForm({
        items: ret.items.map((item) => ({
            id: item.id,
            quantity: item.quantity,
            disposition: (item.disposition ?? item.suggested_disposition ?? null) as Disposition | null,
        })),
    });
    // Saldo a favor solo para un cliente identificado: al consumidor final no se le abona.
    const canCredit = ret.customer !== null && !ret.customer.generic;
    const resolveForm = useForm({
        resolution: canCredit ? 'store_credit' : 'refund',
        method: hasCashSession ? 'cash' : 'transfer',
    });

    const post = (name: string, done: () => void) => {
        setBusy(true);
        router.post(route(name, ret.id), {}, { preserveScroll: true, onFinish: () => setBusy(false), onSuccess: done });
    };

    const submitReject = () => rejectForm.post(route('customer_returns.reject', ret.id), { preserveScroll: true, onSuccess: () => setRejecting(false) });
    const submitReceive = () => receiveForm.post(route('customer_returns.receive', ret.id), { preserveScroll: true, onSuccess: () => setReceiving(false) });
    const submitResolve = () => resolveForm.post(route('customer_returns.resolve', ret.id), { preserveScroll: true, onSuccess: () => setResolving(false) });

    const setLine = (id: number, changes: Partial<{ quantity: number; disposition: Disposition | null }>) =>
        receiveForm.setData(
            'items',
            receiveForm.data.items.map((line) => (line.id === id ? { ...line, ...changes } : line)),
        );

    const lineOf = (id: number) => receiveForm.data.items.find((line) => line.id === id);
    const receivedValue = topLines.reduce((sum, item) => {
        const quantity = lineOf(item.id)?.quantity ?? item.quantity;
        const components = componentsOf(item.id).reduce((acc, component) => acc + (item.quantity > 0 ? (component.line_total * quantity) / item.quantity : 0), 0);

        return sum + (item.quantity > 0 ? (item.line_total * quantity) / item.quantity : 0) + components;
    }, 0);
    const errorList = Object.values({ ...receiveForm.errors, ...resolveForm.errors, ...rejectForm.errors }).filter(Boolean) as string[];

    return (
        <AppLayout title={`Devolución ${ret.number}`}>
            <PageHeader
                title={`Devolución ${ret.number}`}
                description={`${ret.status_label} · ${ret.origin_label} · ${ret.store ?? '—'}`}
                breadcrumb={
                    <span className="flex items-center gap-2">
                        <ButtonLink href={route('customer_returns.index')} size="sm" variant="ghost">
                            Volver a devoluciones
                        </ButtonLink>
                        <Badge tone={CUSTOMER_RETURN_TONES[ret.status]}>{ret.status_label}</Badge>
                    </span>
                }
                actions={
                    <>
                        <a
                            href={route('customer_returns.document', ret.id)}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex h-[calc(2.5rem*var(--density))] items-center gap-2 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-4 text-sm font-medium text-[var(--text)] hover:bg-[var(--surface-3)]"
                        >
                            <FilePdfIcon size={16} />
                            Comprobante
                        </a>
                        {allowed.cancel && (
                            <Button variant="secondary" onClick={() => setCancelling(true)}>
                                Anular
                            </Button>
                        )}
                        {allowed.reject && (
                            <Button variant="secondary" icon={<ProhibitIcon size={16} />} onClick={() => setRejecting(true)}>
                                No procede
                            </Button>
                        )}
                        {allowed.approve && (
                            <Button variant="secondary" icon={<CheckIcon size={16} />} onClick={() => setApproving(true)}>
                                Aprobar
                            </Button>
                        )}
                        {allowed.receive && (
                            <Button icon={<PackageIcon size={16} />} onClick={() => setReceiving(true)}>
                                Recibir mercancía
                            </Button>
                        )}
                        {allowed.resolve && <Button onClick={() => setResolving(true)}>Resolver</Button>}
                    </>
                }
            />

            {ret.status === 'rejected' && ret.reject_reason && (
                <Card className="mb-4">
                    <p className="flex items-start gap-2 text-[0.8125rem] text-[var(--danger)]">
                        <XIcon size={16} weight="bold" className="mt-px shrink-0" />
                        No procede: {ret.reject_reason}
                    </p>
                </Card>
            )}

            {ret.status === 'received' && (
                <Card className="mb-4">
                    <p className="text-[0.8125rem] text-[var(--muted)]">
                        La mercancía ya está en la sede y se decidió a dónde va cada artículo. El inventario se mueve al resolver, junto con la plata o el saldo.
                    </p>
                </Card>
            )}

            {ret.out_of_window && (
                <Card className="mb-4">
                    <p className="flex items-start gap-2 text-[0.8125rem] text-[var(--warn)]">
                        <WarningCircleIcon size={16} weight="fill" className="mt-px shrink-0" />
                        Fuera del plazo de devolución. Autorizó {ret.window_approved_by ?? '—'}.
                    </p>
                </Card>
            )}

            <div className="mb-4 grid gap-4 lg:grid-cols-3">
                <Card>
                    <CardHeader title="Cliente y venta" />
                    <dl className="flex flex-col gap-1.5 text-[0.78125rem]">
                        <div className="flex justify-between gap-2">
                            <dt className="text-[var(--muted)]">Cliente</dt>
                            <dd className="text-right">
                                {ret.customer ? (
                                    can('customers.show.view') ? (
                                        <Link href={route('customers.show', ret.customer.id)} className="font-medium text-[var(--accent)] hover:underline">
                                            {ret.customer.name}
                                        </Link>
                                    ) : (
                                        ret.customer.name
                                    )
                                ) : (
                                    'Consumidor final'
                                )}
                            </dd>
                        </div>
                        {ret.customer?.document && <Row label="Documento" value={ret.customer.document} />}
                        {ret.customer?.phone && <Row label="Teléfono" value={ret.customer.phone} />}
                        <div className="flex justify-between gap-2">
                            <dt className="text-[var(--muted)]">Venta</dt>
                            <dd className="text-right">
                                {ret.sale &&
                                    (can('sales.index.view') ? (
                                        <Link href={route('sales.show', ret.sale.id)} className="font-mono font-medium text-[var(--accent)] hover:underline">
                                            {ret.sale.number}
                                        </Link>
                                    ) : (
                                        <span className="font-mono">{ret.sale.number}</span>
                                    ))}
                                {ret.sale?.sold_at && <span className="block text-[var(--muted)]">{dateTime(ret.sale.sold_at)}</span>}
                            </dd>
                        </div>
                        {ret.order && (
                            <div className="flex justify-between gap-2">
                                <dt className="text-[var(--muted)]">Pedido</dt>
                                <dd className="text-right">
                                    {can('orders.show.view') ? (
                                        <Link href={route('orders.show', ret.order.id)} className="font-mono font-medium text-[var(--accent)] hover:underline">
                                            #{ret.order.number}
                                        </Link>
                                    ) : (
                                        <span className="font-mono">#{ret.order.number}</span>
                                    )}
                                </dd>
                            </div>
                        )}
                        {ret.reason && <Row label="Motivo" value={ret.reason} />}
                    </dl>
                    {ret.customer_note && (
                        <p className="mt-3 rounded-control bg-[var(--surface-2)] p-2.5 text-[0.78125rem]">
                            <span className="block text-[0.6875rem] font-semibold uppercase tracking-wide text-[var(--muted)]">El cliente dice</span>
                            {ret.customer_note}
                        </p>
                    )}
                </Card>

                <Card className="lg:col-span-2">
                    <CardHeader title="Valor y respuesta" description="Lo que vale lo devuelto, con IVA, y cómo se le respondió al cliente." />
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <Figure label="Valor devuelto" value={money(ret.total)} strong />
                        <Figure label={`Reembolsado${ret.refund_method_label ? ` · ${ret.refund_method_label}` : ''}`} value={ret.refunded_total > 0 ? money(ret.refunded_total) : '—'} />
                        <Figure label="Saldo a favor" value={ret.credited_total > 0 ? money(ret.credited_total) : '—'} />
                        <Figure label="Aplicado a cambio" value={ret.exchanged_total > 0 ? money(ret.exchanged_total) : '—'} />
                    </div>
                    <div className="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-[0.75rem] text-[var(--muted)]">
                        {ret.resolution_label && <span>Respuesta: {ret.resolution_label}</span>}
                        {ret.debt_relief_total > 0 && <span>Descontado de la cartera: {money(ret.debt_relief_total)}</span>}
                        {ret.refund_sale && (
                            <span>
                                Venta en negativo{' '}
                                {can('sales.index.view') ? (
                                    <Link href={route('sales.show', ret.refund_sale.id)} className="font-mono text-[var(--accent)] hover:underline">
                                        {ret.refund_sale.number}
                                    </Link>
                                ) : (
                                    <span className="font-mono">{ret.refund_sale.number}</span>
                                )}
                            </span>
                        )}
                        {ret.exchange_sale && (
                            <span>
                                Venta del cambio{' '}
                                {can('sales.index.view') ? (
                                    <Link href={route('sales.show', ret.exchange_sale.id)} className="font-mono text-[var(--accent)] hover:underline">
                                        {ret.exchange_sale.number}
                                    </Link>
                                ) : (
                                    <span className="font-mono">{ret.exchange_sale.number}</span>
                                )}
                            </span>
                        )}
                        {ret.customer && ret.customer.store_credit > 0 && <span>Saldo a favor disponible del cliente: {money(ret.customer.store_credit)}</span>}
                    </div>
                </Card>
            </div>

            <Card padded={false} className="mb-4">
                <Table className="rounded-none border-0">
                    <THead>
                        <TR>
                            <TH>Artículo</TH>
                            <TH>Motivo</TH>
                            <TH align="right">Cantidad</TH>
                            <TH>Destino</TH>
                            <TH align="right">Valor</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {topLines.map((item) => (
                            <ItemRows key={item.id} item={item} components={componentsOf(item.id)} />
                        ))}
                    </TBody>
                </Table>
            </Card>

            <div className="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader title="Fotos del cliente" />
                    {ret.photos.length === 0 ? (
                        <p className="text-[0.8125rem] text-[var(--muted)]">{ret.channel === 'online' ? 'No adjuntó fotos.' : 'Las devoluciones de caja no llevan fotos.'}</p>
                    ) : (
                        <div className="flex flex-wrap gap-2">
                            {ret.photos.map((url, index) => (
                                <a key={url} href={url} target="_blank" rel="noreferrer" className="block size-28 overflow-hidden rounded-control border border-[var(--line)]">
                                    <img src={url} alt={`Foto ${index + 1}`} className="h-full w-full object-cover" />
                                </a>
                            ))}
                        </div>
                    )}
                </Card>

                <Card>
                    <CardHeader title="Historial" />
                    <ol className="flex flex-col gap-3">
                        {ret.timeline.map((event, position) => (
                            <li key={position} className="flex gap-3">
                                <span className="mt-1.5 size-2 shrink-0 rounded-full bg-[var(--accent)]" />
                                <div className="text-[0.8125rem]">
                                    <p className="font-medium">{event.label}</p>
                                    <p className="text-[0.75rem] text-[var(--muted)]">
                                        {dateTime(event.at)}
                                        {event.by ? ` · ${event.by}` : ''}
                                    </p>
                                </div>
                            </li>
                        ))}
                    </ol>
                </Card>
            </div>

            <ConfirmDialog
                open={approving}
                onClose={() => setApproving(false)}
                onConfirm={() => post('customer_returns.approve', () => setApproving(false))}
                title="Aprobar la solicitud"
                message="Se le avisa al cliente que la devolución procede y que se recogerá o la puede llevar a la sede. La plata o el saldo se entregan al recibir y resolver."
                confirmLabel="Aprobar"
                tone="primary"
                loading={busy}
            />

            <ConfirmDialog
                open={cancelling}
                onClose={() => setCancelling(false)}
                onConfirm={() => post('customer_returns.cancel', () => setCancelling(false))}
                title="Anular la devolución"
                message="No se ha recibido nada, así que anularla no mueve inventario ni plata. Lo que apartaba de la venta vuelve a quedar disponible."
                confirmLabel="Anular"
                loading={busy}
            />

            <Modal
                open={rejecting}
                onClose={() => setRejecting(false)}
                title="No procede"
                description="No se mueve inventario ni plata. Si el cliente ya entregó la mercancía, se le devuelve."
                size="sm"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setRejecting(false)}>
                            Volver
                        </Button>
                        <Button variant="danger" loading={rejectForm.processing} onClick={submitReject}>
                            Rechazar
                        </Button>
                    </>
                }
            >
                <Textarea
                    label="Por qué no procede"
                    hint="El cliente lo lee en su correo y en la tienda."
                    required
                    maxLength={255}
                    value={rejectForm.data.reason}
                    error={rejectForm.errors.reason}
                    onChange={(event) => rejectForm.setData('reason', event.target.value)}
                />
            </Modal>

            <Modal
                open={receiving}
                onClose={() => setReceiving(false)}
                title="Recibir la mercancía"
                description="Cuánto llegó de cada artículo y a dónde va. Lo dañado o vencido se propone como avería."
                size="lg"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setReceiving(false)}>
                            Volver
                        </Button>
                        <Button loading={receiveForm.processing} onClick={submitReceive}>
                            Recibir · {money(Math.round(receivedValue))}
                        </Button>
                    </>
                }
            >
                <div className="flex flex-col divide-y divide-[var(--line)]">
                    {topLines.map((item) => {
                        const line = lineOf(item.id);

                        return (
                            <div key={item.id} className="py-3">
                                <div className="flex flex-wrap items-center gap-3">
                                    <div className="min-w-0 flex-1">
                                        <p className="text-[0.8125rem] font-medium">{item.description}</p>
                                        <p className="text-[0.71875rem] text-[var(--muted)]">
                                            Pidió {number(item.quantity)} · {item.reason_label}
                                        </p>
                                    </div>
                                    <label className="flex items-center gap-1.5 text-[0.75rem] text-[var(--muted)]">
                                        Llegaron
                                        <input
                                            type="number"
                                            min={0}
                                            max={item.quantity}
                                            step={item.is_service ? '1' : '0.001'}
                                            aria-label={`Cantidad recibida de ${item.description}`}
                                            value={line?.quantity ?? item.quantity}
                                            onChange={(event) => setLine(item.id, { quantity: Math.min(item.quantity, Math.max(0, Number(event.target.value) || 0)) })}
                                            className="h-9 w-20 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2 text-right font-mono text-[0.8125rem] tabular focus:border-[var(--accent)] focus:outline-none"
                                        />
                                    </label>
                                    {item.product_id && (
                                        <DispositionSelect
                                            label={`Destino de ${item.description}`}
                                            value={line?.disposition ?? null}
                                            options={dispositions.filter((option) => option.value !== 'none')}
                                            onChange={(value) => setLine(item.id, { disposition: value })}
                                        />
                                    )}
                                </div>
                                {componentsOf(item.id).map((component) => (
                                    <div key={component.id} className="mt-2 flex flex-wrap items-center gap-3 pl-4">
                                        <p className="min-w-0 flex-1 text-[0.78125rem] text-[var(--muted)]">
                                            · {number(component.quantity)} {component.description}
                                        </p>
                                        <DispositionSelect
                                            label={`Destino de ${component.description}`}
                                            value={lineOf(component.id)?.disposition ?? null}
                                            options={dispositions}
                                            onChange={(value) => setLine(component.id, { disposition: value })}
                                        />
                                    </div>
                                ))}
                            </div>
                        );
                    })}
                </div>
                {errorList.length > 0 && <p className="mt-2 text-[0.78125rem] font-medium text-[var(--danger)]">{errorList[0]}</p>}
            </Modal>

            <Modal
                open={resolving}
                onClose={() => setResolving(false)}
                title="Resolver la devolución"
                description={`Se devuelven ${money(ret.total)}. Lo que el cliente debía de esa venta a crédito se descuenta primero.`}
                size="sm"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setResolving(false)}>
                            Volver
                        </Button>
                        <Button loading={resolveForm.processing} onClick={submitResolve}>
                            Resolver
                        </Button>
                    </>
                }
            >
                <div className="flex flex-col gap-3">
                    <div className="grid grid-cols-2 gap-2">
                        {[
                            { value: 'refund', label: 'Reembolso', hint: 'Plata de vuelta' },
                            { value: 'store_credit', label: 'Saldo a favor', hint: canCredit ? 'Para su próxima compra' : 'Necesita un cliente identificado' },
                        ].map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                disabled={option.value === 'store_credit' && !canCredit}
                                onClick={() => resolveForm.setData('resolution', option.value)}
                                className={cn(
                                    'rounded-control border p-3 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-50',
                                    resolveForm.data.resolution === option.value
                                        ? 'border-[var(--accent)] bg-[var(--accent-soft)]'
                                        : 'border-[var(--line)] bg-[var(--surface-2)] hover:border-[var(--accent)]',
                                )}
                            >
                                <span className="block text-[0.8125rem] font-semibold">{option.label}</span>
                                <span className="block text-[0.71875rem] text-[var(--muted)]">{option.hint}</span>
                            </button>
                        ))}
                    </div>
                    {resolveForm.data.resolution === 'refund' && (
                        <Select
                            label="Medio"
                            required
                            options={refundMethods.filter((option) => option.value !== 'cash' || hasCashSession)}
                            value={resolveForm.data.method}
                            error={resolveForm.errors.method}
                            hint={hasCashSession ? 'Tarjeta y transferencia se reversan por fuera; aquí queda registrado.' : 'Para devolver en efectivo abre una caja en esta sede.'}
                            onChange={(event) => resolveForm.setData('method', event.target.value)}
                        />
                    )}
                    {errorList.length > 0 && <p className="text-[0.78125rem] font-medium text-[var(--danger)]">{errorList[0]}</p>}
                </div>
            </Modal>
        </AppLayout>
    );
}

function ItemRows({ item, components }: { item: CustomerReturnDetail['items'][number]; components: CustomerReturnDetail['items'] }) {
    return (
        <>
            <TR>
                <TD>
                    <p className="text-[0.8125rem] font-medium">{item.description}</p>
                    {item.is_service && <p className="text-[0.71875rem] text-[var(--muted)]">Servicio</p>}
                </TD>
                <TD className="text-[0.78125rem]">{item.reason_label}</TD>
                <TD align="right" className="font-mono text-[0.8125rem] tabular">
                    {number(item.quantity)}
                </TD>
                <TD>
                    {item.disposition ? (
                        <Badge tone={DISPOSITION_TONES[item.disposition]}>{item.disposition_label}</Badge>
                    ) : (
                        <span className="text-[0.75rem] text-[var(--muted)]">{item.product_id ? 'Se decide al recibir' : '—'}</span>
                    )}
                    {item.warehouse && <span className="block text-[0.6875rem] text-[var(--muted)]">{item.warehouse}</span>}
                </TD>
                <TD align="right" className="font-mono text-[0.8125rem] tabular">
                    {money(item.line_total)}
                </TD>
            </TR>
            {components.map((component) => (
                <TR key={component.id}>
                    <TD className="pl-8 text-[0.78125rem] text-[var(--muted)]">· {component.description}</TD>
                    <TD />
                    <TD align="right" className="font-mono text-[0.78125rem] tabular text-[var(--muted)]">
                        {number(component.quantity)}
                    </TD>
                    <TD>
                        {component.disposition ? (
                            <Badge tone={DISPOSITION_TONES[component.disposition]}>{component.disposition_label}</Badge>
                        ) : (
                            <span className="text-[0.75rem] text-[var(--muted)]">—</span>
                        )}
                    </TD>
                    <TD align="right" className="font-mono text-[0.78125rem] tabular text-[var(--muted)]">
                        {component.line_total !== 0 ? money(component.line_total) : 'incluido'}
                    </TD>
                </TR>
            ))}
        </>
    );
}

function DispositionSelect({ label, value, options, onChange }: { label: string; value: Disposition | null; options: Option[]; onChange: (value: Disposition) => void }) {
    return (
        <select
            aria-label={label}
            value={value ?? ''}
            onChange={(event) => onChange(event.target.value as Disposition)}
            className="h-9 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2 text-[0.78125rem] focus:border-[var(--accent)] focus:outline-none"
        >
            {options.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
        </select>
    );
}

function Row({ label, value }: { label: string; value: string | null }) {
    return (
        <div className="flex justify-between gap-2">
            <dt className="text-[var(--muted)]">{label}</dt>
            <dd className="text-right">{value ?? '—'}</dd>
        </div>
    );
}

function Figure({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
    return (
        <div>
            <p className="text-[0.75rem] text-[var(--muted)]">{label}</p>
            <p className={`font-mono tabular ${strong ? 'text-[1.375rem] font-semibold text-[var(--accent)]' : 'text-[1.0625rem] font-medium'}`}>{value}</p>
        </div>
    );
}

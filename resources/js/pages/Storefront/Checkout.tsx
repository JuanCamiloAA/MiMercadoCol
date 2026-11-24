import { router, useForm, usePage } from '@inertiajs/react';
import { TicketIcon, XIcon } from '@phosphor-icons/react';
import { type FormEvent, useEffect, useState } from 'react';
import { paymentIcon, type PresentedPaymentMethod } from '@/components/payments/payment-icons';
import StoreLayout from '@/layouts/StoreLayout';
import { cn } from '@/lib/cn';
import { money, number, UNIT_SHORT } from '@/lib/format';

interface SlotRow {
    key: string;
    label: string;
    day: string;
    remaining: number | null;
    full: boolean;
}

/** Los medios de la tienda como se muestran: en línea, al recibir y crédito. */
interface PaymentGroup {
    key: string;
    label: string;
    methods: PresentedPaymentMethod[];
}

interface CheckoutProps {
    store: { id: number; name: string };
    items: { id: number; kind?: 'product' | 'service'; name: string; quantity: number; unit: string; line_total: number; includes?: { name: string; label?: string; quantity: number; unit: string }[] }[];
    subtotal: number;
    deliveryFee: number;
    discount: number;
    total: number;
    cartCount: number;
    delivery: {
        enabled: boolean;
        eta_minutes: number;
        min_order_total: number;
        free_from: number;
        note: string | null;
        below_minimum: boolean;
    };
    zones: { index: number; name: string; fee: number; eta_minutes: number }[];
    zone: number | null;
    slots: SlotRow[];
    /** Los medios que valen en esta sede, por este monto y para este cliente (Medios de pago). */
    paymentGroups: PaymentGroup[];
    /** Los mismos, si el saldo a favor paga primero: el medio cubre menos y puede valer otro. */
    paymentGroupsWithStoreCredit: PaymentGroup[] | null;
    coupon: { code: string; discount: number; free_delivery: boolean } | null;
    customer: { name: string; phone: string | null; address: string | null; neighborhood: string | null; delivery_notes: string | null };
    /** Saldo a favor disponible por devoluciones. */
    storeCredit: number;
    credit: {
        enabled: boolean;
        blocked: boolean;
        blocked_reason: string | null;
        limit: number;
        balance: number;
        available: number;
        term_days: number;
        rejection: string | null;
    };
}

export default function StorefrontCheckout({
    store,
    items,
    subtotal,
    deliveryFee,
    discount,
    total,
    cartCount,
    delivery,
    zones,
    zone,
    slots,
    paymentGroups,
    paymentGroupsWithStoreCredit,
    coupon,
    customer,
    storeCredit,
    credit,
}: CheckoutProps) {
    const pageErrors = (usePage().props.errors ?? {}) as Record<string, string>;

    const { data, setData, post, processing, errors } = useForm({
        delivery_address: [customer.address, customer.neighborhood].filter(Boolean).join(', '),
        delivery_notes: customer.delivery_notes ?? '',
        zone: zone ?? (zones.length > 0 ? zones[0].index : null),
        slot: slots.find((slot) => !slot.full)?.key ?? '',
        payment_method_id: (paymentGroupsWithStoreCredit ?? paymentGroups)[0]?.methods[0]?.id ?? null,
        use_store_credit: storeCredit > 0,
    });

    const groups = data.use_store_credit && paymentGroupsWithStoreCredit ? paymentGroupsWithStoreCredit : paymentGroups;
    const methods = groups.flatMap((group) => group.methods);
    const selected = methods.find((method) => method.id === data.payment_method_id) ?? null;

    // Si con el saldo a favor (o sin el) el medio elegido deja de valer, se pasa al primero que valga.
    useEffect(() => {
        if (!selected && methods.length > 0) {
            setData('payment_method_id', methods[0].id);
        }
    }, [selected, methods, setData]);

    const [code, setCode] = useState('');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('storefront.checkout.store'));
    };

    const chooseZone = (index: number) => {
        setData('zone', index);
        // La tarifa la decide el servidor: aqui solo se avisa cual zona eligio.
        router.post(route('storefront.checkout.zone'), { zone: index }, { preserveScroll: true, preserveState: true });
    };

    // El saldo a favor paga primero; el medio elegido cubre el resto.
    const creditUsed = data.use_store_credit ? Math.min(storeCredit, total) : 0;
    const toPay = total - creditUsed;
    const creditBlocked = selected?.kind === 'credit' && toPay > 0 && credit.rejection !== null;
    const blocked = creditBlocked || delivery.below_minimum || methods.length === 0;
    const days = [...new Set(slots.map((slot) => slot.day))];

    return (
        <StoreLayout title="Pago" current="cart" cartCount={cartCount}>
            <h1 className="mb-3 text-[20px] md:text-[24px]">Confirmar el pedido</h1>

            {delivery.below_minimum && (
                <p className="mb-3 rounded-[12px] bg-[var(--danger-soft)] px-3 py-2.5 text-[13px] font-medium text-[var(--danger)]">
                    El pedido mínimo en esta sede es de {money(delivery.min_order_total)}. Agrega {money(delivery.min_order_total - subtotal)}{' '}
                    más para poder pedir.
                </p>
            )}

            <form onSubmit={submit} className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start">
                <div className="flex flex-col gap-4">
                    <section className="rounded-[14px] border border-[var(--line)] bg-[var(--surface)] p-4">
                        <h2 className="mb-3 text-[15px] font-semibold">Entrega</h2>

                        <label className="mb-1 block text-[12.5px] font-medium">Dirección</label>
                        <input
                            type="text"
                            value={data.delivery_address}
                            onChange={(event) => setData('delivery_address', event.target.value)}
                            className="mb-1 h-11 w-full rounded-[11px] border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[14px] focus:border-[var(--accent)] focus:outline-none"
                        />
                        {errors.delivery_address && <p className="mb-2 text-[12px] font-medium text-[var(--danger)]">{errors.delivery_address}</p>}

                        <label className="mb-1 mt-3 block text-[12.5px] font-medium">Indicaciones</label>
                        <textarea
                            value={data.delivery_notes}
                            onChange={(event) => setData('delivery_notes', event.target.value)}
                            placeholder="Torre, apartamento, punto de referencia"
                            className="min-h-[70px] w-full rounded-[11px] border border-[var(--line)] bg-[var(--surface-2)] px-3 py-2 text-[13.5px] focus:border-[var(--accent)] focus:outline-none"
                        />

                        {zones.length > 0 && (
                            <>
                                <p className="mb-2 mt-3 text-[12.5px] font-medium">Tu zona</p>
                                <div className="grid gap-2 sm:grid-cols-2">
                                    {zones.map((item) => (
                                        <button
                                            key={item.index}
                                            type="button"
                                            onClick={() => chooseZone(item.index)}
                                            className={cn(
                                                'flex min-h-[48px] flex-col items-start justify-center rounded-[11px] border px-3 py-1.5 text-left transition-colors',
                                                data.zone === item.index
                                                    ? 'border-[var(--accent)] bg-[var(--accent-soft)]'
                                                    : 'border-[var(--line)] bg-[var(--surface-2)]',
                                            )}
                                        >
                                            <span className="text-[13.5px] font-medium">{item.name}</span>
                                            <span className="text-[11.5px] text-[var(--muted)] tabular-nums">
                                                {item.fee === 0 ? 'Sin costo' : money(item.fee)}
                                                {item.eta_minutes > 0 && ` · ${item.eta_minutes} min`}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                                {errors.zone && <p className="mt-2 text-[12px] font-medium text-[var(--danger)]">{errors.zone}</p>}
                            </>
                        )}

                        {slots.length > 0 ? (
                            <>
                                <p className="mb-2 mt-3 text-[12.5px] font-medium">Franja de entrega</p>
                                {days.map((day) => (
                                    <div key={day} className="mb-2">
                                        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                            {slots
                                                .filter((slot) => slot.day === day)
                                                .map((slot) => (
                                                    <button
                                                        key={slot.key}
                                                        type="button"
                                                        disabled={slot.full}
                                                        onClick={() => setData('slot', slot.key)}
                                                        className={cn(
                                                            'flex min-h-[48px] flex-col items-start justify-center rounded-[11px] border px-3 py-1.5 text-left text-[13px] transition-colors',
                                                            slot.full
                                                                ? 'cursor-not-allowed border-[var(--line)] bg-[var(--surface-2)] text-[var(--muted)] opacity-60'
                                                                : data.slot === slot.key
                                                                  ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]'
                                                                  : 'border-[var(--line)] bg-[var(--surface-2)]',
                                                        )}
                                                    >
                                                        <span className="font-medium">{slot.label}</span>
                                                        <span className="text-[11.5px] text-[var(--muted)]">
                                                            {slot.full
                                                                ? 'Sin cupos'
                                                                : slot.remaining === null
                                                                  ? 'Disponible'
                                                                  : `Quedan ${slot.remaining}`}
                                                        </span>
                                                    </button>
                                                ))}
                                        </div>
                                    </div>
                                ))}
                            </>
                        ) : (
                            <p className="mt-3 rounded-[11px] bg-[var(--surface-2)] px-3 py-2 text-[12.5px] text-[var(--muted)]">
                                {delivery.eta_minutes > 0
                                    ? `Llegamos en unos ${delivery.eta_minutes} minutos.`
                                    : 'Te contactamos para acordar la entrega.'}
                            </p>
                        )}
                        {errors.slot && <p className="mt-2 text-[12px] font-medium text-[var(--danger)]">{errors.slot}</p>}

                        {delivery.note && <p className="mt-3 text-[12.5px] text-[var(--muted)]">{delivery.note}</p>}
                    </section>

                    <section className="rounded-[14px] border border-[var(--line)] bg-[var(--surface)] p-4">
                        <h2 className="mb-3 text-[15px] font-semibold">Como pagas</h2>

                        {methods.length === 0 ? (
                            <p className="rounded-[11px] bg-[var(--danger-soft)] px-3 py-2 text-[12.5px] font-medium text-[var(--danger)]">
                                Esta sede no tiene medios de pago habilitados. Escríbenos para tomar tu pedido.
                            </p>
                        ) : (
                            <div className="flex flex-col gap-3">
                                {groups.map((group) => (
                                    <div key={group.key}>
                                        {groups.length > 1 && <p className="mb-1.5 text-[12px] font-semibold uppercase tracking-wide text-[var(--muted)]">{group.label}</p>}
                                        <div className="flex flex-col gap-2">
                                            {group.methods.map((method) => {
                                                const disabled = method.kind === 'credit' && (!credit.enabled || credit.blocked);
                                                const MethodIcon = paymentIcon(method.icon);
                                                const active = data.payment_method_id === method.id;

                                                return (
                                                    <button
                                                        key={method.id}
                                                        type="button"
                                                        disabled={disabled}
                                                        onClick={() => setData('payment_method_id', method.id)}
                                                        aria-pressed={active}
                                                        className={cn(
                                                            'flex min-h-12 items-center gap-2.5 rounded-[11px] border px-3 py-2 text-left transition-colors',
                                                            disabled
                                                                ? 'cursor-not-allowed border-[var(--line)] bg-[var(--surface-2)] text-[var(--muted)] opacity-60'
                                                                : active
                                                                  ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]'
                                                                  : 'border-[var(--line)] bg-[var(--surface-2)]',
                                                            method.highlight && !active && !disabled && 'ring-1 ring-[var(--accent)]/40',
                                                        )}
                                                    >
                                                        <MethodIcon size={18} className="shrink-0" />
                                                        <span className="min-w-0 flex-1">
                                                            <span className="block text-[14px] font-medium">{method.name}</span>
                                                            {method.description && <span className="block text-[11.5px] text-[var(--muted)]">{method.description}</span>}
                                                        </span>
                                                        {disabled && <span className="ml-auto text-[11.5px]">no disponible</span>}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}

                        {selected?.instructions && (
                            <p className="mt-3 whitespace-pre-line rounded-[11px] bg-[var(--surface-2)] px-3 py-2 text-[12.5px] text-[var(--muted)]">{selected.instructions}</p>
                        )}

                        {selected?.kind === 'gateway_checkout' && toPay > 0 && (
                            <p className="mt-3 text-[12.5px] text-[var(--muted)]">
                                Al confirmar te llevamos a la pasarela para pagar {money(toPay)}. Apartamos tu pedido mientras pagas.
                            </p>
                        )}

                        {credit.enabled && selected?.kind === 'credit' && (
                            <dl className="mt-3 flex flex-col gap-1.5 rounded-[11px] border border-[var(--line)] bg-[var(--surface-2)] p-3 text-[13px]">
                                <Row label="Tu cupo" value={money(credit.limit)} />
                                <Row label="Deuda actual" value={money(credit.balance)} />
                                <Row label="Este pedido" value={money(toPay)} />
                                <div className="flex items-center justify-between border-t border-[var(--line)] pt-1.5">
                                    <dt className="font-medium">Nuevo saldo</dt>
                                    <dd className="font-semibold tabular-nums">{money(credit.balance + toPay)}</dd>
                                </div>
                                <Row label="Cupo restante" value={money(Math.max(0, credit.available - toPay))} />
                                <Row label="Plazo" value={`${credit.term_days} dias`} />
                            </dl>
                        )}

                        {creditBlocked && (
                            <p className="mt-2 rounded-[10px] bg-[var(--danger-soft)] px-3 py-2 text-[12.5px] font-medium text-[var(--danger)]">
                                {credit.rejection}
                            </p>
                        )}

                        {credit.blocked && credit.blocked_reason && (
                            <p className="mt-2 rounded-[10px] bg-[var(--danger-soft)] px-3 py-2 text-[12.5px] text-[var(--danger)]">
                                {credit.blocked_reason}
                            </p>
                        )}

                        {storeCredit > 0 && (
                            <label className="mt-3 flex cursor-pointer items-start gap-2.5 rounded-[11px] border border-[var(--accent)]/40 bg-[var(--accent-soft)] p-3 text-[13px]">
                                <input
                                    type="checkbox"
                                    className="mt-0.5 size-4 accent-[var(--accent)]"
                                    checked={data.use_store_credit}
                                    onChange={(event) => setData('use_store_credit', event.target.checked)}
                                />
                                <span>
                                    <span className="block font-semibold">Usar mi saldo a favor ({money(storeCredit)})</span>
                                    <span className="block text-[12px] text-[var(--muted)]">
                                        {toPay === 0 && data.use_store_credit
                                            ? 'Tu saldo cubre todo el pedido.'
                                            : `Se descuenta primero; el resto lo pagas con ${selected?.name.toLowerCase() ?? 'el medio que elijas'}.`}
                                    </span>
                                </span>
                            </label>
                        )}

                        {(errors.payment_method_id ?? pageErrors.payment_method) && (
                            <p className="mt-2 text-[12px] font-medium text-[var(--danger)]">{errors.payment_method_id ?? pageErrors.payment_method}</p>
                        )}
                    </section>
                </div>

                <section className="rounded-[14px] border border-[var(--line)] bg-[var(--surface)] p-4 lg:sticky lg:top-24">
                    <h2 className="mb-3 text-[15px] font-semibold">Tu pedido</h2>

                    <ul className="flex flex-col gap-1.5 text-[13px]">
                        {items.map((item) => (
                            <li key={item.id} className="flex items-start justify-between gap-3">
                                <span className="min-w-0">
                                    <span className="block truncate">{item.name}</span>
                                    <span className="text-[11.5px] text-[var(--muted)] tabular-nums">
                                        {number(item.quantity)} {item.kind === 'service' ? (item.quantity === 1 ? 'servicio' : 'servicios') : (UNIT_SHORT[item.unit] ?? '')}
                                    </span>
                                    {item.kind === 'service' && (item.includes ?? []).length > 0 && (
                                        <span className="block text-[11.5px] text-[var(--muted)]">
                                            Incluye: {(item.includes ?? []).map((part) => part.label ?? part.name).join(', ')}
                                        </span>
                                    )}
                                </span>
                                <span className="tabular-nums">{money(item.line_total)}</span>
                            </li>
                        ))}
                    </ul>

                    <div className="mt-3 border-t border-[var(--line)] pt-3">
                        {coupon ? (
                            <div className="flex items-center gap-2 rounded-[11px] bg-[var(--accent-soft)] px-3 py-2">
                                <TicketIcon size={16} weight="fill" className="text-[var(--accent)]" />
                                <span className="min-w-0 flex-1 truncate text-[13px] font-semibold text-[var(--accent)]">{coupon.code}</span>
                                <button
                                    type="button"
                                    onClick={() => router.delete(route('storefront.checkout.coupon.remove'), { preserveScroll: true })}
                                    aria-label="Quitar el cupón"
                                    className="grid size-8 place-items-center rounded-[8px] text-[var(--accent)] hover:bg-[var(--surface)]"
                                >
                                    <XIcon size={14} weight="bold" />
                                </button>
                            </div>
                        ) : (
                            <div className="flex gap-2">
                                <input
                                    type="text"
                                    value={code}
                                    onChange={(event) => setCode(event.target.value.toUpperCase())}
                                    placeholder="¿Tienes un cupón?"
                                    className="h-11 min-w-0 flex-1 rounded-[11px] border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[13.5px] focus:border-[var(--accent)] focus:outline-none"
                                />
                                <button
                                    type="button"
                                    disabled={code.trim() === ''}
                                    onClick={() =>
                                        router.post(
                                            route('storefront.checkout.coupon.apply'),
                                            { coupon: code },
                                            { preserveScroll: true, onSuccess: () => setCode('') },
                                        )
                                    }
                                    className="h-11 shrink-0 rounded-[11px] border border-[var(--line)] px-3 text-[13px] font-semibold disabled:opacity-40"
                                >
                                    Aplicar
                                </button>
                            </div>
                        )}
                        {pageErrors.coupon && <p className="mt-1.5 text-[12px] font-medium text-[var(--danger)]">{pageErrors.coupon}</p>}
                    </div>

                    <dl className="mt-3 flex flex-col gap-1.5 border-t border-[var(--line)] pt-3 text-[13.5px]">
                        <Row label="Productos" value={money(subtotal)} />
                        {discount > 0 && <Row label="Cupón" value={`− ${money(discount)}`} />}
                        <Row label="Domicilio" value={deliveryFee === 0 ? 'Gratis' : money(deliveryFee)} />
                        <div className="flex items-center justify-between border-t border-[var(--line)] pt-2">
                            <dt className="text-[15px] font-semibold">Total</dt>
                            <dd className="text-[22px] font-semibold text-[var(--accent)] tabular-nums">{money(total)}</dd>
                        </div>
                        {creditUsed > 0 && (
                            <>
                                <Row label="Saldo a favor" value={`− ${money(creditUsed)}`} />
                                <div className="flex items-center justify-between">
                                    <dt className="font-semibold">A pagar</dt>
                                    <dd className="font-semibold tabular-nums">{money(toPay)}</dd>
                                </div>
                            </>
                        )}
                    </dl>

                    {delivery.free_from > 0 && deliveryFee > 0 && (
                        <p className="mt-2 text-[12px] text-[var(--muted)]">
                            Domicilio gratis desde {money(delivery.free_from)}: te faltan {money(delivery.free_from - subtotal)}.
                        </p>
                    )}

                    <button
                        type="submit"
                        disabled={processing || blocked}
                        className={cn(
                            'mt-4 flex h-12 w-full items-center justify-center rounded-[13px] text-[15px] font-semibold transition-colors',
                            blocked ? 'cursor-not-allowed bg-[var(--surface-2)] text-[var(--muted)]' : 'bg-[var(--accent)] text-[var(--accent-ink)]',
                        )}
                    >
                        {selected?.kind === 'gateway_checkout' && toPay > 0 ? `Ir a pagar ${money(toPay)}` : 'Confirmar el pedido'}
                    </button>

                    <p className="mt-2 text-center text-[11.5px] text-[var(--muted)]">Te atiende la sede {store.name}</p>
                </section>
            </form>
        </StoreLayout>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-center justify-between">
            <dt className="text-[var(--muted)]">{label}</dt>
            <dd className="tabular-nums">{value}</dd>
        </div>
    );
}

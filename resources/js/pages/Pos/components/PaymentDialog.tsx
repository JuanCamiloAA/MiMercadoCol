import {
    MoneyIcon,
    ArrowUUpLeftIcon,
    ArrowsSplitIcon,
    CheckCircleIcon,
    CreditCardIcon,
    InfoIcon,
    LockKeyIcon,
    PencilSimpleIcon,
    TrashIcon,
    WalletIcon,
    WarningCircleIcon,
    XIcon,
    type Icon,
} from '@phosphor-icons/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Button } from '@/components/ui/Button';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';

export type PaymentMethod = 'cash' | 'card' | 'transfer' | 'credit' | 'store_credit' | 'exchange';

/** Como vive un pago mientras se arma el cobro. */
export interface PaymentLine {
    method: PaymentMethod;
    amount: number;
    reference?: string | null;
}

/** Como viaja al servidor, con los nombres que espera el endpoint. */
export interface PaymentPayload {
    method: PaymentMethod;
    amount: number;
    received_amount: number | null;
    reference: string | null;
}

interface DialogCustomer {
    id: number;
    name: string;
    credit_enabled: boolean;
    credit_blocked: boolean;
    blocked_reason?: string | null;
    credit_limit: number;
    balance: number;
    available_credit: number;
    credit_term_days: number;
    /** Saldo a favor por devoluciones: se gasta como un medio mas. */
    store_credit?: number;
}

interface PaymentDialogProps {
    open: boolean;
    onClose: () => void;
    /** Encabezado y linea de contexto. El cobro de un pedido no es una venta del mostrador. */
    title?: string;
    subtitle?: string;
    /** Medios habilitados. Contraentrega, por ejemplo, no admite credito. */
    allowedMethods?: PaymentMethod[];
    confirmLabel?: string;
    cancelLabel?: string;
    /** Total del tiquete, ya calculado por el servidor en cada cambio del carrito. */
    total: number;
    /** Escalon de redondeo del efectivo. El servidor aplica el mismo. */
    cashRounding: number;
    /** Cambia con cada venta confirmada: es la senal para empezar un cobro limpio. */
    ticketKey: number;
    ticketNumber: string;
    customer?: DialogCustomer | null;
    canSellOnCredit?: boolean;
    creditApprovedBy?: string;
    submitting: boolean;
    onRequestCreditApproval?: (message: string) => void;
    onConfirm: (payments: PaymentPayload[]) => void;
    /**
     * Pagos que ya vienen puestos y no se tocan: el valor de una devolucion en un cambio. Cuentan
     * para el total y viajan con los demas.
     */
    fixedPayments?: PaymentLine[];
    /** Las fichas a las que se le puede atribuir la venta. Vacio oculta el selector. */
    sellers?: { value: number; label: string; position: string | null }[];
    seller?: number | null;
    onSellerChange?: (seller: number | null) => void;
}

interface MethodMeta {
    label: string;
    hint: string;
    icon: Icon;
    initials: string;
}

const METHODS: Record<PaymentMethod, MethodMeta> = {
    cash: { label: 'Efectivo', hint: 'Genera cambio', icon: MoneyIcon, initials: 'EF' },
    card: { label: 'Tarjeta', hint: 'Debito o credito', icon: CreditCardIcon, initials: 'TA' },
    transfer: { label: 'Transferencia', hint: 'Nequi, Daviplata, PSE', icon: ArrowsSplitIcon, initials: 'TR' },
    credit: { label: 'Credito', hint: 'Se le fia al cliente', icon: LockKeyIcon, initials: 'CR' },
    store_credit: { label: 'Saldo a favor', hint: 'Por devoluciones', icon: WalletIcon, initials: 'SF' },
    exchange: { label: 'Cambio', hint: 'Lo que devolvió', icon: ArrowUUpLeftIcon, initials: 'CB' },
};

const ORDER: PaymentMethod[] = ['cash', 'card', 'transfer', 'credit', 'store_credit'];

const KEYPAD = ['7', '8', '9', '4', '5', '6', '1', '2', '3', '00', '0', 'C'];

/** Atajo de teclado por medio: el cajero no suelta el teclado para buscar el raton. */
const SHORTCUTS: Record<string, PaymentMethod> = { e: 'cash', t: 'card', r: 'transfer', c: 'credit' };

export default function PaymentDialog({
    open,
    onClose,
    title = 'Cobrar',
    subtitle,
    allowedMethods = ORDER,
    confirmLabel = 'Confirmar la venta',
    cancelLabel = 'Volver al tiquete',
    total,
    cashRounding,
    ticketKey,
    ticketNumber,
    customer = null,
    canSellOnCredit = false,
    creditApprovedBy,
    submitting,
    onRequestCreditApproval,
    onConfirm,
    sellers,
    seller,
    onSellerChange,
    fixedPayments,
}: PaymentDialogProps) {
    const [payments, setPayments] = useState<PaymentLine[]>([]);
    const [active, setActive] = useState<PaymentMethod | null>(null);
    const [buffer, setBuffer] = useState('');

    // Los pagos fijos (el valor devuelto en un cambio) no se editan ni se quitan.
    const fixedKey = JSON.stringify(fixedPayments ?? []);
    const isFixed = useCallback((method: PaymentMethod) => (fixedPayments ?? []).some((payment) => payment.method === method), [fixedPayments]);
    const storeCreditAvailable = customer?.store_credit ?? 0;

    // Cada venta confirmada empieza un cobro en blanco (con los fijos); cerrar la modal no borra nada.
    useEffect(() => {
        setPayments(JSON.parse(fixedKey) as PaymentLine[]);
        setActive(null);
        setBuffer('');
    }, [ticketKey, fixedKey]);

    // El servidor redondea el total solo cuando el cobro es todo en efectivo. La modal aplica
    // la misma regla, o el cajero asignaria un monto que el servidor despues no acepta.
    const allCash = payments.every((payment) => payment.method === 'cash');
    const payable = useMemo(
        () => (allCash && cashRounding > 1 ? Math.round(total / cashRounding) * cashRounding : total),
        [allCash, cashRounding, total],
    );
    const rounding = payable - total;

    const paid = useMemo(() => payments.reduce((sum, payment) => sum + payment.amount, 0), [payments]);
    const remaining = Math.max(0, payable - paid);
    const change = Math.max(0, paid - payable);
    const creditUsed = useMemo(() => payments.find((payment) => payment.method === 'credit')?.amount ?? 0, [payments]);
    const available = customer?.available_credit ?? 0;

    /** Lo que faltaria por cobrar si este medio no estuviera puesto. */
    const remainingExcluding = useCallback(
        (method: PaymentMethod) =>
            Math.max(0, payable - payments.filter((payment) => payment.method !== method).reduce((sum, payment) => sum + payment.amount, 0)),
        [payments, payable],
    );

    /** Tope de cada medio. El efectivo no tiene: lo que sobre es cambio. */
    const limitFor = useCallback(
        (method: PaymentMethod): number | null => {
            if (method === 'cash') {
                return null;
            }

            if (method === 'credit') {
                return Math.min(remainingExcluding('credit'), available);
            }

            if (method === 'store_credit') {
                return Math.min(remainingExcluding('store_credit'), storeCreditAvailable);
            }

            return remainingExcluding(method);
        },
        [remainingExcluding, available, storeCreditAvailable],
    );

    const creditBlockedReason = useMemo(() => {
        if (!allowedMethods.includes('credit')) {
            return 'Este cobro no admite credito.';
        }

        if (!customer) {
            return 'Elige un cliente antes de vender a credito.';
        }

        if (!customer.credit_enabled) {
            return 'Este cliente no tiene credito habilitado.';
        }

        if (customer.credit_blocked) {
            return customer.blocked_reason ?? 'El credito de este cliente esta bloqueado.';
        }

        if (available <= 0) {
            return 'El cliente no tiene cupo disponible.';
        }

        return null;
    }, [customer, available, allowedMethods]);

    const needsCreditApproval = creditUsed > 0 && !canSellOnCredit && !creditApprovedBy && onRequestCreditApproval !== undefined;

    const setAmount = useCallback(
        (method: PaymentMethod, amount: number) => {
            setPayments((current) => {
                const clean = Math.max(0, Math.round(amount));

                if (clean === 0) {
                    return current.filter((payment) => payment.method !== method);
                }

                const exists = current.some((payment) => payment.method === method);

                if (exists) {
                    return current.map((payment) => (payment.method === method ? { ...payment, amount: clean } : payment));
                }

                return [...current, { method, amount: clean, reference: null }];
            });
        },
        [],
    );

    /** Un toque asigna lo que falte, acotado al tope del medio. Ese es el camino rapido. */
    const chooseMethod = useCallback(
        (method: PaymentMethod) => {
            if (! allowedMethods.includes(method) || isFixed(method)) {
                return;
            }

            if (method === 'credit' && creditBlockedReason) {
                return;
            }

            setActive(method);
            setBuffer('');

            const already = payments.find((payment) => payment.method === method);

            if (already) {
                return;
            }

            const limit = limitFor(method);
            const proposed = limit === null ? remaining : Math.min(remaining, limit);

            if (proposed > 0) {
                setAmount(method, proposed);
            }
        },
        [payments, limitFor, remaining, setAmount, creditBlockedReason, allowedMethods, isFixed],
    );

    const removePayment = useCallback((method: PaymentMethod) => {
        setPayments((current) => current.filter((payment) => payment.method !== method));
        setActive((current) => (current === method ? null : current));
        setBuffer('');
    }, []);

    const typeDigits = useCallback(
        (digits: string) => {
            if (!active) {
                return;
            }

            const next = buffer + digits;
            const limit = limitFor(active);
            const value = Math.min(Number(next) || 0, limit ?? Number.MAX_SAFE_INTEGER);

            setBuffer(next);
            setAmount(active, value);
        },
        [active, buffer, limitFor, setAmount],
    );

    const clearAmount = useCallback(() => {
        if (!active) {
            return;
        }

        setBuffer('');
        setAmount(active, 0);
    }, [active, setAmount]);

    const backspace = useCallback(() => {
        if (!active) {
            return;
        }

        const next = buffer.slice(0, -1);
        setBuffer(next);
        setAmount(active, Number(next) || 0);
    }, [active, buffer, setAmount]);

    const setReference = useCallback((method: PaymentMethod, reference: string) => {
        setPayments((current) => current.map((payment) => (payment.method === method ? { ...payment, reference } : payment)));
    }, []);

    // Si cambia el tiquete con la modal abierta, ningun medio puede quedar por encima de su tope.
    useEffect(() => {
        setPayments((current) => {
            let touched = false;

            const clamped = current.map((payment) => {
                if (payment.method === 'cash' || payment.method === 'exchange') {
                    return payment;
                }

                const others = current
                    .filter((other) => other.method !== payment.method)
                    .reduce((sum, other) => sum + other.amount, 0);
                const ceiling =
                    payment.method === 'credit'
                        ? Math.min(Math.max(0, payable - others), available)
                        : payment.method === 'store_credit'
                          ? Math.min(Math.max(0, payable - others), storeCreditAvailable)
                          : Math.max(0, payable - others);

                if (payment.amount > ceiling) {
                    touched = true;

                    return { ...payment, amount: ceiling };
                }

                return payment;
            });

            return touched ? clamped.filter((payment) => payment.amount > 0) : current;
        });
    }, [payable, available, storeCreditAvailable]);

    const canConfirm = payments.length > 0 && paid >= payable && creditUsed <= available && !needsCreditApproval && payable > 0;

    const confirm = useCallback(() => {
        if (!canConfirm || submitting) {
            return;
        }

        onConfirm(
            payments.map((payment) => ({
                method: payment.method,
                amount: payment.amount,
                received_amount: payment.method === 'cash' ? payment.amount : null,
                reference: payment.reference && payment.reference !== '' ? payment.reference : null,
            })),
        );
    }, [canConfirm, submitting, onConfirm, payments]);

    // --- Teclado fisico ----------------------------------------------------

    useEffect(() => {
        if (!open) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;

            if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA')) {
                return;
            }

            if (event.key >= '0' && event.key <= '9') {
                event.preventDefault();
                typeDigits(event.key);

                return;
            }

            if (event.key === 'Backspace') {
                event.preventDefault();
                backspace();

                return;
            }

            if (event.key === 'Enter') {
                event.preventDefault();
                confirm();

                return;
            }

            const method = SHORTCUTS[event.key.toLowerCase()];

            if (method) {
                event.preventDefault();
                chooseMethod(method);
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [open, typeDigits, backspace, confirm, chooseMethod]);

    if (!open) {
        return null;
    }

    const notice = buildNotice({
        payments: payments.length,
        remaining,
        change,
        creditUsed,
        available,
        needsCreditApproval,
    });

    // «Exacto» es lo que le toca al medio activo, no lo que falte: si ese medio ya tiene el
    // total asignado, lo que falta es cero y el primer billete quedaria rotulado «Exacto».
    const exact = active ? remainingExcluding(active) : remaining;
    const quickAmounts = [
        { value: exact, exact: true },
        ...[20000, 50000, 100000].map((value) => ({ value, exact: false })),
    ].filter((option, index, array) => option.value > 0 && array.findIndex((other) => other.value === option.value) === index);

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-label="Cobrar">
            <div className="absolute inset-0" style={{ backgroundColor: 'rgba(6,10,12,.66)' }} onClick={onClose} />

            <div className="relative flex max-h-[94vh] w-[61.25rem] max-w-full flex-col overflow-hidden rounded-sheet border border-[var(--line)] bg-[var(--surface)] shadow-2xl">
                <header className="flex shrink-0 items-start gap-4 border-b border-[var(--line)] px-6 py-[1.125rem]">
                    <div className="min-w-0 flex-1">
                        <h2 className="font-display text-[1.3125rem] font-bold leading-tight text-[var(--text)]">{title}</h2>
                        <p className="mt-0.5 truncate text-[0.78125rem] text-[var(--muted)]">
                            {subtitle ?? `Tiquete ${ticketNumber}${customer ? ` · ${customer.name}` : ' · Consumidor final'}`} · puedes
                            combinar varios medios
                        </p>
                    </div>

                    <div className="shrink-0 text-right">
                        <p className="text-[0.71875rem] uppercase tracking-wide text-[var(--muted)]">Total a cobrar</p>
                        <p className="font-mono text-[1.875rem] font-semibold leading-none tabular text-[var(--text)]">{money(payable)}</p>
                    </div>

                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Cerrar"
                        className="grid size-9 shrink-0 place-items-center rounded-control text-[var(--muted)] transition-colors hover:bg-[var(--surface-2)] hover:text-[var(--text)]"
                    >
                        <XIcon size={18} />
                    </button>
                </header>

                <div className="flex min-h-0 flex-1 flex-col overflow-y-auto lg:flex-row lg:overflow-hidden">
                    <section className="flex w-full shrink-0 flex-col gap-4 border-b border-[var(--line)] p-5 lg:w-[35.375rem] lg:overflow-y-auto lg:border-b-0 lg:border-r">
                        <div>
                            <p className="mb-2 text-[0.6875rem] font-semibold uppercase tracking-wider text-[var(--muted)]">
                                Paso 1 · Medios de pago
                            </p>
                            <div className="grid grid-cols-2 gap-2">
                                {ORDER.filter((method) => allowedMethods.includes(method) && (method !== 'store_credit' || storeCreditAvailable > 0)).map((method) => {
                                    const meta = METHODS[method];
                                    const MethodIcon = meta.icon;
                                    const line = payments.find((payment) => payment.method === method);
                                    const disabled = method === 'credit' && creditBlockedReason !== null;
                                    const selected = active === method;

                                    return (
                                        <button
                                            key={method}
                                            type="button"
                                            onClick={() => chooseMethod(method)}
                                            disabled={disabled}
                                            aria-disabled={disabled}
                                            title={disabled ? (creditBlockedReason ?? undefined) : meta.hint}
                                            className={cn(
                                                'flex h-[4.375rem] items-center gap-3 rounded-card border px-3 text-left transition-colors',
                                                disabled && 'cursor-not-allowed opacity-45',
                                                !disabled && selected && 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]',
                                                !disabled && !selected && line && 'border-[var(--accent)] bg-[var(--surface-2)]',
                                                !disabled && !selected && !line && 'border-[var(--line-2)] bg-[var(--surface-2)] hover:border-[var(--line)]',
                                            )}
                                        >
                                            <span
                                                className={cn(
                                                    'grid size-[2.125rem] shrink-0 place-items-center rounded-control',
                                                    line ? 'bg-[var(--accent)] text-[var(--accent-ink)]' : 'bg-[var(--surface-3)] text-[var(--muted)]',
                                                )}
                                            >
                                                <MethodIcon size={18} weight={line ? 'fill' : 'regular'} />
                                            </span>

                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-[0.875rem] font-semibold">{meta.label}</span>
                                                <span className="block truncate text-[0.6875rem] text-[var(--muted)]">
                                                    {disabled ? creditBlockedReason : method === 'store_credit' ? `Tiene ${money(storeCreditAvailable)}` : meta.hint}
                                                </span>
                                            </span>

                                            <span
                                                className={cn(
                                                    'shrink-0 font-mono text-[0.8125rem] tabular',
                                                    line ? 'font-semibold text-[var(--text)]' : 'text-[var(--muted)]',
                                                )}
                                            >
                                                {line ? money(line.amount) : 'Asignar'}
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                        </div>

                        <div className={cn('flex flex-col gap-2.5', !active && 'opacity-45')}>
                            <p className="text-[0.6875rem] font-semibold uppercase tracking-wider text-[var(--muted)]">
                                Paso 2 · Monto {active ? `· ${METHODS[active].label}` : ''}
                            </p>

                            <div className="flex items-center justify-between rounded-card border border-[var(--accent)] bg-[var(--surface-2)] px-4 py-3">
                                <span className="text-[0.84375rem] font-medium text-[var(--text)]">
                                    {active ? METHODS[active].label : 'Elige un medio'}
                                </span>
                                <span className="font-mono text-[1.375rem] font-semibold tabular text-[var(--accent)]">
                                    {money(active ? (payments.find((payment) => payment.method === active)?.amount ?? 0) : 0)}
                                </span>
                            </div>

                            <p className="text-[0.71875rem] text-[var(--muted)]">
                                {active === null
                                    ? 'Toca un medio de pago para asignarle un monto.'
                                    : limitFor(active) === null
                                      ? 'Sin tope: lo que exceda el total sale como cambio.'
                                      : `Maximo ${money(limitFor(active) ?? 0)}`}
                            </p>

                            {(active === 'card' || active === 'transfer') && (
                                <input
                                    type="text"
                                    maxLength={60}
                                    value={payments.find((payment) => payment.method === active)?.reference ?? ''}
                                    onChange={(event) => setReference(active, event.target.value)}
                                    placeholder="Voucher o referencia"
                                    className="h-11 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 font-mono text-[0.875rem] focus:border-[var(--accent)] focus:outline-none"
                                />
                            )}

                            <div className="flex flex-wrap gap-1.5">
                                {quickAmounts.map(({ value: amount, exact: isExact }) => (
                                    <button
                                        key={`${amount}-${isExact ? 'exact' : 'bill'}`}
                                        type="button"
                                        disabled={!active}
                                        onClick={() => {
                                            if (!active) {
                                                return;
                                            }
                                            const limit = limitFor(active);
                                            setBuffer(String(Math.min(amount, limit ?? amount)));
                                            setAmount(active, Math.min(amount, limit ?? amount));
                                        }}
                                        className="h-11 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 font-mono text-[0.8125rem] tabular transition-colors enabled:hover:bg-[var(--surface-3)] disabled:cursor-not-allowed"
                                    >
                                        {isExact ? 'Exacto' : money(amount)}
                                    </button>
                                ))}
                            </div>

                            <div className="grid grid-cols-3 gap-1.5">
                                {KEYPAD.map((key) => (
                                    <button
                                        key={key}
                                        type="button"
                                        disabled={!active}
                                        onClick={() => (key === 'C' ? clearAmount() : typeDigits(key))}
                                        className="h-[3.25rem] rounded-control border border-[var(--line)] bg-[var(--surface-2)] font-mono text-[1.0625rem] transition-colors enabled:hover:bg-[var(--surface-3)] disabled:cursor-not-allowed"
                                    >
                                        {key}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </section>

                    <section className="flex min-w-0 flex-1 flex-col gap-3 p-5 lg:overflow-y-auto">
                        <div className="flex items-baseline justify-between">
                            <p className="text-[0.6875rem] font-semibold uppercase tracking-wider text-[var(--muted)]">Pagos aplicados</p>
                            <span className="font-mono text-[0.71875rem] tabular text-[var(--muted)]">
                                {payments.length} de {allowedMethods.length}
                            </span>
                        </div>

                        {payments.length === 0 ? (
                            <div className="grid place-items-center rounded-card border border-dashed border-[var(--line-2)] px-4 py-7 text-center">
                                <p className="text-[0.78125rem] text-[var(--muted)]">
                                    Todavia no hay pagos.
                                    <br />
                                    Toca un medio y se le asigna lo que falta.
                                </p>
                            </div>
                        ) : (
                            <ul className="flex flex-col gap-1.5">
                                {payments.map((payment) => {
                                    const meta = METHODS[payment.method];
                                    const isCash = payment.method === 'cash';

                                    return (
                                        <li
                                            key={payment.method}
                                            className={cn(
                                                'flex items-center gap-2.5 rounded-card border bg-[var(--surface-2)] px-3 py-2.5',
                                                active === payment.method ? 'border-[var(--accent)]' : 'border-[var(--line)]',
                                            )}
                                        >
                                            <span className="grid size-8 shrink-0 place-items-center rounded-control bg-[var(--surface-3)] font-mono text-[0.6875rem] font-semibold text-[var(--muted)]">
                                                {meta.initials}
                                            </span>

                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-[0.84375rem] font-medium text-[var(--text)]">{meta.label}</span>
                                                <span className="block truncate text-[0.71875rem] text-[var(--muted)]">
                                                    {isCash && change > 0
                                                        ? `Cambio ${money(change)}`
                                                        : payment.reference
                                                          ? `Ref. ${payment.reference}`
                                                          : meta.hint}
                                                </span>
                                            </span>

                                            <span className="shrink-0 font-mono text-[0.9375rem] font-semibold tabular text-[var(--text)]">
                                                {money(payment.amount)}
                                            </span>

                                            {!isFixed(payment.method) && (
                                                <>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setActive(payment.method);
                                                    setBuffer('');
                                                }}
                                                aria-label={`Editar ${meta.label}`}
                                                className="grid size-8 shrink-0 place-items-center rounded-chip text-[var(--muted)] transition-colors hover:bg-[var(--surface-3)] hover:text-[var(--text)]"
                                            >
                                                <PencilSimpleIcon size={15} />
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => removePayment(payment.method)}
                                                aria-label={`Quitar ${meta.label}`}
                                                className="grid size-8 shrink-0 place-items-center rounded-chip text-[var(--muted)] transition-colors hover:bg-[var(--danger-soft)] hover:text-[var(--danger)]"
                                            >
                                                <TrashIcon size={15} />
                                            </button>
                                                </>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        )}

                        <div className="mt-1 rounded-card border border-[var(--line)] bg-[var(--surface-2)] p-4">
                            {rounding !== 0 && (
                                <>
                                    <div className="flex items-baseline justify-between text-[0.8125rem]">
                                        <span className="text-[var(--muted)]">Tiquete</span>
                                        <span className="font-mono tabular text-[var(--text)]">{money(total)}</span>
                                    </div>
                                    <div className="mt-1 flex items-baseline justify-between text-[0.8125rem]">
                                        <span className="text-[var(--warn)]">Ajuste al redondeo</span>
                                        <span className="font-mono tabular text-[var(--warn)]">
                                            {rounding > 0 ? '+' : '−'} {money(Math.abs(rounding))}
                                        </span>
                                    </div>
                                </>
                            )}
                            <div className="mt-1 flex items-baseline justify-between text-[0.8125rem]">
                                <span className="text-[var(--muted)]">Total</span>
                                <span className="font-mono tabular text-[var(--text)]">{money(payable)}</span>
                            </div>
                            <div className="mt-1 flex items-baseline justify-between text-[0.8125rem]">
                                <span className="text-[var(--muted)]">Pagado</span>
                                <span className="font-mono tabular text-[var(--text)]">{money(paid)}</span>
                            </div>
                            <div className="mt-2 flex items-baseline justify-between border-t border-[var(--line)] pt-2">
                                <span className="text-[0.84375rem] font-semibold text-[var(--text)]">
                                    {remaining > 0 ? 'Falta por cobrar' : 'Cambio a entregar'}
                                </span>
                                <span
                                    className="font-mono text-[1.625rem] font-semibold tabular"
                                    style={{ color: remaining > 0 ? 'var(--warn)' : 'var(--accent)' }}
                                >
                                    {money(remaining > 0 ? remaining : change)}
                                </span>
                            </div>
                        </div>

                        {creditUsed > 0 && customer && (
                            <div
                                className={cn(
                                    'rounded-card border p-4',
                                    creditUsed > available
                                        ? 'border-[var(--danger)]/50 bg-[var(--danger-soft)]'
                                        : 'border-[var(--line)] bg-[var(--surface-2)]',
                                )}
                            >
                                <p
                                    className="mb-2 text-[0.75rem] font-semibold uppercase tracking-wide"
                                    style={{ color: creditUsed > available ? 'var(--danger)' : 'var(--muted)' }}
                                >
                                    {creditUsed > available ? 'Supera el cupo aprobado' : 'Credito del cliente'}
                                </p>
                                <dl className="flex flex-col gap-1.5 text-[0.78125rem]">
                                    <div className="flex justify-between">
                                        <dt className="text-[var(--muted)]">Deuda actual</dt>
                                        <dd className="font-mono tabular">{money(customer.balance)}</dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-[var(--muted)]">A credito en esta venta</dt>
                                        <dd className="font-mono tabular">{money(creditUsed)}</dd>
                                    </div>
                                    <div className="flex justify-between border-t border-[var(--line)] pt-1.5">
                                        <dt className="font-medium">Nuevo saldo</dt>
                                        <dd className="font-mono font-semibold tabular">{money(customer.balance + creditUsed)}</dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-[var(--muted)]">Cupo disponible despues</dt>
                                        <dd
                                            className="font-mono tabular"
                                            style={{ color: available - creditUsed < 0 ? 'var(--danger)' : 'var(--accent)' }}
                                        >
                                            {money(Math.max(0, available - creditUsed))}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-[var(--muted)]">Plazo</dt>
                                        <dd className="font-mono tabular">{customer.credit_term_days} dias</dd>
                                    </div>
                                </dl>
                            </div>
                        )}

                        <p
                            className="flex items-start gap-2 rounded-control px-3 py-2 text-[0.78125rem] font-medium"
                            style={{ color: notice.color, backgroundColor: notice.background }}
                        >
                            <notice.icon size={16} weight="fill" className="mt-px shrink-0" />
                            {notice.text}
                        </p>

                        {needsCreditApproval && (
                            <Button
                                variant="warn"
                                icon={<LockKeyIcon size={16} />}
                                onClick={() => onRequestCreditApproval?.('Esta venta lleva credito y el cajero no tiene ese permiso.')}
                            >
                                Autorizar con PIN de supervisor
                            </Button>
                        )}

                        {creditUsed > 0 && creditApprovedBy && (
                            <p className="rounded-control border border-[var(--accent)]/30 bg-[var(--accent-soft)] px-3 py-2 text-[0.75rem] font-medium text-[var(--accent)]">
                                Autorizado por {creditApprovedBy}.
                            </p>
                        )}

                        {/*
                          * Quien vendio.
                          *
                          * Viene puesto en quien abrio la caja, que es lo normal; se cambia
                          * cuando el tiquete lo hizo otro asesor. Va aqui y no antes porque es
                          * el ultimo dato que se sabe con certeza: al cerrar, no al empezar.
                          */}
                        {sellers && sellers.length > 0 && (
                            <label className="flex flex-col gap-1.5">
                                <span className="text-[0.78125rem] font-medium text-[var(--muted)]">Vendedor</span>
                                <select
                                    value={seller ?? ''}
                                    onChange={(event) => onSellerChange?.(event.target.value === '' ? null : Number(event.target.value))}
                                    className="h-12 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.875rem] focus:border-[var(--accent)] focus:outline-none"
                                >
                                    <option value="">Sin asignar</option>
                                    {sellers.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                            {option.position ? ` · ${option.position}` : ''}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}

                        <div className="mt-auto flex gap-2 pt-2">
                            <Button variant="secondary" size="pos" className="flex-1" onClick={onClose}>
                                {cancelLabel}
                            </Button>
                            <Button size="pos" className="flex-1" disabled={!canConfirm} loading={submitting} onClick={confirm}>
                                {confirmLabel}
                            </Button>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    );
}

interface Notice {
    text: string;
    color: string;
    background: string;
    icon: Icon;
}

/** Una sola linea que dice por que no se puede confirmar, o que ya se puede. */
function buildNotice(state: {
    payments: number;
    remaining: number;
    change: number;
    creditUsed: number;
    available: number;
    needsCreditApproval: boolean;
}): Notice {
    if (state.creditUsed > state.available) {
        return {
            text: `El credito supera el cupo disponible (${money(state.available)}). Baja el monto o pide autorizacion.`,
            color: 'var(--danger)',
            background: 'var(--danger-soft)',
            icon: WarningCircleIcon,
        };
    }

    if (state.payments === 0) {
        return {
            text: 'Agrega al menos un medio de pago para poder confirmar.',
            color: 'var(--muted)',
            background: 'var(--surface-2)',
            icon: InfoIcon,
        };
    }

    if (state.remaining > 0) {
        return {
            text: `Faltan ${money(state.remaining)} por asignar. Puedes repartirlos en otro medio de pago.`,
            color: 'var(--warn)',
            background: 'var(--warn-soft)',
            icon: WarningCircleIcon,
        };
    }

    if (state.needsCreditApproval) {
        return {
            text: 'Falta la autorizacion del supervisor para dejar parte de la venta a credito.',
            color: 'var(--warn)',
            background: 'var(--warn-soft)',
            icon: LockKeyIcon,
        };
    }

    if (state.change > 0) {
        return {
            text: `Entrega ${money(state.change)} de cambio y confirma la venta.`,
            color: 'var(--accent)',
            background: 'var(--accent-soft)',
            icon: CheckCircleIcon,
        };
    }

    return {
        text: 'El pago cuadra con el total. Confirma para imprimir el tiquete.',
        color: 'var(--accent)',
        background: 'var(--accent-soft)',
        icon: CheckCircleIcon,
    };
}

import {
    ArrowUUpLeftIcon,
    CheckCircleIcon,
    InfoIcon,
    LockKeyIcon,
    PencilSimpleIcon,
    QrCodeIcon,
    TrashIcon,
    WarningCircleIcon,
    XIcon,
    type Icon,
} from '@phosphor-icons/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { GATEWAY_KINDS, paymentIcon, type PaymentKind, type PresentedPaymentMethod } from '@/components/payments/payment-icons';
import { Button } from '@/components/ui/Button';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';

export type { PaymentKind };

/** Como vive un pago mientras se arma el cobro. `key` es el id del medio, o `exchange`. */
export interface PaymentLine {
    key: string;
    method_id: number | null;
    kind: PaymentKind;
    name: string;
    amount: number;
    reference?: string | null;
}

/** Como viaja al servidor, con los nombres que espera el endpoint. */
export type PaymentPayload = {
    payment_method_id: number | null;
    method: PaymentKind;
    amount: number;
    received_amount: number | null;
    reference: string | null;
};

/** El renglon que se cobra por la pasarela: la caja abre el QR o el datafono, no la venta. */
export interface GatewayRequest {
    payment_method_id: number;
    amount: number;
    mode: 'qr' | 'link' | 'terminal';
    terminal_id: number | null;
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
    /** El consumidor final generico no cuenta como cliente registrado. */
    is_generic?: boolean;
}

interface PaymentDialogProps {
    open: boolean;
    onClose: () => void;
    /** Encabezado y linea de contexto. El cobro de un pedido no es una venta del mostrador. */
    title?: string;
    subtitle?: string;
    /** Los medios que valen aqui y ahora, ya resueltos por el servidor. */
    methods: PresentedPaymentMethod[];
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
    /** Con un renglon de pasarela no se confirma la venta: se abre el cobro (QR, link o datafono). */
    onStartGateway?: (payments: PaymentPayload[], gateway: GatewayRequest) => void;
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

const KEYPAD = ['7', '8', '9', '4', '5', '6', '1', '2', '3', '00', '0', 'C'];

/** Atajo de teclado por tipo: el primer medio de ese tipo. El cajero no suelta el teclado. */
const SHORTCUTS: Record<string, PaymentKind> = { e: 'cash', t: 'card', r: 'transfer', c: 'credit', q: 'gateway_link' };

const INITIALS: Record<PaymentKind, string> = {
    cash: 'EF',
    card: 'TA',
    transfer: 'TR',
    credit: 'CR',
    store_credit: 'SF',
    exchange: 'CB',
    gateway_checkout: 'PL',
    gateway_link: 'QR',
    gateway_terminal: 'DI',
    other: 'OT',
};

const isGateway = (kind: PaymentKind) => GATEWAY_KINDS.includes(kind);

export default function PaymentDialog({
    open,
    onClose,
    title = 'Cobrar',
    subtitle,
    methods,
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
    onStartGateway,
    sellers,
    seller,
    onSellerChange,
    fixedPayments,
}: PaymentDialogProps) {
    const [payments, setPayments] = useState<PaymentLine[]>([]);
    const [active, setActive] = useState<string | null>(null);
    const [buffer, setBuffer] = useState('');
    const [gatewayMode, setGatewayMode] = useState<'qr' | 'link'>('qr');
    const [terminalId, setTerminalId] = useState<number | null>(null);

    // Los pagos fijos (el valor devuelto en un cambio) no se editan ni se quitan.
    const fixedKey = JSON.stringify(fixedPayments ?? []);
    const isFixed = useCallback((key: string) => (fixedPayments ?? []).some((payment) => payment.key === key), [fixedPayments]);
    const storeCreditAvailable = customer?.store_credit ?? 0;
    const byKey = useMemo(() => new Map(methods.map((method) => [String(method.id), method])), [methods]);

    // Cada venta confirmada empieza un cobro en blanco (con los fijos); cerrar la modal no borra nada.
    useEffect(() => {
        setPayments(JSON.parse(fixedKey) as PaymentLine[]);
        setActive(null);
        setBuffer('');
    }, [ticketKey, fixedKey]);

    // El servidor redondea el total solo cuando el cobro es todo en efectivo. La modal aplica
    // la misma regla, o el cajero asignaria un monto que el servidor despues no acepta.
    const allCash = payments.every((payment) => payment.kind === 'cash');
    const payable = useMemo(
        () => (allCash && cashRounding > 1 ? Math.round(total / cashRounding) * cashRounding : total),
        [allCash, cashRounding, total],
    );
    const rounding = payable - total;

    const paid = useMemo(() => payments.reduce((sum, payment) => sum + payment.amount, 0), [payments]);
    const remaining = Math.max(0, payable - paid);
    const change = Math.max(0, paid - payable);
    const creditUsed = useMemo(() => payments.filter((payment) => payment.kind === 'credit').reduce((sum, payment) => sum + payment.amount, 0), [payments]);
    const available = customer?.available_credit ?? 0;
    const gatewayLine = payments.find((payment) => isGateway(payment.kind)) ?? null;

    /** Lo que faltaria por cobrar si este medio no estuviera puesto. */
    const remainingExcluding = useCallback(
        (key: string) => Math.max(0, payable - payments.filter((payment) => payment.key !== key).reduce((sum, payment) => sum + payment.amount, 0)),
        [payments, payable],
    );

    /** Por que un medio no se puede usar ahora con este cliente, o null si se puede. */
    const blockedReason = useCallback(
        (method: PresentedPaymentMethod): string | null => {
            if (method.kind === 'credit') {
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
            }

            if (method.kind === 'store_credit' && storeCreditAvailable <= 0) {
                return 'El cliente no tiene saldo a favor.';
            }

            if (method.requires_customer && (!customer || customer.is_generic)) {
                return 'Pide un cliente registrado.';
            }

            if (method.requires_credit_line && (!customer || !customer.credit_enabled || customer.credit_blocked)) {
                return 'Pide un cliente con cupo de crédito.';
            }

            if (method.requires_store_credit && storeCreditAvailable <= 0) {
                return 'Pide un cliente con saldo a favor.';
            }

            if (isGateway(method.kind) && gatewayLine && gatewayLine.key !== String(method.id)) {
                return 'Un cobro admite un solo pago por pasarela.';
            }

            if (method.kind === 'gateway_terminal' && (method.terminals ?? []).length === 0) {
                return 'No hay un datáfono para esta caja.';
            }

            return null;
        },
        [customer, available, storeCreditAvailable, gatewayLine],
    );

    /** Tope de cada medio. El efectivo no tiene: lo que sobre es cambio. */
    const limitFor = useCallback(
        (key: string): number | null => {
            const method = byKey.get(key);
            const kind = method?.kind ?? payments.find((payment) => payment.key === key)?.kind;

            if (kind === 'cash') {
                return method?.max_amount ?? null;
            }

            let limit = remainingExcluding(key);

            if (kind === 'credit') {
                limit = Math.min(limit, available);
            }

            if (kind === 'store_credit') {
                limit = Math.min(limit, storeCreditAvailable);
            }

            return method?.max_amount ? Math.min(limit, method.max_amount) : limit;
        },
        [byKey, payments, remainingExcluding, available, storeCreditAvailable],
    );

    const needsCreditApproval = creditUsed > 0 && !canSellOnCredit && !creditApprovedBy && onRequestCreditApproval !== undefined;

    const setAmount = useCallback(
        (key: string, amount: number) => {
            setPayments((current) => {
                const clean = Math.max(0, Math.round(amount));

                if (clean === 0) {
                    return current.filter((payment) => payment.key !== key);
                }

                if (current.some((payment) => payment.key === key)) {
                    return current.map((payment) => (payment.key === key ? { ...payment, amount: clean } : payment));
                }

                const method = byKey.get(key);

                if (!method) {
                    return current;
                }

                return [...current, { key, method_id: method.id, kind: method.kind, name: method.name, amount: clean, reference: null }];
            });
        },
        [byKey],
    );

    /** Un toque asigna lo que falte, acotado al tope del medio. Ese es el camino rapido. */
    const chooseMethod = useCallback(
        (method: PresentedPaymentMethod) => {
            const key = String(method.id);

            if (isFixed(key) || blockedReason(method) !== null) {
                return;
            }

            setActive(key);
            setBuffer('');

            if (method.kind === 'gateway_terminal') {
                setTerminalId((current) => current ?? method.terminals?.[0]?.id ?? null);
            }

            if (payments.some((payment) => payment.key === key)) {
                return;
            }

            const limit = limitFor(key);
            const proposed = limit === null ? remaining : Math.min(remaining, limit);

            if (proposed > 0) {
                setAmount(key, proposed);
            }
        },
        [payments, limitFor, remaining, setAmount, blockedReason, isFixed],
    );

    const removePayment = useCallback((key: string) => {
        setPayments((current) => current.filter((payment) => payment.key !== key));
        setActive((current) => (current === key ? null : current));
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

    const setReference = useCallback((key: string, reference: string) => {
        setPayments((current) => current.map((payment) => (payment.key === key ? { ...payment, reference } : payment)));
    }, []);

    // Si cambia el tiquete con la modal abierta, ningun medio puede quedar por encima de su tope.
    useEffect(() => {
        setPayments((current) => {
            let touched = false;

            const clamped = current.map((payment) => {
                if (payment.kind === 'cash' || payment.kind === 'exchange') {
                    return payment;
                }

                const others = current.filter((other) => other.key !== payment.key).reduce((sum, other) => sum + other.amount, 0);
                let ceiling = Math.max(0, payable - others);

                if (payment.kind === 'credit') {
                    ceiling = Math.min(ceiling, available);
                }

                if (payment.kind === 'store_credit') {
                    ceiling = Math.min(ceiling, storeCreditAvailable);
                }

                if (payment.amount > ceiling) {
                    touched = true;

                    return { ...payment, amount: ceiling };
                }

                return payment;
            });

            return touched ? clamped.filter((payment) => payment.amount > 0) : current;
        });
    }, [payable, available, storeCreditAvailable]);

    // Lo que el servidor rechazaria: se dice antes de mandar.
    const problem = useMemo((): string | null => {
        for (const payment of payments) {
            const method = byKey.get(payment.key);

            if (!method) {
                continue;
            }

            if (method.min_amount && payment.amount < method.min_amount) {
                return `Con «${method.name}» se cobra desde ${money(method.min_amount)}.`;
            }

            if (method.requires_reference && (payment.reference ?? '').trim() === '') {
                return `Falta ${(method.reference_label ?? 'la referencia').toLowerCase()} de «${method.name}».`;
            }

            if (!method.combinable && payments.length > 1) {
                return `«${method.name}» no se combina con otros medios.`;
            }
        }

        if (payments.filter((payment) => payment.kind === 'cash').length > 1) {
            return 'Junta el efectivo en un solo pago: el cambio sale de una sola línea.';
        }

        if (gatewayLine && change > 0) {
            return 'Con un pago por pasarela el efectivo no puede dejar cambio: ajusta el monto.';
        }

        return null;
    }, [payments, byKey, gatewayLine, change]);

    const canConfirm =
        payments.length > 0 && paid >= payable && creditUsed <= available && !needsCreditApproval && payable > 0 && problem === null && (!gatewayLine || onStartGateway !== undefined);

    const confirm = useCallback(() => {
        if (!canConfirm || submitting) {
            return;
        }

        const toPayload = (payment: PaymentLine): PaymentPayload => ({
            payment_method_id: payment.method_id,
            method: payment.kind,
            amount: payment.amount,
            received_amount: payment.kind === 'cash' ? payment.amount : null,
            reference: payment.reference && payment.reference !== '' ? payment.reference : null,
        });

        if (gatewayLine && onStartGateway && gatewayLine.method_id !== null) {
            onStartGateway(
                payments.filter((payment) => payment.key !== gatewayLine.key).map(toPayload),
                {
                    payment_method_id: gatewayLine.method_id,
                    amount: gatewayLine.amount,
                    mode: gatewayLine.kind === 'gateway_terminal' ? 'terminal' : gatewayMode,
                    terminal_id: gatewayLine.kind === 'gateway_terminal' ? terminalId : null,
                },
            );

            return;
        }

        onConfirm(payments.map(toPayload));
    }, [canConfirm, submitting, onConfirm, onStartGateway, payments, gatewayLine, gatewayMode, terminalId]);

    // --- Teclado fisico ----------------------------------------------------

    useEffect(() => {
        if (!open) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;

            if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT')) {
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

            const kind = SHORTCUTS[event.key.toLowerCase()];
            const method = kind ? methods.find((item) => item.kind === kind && (onStartGateway !== undefined || !isGateway(item.kind))) : undefined;

            if (method) {
                event.preventDefault();
                chooseMethod(method);
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [open, typeDigits, backspace, confirm, chooseMethod, methods, onStartGateway]);

    if (!open) {
        return null;
    }

    const notice = buildNotice({ payments: payments.length, remaining, change, creditUsed, available, needsCreditApproval, problem, gateway: gatewayLine !== null });
    const activeMethod = active ? byKey.get(active) : undefined;
    const activeLine = active ? payments.find((payment) => payment.key === active) : undefined;
    const activeLimit = active ? limitFor(active) : null;

    // «Exacto» es lo que le toca al medio activo, no lo que falte: si ese medio ya tiene el
    // total asignado, lo que falta es cero y el primer billete quedaria rotulado «Exacto».
    const exact = active ? remainingExcluding(active) : remaining;
    const quickAmounts = [
        { value: exact, exact: true },
        ...(activeMethod?.kind === 'cash' ? [20000, 50000, 100000] : []).map((value) => ({ value, exact: false })),
    ].filter((option, index, array) => option.value > 0 && array.findIndex((other) => other.value === option.value) === index);

    // Sin quien abra el cobro (un cambio, el cobro de un pedido) la pasarela no se ofrece.
    const visible = methods.filter(
        (method) =>
            method.kind !== 'exchange' &&
            (method.kind !== 'store_credit' || storeCreditAvailable > 0) &&
            (onStartGateway !== undefined || !isGateway(method.kind)),
    );

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
                            <p className="mb-2 text-[0.6875rem] font-semibold uppercase tracking-wider text-[var(--muted)]">Paso 1 · Medios de pago</p>
                            {visible.length === 0 ? (
                                <p className="rounded-card border border-dashed border-[var(--line-2)] px-4 py-6 text-center text-[0.8125rem] text-[var(--muted)]">
                                    No hay medios de pago para esta caja en este momento.
                                </p>
                            ) : (
                                <div className="grid grid-cols-2 gap-2">
                                    {visible.map((method) => {
                                        const key = String(method.id);
                                        const MethodIcon = paymentIcon(method.icon);
                                        const line = payments.find((payment) => payment.key === key);
                                        const reason = blockedReason(method);
                                        const disabled = reason !== null;
                                        const selected = active === key;

                                        return (
                                            <button
                                                key={key}
                                                type="button"
                                                onClick={() => chooseMethod(method)}
                                                disabled={disabled}
                                                aria-disabled={disabled}
                                                title={disabled ? reason ?? undefined : (method.description ?? undefined)}
                                                className={cn(
                                                    'flex h-[4.375rem] items-center gap-3 rounded-card border px-3 text-left transition-colors',
                                                    disabled && 'cursor-not-allowed opacity-45',
                                                    !disabled && selected && 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]',
                                                    !disabled && !selected && line && 'border-[var(--accent)] bg-[var(--surface-2)]',
                                                    !disabled && !selected && !line && 'border-[var(--line-2)] bg-[var(--surface-2)] hover:border-[var(--line)]',
                                                    method.highlight && !line && !selected && !disabled && 'ring-1 ring-[var(--accent)]/40',
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
                                                    <span className="block truncate text-[0.875rem] font-semibold">{method.name}</span>
                                                    <span className="block truncate text-[0.6875rem] text-[var(--muted)]">
                                                        {disabled
                                                            ? reason
                                                            : method.kind === 'store_credit'
                                                              ? `Tiene ${money(storeCreditAvailable)}`
                                                              : (method.description ?? (method.kind === 'cash' ? 'Genera cambio' : ''))}
                                                    </span>
                                                </span>

                                                <span className={cn('shrink-0 font-mono text-[0.8125rem] tabular', line ? 'font-semibold text-[var(--text)]' : 'text-[var(--muted)]')}>
                                                    {line ? money(line.amount) : 'Asignar'}
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                            )}
                        </div>

                        <div className={cn('flex flex-col gap-2.5', !active && 'opacity-45')}>
                            <p className="text-[0.6875rem] font-semibold uppercase tracking-wider text-[var(--muted)]">
                                Paso 2 · Monto {activeMethod ? `· ${activeMethod.name}` : ''}
                            </p>

                            <div className="flex items-center justify-between rounded-card border border-[var(--accent)] bg-[var(--surface-2)] px-4 py-3">
                                <span className="text-[0.84375rem] font-medium text-[var(--text)]">{activeMethod ? activeMethod.name : 'Elige un medio'}</span>
                                <span className="font-mono text-[1.375rem] font-semibold tabular text-[var(--accent)]">{money(activeLine?.amount ?? 0)}</span>
                            </div>

                            <p className="text-[0.71875rem] text-[var(--muted)]">
                                {active === null
                                    ? 'Toca un medio de pago para asignarle un monto.'
                                    : activeLimit === null
                                      ? 'Sin tope: lo que exceda el total sale como cambio.'
                                      : `Maximo ${money(activeLimit)}`}
                                {activeMethod?.min_amount ? ` · mínimo ${money(activeMethod.min_amount)}` : ''}
                            </p>

                            {activeMethod?.instructions && (
                                <p className="whitespace-pre-line rounded-control bg-[var(--surface-2)] px-3 py-2 text-[0.75rem] text-[var(--muted)]">{activeMethod.instructions}</p>
                            )}

                            {activeMethod && (activeMethod.requires_reference || ['card', 'transfer', 'other'].includes(activeMethod.kind)) && (
                                <input
                                    type="text"
                                    maxLength={60}
                                    value={activeLine?.reference ?? ''}
                                    onChange={(event) => active && setReference(active, event.target.value)}
                                    placeholder={activeMethod.reference_label ?? 'Voucher o referencia'}
                                    aria-label={activeMethod.reference_label ?? 'Voucher o referencia'}
                                    className="h-11 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 font-mono text-[0.875rem] focus:border-[var(--accent)] focus:outline-none"
                                />
                            )}

                            {activeMethod?.kind === 'gateway_link' && (
                                <div className="flex gap-1.5" role="radiogroup" aria-label="Cómo se cobra">
                                    {(['qr', 'link'] as const).map((mode) => (
                                        <button
                                            key={mode}
                                            type="button"
                                            role="radio"
                                            aria-checked={gatewayMode === mode}
                                            onClick={() => setGatewayMode(mode)}
                                            className={cn(
                                                'h-10 flex-1 rounded-control border text-[0.8125rem] font-medium',
                                                gatewayMode === mode ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]' : 'border-[var(--line)] bg-[var(--surface-2)]',
                                            )}
                                        >
                                            {mode === 'qr' ? 'Mostrar QR' : 'Enviar link'}
                                        </button>
                                    ))}
                                </div>
                            )}

                            {activeMethod?.kind === 'gateway_terminal' && (activeMethod.terminals ?? []).length > 1 && (
                                <select
                                    value={terminalId ?? ''}
                                    onChange={(event) => setTerminalId(Number(event.target.value))}
                                    aria-label="Datáfono"
                                    className="h-11 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.875rem] focus:border-[var(--accent)] focus:outline-none"
                                >
                                    {(activeMethod.terminals ?? []).map((terminal) => (
                                        <option key={terminal.id} value={terminal.id}>
                                            {terminal.name}
                                        </option>
                                    ))}
                                </select>
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
                            <span className="font-mono text-[0.71875rem] tabular text-[var(--muted)]">{payments.length}</span>
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
                                    const isCash = payment.kind === 'cash';

                                    return (
                                        <li
                                            key={payment.key}
                                            className={cn(
                                                'flex items-center gap-2.5 rounded-card border bg-[var(--surface-2)] px-3 py-2.5',
                                                active === payment.key ? 'border-[var(--accent)]' : 'border-[var(--line)]',
                                            )}
                                        >
                                            <span className="grid size-8 shrink-0 place-items-center rounded-control bg-[var(--surface-3)] font-mono text-[0.6875rem] font-semibold text-[var(--muted)]">
                                                {payment.kind === 'exchange' ? <ArrowUUpLeftIcon size={15} /> : isGateway(payment.kind) ? <QrCodeIcon size={15} /> : INITIALS[payment.kind]}
                                            </span>

                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-[0.84375rem] font-medium text-[var(--text)]">{payment.name}</span>
                                                <span className="block truncate text-[0.71875rem] text-[var(--muted)]">
                                                    {isCash && change > 0
                                                        ? `Cambio ${money(change)}`
                                                        : payment.reference
                                                          ? `Ref. ${payment.reference}`
                                                          : isGateway(payment.kind)
                                                            ? 'Se cobra con la pasarela al confirmar'
                                                            : (byKey.get(payment.key)?.description ?? '')}
                                                </span>
                                            </span>

                                            <span className="shrink-0 font-mono text-[0.9375rem] font-semibold tabular text-[var(--text)]">{money(payment.amount)}</span>

                                            {!isFixed(payment.key) && (
                                                <>
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setActive(payment.key);
                                                            setBuffer('');
                                                        }}
                                                        aria-label={`Editar ${payment.name}`}
                                                        className="grid size-8 shrink-0 place-items-center rounded-chip text-[var(--muted)] transition-colors hover:bg-[var(--surface-3)] hover:text-[var(--text)]"
                                                    >
                                                        <PencilSimpleIcon size={15} />
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => removePayment(payment.key)}
                                                        aria-label={`Quitar ${payment.name}`}
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
                                <span className="text-[0.84375rem] font-semibold text-[var(--text)]">{remaining > 0 ? 'Falta por cobrar' : 'Cambio a entregar'}</span>
                                <span className="font-mono text-[1.625rem] font-semibold tabular" style={{ color: remaining > 0 ? 'var(--warn)' : 'var(--accent)' }}>
                                    {money(remaining > 0 ? remaining : change)}
                                </span>
                            </div>
                        </div>

                        {creditUsed > 0 && customer && (
                            <div className={cn('rounded-card border p-4', creditUsed > available ? 'border-[var(--danger)]/50 bg-[var(--danger-soft)]' : 'border-[var(--line)] bg-[var(--surface-2)]')}>
                                <p className="mb-2 text-[0.75rem] font-semibold uppercase tracking-wide" style={{ color: creditUsed > available ? 'var(--danger)' : 'var(--muted)' }}>
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
                                        <dd className="font-mono tabular" style={{ color: available - creditUsed < 0 ? 'var(--danger)' : 'var(--accent)' }}>
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

                        <p className="flex items-start gap-2 rounded-control px-3 py-2 text-[0.78125rem] font-medium" style={{ color: notice.color, backgroundColor: notice.background }}>
                            <notice.icon size={16} weight="fill" className="mt-px shrink-0" />
                            {notice.text}
                        </p>

                        {needsCreditApproval && (
                            <Button variant="warn" icon={<LockKeyIcon size={16} />} onClick={() => onRequestCreditApproval?.('Esta venta lleva credito y el cajero no tiene ese permiso.')}>
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
                                {gatewayLine ? (gatewayLine.kind === 'gateway_terminal' ? 'Cobrar en el datáfono' : 'Cobrar con la pasarela') : confirmLabel}
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
    problem: string | null;
    gateway: boolean;
}): Notice {
    const warn = { color: 'var(--warn)', background: 'var(--warn-soft)', icon: WarningCircleIcon };
    const ok = { color: 'var(--accent)', background: 'var(--accent-soft)', icon: CheckCircleIcon };

    if (state.creditUsed > state.available) {
        return { text: `El credito supera el cupo disponible (${money(state.available)}). Baja el monto o pide autorizacion.`, color: 'var(--danger)', background: 'var(--danger-soft)', icon: WarningCircleIcon };
    }

    if (state.payments === 0) {
        return { text: 'Agrega al menos un medio de pago para poder confirmar.', color: 'var(--muted)', background: 'var(--surface-2)', icon: InfoIcon };
    }

    if (state.remaining > 0) {
        return { ...warn, text: `Faltan ${money(state.remaining)} por asignar. Puedes repartirlos en otro medio de pago.` };
    }

    if (state.problem) {
        return { ...warn, text: state.problem };
    }

    if (state.needsCreditApproval) {
        return { ...warn, icon: LockKeyIcon, text: 'Falta la autorizacion del supervisor para dejar parte de la venta a credito.' };
    }

    if (state.gateway) {
        return { ...ok, text: 'Al confirmar se abre el cobro en la pasarela; la venta queda cuando el pago se apruebe.' };
    }

    if (state.change > 0) {
        return { ...ok, text: `Entrega ${money(state.change)} de cambio y confirma la venta.` };
    }

    return { ...ok, text: 'El pago cuadra con el total. Confirma para imprimir el tiquete.' };
}

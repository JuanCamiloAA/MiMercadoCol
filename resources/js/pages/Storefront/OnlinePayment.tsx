import { Link } from '@inertiajs/react';
import { CheckCircleIcon, CircleNotchIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import StoreLayout from '@/layouts/StoreLayout';
import { money } from '@/lib/format';

interface PaymentState {
    reference: string;
    status: string;
    status_label: string;
    amount: number;
    expires_at: string | null;
    approved: boolean;
    final: boolean;
}

/** Como se lleva al cliente a pagar (`CheckoutSession::toArray`). Nada secreto viaja aqui. */
interface CheckoutSession {
    type: 'redirect' | 'form' | 'widget';
    url: string | null;
    fields: Record<string, string>;
    widget: { script?: string; config?: Record<string, unknown>; data?: Record<string, string> };
}

interface OnlinePaymentProps {
    payment: PaymentState;
    order: { id: number; number: string; total: number; url: string } | null;
    /** Solo a la ida: a la vuelta no se reabre el cobro, se confirma. */
    checkout: CheckoutSession | null;
    hint: string | null;
}

declare global {
    interface Window {
        ePayco?: { checkout: { configure: (config: Record<string, unknown>) => { open: (data: Record<string, string>) => void } } };
    }
}

const POLL_MS = 3000;

/**
 * Ida y vuelta del pago en linea. A la ida lleva al cliente a la pasarela (redireccion, formulario
 * que se envia solo o el widget de ePayco). A la vuelta no le cree a la URL: pregunta el estado
 * al servidor, que lo sabe por el webhook o releyendo la transaccion en la pasarela.
 */
export default function OnlinePayment({ payment: initial, order, checkout, hint }: OnlinePaymentProps) {
    const [payment, setPayment] = useState<PaymentState>(initial);
    const [opening, setOpening] = useState(false);
    const formRef = useRef<HTMLFormElement>(null);

    const open = useCallback(async () => {
        if (!checkout) {
            return;
        }

        setOpening(true);

        if (checkout.type === 'redirect' && checkout.url) {
            window.location.assign(checkout.url);

            return;
        }

        if (checkout.type === 'form') {
            formRef.current?.submit();

            return;
        }

        if (checkout.type === 'widget' && checkout.widget.script) {
            const script = checkout.widget.script;

            if (!window.ePayco) {
                await new Promise<void>((resolve, reject) => {
                    const tag = document.createElement('script');
                    tag.src = script;
                    tag.async = true;
                    tag.onload = () => resolve();
                    tag.onerror = () => reject(new Error('script'));
                    document.head.appendChild(tag);
                }).catch(() => undefined);
            }

            if (window.ePayco) {
                window.ePayco.checkout.configure(checkout.widget.config ?? {}).open(checkout.widget.data ?? {});
            }

            setOpening(false);
        }
    }, [checkout]);

    // A la ida se abre solo, una vez por pago: si el cliente vuelve atras no entra en un bucle.
    useEffect(() => {
        if (!checkout || payment.status !== 'pending') {
            return;
        }

        const key = `payment-opened:${payment.reference}`;
        let opened = false;

        try {
            opened = window.sessionStorage.getItem(key) === '1';
            window.sessionStorage.setItem(key, '1');
        } catch {
            // Sin almacenamiento se abre igual; el boton queda para volver a intentarlo.
        }

        if (!opened) {
            void open();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Mientras siga pendiente se pregunta; la primera vez con la pista que trajo la vuelta.
    useEffect(() => {
        if (payment.final) {
            return;
        }

        let first = true;

        const ask = async () => {
            const query = first && hint ? `?id=${encodeURIComponent(hint)}` : '';
            first = false;

            try {
                const response = await fetch(`${route('storefront.payments.status', payment.reference)}${query}`, { headers: { Accept: 'application/json' } });

                if (response.ok) {
                    setPayment((await response.json()) as PaymentState);
                }
            } catch {
                // Se reintenta en la siguiente vuelta.
            }
        };

        const timer = window.setInterval(() => void ask(), POLL_MS);

        if (!checkout) {
            void ask();
        }

        return () => window.clearInterval(timer);
    }, [payment.final, payment.reference, hint, checkout]);

    return (
        <StoreLayout title="Pago en línea" current="orders" hideCartBar>
            <div className="mx-auto flex max-w-[480px] flex-col items-center gap-4 rounded-[16px] border border-[var(--line)] bg-[var(--surface)] p-6 text-center">
                {payment.approved ? (
                    <CheckCircleIcon size={56} weight="fill" className="text-[var(--accent)]" />
                ) : payment.final ? (
                    <WarningCircleIcon size={56} weight="fill" className="text-[var(--danger)]" />
                ) : (
                    <CircleNotchIcon size={48} className="animate-spin text-[var(--accent)]" />
                )}

                <div>
                    <h1 className="text-[20px] font-semibold">
                        {payment.approved ? 'Pago aprobado' : payment.final ? 'El pago no se completó' : checkout ? 'Te llevamos a pagar' : 'Confirmando tu pago…'}
                    </h1>
                    <p className="mt-1 text-[14px] text-[var(--muted)]">
                        {payment.approved
                            ? `Recibimos ${money(payment.amount)}. Tu pedido${order ? ` #${order.number}` : ''} ya está en preparación.`
                            : payment.final
                              ? `${payment.status_label}. Tu pedido${order ? ` #${order.number}` : ''} se canceló y no se te cobró nada; puedes volver a pedir y elegir otro medio.`
                              : checkout
                                ? `Paga ${money(payment.amount)} en la pasarela. Apartamos tu pedido mientras tanto.`
                                : 'Estamos esperando la confirmación de la pasarela. No cierres esta página.'}
                    </p>
                </div>

                {checkout && !payment.final && (
                    <button
                        type="button"
                        onClick={() => void open()}
                        disabled={opening && checkout.type !== 'widget'}
                        className="flex h-12 w-full items-center justify-center rounded-[13px] bg-[var(--accent)] text-[15px] font-semibold text-[var(--accent-ink)] disabled:opacity-60"
                    >
                        Ir a pagar {money(payment.amount)}
                    </button>
                )}

                {checkout?.type === 'form' && checkout.url && (
                    <form ref={formRef} method="post" action={checkout.url} className="hidden">
                        {Object.entries(checkout.fields).map(([name, value]) => (
                            <input key={name} type="hidden" name={name} value={value} />
                        ))}
                    </form>
                )}

                {order && (
                    <Link href={order.url} className="text-[14px] font-medium text-[var(--accent)] underline-offset-2 hover:underline">
                        Ver mi pedido
                    </Link>
                )}

                <p className="font-mono text-[11.5px] text-[var(--muted)]">Ref. {payment.reference}</p>
            </div>
        </StoreLayout>
    );
}

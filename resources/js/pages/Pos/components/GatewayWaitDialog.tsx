import { CheckCircleIcon, CopyIcon, DeviceMobileIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { money } from '@/lib/format';
import type { LastSaleFlash } from '@/types';

/** Lo que responde `GET /pos/pagos/{reference}`. */
export interface GatewayPaymentState {
    reference: string;
    status: 'pending' | 'approved' | 'declined' | 'failed' | 'expired' | 'cancelled' | 'error' | 'refunded' | string;
    status_label: string;
    status_detail: string | null;
    mode: 'qr' | 'link' | 'terminal' | 'checkout';
    amount: number;
    checkout_url: string | null;
    qr_image: string | null;
    expires_at: string | null;
    applied: boolean;
    to_reconcile: boolean;
    /** El tiquete que se guardo mientras se esperaba; solo llega cuando el cobro no se dio. */
    held_sale_id: number | null;
    sale: LastSaleFlash | null;
}

interface GatewayWaitDialogProps {
    /** El cobro en curso. null cierra la ventana. */
    payment: GatewayPaymentState | null;
    pollSeconds: number;
    onUpdate: (payment: GatewayPaymentState) => void;
    /** La venta quedo registrada: la caja muestra el tiquete y empieza otro. */
    onApplied: (sale: LastSaleFlash) => void;
    /** Rechazado, vencido o cancelado: se vuelve al cobro con el tiquete intacto. */
    onBack: () => void;
}

const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

/**
 * La espera de un cobro por pasarela en la caja: el QR (o el link) en grande, o «esperando el
 * datáfono», con la cuenta regresiva. Pregunta el estado cada pocos segundos; el webhook puede
 * haberlo aprobado antes y el servidor responde lo mismo por los dos caminos.
 */
export default function GatewayWaitDialog({ payment, pollSeconds, onUpdate, onApplied, onBack }: GatewayWaitDialogProps) {
    const [now, setNow] = useState(() => Date.now());
    const [cancelling, setCancelling] = useState(false);
    const reported = useRef<string | null>(null);

    const pending = payment?.status === 'pending';

    const poll = useCallback(async () => {
        if (!payment) {
            return;
        }

        try {
            const response = await fetch(route('pos.gateway.show', payment.reference), { headers: { Accept: 'application/json' } });

            if (response.ok) {
                onUpdate((await response.json()) as GatewayPaymentState);
            }
        } catch {
            // Sin red se vuelve a intentar en el siguiente turno: la pasarela no se entera.
        }
    }, [payment, onUpdate]);

    useEffect(() => {
        if (!payment || !pending) {
            return;
        }

        const timer = window.setInterval(() => void poll(), Math.max(2, pollSeconds) * 1000);

        return () => window.clearInterval(timer);
    }, [payment, pending, poll, pollSeconds]);

    useEffect(() => {
        if (!pending) {
            return;
        }

        const timer = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(timer);
    }, [pending]);

    // Aprobada y con venta: se entrega una sola vez.
    useEffect(() => {
        if (payment?.applied && payment.sale && reported.current !== payment.reference) {
            reported.current = payment.reference;
            onApplied(payment.sale);
        }
    }, [payment, onApplied]);

    if (!payment) {
        return null;
    }

    const cancel = async () => {
        setCancelling(true);

        try {
            const response = await fetch(route('pos.gateway.cancel', payment.reference), {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            });

            if (response.ok) {
                onUpdate((await response.json()) as GatewayPaymentState);
            } else {
                toast.error('No se pudo cancelar el cobro. Vuelve a intentarlo.');
            }
        } finally {
            setCancelling(false);
        }
    };

    const remaining = payment.expires_at ? Math.max(0, Math.floor((new Date(payment.expires_at).getTime() - now) / 1000)) : null;
    const clock = remaining === null ? null : `${Math.floor(remaining / 60)}:${String(remaining % 60).padStart(2, '0')}`;
    const failed = !pending && !payment.applied;

    return (
        <Modal
            open
            onClose={() => (pending ? undefined : onBack())}
            title={payment.mode === 'terminal' ? 'Cobro en el datáfono' : 'Cobro con QR o link'}
            size="md"
            footer={
                pending ? (
                    <>
                        <Button variant="secondary" onClick={() => void poll()}>
                            Consultar ahora
                        </Button>
                        <Button variant="danger" loading={cancelling} onClick={() => void cancel()}>
                            Cancelar el cobro
                        </Button>
                    </>
                ) : failed ? (
                    <Button onClick={onBack}>Volver al cobro</Button>
                ) : null
            }
        >
            <div className="flex flex-col items-center gap-4 text-center">
                <p className="font-mono text-[1.75rem] font-semibold tabular">{money(payment.amount)}</p>

                {pending && payment.mode !== 'terminal' && payment.qr_image && (
                    <img src={payment.qr_image} alt="Código QR del cobro" className="size-56 rounded-card border border-[var(--line)] bg-white p-2" />
                )}

                {pending && payment.mode === 'terminal' && (
                    <div className="grid size-28 place-items-center rounded-full bg-[var(--accent-soft)] text-[var(--accent)]">
                        <DeviceMobileIcon size={48} />
                    </div>
                )}

                {pending && payment.checkout_url && payment.mode === 'link' && (
                    <div className="flex w-full items-center gap-2 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 py-2">
                        <span className="min-w-0 flex-1 truncate text-left font-mono text-[0.78125rem]">{payment.checkout_url}</span>
                        <Button
                            size="sm"
                            variant="secondary"
                            icon={<CopyIcon size={14} />}
                            onClick={() => {
                                void navigator.clipboard?.writeText(payment.checkout_url ?? '').then(() => toast.success('Link copiado.'));
                            }}
                        >
                            Copiar
                        </Button>
                    </div>
                )}

                {pending && (
                    <p className="text-[0.84375rem] text-[var(--muted)]">
                        {payment.mode === 'terminal'
                            ? 'El cliente paga en el datáfono. La venta se registra sola cuando el pago se apruebe.'
                            : 'El cliente escanea el código o abre el link y paga. La venta se registra sola cuando el pago se apruebe.'}
                        {clock !== null && <span className="mt-1 block font-mono tabular">Vence en {clock}</span>}
                    </p>
                )}

                {payment.applied && (
                    <p className="flex items-center gap-2 text-[0.9375rem] font-semibold text-[var(--accent)]">
                        <CheckCircleIcon size={20} weight="fill" /> Pago aprobado. Venta {payment.sale?.number}.
                    </p>
                )}

                {payment.to_reconcile && (
                    <p className="flex items-start gap-2 rounded-control bg-[var(--warn-soft)] px-3 py-2 text-left text-[0.8125rem] text-[var(--warn)]">
                        <WarningCircleIcon size={18} weight="fill" className="mt-px shrink-0" />
                        La pasarela aprobó el pago pero la venta no se pudo registrar. Quedó en «Pagos por conciliar» con la referencia {payment.reference}.
                    </p>
                )}

                {failed && !payment.to_reconcile && (
                    <p className="flex items-start gap-2 rounded-control bg-[var(--danger-soft)] px-3 py-2 text-left text-[0.8125rem] text-[var(--danger)]">
                        <WarningCircleIcon size={18} weight="fill" className="mt-px shrink-0" />
                        {payment.status_label}
                        {payment.status_detail ? ` · ${payment.status_detail}` : ''}. El tiquete sigue intacto: puedes cobrarlo con otro medio.
                    </p>
                )}

                <p className="font-mono text-[0.71875rem] text-[var(--muted)]">Ref. {payment.reference}</p>
            </div>
        </Modal>
    );
}

<?php

namespace App\Services\Payments;

use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Payments\Gateways\GatewayException;
use App\Services\OrderService;
use App\Services\SettingsService;
use Illuminate\Validation\ValidationException;

/**
 * Un pedido de la tienda pagado en linea por la pasarela de la empresa.
 *
 *     checkout ─▶ pedido `pending_payment` (aparta inventario y saldo) + transaccion `pending`
 *              ─▶ el cliente paga en la pasarela (la tarjeta nunca toca este servidor)
 *              ─▶ aprobado: el pedido pasa a `new`, pagado, y entra al alistamiento de siempre
 *              ─▶ rechazado o vencido: el pedido se cancela y se libera lo apartado
 *
 * La pagina de vuelta no le cree a la URL: muestra «confirmando…» y pregunta el estado, que sale
 * del webhook o de la relectura.
 */
class OnlineOrderPayments implements GatewayPaymentHandler
{
    public function __construct(
        protected GatewayPaymentService $payments,
        protected OrderService $orders,
        protected SettingsService $settings,
    ) {}

    /**
     * Abre el cobro de un pedido recien tomado: la fila `pending` primero, despues la pasarela.
     */
    public function start(Order $order, Customer $customer): PaymentTransaction
    {
        $method = PaymentMethod::query()->withoutGlobalScopes()->with('gatewayAccount')->findOrFail($order->payment_method_id);
        $amount = (int) $order->total - (int) $order->store_credit_amount;
        $minutes = max(5, (int) $this->settings->get((int) $order->company_id, 'payments.online_hold_minutes'));

        $transaction = $this->payments->open($method, $amount, PaymentTransaction::CHANNEL_ONLINE, PaymentTransaction::MODE_CHECKOUT, [
            'store_id' => $order->store_id,
            'payable_type' => $order->getMorphClass(),
            'payable_id' => $order->id,
            'expires_at' => now()->addMinutes($minutes),
        ]);

        try {
            $session = $this->payments->gatewayFor($transaction)->createCheckout($transaction->setRelation('gatewayAccount', $method->gatewayAccount), [
                'return_url' => route('storefront.payments.show', $transaction->reference),
                'description' => "Pedido #{$order->number}",
                'customer' => array_filter([
                    'email' => $customer->email,
                    'full-name' => $customer->name,
                    'phone-number' => $customer->phone,
                ]),
            ]);
        } catch (GatewayException $exception) {
            $error = $this->payments->failToOpen($transaction, $exception);
            $this->orders->cancelUnpaid($order->fresh(), 'No se pudo abrir el pago en línea.');

            throw $error;
        }

        $transaction->forceFill([
            'checkout_url' => $session->url,
            'gateway_link_id' => $session->gatewayId,
            'context' => ['checkout' => $session->toArray()],
        ])->save();

        return $transaction;
    }

    public function onApproved(PaymentTransaction $transaction): void
    {
        $order = Order::query()->withoutGlobalScopes()->lockForUpdate()->find($transaction->payable_id);

        if (! $order || $order->status !== Order::STATUS_PENDING_PAYMENT) {
            // Llego tarde: el pedido ya se cancelo y se libero lo apartado. La plata entro igual.
            throw ValidationException::withMessages(['payment' => ['El pedido ya no esperaba este pago.']]);
        }

        $this->orders->confirmOnlinePayment($order, $transaction);

        $transaction->forceFill(['applied_at' => now()])->save();
    }

    public function onFailed(PaymentTransaction $transaction): void
    {
        $order = Order::query()->withoutGlobalScopes()->lockForUpdate()->find($transaction->payable_id);

        if (! $order || $order->status !== Order::STATUS_PENDING_PAYMENT) {
            return;
        }

        $reason = match ($transaction->status) {
            PaymentTransaction::STATUS_EXPIRED => 'El pago en línea venció sin completarse.',
            PaymentTransaction::STATUS_DECLINED => 'La pasarela rechazó el pago.',
            default => 'El pago en línea no se completó.',
        };

        $this->orders->cancelUnpaid($order, $reason);
    }

    /**
     * La transaccion del pedido, si tiene.
     */
    public static function transactionOf(Order $order): ?PaymentTransaction
    {
        return PaymentTransaction::query()->withoutGlobalScopes()
            ->where('payable_type', $order->getMorphClass())
            ->where('payable_id', $order->id)
            ->latest('id')
            ->first();
    }
}

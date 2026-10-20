<?php

namespace App\Services\Payments;

use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\RegisterSession;
use App\Models\StoreCreditMovement;
use App\Models\User;
use App\Payments\Gateways\GatewayRegistry;
use App\Payments\Gateways\PaymentGateway;
use App\Services\StoreCreditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * «Pagos por conciliar»: plata que la pasarela aprobo y que no termino en ninguna venta ni pedido
 * (la caja se cerro, se fue la luz, el cajero cancelo tarde, el pedido ya habia vencido).
 *
 * Nunca se descarta en silencio. Se resuelve con una de estas acciones, cada una con su permiso
 * y su nota: aplicarla a una venta (en una caja abierta) o a un pedido sin pagar, abonarla como
 * saldo a favor de un cliente (nunca al consumidor final), o registrar que se reembolso (por fuera
 * o por la pasarela, si el conector lo ofrece).
 */
class PaymentReconciliationService
{
    public function __construct(
        protected PosGatewayPayments $pos,
        protected StoreCreditService $storeCredits,
        protected GatewayRegistry $gateways,
    ) {}

    /** Crea la venta del tiquete que se iba a cobrar, en una caja abierta de la sede. */
    public function applyToSale(PaymentTransaction $transaction, RegisterSession $session, User $user, string $note): PaymentTransaction
    {
        return $this->resolve($transaction, function (PaymentTransaction $locked) use ($session, $user, $note) {
            if ($locked->channel !== PaymentTransaction::CHANNEL_POS) {
                throw ValidationException::withMessages(['action' => ['Solo un cobro de la caja se convierte en venta. Este se aplica a un pedido.']]);
            }

            if ($session->status !== RegisterSession::STATUS_OPEN || (int) $session->store_id !== (int) $locked->store_id) {
                throw ValidationException::withMessages(['register_session_id' => ['Elige una caja abierta de la misma sede.']]);
            }

            $sale = $this->pos->createSale($locked, $session, $user);

            $locked->forceFill([
                'applied_at' => now(),
                'payable_type' => $sale->getMorphClass(),
                'payable_id' => $sale->id,
                'resolution' => PaymentTransaction::RESOLUTION_APPLIED,
                'resolution_note' => mb_substr("Venta {$sale->number}. {$note}", 0, 250),
            ]);
        }, $user);
    }

    /**
     * Paga con ella un pedido que sigue sin pagar (contraentrega, a credito no): queda pagado por la
     * pasarela y al entregarse la venta lleva ese renglon.
     */
    public function applyToOrder(PaymentTransaction $transaction, Order $order, User $user, string $note): PaymentTransaction
    {
        return $this->resolve($transaction, function (PaymentTransaction $locked) use ($order, $note) {
            $order = Order::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);

            if ((int) $order->company_id !== (int) $locked->company_id) {
                abort(404);
            }

            $open = [Order::STATUS_NEW, Order::STATUS_PICKING, Order::STATUS_READY, Order::STATUS_ON_ROUTE];

            if (! in_array($order->status, $open, true) || $order->payment_status === 'paid' || $order->payment_method === Order::PAYMENT_CREDIT) {
                throw ValidationException::withMessages(['order_id' => ['Ese pedido no está esperando un pago.']]);
            }

            if ((int) $order->total - (int) $order->store_credit_amount !== (int) $locked->amount) {
                throw ValidationException::withMessages(['order_id' => ['El valor del pedido no es el de este pago.']]);
            }

            // El pedido queda pagado con el medio de la transaccion: al entregarlo, la venta lleva
            // ese renglon ligado a ella.
            $order->forceFill([
                'payment_method_id' => $locked->payment_method_id,
                'payment_method' => Order::PAYMENT_GATEWAY,
                'payment_status' => 'paid',
            ])->save();

            $locked->forceFill([
                'applied_at' => now(),
                'payable_type' => $order->getMorphClass(),
                'payable_id' => $order->id,
                'resolution' => PaymentTransaction::RESOLUTION_APPLIED,
                'resolution_note' => mb_substr("Pedido #{$order->number}. {$note}", 0, 250),
            ]);
        }, $user);
    }

    /** Se le abona al cliente como saldo a favor. Al consumidor final generico, nunca. */
    public function creditCustomer(PaymentTransaction $transaction, Customer $customer, User $user, string $note): PaymentTransaction
    {
        return $this->resolve($transaction, function (PaymentTransaction $locked) use ($customer, $user, $note) {
            if ((int) $customer->company_id !== (int) $locked->company_id) {
                abort(404);
            }

            if ($customer->isGenericConsumer()) {
                throw ValidationException::withMessages(['customer_id' => ['Al consumidor final no se le abona saldo a favor: elige la ficha del cliente.']]);
            }

            $this->storeCredits->grant($customer, (int) $locked->amount, StoreCreditMovement::TYPE_GATEWAY_CREDIT, [], $user, "Pago por pasarela {$locked->reference}: {$note}");

            $locked->forceFill([
                'resolution' => PaymentTransaction::RESOLUTION_STORE_CREDIT,
                'resolution_note' => mb_substr("Saldo a favor de {$customer->name}. {$note}", 0, 250),
            ]);
        }, $user);
    }

    /** Se le devolvio la plata al cliente por fuera del sistema (transferencia, efectivo). */
    public function refundedOutside(PaymentTransaction $transaction, User $user, string $note): PaymentTransaction
    {
        return $this->resolve($transaction, function (PaymentTransaction $locked) use ($note) {
            $locked->forceFill([
                'resolution' => PaymentTransaction::RESOLUTION_REFUNDED_OUTSIDE,
                'resolution_note' => mb_substr($note, 0, 250),
                'refunded_amount' => (int) $locked->amount,
            ]);
        }, $user);
    }

    /** Se reembolsa por la pasarela, si el conector lo ofrece. */
    public function refundThroughGateway(PaymentTransaction $transaction, User $user, string $note): PaymentTransaction
    {
        $gateway = $this->gateways->for($transaction->provider);

        if (! in_array(PaymentGateway::CAPABILITY_REFUND, $gateway->capabilities(), true)) {
            throw ValidationException::withMessages(['action' => ["{$gateway->label()} no ofrece reembolsos por su API: registra el reembolso hecho por fuera."]]);
        }

        return $this->resolve($transaction, function (PaymentTransaction $locked) use ($gateway, $note) {
            try {
                $done = $gateway->refund($locked, (int) $locked->amount);
            } catch (Throwable) {
                $done = false;
            }

            if (! $done) {
                throw ValidationException::withMessages(['action' => ['La pasarela no aceptó el reembolso. Inténtalo de nuevo o hazlo desde su panel.']]);
            }

            $locked->forceFill([
                'resolution' => PaymentTransaction::RESOLUTION_REFUNDED_GATEWAY,
                'resolution_note' => mb_substr($note, 0, 250),
                'refunded_amount' => (int) $locked->amount,
            ]);
        }, $user);
    }

    /**
     * Toda resolucion pasa por aqui: con la fila bloqueada, solo una aprobada sin aplicar ni
     * resolver, y deja quien y cuando.
     */
    protected function resolve(PaymentTransaction $transaction, callable $action, User $user): PaymentTransaction
    {
        return DB::transaction(function () use ($transaction, $action, $user) {
            /** @var PaymentTransaction $locked */
            $locked = PaymentTransaction::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($transaction->id);

            if ($locked->status !== PaymentTransaction::STATUS_APPROVED || $locked->applied_at !== null || $locked->resolution !== null) {
                throw ValidationException::withMessages(['action' => ['Ese pago ya no está por conciliar.']]);
            }

            $action($locked);

            $locked->forceFill(['resolved_by_user_id' => $user->id, 'resolved_at' => now()])->save();

            return $locked;
        });
    }
}

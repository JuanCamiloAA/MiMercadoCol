<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Payments\Gateways\GatewayException;
use App\Payments\Gateways\GatewayRegistry;
use App\Payments\Gateways\GatewayStatus;
use App\Payments\Gateways\PaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Las transacciones por pasarela de las empresas: abrirlas, releerlas y liquidarlas.
 *
 * El orden de siempre:
 * 1. La fila `pending` se crea ANTES de llamar a la pasarela (`open`).
 * 2. Lo que dice un evento no se cree: se relee en la pasarela (`fetch`).
 * 3. Se liquida con la fila bloqueada (`settle`), idempotente por `reference`: el webhook y el
 *    sondeo terminan aqui y no crean dos ventas ni confirman dos veces un pedido.
 * 4. Antes de aprobar, monto y moneda tienen que ser los de la fila. Si no, queda en `error`.
 *
 * Que pasa cuando aprueba o falla lo decide quien la origino: un pedido de la tienda
 * (`OnlineOrderPayments`) o un cobro de la caja (`PosGatewayPayments`). Si eso falla —la caja se
 * cerro, el pedido ya se cancelo— la plata entro igual: la transaccion queda aprobada sin
 * aplicar y aparece en «Pagos por conciliar». Nunca se descarta en silencio.
 */
class GatewayPaymentService
{
    public function __construct(protected GatewayRegistry $gateways) {}

    public function gatewayFor(PaymentTransaction|PaymentGatewayAccount $subject): PaymentGateway
    {
        return $this->gateways->for($subject->provider);
    }

    /**
     * La fila `pending` de un cobro nuevo. Siempre antes de hablar con la pasarela.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function open(PaymentMethod $method, int $amount, string $channel, string $mode, array $attributes = []): PaymentTransaction
    {
        $account = $method->gatewayAccount;

        if (! $method->isGateway() || ! $account || ! $account->is_enabled) {
            throw ValidationException::withMessages(['payment_method' => ["«{$method->name}» no tiene una cuenta de pasarela encendida."]]);
        }

        if (! $this->gateways->isEnabled($account->provider)) {
            throw ValidationException::withMessages(['payment_method' => ["«{$method->name}» no está disponible por ahora."]]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['payment_method' => ['No hay nada que cobrar por la pasarela.']]);
        }

        return PaymentTransaction::query()->create(array_merge([
            'company_id' => $method->company_id,
            'payment_method_id' => $method->id,
            'gateway_account_id' => $account->id,
            'provider' => $account->provider,
            'environment' => $account->environment,
            'reference' => PaymentTransaction::newReference(),
            'amount' => $amount,
            'currency' => 'COP',
            'channel' => $channel,
            'mode' => $mode,
            'status' => PaymentTransaction::STATUS_PENDING,
        ], $attributes));
    }

    /**
     * La pasarela no alcanzo a abrir el cobro: la fila queda en `error` con un motivo apto para
     * la persona, y se devuelve el error de validacion para la pantalla.
     */
    public function failToOpen(PaymentTransaction $transaction, GatewayException $exception): ValidationException
    {
        $transaction->forceFill([
            'status' => PaymentTransaction::STATUS_ERROR,
            'status_detail' => mb_substr($exception->getMessage(), 0, 250),
        ])->save();

        Log::warning('Pasarela: no se pudo abrir el cobro.', ['reference' => $transaction->reference, 'provider' => $transaction->provider]);

        return ValidationException::withMessages(['payment_method' => [$exception->getMessage()]]);
    }

    /**
     * Si sigue pendiente, relee en la pasarela y liquida lo que haya. Es el sondeo de la caja y
     * de la pagina de estado de la tienda. `hint` es un id que trajo la vuelta del cliente: se
     * usa para preguntar, nunca como respuesta.
     */
    public function refresh(PaymentTransaction $transaction, ?string $hint = null): PaymentTransaction
    {
        if (! $transaction->isPending()) {
            return $transaction;
        }

        $probe = $transaction->replicate();
        $probe->id = $transaction->id;
        $probe->setRelation('gatewayAccount', $transaction->gatewayAccount);

        if ($hint !== null && $hint !== '' && ! $probe->gateway_transaction_id) {
            $probe->gateway_transaction_id = mb_substr($hint, 0, 120);
        }

        try {
            $status = $this->gatewayFor($transaction)->fetch($probe);
        } catch (Throwable $exception) {
            Log::warning('Pasarela: fallo la relectura.', ['reference' => $transaction->reference, 'error' => class_basename($exception)]);
            $status = null;
        }

        if ($status !== null && ! $status->isPending()) {
            return $this->settle($transaction, $status);
        }

        if ($transaction->expires_at !== null && $transaction->expires_at->isPast()) {
            return $this->expire($transaction);
        }

        return $transaction->fresh() ?? $transaction;
    }

    /**
     * Liquida lo que dice la pasarela. Idempotente: con la fila bloqueada, lo ya liquidado no se
     * vuelve a liquidar. Un aprobado no se deshace por un evento viejo; un rechazado si puede
     * terminar aprobado (el cliente reintento y pago).
     */
    public function settle(PaymentTransaction $transaction, GatewayStatus $status): PaymentTransaction
    {
        return DB::transaction(function () use ($transaction, $status) {
            /** @var PaymentTransaction $locked */
            $locked = PaymentTransaction::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($transaction->id);

            if ($status->reference !== null && $status->reference !== $locked->reference) {
                Log::warning('Pasarela: la referencia releida no es la de la transaccion.', ['reference' => $locked->reference]);

                return $locked;
            }

            if ($locked->status === PaymentTransaction::STATUS_APPROVED) {
                return $locked;
            }

            if ($status->isPending()) {
                $locked->forceFill([
                    'gateway_transaction_id' => $status->gatewayTransactionId ?? $locked->gateway_transaction_id,
                    'payload' => $status->raw ?: $locked->payload,
                ])->save();

                return $locked;
            }

            if ($status->isApproved()) {
                // Monto y moneda tienen que ser los registrados: no se aprueba nada por otro monto.
                if ($status->amount !== $locked->amount || ($status->currency !== null && strtoupper($status->currency) !== $locked->currency)) {
                    Log::error('Pasarela: el pago aprobado no coincide con el cobro registrado.', [
                        'reference' => $locked->reference,
                        'expected' => $locked->amount,
                        'received' => $status->amount,
                        'currency' => $status->currency,
                    ]);

                    $locked->forceFill([
                        'status' => PaymentTransaction::STATUS_ERROR,
                        'status_detail' => 'El monto o la moneda pagados no coinciden con el cobro: revísalo en la pasarela.',
                        'gateway_transaction_id' => $status->gatewayTransactionId ?? $locked->gateway_transaction_id,
                        'payload' => $status->raw ?: $locked->payload,
                    ])->save();

                    // Lo que esperaba este cobro no se queda esperando: el pedido se cancela y la
                    // caja vuelve al cobro. La plata que si entro se revisa en la pasarela.
                    $this->handler($locked)?->onFailed($locked);

                    return $locked->refresh();
                }

                $locked->forceFill([
                    'status' => PaymentTransaction::STATUS_APPROVED,
                    'status_detail' => $status->detail ?: null,
                    'approved_at' => now(),
                    'gateway_transaction_id' => $status->gatewayTransactionId ?? $locked->gateway_transaction_id,
                    'payload' => $status->raw ?: $locked->payload,
                ])->save();

                $this->apply($locked);

                return $locked->refresh();
            }

            // Un final que no es aprobado no pisa otro final: solo el aprobado sube.
            if ($locked->isFinal()) {
                return $locked;
            }

            $locked->forceFill([
                'status' => $status->status,
                'status_detail' => $status->detail ?: (PaymentTransaction::STATUS_LABELS[$status->status] ?? null),
                'gateway_transaction_id' => $status->gatewayTransactionId ?? $locked->gateway_transaction_id,
                'payload' => $status->raw ?: $locked->payload,
            ])->save();

            $this->handler($locked)?->onFailed($locked);

            return $locked->refresh();
        });
    }

    /** Vencio sin pagarse: se anula en la pasarela si se puede y se libera lo apartado. */
    public function expire(PaymentTransaction $transaction): PaymentTransaction
    {
        try {
            $this->gatewayFor($transaction)->cancel($transaction);
        } catch (Throwable) {
            // Si la pasarela no lo deja anular, caduca solo.
        }

        return $this->settle($transaction, new GatewayStatus(PaymentTransaction::STATUS_EXPIRED, detail: 'Venció sin pagarse.'));
    }

    /**
     * El cajero cancela un cobro que no se ha pagado. Antes se relee: si ya entro la plata, no se
     * cancela nada y se aplica.
     */
    public function cancel(PaymentTransaction $transaction, User $user): PaymentTransaction
    {
        $transaction = $this->refresh($transaction);

        if (! $transaction->isPending()) {
            return $transaction;
        }

        try {
            $this->gatewayFor($transaction)->cancel($transaction);
        } catch (Throwable) {
            // Sin anulacion en la pasarela: el link caduca solo.
        }

        $transaction->forceFill(['cancelled_at' => now()])->save();

        return $this->settle($transaction, new GatewayStatus(PaymentTransaction::STATUS_VOIDED, detail: 'Cancelado por '.$user->full_name.'.'));
    }

    /**
     * Pendientes que ya vencieron. Antes de darlas por vencidas se releen: una que se pago a
     * ultimo minuto no se pierde. Lo usa `payments:expire`, cada minuto.
     */
    public function expireDue(): int
    {
        $count = 0;

        PaymentTransaction::query()->withoutGlobalScopes()
            ->where('status', PaymentTransaction::STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->with('gatewayAccount')
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->each(function (PaymentTransaction $transaction) use (&$count) {
                $result = $this->refresh($transaction);

                if ($result->isPending()) {
                    $this->expire($result);
                }

                $count++;
            });

        return $count;
    }

    /**
     * Lo que pasa cuando aprueba. Si falla (la caja se cerro, el pedido se cancelo, ya no hay
     * existencias), la transaccion queda aprobada y sin aplicar: «Pagos por conciliar».
     */
    protected function apply(PaymentTransaction $transaction): void
    {
        $handler = $this->handler($transaction);

        if (! $handler) {
            return;
        }

        try {
            DB::transaction(fn () => $handler->onApproved($transaction));
        } catch (Throwable $exception) {
            Log::warning('Pasarela: el pago aprobado no se pudo aplicar; queda por conciliar.', [
                'reference' => $transaction->reference,
                'error' => $exception instanceof ValidationException ? collect($exception->errors())->flatten()->first() : class_basename($exception),
            ]);

            $transaction->forceFill([
                'status_detail' => mb_substr('Aprobado, pero no se pudo aplicar: '.($exception instanceof ValidationException ? collect($exception->errors())->flatten()->first() : 'error interno'), 0, 250),
            ])->save();
        }
    }

    protected function handler(PaymentTransaction $transaction): ?GatewayPaymentHandler
    {
        if ($transaction->payable_type === (new Order)->getMorphClass()) {
            return app(OnlineOrderPayments::class);
        }

        if ($transaction->channel === PaymentTransaction::CHANNEL_POS) {
            return app(PosGatewayPayments::class);
        }

        return null;
    }
}

<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentTransaction;
use App\Payments\Gateways\GatewayRegistry;
use App\Services\Payments\GatewayPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Los eventos de las pasarelas de las EMPRESAS: `/webhooks/pagos/{provider}/{token}`. El token
 * dice de que cuenta es. El de la membresia (`/webhooks/wompi`) es otro y no se toca.
 *
 * Orden fijo:
 * 1. La firma, con el secreto DE ESA CUENTA. Invalida → 401.
 * 2. La relectura de la transaccion en la pasarela. Si no responde → 503 (para que reintente).
 * 3. La liquidacion, idempotente por referencia y con la fila bloqueada; antes de aprobar, monto
 *    y moneda tienen que coincidir.
 *
 * Responde 200 rapido; crear la venta o confirmar el pedido va en la misma transaccion de base de
 * datos, y nada de correos fuera de `DB::afterCommit`.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(
        protected GatewayRegistry $gateways,
        protected GatewayPaymentService $payments,
    ) {}

    public function __invoke(Request $request, string $provider, string $token): JsonResponse
    {
        $account = PaymentGatewayAccount::query()->withoutGlobalScopes()
            ->where('provider', $provider)
            ->where('webhook_token', $token)
            ->first();

        if (! $account || ! $this->gateways->exists($provider)) {
            return response()->json(['message' => 'Cuenta desconocida.'], 404);
        }

        $gateway = $this->gateways->for($provider);

        try {
            $event = $gateway->verifyWebhook($request, $account);
        } catch (Throwable) {
            $event = null;
        }

        if ($event === null) {
            Log::warning('Pasarela de empresa: evento con firma invalida.', ['provider' => $provider, 'account' => $account->id, 'ip' => $request->ip()]);

            return response()->json(['message' => 'Firma inválida.'], 401);
        }

        if ($event->ignorable) {
            return response()->json(['message' => 'Evento ignorado.']);
        }

        $reference = $event->reference;

        // El aviso solo trae el id de la pasarela: la referencia sale de releerlo.
        if ($reference === null) {
            $looked = $gateway->lookup($account, $event);

            if ($looked === null) {
                return response()->json(['message' => 'No se pudo verificar la transacción.'], 503);
            }

            $reference = $looked->reference;
        }

        $transaction = $reference
            ? PaymentTransaction::query()->withoutGlobalScopes()->with('gatewayAccount')
                ->where('reference', $reference)
                ->where('gateway_account_id', $account->id)
                ->first()
            : null;

        if (! $transaction) {
            Log::info('Pasarela de empresa: evento de una referencia que no es nuestra.', ['provider' => $provider, 'reference' => $reference]);

            return response()->json(['message' => 'Referencia desconocida.']);
        }

        // La relectura manda, con el id que trajo el evento para preguntar.
        $probe = clone $transaction;

        if (! $probe->gateway_transaction_id && $event->gatewayTransactionId) {
            $probe->gateway_transaction_id = $event->gatewayTransactionId;
        }

        try {
            $fresh = $gateway->fetch($probe);
        } catch (Throwable) {
            $fresh = null;
        }

        if ($fresh === null) {
            return response()->json(['message' => 'No se pudo verificar la transacción.'], 503);
        }

        $this->payments->settle($transaction, $fresh);

        return response()->json(['message' => 'Recibido.']);
    }
}

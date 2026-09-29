<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\CompanySignup;
use App\Models\MembershipCharge;
use App\Services\Membership\MembershipBillingService;
use App\Services\Payments\PaymentGatewayResolver;
use App\Services\Platform\SignupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Los eventos de Wompi. Ruta publica, sin sesion y fuera de CSRF: aqui no se cree nada.
 *
 * 1. Firma invalida → 401 y log. Sin eso, cualquiera que conozca la URL mandaria un APPROVED.
 * 2. Solo `transaction.updated`.
 * 3. El estado y el monto se releen de la API: la firma prueba quien envia, no que el cuerpo
 *    siga siendo la ultima verdad.
 * 4. Se enruta por el prefijo de la referencia: `SIG-` contratacion, `MEM-` membresia.
 * 5. Idempotente: quien liquida encuentra lo ya liquidado y no hace nada.
 *
 * Responde 200 salvo firma invalida (401) o API caida (503, para que Wompi reintente).
 */
class WompiWebhookController extends Controller
{
    public function __construct(
        protected PaymentGatewayResolver $gateways,
        protected MembershipBillingService $billing,
        protected SignupService $signups,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $wompi = $this->gateways->wompi();
        $payload = $request->json()->all();

        if (! $wompi->verifyEventSignature($payload, $request->header('X-Event-Checksum'))) {
            Log::warning('Wompi: evento con firma invalida.', ['event' => $payload['event'] ?? null, 'ip' => $request->ip()]);

            return response()->json(['message' => 'Firma inválida.'], 401);
        }

        if (($payload['event'] ?? null) !== 'transaction.updated') {
            return response()->json(['message' => 'Evento ignorado.']);
        }

        $transactionId = data_get($payload, 'data.transaction.id');
        $reference = data_get($payload, 'data.transaction.reference');

        if (! is_scalar($transactionId) || ! is_string($reference) || $reference === '') {
            return response()->json(['message' => 'Evento sin transacción utilizable.']);
        }

        $fresh = $wompi->fetchTransaction((string) $transactionId);

        if ($fresh === null) {
            return response()->json(['message' => 'No se pudo verificar la transacción.'], 503);
        }

        if (($fresh['reference'] ?? null) !== $reference) {
            Log::warning('Wompi: la referencia del evento no coincide con la de la API.', ['transaction_id' => $transactionId]);

            return response()->json(['message' => 'Referencia inconsistente.']);
        }

        match (true) {
            str_starts_with($reference, MembershipCharge::REFERENCE_PREFIX) => $this->billing->settle($reference, $fresh),
            str_starts_with($reference, CompanySignup::REFERENCE_PREFIX) => $this->signups->settle($reference, $fresh),
            default => Log::info('Wompi: evento con una referencia que no es de la plataforma.', ['reference' => $reference]),
        };

        return response()->json(['message' => 'Recibido.']);
    }
}

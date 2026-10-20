<?php

namespace App\Payments\Gateways;

use App\Models\PaymentGatewayAccount;
use App\Models\PaymentTransaction;
use App\Services\Payments\WompiCredentials;
use App\Services\Payments\WompiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Wompi con la cuenta de una empresa. Usa el mismo `WompiService` que las membresias: las firmas
 * de integridad y de eventos no se duplican, cambian las llaves. Contrato en `config/wompi.php`.
 *
 * El link y el QR de la caja son el Web Checkout firmado con la referencia, el monto y la
 * expiracion: se abre en el celular del cliente y paga con PSE, Nequi o tarjeta. Asi la
 * transaccion lleva NUESTRA referencia y se relee por ella. Wompi no tiene datafono integrado.
 */
class WompiGateway extends AbstractGateway
{
    public function provider(): string
    {
        return 'wompi';
    }

    public function label(): string
    {
        return 'Wompi';
    }

    public function fields(): array
    {
        return [
            ['key' => 'public_key', 'label' => 'Llave pública', 'secret' => false, 'required' => true, 'hint' => 'Empieza por pub_test_ en pruebas y pub_prod_ en producción.'],
            ['key' => 'private_key', 'label' => 'Llave privada', 'secret' => true, 'required' => true, 'hint' => 'prv_test_… / prv_prod_…'],
            ['key' => 'events_secret', 'label' => 'Secreto de eventos', 'secret' => true, 'required' => true, 'hint' => 'Panel de Wompi > Desarrolladores > Secretos para integración técnica.'],
            ['key' => 'integrity_secret', 'label' => 'Secreto de integridad', 'secret' => true, 'required' => true],
        ];
    }

    public function capabilities(): array
    {
        return [self::CAPABILITY_CHECKOUT, self::CAPABILITY_LINK, self::CAPABILITY_QR];
    }

    public function webhookInstructions(): string
    {
        return 'En el panel de Wompi, Desarrolladores > URL de eventos: pega esta dirección en el entorno de la cuenta (pruebas o producción).';
    }

    public function service(PaymentGatewayAccount $account): WompiService
    {
        return new WompiService(WompiCredentials::fromAccount($account));
    }

    public function test(PaymentGatewayAccount $account): TestResult
    {
        $credentials = WompiCredentials::fromAccount($account);

        if (! $credentials->isComplete()) {
            return new TestResult(false, 'Faltan llaves: se necesitan las cuatro.');
        }

        $prefixes = (array) config('wompi.public_key_prefixes');

        if (isset($prefixes[$account->environment]) && ! str_starts_with((string) $credentials->publicKey, $prefixes[$account->environment])) {
            return new TestResult(false, 'La llave pública no es de '.($account->isSandbox() ? 'pruebas' : 'producción').'.');
        }

        $public = $this->service($account)->testConnection();

        if (! $public['ok']) {
            return new TestResult(false, $public['message']);
        }

        // La privada se prueba buscando una referencia que no existe: 200 con lista vacia.
        try {
            $response = Http::withToken((string) $credentials->privateKey)->acceptJson()->timeout(15)
                ->get($this->service($account)->apiBase().'/transactions', ['reference' => 'PRUEBA-CONEXION']);
        } catch (Throwable) {
            return new TestResult(false, 'No hubo respuesta de Wompi al probar la llave privada.');
        }

        if (! $response->successful()) {
            return new TestResult(false, 'Wompi no reconoce la llave privada de este entorno.');
        }

        return new TestResult(true, $public['message']);
    }

    public function createCheckout(PaymentTransaction $transaction, array $context): CheckoutSession
    {
        return new CheckoutSession(CheckoutSession::TYPE_REDIRECT, $this->signedUrl($transaction, $context['return_url'], $context['customer'] ?? []));
    }

    public function createLink(PaymentTransaction $transaction): LinkResult
    {
        $url = $this->signedUrl($transaction, route('payments.public', $transaction->reference));

        return new LinkResult($url, null, $url);
    }

    public function createQr(PaymentTransaction $transaction): LinkResult
    {
        return $this->createLink($transaction);
    }

    public function fetch(PaymentTransaction $transaction): ?GatewayStatus
    {
        $service = $this->service($this->account($transaction));

        $data = $transaction->gateway_transaction_id
            ? $service->fetchTransaction($transaction->gateway_transaction_id)
            : $service->findTransactionByReference($transaction->reference);

        return $data === null ? null : $this->status($data);
    }

    public function verifyWebhook(Request $request, PaymentGatewayAccount $account): ?GatewayEvent
    {
        $payload = $request->json()->all();

        if (! $this->service($account)->verifyEventSignature($payload, $request->header('X-Event-Checksum'))) {
            return null;
        }

        if (($payload['event'] ?? null) !== 'transaction.updated') {
            return new GatewayEvent(ignorable: true);
        }

        $reference = data_get($payload, 'data.transaction.reference');
        $id = data_get($payload, 'data.transaction.id');

        return new GatewayEvent(
            is_string($reference) ? $reference : null,
            is_scalar($id) ? (string) $id : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data  la transaccion releida de la API
     */
    public function status(array $data): GatewayStatus
    {
        $cents = isset($data['amount_in_cents']) ? (int) $data['amount_in_cents'] : null;

        return new GatewayStatus(
            match ((string) ($data['status'] ?? '')) {
                WompiService::STATUS_APPROVED => PaymentTransaction::STATUS_APPROVED,
                WompiService::STATUS_DECLINED => PaymentTransaction::STATUS_DECLINED,
                WompiService::STATUS_VOIDED => PaymentTransaction::STATUS_VOIDED,
                WompiService::STATUS_ERROR => PaymentTransaction::STATUS_ERROR,
                default => PaymentTransaction::STATUS_PENDING,
            },
            // Los centavos se vuelven pesos solo aqui. Centavos sueltos no cuadran con COP entero.
            $cents === null ? null : ($cents % 100 === 0 ? intdiv($cents, 100) : -1),
            isset($data['currency']) ? (string) $data['currency'] : null,
            isset($data['id']) ? (string) $data['id'] : null,
            $this->safeMessage($data['status_message'] ?? null, ''),
            self::sanitize(array_intersect_key($data, array_flip(['id', 'status', 'status_message', 'reference', 'amount_in_cents', 'currency', 'payment_method_type', 'created_at', 'finalized_at']))),
            isset($data['reference']) ? (string) $data['reference'] : null,
        );
    }

    /**
     * @param  array<string, ?string>  $customer
     */
    protected function signedUrl(PaymentTransaction $transaction, string $returnUrl, array $customer = []): string
    {
        $service = $this->service($this->account($transaction));

        if (! $service->isUsable()) {
            throw new GatewayException('La cuenta de Wompi no está completa o está apagada.');
        }

        $expires = $transaction->expires_at?->copy()->utc()->format('Y-m-d\TH:i:s.v\Z');

        return $service->checkoutUrl($transaction->reference, $transaction->amount * 100, $transaction->currency, $returnUrl, $customer, $expires);
    }
}

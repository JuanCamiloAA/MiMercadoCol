<?php

namespace App\Payments\Gateways;

use App\Models\PaymentGatewayAccount;
use App\Models\PaymentTransaction;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Throwable;

/**
 * Mercado Pago (Checkout Pro con preferencias). Contrato en `config/mercadopago.php`.
 *
 * La preferencia lleva NUESTRA referencia en `external_reference`; el pago se relee por id o se
 * busca por esa referencia. El aviso llega con el id del pago, asi que la referencia sale de
 * releerlo (`lookup`). En la caja, el `init_point` es el link y el QR.
 */
class MercadoPagoGateway extends AbstractGateway
{
    public function provider(): string
    {
        return 'mercadopago';
    }

    public function label(): string
    {
        return 'Mercado Pago';
    }

    public function fields(): array
    {
        return [
            ['key' => 'public_key', 'label' => 'Public key', 'secret' => false, 'required' => false, 'hint' => 'Credenciales de prueba o de producción, según el entorno.'],
            ['key' => 'access_token', 'label' => 'Access token', 'secret' => true, 'required' => true],
            ['key' => 'webhook_secret', 'label' => 'Clave secreta de webhooks', 'secret' => true, 'required' => true, 'hint' => 'Tus integraciones > Webhooks > Clave secreta.'],
        ];
    }

    public function capabilities(): array
    {
        return [self::CAPABILITY_CHECKOUT, self::CAPABILITY_LINK, self::CAPABILITY_QR, self::CAPABILITY_REFUND];
    }

    public function webhookInstructions(): string
    {
        return 'En Mercado Pago, Tus integraciones > Webhooks: pega esta URL en el modo que corresponda y marca el evento «Pagos». Copia la clave secreta que te muestra.';
    }

    public function test(PaymentGatewayAccount $account): TestResult
    {
        if (! $account->secretField('access_token') || ! $account->secretField('webhook_secret')) {
            return new TestResult(false, 'Faltan el access token o la clave secreta de webhooks.');
        }

        try {
            $response = $this->client($account)->get($this->base($account).'/users/me');
        } catch (Throwable) {
            return new TestResult(false, 'No hubo respuesta de Mercado Pago. Revisa la conexión del servidor.');
        }

        if (! $response->successful()) {
            return new TestResult(false, $response->status() === 401 ? 'Mercado Pago no reconoce el access token.' : "Mercado Pago respondió con el código {$response->status()}.");
        }

        $name = $response->json('nickname') ?? $response->json('first_name');

        return new TestResult(true, $name ? "Conexión correcta con la cuenta «{$name}»." : 'Conexión correcta.');
    }

    public function createCheckout(PaymentTransaction $transaction, array $context): CheckoutSession
    {
        $preference = $this->preference($transaction, $context['return_url'], $context['description'] ?? null, $context['customer']['email'] ?? null);

        return new CheckoutSession(CheckoutSession::TYPE_REDIRECT, $preference['url'], gatewayId: $preference['id']);
    }

    public function createLink(PaymentTransaction $transaction): LinkResult
    {
        $preference = $this->preference($transaction, route('payments.public', $transaction->reference), null, null);

        return new LinkResult($preference['url'], $preference['id'], $preference['url']);
    }

    public function createQr(PaymentTransaction $transaction): LinkResult
    {
        return $this->createLink($transaction);
    }

    public function fetch(PaymentTransaction $transaction): ?GatewayStatus
    {
        $account = $this->account($transaction);

        try {
            if ($transaction->gateway_transaction_id) {
                $response = $this->client($account)->get($this->base($account).'/v1/payments/'.rawurlencode($transaction->gateway_transaction_id));

                return $response->successful() && is_array($response->json()) ? $this->status($response->json()) : null;
            }

            $response = $this->client($account)->get($this->base($account).'/v1/payments/search', [
                'external_reference' => $transaction->reference,
                'sort' => 'date_created',
                'criteria' => 'desc',
            ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $results = collect((array) $response->json('results'))->filter(fn ($row) => is_array($row));

        if ($results->isEmpty()) {
            // Nadie ha pagado todavia: sigue pendiente.
            return new GatewayStatus(PaymentTransaction::STATUS_PENDING, reference: $transaction->reference);
        }

        // Si hubo varios intentos con la misma referencia, manda el aprobado.
        return $this->status($results->firstWhere('status', 'approved') ?? $results->first());
    }

    public function lookup(PaymentGatewayAccount $account, GatewayEvent $event): ?GatewayStatus
    {
        if (! $event->gatewayTransactionId) {
            return null;
        }

        try {
            $response = $this->client($account)->get($this->base($account).'/v1/payments/'.rawurlencode($event->gatewayTransactionId));
        } catch (Throwable) {
            return null;
        }

        return $response->successful() && is_array($response->json()) ? $this->status($response->json()) : null;
    }

    public function verifyWebhook(Request $request, PaymentGatewayAccount $account): ?GatewayEvent
    {
        $secret = (string) $account->secretField('webhook_secret');
        $header = (string) $request->header('x-signature');

        if ($secret === '' || $header === '') {
            return null;
        }

        $parts = [];

        foreach (explode(',', $header) as $piece) {
            [$key, $value] = array_pad(explode('=', trim($piece), 2), 2, '');
            $parts[trim($key)] = trim($value);
        }

        $ts = $parts['ts'] ?? '';
        $v1 = $parts['v1'] ?? '';
        $dataId = (string) ($request->query('data_id') ?? $request->query('data.id') ?? $request->input('data.id') ?? '');
        $requestId = (string) $request->header('x-request-id');

        if ($ts === '' || $v1 === '') {
            return null;
        }

        // Lo que no viene se quita de la plantilla; el id alfanumerico va en minusculas.
        $manifest = ($dataId !== '' ? 'id:'.strtolower($dataId).';' : '')
            .($requestId !== '' ? 'request-id:'.$requestId.';' : '')
            .'ts:'.$ts.';';

        if (! hash_equals(hash_hmac('sha256', $manifest, $secret), strtolower($v1))) {
            return null;
        }

        $type = (string) ($request->input('type') ?? $request->query('type') ?? $request->query('topic') ?? '');

        if ($type !== 'payment' || $dataId === '') {
            return new GatewayEvent(ignorable: true);
        }

        return new GatewayEvent(null, $dataId);
    }

    public function cancel(PaymentTransaction $transaction): bool
    {
        if (! $transaction->gateway_link_id) {
            return false;
        }

        $account = $this->account($transaction);

        try {
            // Una preferencia no se borra: se vence ya.
            return $this->client($account)->put($this->base($account).'/checkout/preferences/'.rawurlencode($transaction->gateway_link_id), [
                'expires' => true,
                'expiration_date_to' => now()->subMinute()->format('Y-m-d\TH:i:s.vP'),
            ])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public function refund(PaymentTransaction $transaction, int $amount): bool
    {
        if (! $transaction->gateway_transaction_id || $amount <= 0) {
            return false;
        }

        $account = $this->account($transaction);

        try {
            $response = $this->client($account)
                ->withHeaders(['X-Idempotency-Key' => $transaction->reference.'-R'.$amount.'-'.(int) $transaction->refunded_amount])
                ->post($this->base($account).'/v1/payments/'.rawurlencode($transaction->gateway_transaction_id).'/refunds', ['amount' => $amount]);
        } catch (Throwable) {
            return false;
        }

        return $response->successful();
    }

    /**
     * @param  array<string, mixed>  $payment  el pago de Mercado Pago
     */
    public function status(array $payment): GatewayStatus
    {
        $amount = isset($payment['transaction_amount']) ? (float) $payment['transaction_amount'] : null;

        return new GatewayStatus(
            match ((string) ($payment['status'] ?? '')) {
                'approved' => PaymentTransaction::STATUS_APPROVED,
                'rejected' => PaymentTransaction::STATUS_DECLINED,
                'cancelled', 'refunded', 'charged_back' => PaymentTransaction::STATUS_VOIDED,
                default => PaymentTransaction::STATUS_PENDING,
            },
            // Pesos con decimales no cuadran con COP entero: un monto partido se marca distinto.
            $amount === null ? null : (abs($amount - round($amount)) < 0.001 ? (int) round($amount) : -1),
            isset($payment['currency_id']) ? (string) $payment['currency_id'] : null,
            isset($payment['id']) ? (string) $payment['id'] : null,
            $this->safeMessage($payment['status_detail'] ?? null, ''),
            self::sanitize(array_intersect_key($payment, array_flip(['id', 'status', 'status_detail', 'external_reference', 'transaction_amount', 'currency_id', 'payment_method_id', 'payment_type_id', 'date_created', 'date_approved', 'live_mode']))),
            isset($payment['external_reference']) ? (string) $payment['external_reference'] : null,
        );
    }

    /**
     * @return array{id: string, url: string}
     */
    protected function preference(PaymentTransaction $transaction, string $returnUrl, ?string $description, ?string $email): array
    {
        $account = $this->account($transaction);
        $body = [
            'items' => [[
                'id' => $transaction->reference,
                'title' => mb_substr($description ?: 'Compra '.$transaction->reference, 0, 120),
                'quantity' => 1,
                'unit_price' => $transaction->amount,
                'currency_id' => $transaction->currency,
            ]],
            'external_reference' => $transaction->reference,
            'notification_url' => $account->webhookUrl(),
            'back_urls' => ['success' => $returnUrl, 'pending' => $returnUrl, 'failure' => $returnUrl],
            'auto_return' => 'approved',
            'statement_descriptor' => mb_substr((string) ($account->company?->name ?? ''), 0, 22) ?: null,
        ];

        if ($transaction->expires_at) {
            $body['expires'] = true;
            $body['expiration_date_to'] = $transaction->expires_at->copy()->timezone('America/Bogota')->format('Y-m-d\TH:i:s.vP');
        }

        if ($email) {
            $body['payer'] = ['email' => $email];
        }

        try {
            $response = $this->client($account)
                ->withHeaders(['X-Idempotency-Key' => $transaction->reference])
                ->post($this->base($account).'/checkout/preferences', array_filter($body, fn ($value) => $value !== null));
        } catch (Throwable) {
            throw new GatewayException('No pudimos comunicarnos con Mercado Pago.');
        }

        $url = $account->isSandbox()
            ? ($response->json('sandbox_init_point') ?: $response->json('init_point'))
            : $response->json('init_point');

        if (! $response->successful() || ! is_string($url) || ! is_string($response->json('id'))) {
            throw new GatewayException($this->failure($response));
        }

        return ['id' => (string) $response->json('id'), 'url' => $url];
    }

    protected function client(PaymentGatewayAccount $account): PendingRequest
    {
        return $this->http()->withToken((string) $account->secretField('access_token'))->asJson();
    }

    protected function base(PaymentGatewayAccount $account): string
    {
        return $this->host($account, 'mercadopago.hosts');
    }

    protected function failure(Response $response): string
    {
        return $this->safeMessage($response->json('message'), "Mercado Pago no aceptó el cobro (código {$response->status()}).");
    }
}

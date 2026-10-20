<?php

namespace App\Payments\Gateways;

use App\Models\PaymentGatewayAccount;
use App\Models\PaymentTerminal;
use App\Models\PaymentTransaction;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Throwable;

/**
 * Bold: links de pago (tienda, link y QR de la caja) y datafonos SmartPro por API Integrations.
 * Contrato en `config/bold.php`.
 *
 * El link lleva NUESTRA referencia; la venta se relee por ella en el comprobante de pago. El
 * resultado del datafono llega por webhook y tambien se relee por la referencia.
 */
class BoldGateway extends AbstractGateway
{
    public function provider(): string
    {
        return 'bold';
    }

    public function label(): string
    {
        return 'Bold';
    }

    public function fields(): array
    {
        return [
            ['key' => 'identity_key', 'label' => 'Llave de identidad', 'secret' => false, 'required' => true, 'hint' => 'Panel de Bold > Integraciones > Llaves (de pruebas o de producción).'],
            ['key' => 'secret_key', 'label' => 'Llave secreta', 'secret' => true, 'required' => false, 'hint' => 'Firma los webhooks. En pruebas Bold firma con la cadena vacía: déjala vacía.'],
            ['key' => 'user_email', 'label' => 'Correo del usuario de los datáfonos', 'secret' => false, 'required' => false, 'hint' => 'El que Bold pide para cobrar en un datáfono por API.'],
        ];
    }

    public function capabilities(): array
    {
        return [self::CAPABILITY_CHECKOUT, self::CAPABILITY_LINK, self::CAPABILITY_QR, self::CAPABILITY_TERMINAL];
    }

    public function webhookInstructions(): string
    {
        return 'En el panel de Bold, Integraciones > Webhooks: registra esta URL. Bold espera respuesta en menos de 2 segundos y reintenta cinco veces.';
    }

    public function test(PaymentGatewayAccount $account): TestResult
    {
        if (! $account->publicField('identity_key')) {
            return new TestResult(false, 'Falta la llave de identidad.');
        }

        if (! $account->isSandbox() && ! $account->secretField('secret_key')) {
            return new TestResult(false, 'En producción hace falta la llave secreta para verificar los webhooks.');
        }

        try {
            $response = $this->client($account)->get($this->base($account).'/online/link/v1/payment_methods');
        } catch (Throwable) {
            return new TestResult(false, 'No hubo respuesta de Bold. Revisa la conexión del servidor.');
        }

        return $response->successful()
            ? new TestResult(true, 'Conexión correcta con Bold.')
            : new TestResult(false, in_array($response->status(), [401, 403], true) ? 'Bold no reconoce la llave de identidad.' : "Bold respondió con el código {$response->status()}.");
    }

    public function createCheckout(PaymentTransaction $transaction, array $context): CheckoutSession
    {
        $link = $this->link($transaction, $context['return_url'], $context['description'] ?? null, $context['customer']['email'] ?? null);

        return new CheckoutSession(CheckoutSession::TYPE_REDIRECT, $link['url'], gatewayId: $link['id']);
    }

    public function createLink(PaymentTransaction $transaction): LinkResult
    {
        $link = $this->link($transaction, route('payments.public', $transaction->reference), null, null);

        return new LinkResult($link['url'], $link['id'], $link['url']);
    }

    public function createQr(PaymentTransaction $transaction): LinkResult
    {
        return $this->createLink($transaction);
    }

    public function createTerminalIntent(PaymentTransaction $transaction, PaymentTerminal $terminal): TerminalResult
    {
        $account = $this->account($transaction);

        try {
            $response = $this->client($account)->post($this->base($account).'/payments/app-checkout', [
                'amount' => [
                    'currency' => $transaction->currency,
                    'total_amount' => $transaction->amount,
                    'taxes' => [],
                    'tip_amount' => 0,
                ],
                'payment_method' => 'POS',
                'terminal_model' => (string) ($terminal->model ?: 'N86'),
                'terminal_serial' => $terminal->device_id,
                'reference' => $transaction->reference,
                'user_email' => (string) ($account->publicField('user_email') ?? $account->company?->email ?? ''),
                'description' => 'Venta '.$transaction->reference,
            ]);
        } catch (Throwable) {
            throw new GatewayException('No pudimos comunicarnos con el datáfono de Bold.');
        }

        $id = $response->json('payload.integration_id');

        if (! $response->successful() || ! is_string($id)) {
            throw new GatewayException($this->safeMessage(data_get($response->json(), 'errors.0.message'), "Bold no aceptó el cobro en el datáfono (código {$response->status()})."));
        }

        return new TerminalResult($id, 'Cobro enviado al datáfono.');
    }

    public function fetch(PaymentTransaction $transaction): ?GatewayStatus
    {
        $account = $this->account($transaction);

        try {
            $response = $this->client($account)->get(config('bold.voucher_url').rawurlencode($transaction->reference));
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful() || ! is_array($response->json())) {
            return $response->status() === 404 ? $this->linkStatus($transaction) : null;
        }

        $data = (array) $response->json();
        $status = (string) ($data['payment_status'] ?? '');

        if ($status === 'NO_TRANSACTION_FOUND' || $status === '') {
            return $this->linkStatus($transaction) ?? new GatewayStatus(PaymentTransaction::STATUS_PENDING, reference: $transaction->reference);
        }

        $total = isset($data['total']) ? (float) $data['total'] : null;

        return new GatewayStatus(
            match ($status) {
                'APPROVED' => PaymentTransaction::STATUS_APPROVED,
                'REJECTED', 'FAILED' => PaymentTransaction::STATUS_DECLINED,
                'VOIDED' => PaymentTransaction::STATUS_VOIDED,
                default => PaymentTransaction::STATUS_PENDING,
            },
            $total === null ? null : (abs($total - round($total)) < 0.001 ? (int) round($total) : -1),
            // El comprobante no trae moneda: Bold cobra en pesos los links que se crearon en COP.
            'COP',
            isset($data['transaction_id']) ? (string) $data['transaction_id'] : null,
            null,
            self::sanitize(array_intersect_key($data, array_flip(['payment_status', 'transaction_id', 'reference_id', 'total', 'transaction_date', 'payment_method']))),
            isset($data['reference_id']) ? (string) $data['reference_id'] : $transaction->reference,
        );
    }

    public function verifyWebhook(Request $request, PaymentGatewayAccount $account): ?GatewayEvent
    {
        $header = strtolower((string) $request->header('x-bold-signature'));

        if ($header === '') {
            return null;
        }

        // En pruebas Bold firma con la cadena vacia.
        $secret = (string) ($account->secretField('secret_key') ?? '');

        if ($secret === '' && ! $account->isSandbox()) {
            return null;
        }

        $expected = hash_hmac('sha256', base64_encode($request->getContent()), $secret);

        if (! hash_equals($expected, $header)) {
            return null;
        }

        $type = (string) $request->input('type');

        if (! in_array($type, ['SALE_APPROVED', 'SALE_REJECTED', 'VOID_APPROVED'], true)) {
            return new GatewayEvent(ignorable: true);
        }

        $reference = $request->input('data.metadata.reference');
        $paymentId = $request->input('data.payment_id') ?? $request->input('subject');

        return new GatewayEvent(
            is_string($reference) && $reference !== '' ? $reference : null,
            is_scalar($paymentId) ? (string) $paymentId : null,
        );
    }

    /** Si nadie ha pagado, el link dice si ya vencio o lo cancelaron. */
    protected function linkStatus(PaymentTransaction $transaction): ?GatewayStatus
    {
        if (! $transaction->gateway_link_id) {
            return null;
        }

        $account = $this->account($transaction);

        try {
            $response = $this->client($account)->get($this->base($account).'/online/link/v1/'.rawurlencode($transaction->gateway_link_id));
        } catch (Throwable) {
            return null;
        }

        $status = (string) ($response->json('status') ?? $response->json('payload.status') ?? '');

        return match ($status) {
            'EXPIRED' => new GatewayStatus(PaymentTransaction::STATUS_EXPIRED, reference: $transaction->reference),
            'CANCELLED' => new GatewayStatus(PaymentTransaction::STATUS_VOIDED, reference: $transaction->reference),
            'REJECTED' => new GatewayStatus(PaymentTransaction::STATUS_DECLINED, reference: $transaction->reference),
            default => new GatewayStatus(PaymentTransaction::STATUS_PENDING, reference: $transaction->reference),
        };
    }

    /**
     * @return array{id: string, url: string}
     */
    protected function link(PaymentTransaction $transaction, string $callback, ?string $description, ?string $email): array
    {
        $account = $this->account($transaction);
        $body = [
            'amount_type' => 'CLOSE',
            'amount' => ['currency' => $transaction->currency, 'total_amount' => $transaction->amount, 'taxes' => []],
            'reference' => $transaction->reference,
            'description' => mb_substr($description ?: 'Compra '.$transaction->reference, 0, 100),
            'callback_url' => $callback,
        ];

        if ($transaction->expires_at) {
            // Bold pide el vencimiento en nanosegundos.
            $body['expiration_date'] = $transaction->expires_at->getTimestamp() * 1_000_000_000;
        }

        if ($email) {
            $body['payer_email'] = $email;
        }

        try {
            $response = $this->client($account)->post($this->base($account).'/online/link/v1', $body);
        } catch (Throwable) {
            throw new GatewayException('No pudimos comunicarnos con Bold.');
        }

        $id = $response->json('payload.payment_link');
        $url = $response->json('payload.url');

        if (! $response->successful() || ! is_string($id) || ! is_string($url)) {
            throw new GatewayException($this->safeMessage(data_get($response->json(), 'errors.0.message'), "Bold no aceptó el cobro (código {$response->status()})."));
        }

        return ['id' => $id, 'url' => $url];
    }

    protected function client(PaymentGatewayAccount $account): PendingRequest
    {
        return $this->http()->withHeaders(['Authorization' => 'x-api-key '.$account->publicField('identity_key')])->asJson();
    }

    protected function base(PaymentGatewayAccount $account): string
    {
        return $this->host($account, 'bold.hosts');
    }
}

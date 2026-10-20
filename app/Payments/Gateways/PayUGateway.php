<?php

namespace App\Payments\Gateways;

use App\Models\PaymentGatewayAccount;
use App\Models\PaymentTransaction;
use Illuminate\Http\Request;
use Throwable;

/**
 * PayU Latam (WebCheckout). Contrato en `config/payu.php`.
 *
 * El cliente va a PayU con un formulario que el navegador envia solo: los datos de la tarjeta se
 * escriben en PayU, nunca aqui. El aviso es la «pagina de confirmacion», que llega por POST con
 * su propia firma, y el estado se relee con la API de consultas por nuestra referencia.
 */
class PayUGateway extends AbstractGateway
{
    public function provider(): string
    {
        return 'payu';
    }

    public function label(): string
    {
        return 'PayU';
    }

    public function fields(): array
    {
        return [
            ['key' => 'merchant_id', 'label' => 'Merchant ID', 'secret' => false, 'required' => true],
            ['key' => 'account_id', 'label' => 'Account ID (Colombia)', 'secret' => false, 'required' => true],
            ['key' => 'api_login', 'label' => 'API Login', 'secret' => true, 'required' => true],
            ['key' => 'api_key', 'label' => 'API Key', 'secret' => true, 'required' => true],
        ];
    }

    public function capabilities(): array
    {
        return [self::CAPABILITY_CHECKOUT];
    }

    public function webhookInstructions(): string
    {
        return 'PayU no se configura en su panel: esta URL viaja con cada pago como «página de confirmación». Solo verifica que el servidor sea accesible desde internet.';
    }

    public function test(PaymentGatewayAccount $account): TestResult
    {
        foreach (['merchant_id', 'account_id'] as $field) {
            if (! $account->publicField($field)) {
                return new TestResult(false, 'Faltan el Merchant ID o el Account ID.');
            }
        }

        if (! $account->secretField('api_login') || ! $account->secretField('api_key')) {
            return new TestResult(false, 'Faltan el API Login o la API Key.');
        }

        try {
            $response = $this->http()->asJson()->post($this->host($account, 'payu.queries'), $this->query($account, 'PING'));
        } catch (Throwable) {
            return new TestResult(false, 'No hubo respuesta de PayU. Revisa la conexión del servidor.');
        }

        return $response->successful() && $response->json('code') === 'SUCCESS'
            ? new TestResult(true, 'Conexión correcta con PayU.')
            : new TestResult(false, $this->safeMessage($response->json('error'), 'PayU no reconoce el API Login o la API Key.'));
    }

    public function createCheckout(PaymentTransaction $transaction, array $context): CheckoutSession
    {
        $account = $this->account($transaction);
        $merchant = (string) $account->publicField('merchant_id');
        $amount = (string) $transaction->amount;

        if ($merchant === '' || ! $account->publicField('account_id') || ! $account->secretField('api_key')) {
            throw new GatewayException('La cuenta de PayU no está completa.');
        }

        return new CheckoutSession(CheckoutSession::TYPE_FORM, $this->host($account, 'payu.checkout'), [
            'merchantId' => $merchant,
            'accountId' => (string) $account->publicField('account_id'),
            'description' => mb_substr($context['description'] ?? 'Compra '.$transaction->reference, 0, 255),
            'referenceCode' => $transaction->reference,
            'amount' => $amount,
            'tax' => '0',
            'taxReturnBase' => '0',
            'currency' => $transaction->currency,
            'signature' => hash('sha256', $account->secretField('api_key').'~'.$merchant.'~'.$transaction->reference.'~'.$amount.'~'.$transaction->currency),
            'algorithmSignature' => 'SHA256',
            'test' => $account->isSandbox() ? '1' : '0',
            'buyerEmail' => (string) ($context['customer']['email'] ?? ''),
            'responseUrl' => $context['return_url'],
            'confirmationUrl' => $account->webhookUrl(),
        ]);
    }

    public function fetch(PaymentTransaction $transaction): ?GatewayStatus
    {
        $account = $this->account($transaction);

        try {
            $response = $this->http()->asJson()->post($this->host($account, 'payu.queries'), $this->query($account, 'ORDER_DETAIL_BY_REFERENCE_CODE', [
                'referenceCode' => $transaction->reference,
            ]));
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful() || $response->json('code') !== 'SUCCESS') {
            return null;
        }

        $orders = collect((array) $response->json('result.payload'))->filter(fn ($order) => is_array($order));

        if ($orders->isEmpty()) {
            return new GatewayStatus(PaymentTransaction::STATUS_PENDING, reference: $transaction->reference);
        }

        // Varios intentos con la misma referencia: manda el aprobado.
        $rows = $orders->flatMap(fn (array $order) => collect((array) ($order['transactions'] ?? []))->map(fn ($tx) => ['order' => $order, 'tx' => (array) $tx]));
        $row = $rows->first(fn (array $item) => ($item['tx']['transactionResponse']['state'] ?? null) === 'APPROVED') ?? $rows->first();

        if (! $row) {
            return new GatewayStatus(PaymentTransaction::STATUS_PENDING, reference: $transaction->reference);
        }

        $value = data_get($row['order'], 'additionalValues.TX_VALUE.value') ?? data_get($row['tx'], 'additionalValues.TX_VALUE.value');
        $currency = data_get($row['order'], 'additionalValues.TX_VALUE.currency') ?? data_get($row['tx'], 'additionalValues.TX_VALUE.currency');

        return new GatewayStatus(
            $this->map((string) data_get($row['tx'], 'transactionResponse.state')),
            $value === null ? null : $this->pesos((float) $value),
            $currency ? (string) $currency : null,
            isset($row['tx']['id']) ? (string) $row['tx']['id'] : null,
            $this->safeMessage(data_get($row['tx'], 'transactionResponse.responseCode'), ''),
            self::sanitize(['order_id' => $row['order']['id'] ?? null, 'reference' => $row['order']['referenceCode'] ?? null, 'state' => data_get($row['tx'], 'transactionResponse.state'), 'response_code' => data_get($row['tx'], 'transactionResponse.responseCode')]),
            isset($row['order']['referenceCode']) ? (string) $row['order']['referenceCode'] : $transaction->reference,
        );
    }

    public function verifyWebhook(Request $request, PaymentGatewayAccount $account): ?GatewayEvent
    {
        $merchant = (string) $request->input('merchant_id');
        $reference = (string) $request->input('reference_sale');
        $value = (string) $request->input('value');
        $currency = (string) $request->input('currency');
        $state = (string) $request->input('state_pol');
        $sign = strtolower((string) $request->input('sign'));
        $apiKey = (string) $account->secretField('api_key');

        if ($apiKey === '' || $sign === '' || $merchant !== (string) $account->publicField('merchant_id') || $reference === '') {
            return null;
        }

        $base = implode('~', [$apiKey, $merchant, $reference, self::confirmationValue($value), $currency, $state]);

        // La firma de la confirmacion puede venir con el algoritmo que tenga configurado la cuenta.
        $valid = collect(['md5', 'sha1', 'sha256'])->contains(fn (string $algorithm) => hash_equals(hash($algorithm, $base), $sign))
            || hash_equals(hash_hmac('sha256', $base, $apiKey), $sign);

        if (! $valid) {
            return null;
        }

        return new GatewayEvent($reference, $request->input('transaction_id') ? (string) $request->input('transaction_id') : null);
    }

    /**
     * El valor de la firma de confirmacion: un decimal si el segundo es cero (150.00 → 150.0) y
     * dos si no (150.25 → 150.25).
     */
    public static function confirmationValue(string $value): string
    {
        $formatted = number_format((float) $value, 2, '.', '');

        return str_ends_with($formatted, '0') ? substr($formatted, 0, -1) : $formatted;
    }

    protected function map(string $state): string
    {
        return match ($state) {
            'APPROVED' => PaymentTransaction::STATUS_APPROVED,
            'DECLINED' => PaymentTransaction::STATUS_DECLINED,
            'EXPIRED' => PaymentTransaction::STATUS_EXPIRED,
            'ERROR' => PaymentTransaction::STATUS_ERROR,
            default => PaymentTransaction::STATUS_PENDING,
        };
    }

    protected function pesos(float $value): int
    {
        return abs($value - round($value)) < 0.001 ? (int) round($value) : -1;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    protected function query(PaymentGatewayAccount $account, string $command, array $details = []): array
    {
        return array_filter([
            'test' => $account->isSandbox(),
            'language' => 'es',
            'command' => $command,
            'merchant' => ['apiLogin' => (string) $account->secretField('api_login'), 'apiKey' => (string) $account->secretField('api_key')],
            'details' => $details === [] ? null : $details,
        ], fn ($value) => $value !== null);
    }
}

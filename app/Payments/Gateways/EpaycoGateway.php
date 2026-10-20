<?php

namespace App\Payments\Gateways;

use App\Models\PaymentGatewayAccount;
use App\Models\PaymentTransaction;
use Illuminate\Http\Request;
use Throwable;

/**
 * ePayco (checkout estandar con la llave publica). Contrato en `config/epayco.php`.
 *
 * El checkout se abre con su widget desde la pagina: al navegador solo viaja la llave publica y
 * los datos del cobro, nunca las secretas. La confirmacion llega con `x_signature`; la relectura
 * es por `ref_payco`, el numero de ePayco, que trae el aviso (o la vuelta del cliente, como pista
 * que se verifica contra nuestra referencia).
 */
class EpaycoGateway extends AbstractGateway
{
    public function provider(): string
    {
        return 'epayco';
    }

    public function label(): string
    {
        return 'ePayco';
    }

    public function fields(): array
    {
        return [
            ['key' => 'public_key', 'label' => 'Llave pública (PUBLIC_KEY)', 'secret' => false, 'required' => true],
            ['key' => 'p_cust_id_cliente', 'label' => 'P_CUST_ID_CLIENTE', 'secret' => true, 'required' => true],
            ['key' => 'p_key', 'label' => 'P_KEY', 'secret' => true, 'required' => true],
            ['key' => 'private_key', 'label' => 'Llave privada (PRIVATE_KEY)', 'secret' => true, 'required' => false],
        ];
    }

    public function capabilities(): array
    {
        return [self::CAPABILITY_CHECKOUT];
    }

    public function webhookInstructions(): string
    {
        return 'Esta URL viaja con cada pago como «URL de confirmación». Si en el panel de ePayco tienes una de confirmación global, pon esta misma.';
    }

    public function test(PaymentGatewayAccount $account): TestResult
    {
        if (! $account->publicField('public_key') || ! $account->secretField('p_cust_id_cliente') || ! $account->secretField('p_key')) {
            return new TestResult(false, 'Faltan la llave pública, P_CUST_ID_CLIENTE o P_KEY.');
        }

        try {
            $response = $this->http(10)->get(config('epayco.validation_url').'PRUEBA-CONEXION');
        } catch (Throwable) {
            return new TestResult(false, 'No hubo respuesta de ePayco. Revisa la conexión del servidor.');
        }

        // ePayco no tiene una consulta que valide las llaves sin un pago: se confirma que responde.
        return $response->status() < 500
            ? new TestResult(true, 'ePayco responde. Las llaves se confirman con el primer pago de prueba.')
            : new TestResult(false, "ePayco respondió con el código {$response->status()}.");
    }

    public function createCheckout(PaymentTransaction $transaction, array $context): CheckoutSession
    {
        $account = $this->account($transaction);
        $key = $account->publicField('public_key');

        if (! $key) {
            throw new GatewayException('La cuenta de ePayco no está completa.');
        }

        return new CheckoutSession(CheckoutSession::TYPE_WIDGET, widget: [
            'script' => (string) config('epayco.script'),
            'config' => ['key' => $key, 'test' => $account->isSandbox()],
            'data' => [
                'name' => mb_substr($context['description'] ?? 'Compra', 0, 120),
                'description' => mb_substr($context['description'] ?? 'Compra '.$transaction->reference, 0, 255),
                'invoice' => $transaction->reference,
                'currency' => strtolower($transaction->currency),
                'amount' => (string) $transaction->amount,
                'tax_base' => '0',
                'tax' => '0',
                'country' => 'co',
                'lang' => 'es',
                'external' => 'true',
                'response' => $context['return_url'],
                'confirmation' => $account->webhookUrl(),
                'extra1' => $transaction->reference,
                'email_billing' => (string) ($context['customer']['email'] ?? ''),
                'name_billing' => (string) ($context['customer']['full-name'] ?? ''),
            ],
        ]);
    }

    public function fetch(PaymentTransaction $transaction): ?GatewayStatus
    {
        $refPayco = $transaction->gateway_transaction_id;

        if (! $refPayco) {
            // Sin el numero de ePayco no hay como consultarla: sigue pendiente hasta el aviso.
            return null;
        }

        return $this->byRefPayco($refPayco, $transaction->reference);
    }

    public function lookup(PaymentGatewayAccount $account, GatewayEvent $event): ?GatewayStatus
    {
        return $event->gatewayTransactionId ? $this->byRefPayco($event->gatewayTransactionId, $event->reference) : null;
    }

    public function verifyWebhook(Request $request, PaymentGatewayAccount $account): ?GatewayEvent
    {
        $customer = (string) $account->secretField('p_cust_id_cliente');
        $pKey = (string) $account->secretField('p_key');
        $signature = strtolower((string) $request->input('x_signature'));

        if ($customer === '' || $pKey === '' || $signature === '') {
            return null;
        }

        $expected = hash('sha256', implode('^', [
            $customer,
            $pKey,
            (string) $request->input('x_ref_payco'),
            (string) $request->input('x_transaction_id'),
            (string) $request->input('x_amount'),
            (string) $request->input('x_currency_code'),
        ]));

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $reference = (string) ($request->input('x_id_invoice') ?: $request->input('x_extra1'));

        return new GatewayEvent($reference !== '' ? $reference : null, (string) $request->input('x_ref_payco') ?: null);
    }

    protected function byRefPayco(string $refPayco, ?string $expectedReference): ?GatewayStatus
    {
        try {
            $response = $this->http()->get(config('epayco.validation_url').rawurlencode($refPayco));
        } catch (Throwable) {
            return null;
        }

        $data = $response->json('data');

        if (! $response->successful() || ! is_array($data)) {
            return null;
        }

        $reference = (string) ($data['x_id_invoice'] ?? $data['x_extra1'] ?? '');

        // Una pista que no es de esta referencia no se cree.
        if ($expectedReference !== null && $reference !== '' && $reference !== $expectedReference) {
            return null;
        }

        $amount = isset($data['x_amount']) ? (float) $data['x_amount'] : null;

        return new GatewayStatus(
            match ((int) ($data['x_cod_response'] ?? 0)) {
                1 => PaymentTransaction::STATUS_APPROVED,
                2, 4 => PaymentTransaction::STATUS_DECLINED,
                6, 11 => PaymentTransaction::STATUS_VOIDED,
                10 => PaymentTransaction::STATUS_EXPIRED,
                default => PaymentTransaction::STATUS_PENDING,
            },
            $amount === null ? null : (abs($amount - round($amount)) < 0.001 ? (int) round($amount) : -1),
            isset($data['x_currency_code']) ? strtoupper((string) $data['x_currency_code']) : null,
            (string) ($data['x_ref_payco'] ?? $refPayco),
            $this->safeMessage($data['x_response_reason_text'] ?? $data['x_response'] ?? null, ''),
            self::sanitize(array_intersect_key($data, array_flip(['x_ref_payco', 'x_id_invoice', 'x_cod_response', 'x_response', 'x_response_reason_text', 'x_amount', 'x_currency_code', 'x_franchise', 'x_transaction_date', 'x_test_request']))),
            $reference !== '' ? $reference : $expectedReference,
        );
    }
}

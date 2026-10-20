<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayAccount;
use App\Models\PaymentTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\CreatesPaymentGateways;
use Tests\TestCase;

/**
 * El webhook de cada conector con su contrato verificado: firma con el secreto de ESA cuenta,
 * relectura en la pasarela e idempotencia por referencia. Las respuestas de las pasarelas se
 * fingen con `Http::fake`; las formas son las de su documentacion (resumidas en `config/`).
 */
class GatewayConnectorWebhookTest extends TestCase
{
    use CreatesCompanyEnvironment, CreatesPaymentGateways, RefreshDatabase;

    protected const AMOUNT = 10300;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
    }

    protected function pending(PaymentGatewayAccount $account): PaymentTransaction
    {
        return PaymentTransaction::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'gateway_account_id' => $account->id,
            'provider' => $account->provider,
            'environment' => $account->environment,
            'reference' => PaymentTransaction::newReference(),
            'amount' => self::AMOUNT,
            'currency' => 'COP',
            'channel' => PaymentTransaction::CHANNEL_ONLINE,
            'mode' => PaymentTransaction::MODE_CHECKOUT,
            'status' => PaymentTransaction::STATUS_PENDING,
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    protected function url(PaymentGatewayAccount $account, string $query = ''): string
    {
        return route('webhooks.payments', ['provider' => $account->provider, 'token' => $account->webhook_token]).$query;
    }

    protected function assertSettledOnce(PaymentTransaction $transaction, callable $send): void
    {
        $send()->assertOk();
        $send()->assertOk();

        $transaction->refresh();
        $this->assertSame(PaymentTransaction::STATUS_APPROVED, $transaction->status);
        $this->assertNotNull($transaction->approved_at);
    }

    public function test_un_token_desconocido_responde_404(): void
    {
        $this->postJson(route('webhooks.payments', ['provider' => 'wompi', 'token' => 'no-existe']), [])->assertNotFound();
    }

    public function test_el_token_de_una_cuenta_no_sirve_para_otra_pasarela(): void
    {
        $account = $this->wompiAccount();

        $this->postJson(route('webhooks.payments', ['provider' => 'bold', 'token' => $account->webhook_token]), [])->assertNotFound();
    }

    public function test_mercado_pago(): void
    {
        $account = $this->gatewayAccount('mercadopago', ['public_key' => 'TEST-pub'], ['access_token' => 'TEST-token', 'webhook_secret' => 'mp_secreto']);
        $transaction = $this->pending($account);

        Http::fake(['api.mercadopago.com/v1/payments/*' => Http::response([
            'id' => 123456789,
            'status' => 'approved',
            'status_detail' => 'accredited',
            'external_reference' => $transaction->reference,
            'transaction_amount' => self::AMOUNT,
            'currency_id' => 'COP',
        ])]);

        $send = function (string $secret = 'mp_secreto') use ($account): TestResponse {
            $ts = '1760000000';
            $manifest = 'id:123456789;request-id:req-abc;ts:'.$ts.';';

            return $this->withHeaders([
                'x-signature' => 'ts='.$ts.',v1='.hash_hmac('sha256', $manifest, $secret),
                'x-request-id' => 'req-abc',
            ])->postJson($this->url($account, '?data.id=123456789&type=payment'), ['type' => 'payment', 'data' => ['id' => '123456789']]);
        };

        $send('otro')->assertStatus(401);
        $this->assertSame(PaymentTransaction::STATUS_PENDING, $transaction->fresh()->status);

        $this->assertSettledOnce($transaction, fn () => $send());
        $this->assertSame('123456789', $transaction->fresh()->gateway_transaction_id);
    }

    public function test_payu(): void
    {
        $account = $this->gatewayAccount('payu', ['merchant_id' => '508029', 'account_id' => '512321'], ['api_login' => 'pRRXKOl8ikMmt9u', 'api_key' => '4Vj8eK4rloUd272L48hsrarnUA']);
        $transaction = $this->pending($account);

        Http::fake(['sandbox.api.payulatam.com/*' => Http::response([
            'code' => 'SUCCESS',
            'result' => ['payload' => [[
                'id' => 844,
                'referenceCode' => $transaction->reference,
                'additionalValues' => ['TX_VALUE' => ['value' => self::AMOUNT, 'currency' => 'COP']],
                'transactions' => [['id' => 'payu-tx-1', 'transactionResponse' => ['state' => 'APPROVED', 'responseCode' => 'APPROVED']]],
            ]]],
        ])]);

        // La confirmacion llega como formulario; el valor se firma con un decimal (10300.0).
        $send = function (string $apiKey = '4Vj8eK4rloUd272L48hsrarnUA'): TestResponse {
            $reference = PaymentTransaction::query()->withoutGlobalScopes()->value('reference');

            return $this->post($this->url(PaymentGatewayAccount::query()->withoutGlobalScopes()->where('provider', 'payu')->firstOrFail()), [
                'merchant_id' => '508029',
                'reference_sale' => $reference,
                'value' => '10300.00',
                'currency' => 'COP',
                'state_pol' => '4',
                'transaction_id' => 'payu-tx-1',
                'sign' => md5(implode('~', [$apiKey, '508029', $reference, '10300.0', 'COP', '4'])),
            ]);
        };

        $send('llave-equivocada')->assertStatus(401);
        $this->assertSettledOnce($transaction, fn () => $send());
    }

    public function test_epayco(): void
    {
        $account = $this->gatewayAccount('epayco', ['public_key' => 'pk_test'], ['p_cust_id_cliente' => '12345', 'p_key' => 'p_key_secreta']);
        $transaction = $this->pending($account);

        Http::fake(['secure.epayco.co/validation/v1/reference/*' => Http::response(['success' => true, 'data' => [
            'x_ref_payco' => 98765,
            'x_id_invoice' => $transaction->reference,
            'x_cod_response' => 1,
            'x_response' => 'Aceptada',
            'x_amount' => self::AMOUNT,
            'x_currency_code' => 'COP',
        ]])]);

        $send = function (string $pKey = 'p_key_secreta') use ($transaction): TestResponse {
            return $this->post($this->url(PaymentGatewayAccount::query()->withoutGlobalScopes()->where('provider', 'epayco')->firstOrFail()), [
                'x_ref_payco' => '98765',
                'x_transaction_id' => '555',
                'x_amount' => '10300',
                'x_currency_code' => 'COP',
                'x_id_invoice' => $transaction->reference,
                'x_signature' => hash('sha256', implode('^', ['12345', $pKey, '98765', '555', '10300', 'COP'])),
            ]);
        };

        $send('otra')->assertStatus(401);
        $this->assertSettledOnce($transaction, fn () => $send());
    }

    public function test_bold(): void
    {
        // En pruebas Bold firma con la cadena vacia.
        $account = $this->gatewayAccount('bold', ['identity_key' => 'idk_test'], []);
        $transaction = $this->pending($account);

        Http::fake(['payments.api.bold.co/v2/payment-voucher/*' => Http::response([
            'payment_status' => 'APPROVED',
            'transaction_id' => 'bold-tx-1',
            'reference_id' => $transaction->reference,
            'total' => self::AMOUNT,
        ])]);

        $send = function (string $secret = '') use ($account, $transaction): TestResponse {
            $body = json_encode([
                'id' => 'evt-1',
                'type' => 'SALE_APPROVED',
                'subject' => 'bold-tx-1',
                'data' => ['payment_id' => 'bold-tx-1', 'amount' => ['total' => self::AMOUNT], 'metadata' => ['reference' => $transaction->reference]],
            ]);

            return $this->call('POST', $this->url($account), [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_BOLD_SIGNATURE' => hash_hmac('sha256', base64_encode($body), $secret),
            ], $body);
        };

        $send('no-es-el-secreto')->assertStatus(401);
        $this->assertSettledOnce($transaction, fn () => $send());
    }

    public function test_si_la_pasarela_no_responde_se_pide_reintento(): void
    {
        $account = $this->wompiAccount();
        $transaction = $this->pending($account);
        Http::fake(['sandbox.wompi.co/*' => Http::response([], 500)]);

        $this->postJson($this->url($account), $this->wompiEvent($this->wompiTransaction('tx-1', $transaction->reference, self::AMOUNT)))->assertStatus(503);
        $this->assertSame(PaymentTransaction::STATUS_PENDING, $transaction->fresh()->status);
    }

    public function test_una_referencia_ajena_se_ignora(): void
    {
        $account = $this->wompiAccount();
        $this->fakeWompi($this->wompiTransaction('tx-1', 'PAY-DEOTRO', self::AMOUNT));

        $this->postJson($this->url($account), $this->wompiEvent($this->wompiTransaction('tx-1', 'PAY-DEOTRO', self::AMOUNT)))->assertOk();
        $this->assertSame(0, PaymentTransaction::query()->withoutGlobalScopes()->count());
    }
}

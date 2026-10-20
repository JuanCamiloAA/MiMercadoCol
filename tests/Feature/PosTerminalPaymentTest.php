<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayAccount;
use App\Models\PaymentMethod;
use App\Models\PaymentTerminal;
use App\Models\PaymentTransaction;
use App\Models\Register;
use App\Models\RegisterSession;
use App\Models\Sale;
use App\Models\User;
use App\Services\RegisterSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\CreatesPaymentGateways;
use Tests\TestCase;

/**
 * Datafono integrado (Bold): la caja le manda el cobro al aparato de su caja y la venta nace con
 * la confirmacion de la pasarela, por webhook firmado.
 */
class PosTerminalPaymentTest extends TestCase
{
    use CreatesCompanyEnvironment, CreatesPaymentGateways, RefreshDatabase;

    protected User $cashier;

    protected RegisterSession $session;

    protected PaymentGatewayAccount $bold;

    protected PaymentMethod $method;

    protected PaymentTerminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->cashier = $this->makeUser('admin');
        $this->session = app(RegisterSessionService::class)->open($this->register, $this->cashier, 50000);
        $this->bold = $this->gatewayAccount('bold', ['identity_key' => 'idk_test', 'user_email' => 'caja@lacanasta.co'], []);
        $this->method = $this->gatewayMethod(PaymentMethod::KIND_GATEWAY_TERMINAL, $this->bold, ['name' => 'Datáfono Bold']);
        $this->terminal = PaymentTerminal::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'register_id' => $this->register->id,
            'gateway_account_id' => $this->bold->id,
            'device_id' => 'SN-0001',
            'model' => 'SmartPro',
            'name' => 'Datáfono caja 1',
            'is_active' => true,
        ]);
    }

    public function test_la_caja_ofrece_el_datafono_de_su_caja(): void
    {
        $methods = $this->actingAs($this->cashier)->withSession($this->withActiveStore())->get(route('pos.index'))->viewData('page')['props']['paymentMethods'];
        $terminal = collect($methods)->firstWhere('id', $this->method->id);

        $this->assertNotNull($terminal);
        $this->assertSame([['id' => $this->terminal->id, 'name' => 'Datáfono caja 1']], $terminal['terminals']);

        // Otra caja sin datafono propio no lo ve.
        $other = Register::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'store_id' => $this->store->id, 'name' => 'Caja 2', 'code' => 'C2', 'is_active' => true]);
        $cashier = $this->makeUser('admin');
        app(RegisterSessionService::class)->open($other, $cashier, 0);
        $methods = $this->actingAs($cashier)->withSession($this->withActiveStore())->get(route('pos.index', ['caja' => RegisterSession::query()->withoutGlobalScopes()->where('register_id', $other->id)->value('id')]))->viewData('page')['props']['paymentMethods'];
        $this->assertNull(collect($methods)->firstWhere('id', $this->method->id));
    }

    public function test_el_cobro_va_al_datafono_y_la_venta_nace_con_el_webhook(): void
    {
        $product = $this->makeProduct(['price' => 25000, 'tax_rate' => 0], 20);
        $sent = null;

        Http::fake([
            'integrations.api.bold.co/payments/app-checkout' => function (ClientRequest $request) use (&$sent) {
                $sent = $request->data();

                return Http::response(['payload' => ['integration_id' => 'INT-77']]);
            },
            'payments.api.bold.co/v2/payment-voucher/*' => function (ClientRequest $request) {
                return Http::response(['payment_status' => 'APPROVED', 'transaction_id' => 'bold-77', 'reference_id' => PaymentTransaction::query()->withoutGlobalScopes()->value('reference'), 'total' => 25000]);
            },
        ]);

        $reference = $this->actingAs($this->cashier)->withSession($this->withActiveStore())->postJson(route('pos.gateway.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [],
            'register_session_id' => $this->session->id,
            'gateway' => ['payment_method_id' => $this->method->id, 'amount' => 25000, 'mode' => 'terminal', 'terminal_id' => $this->terminal->id],
        ])->assertCreated()->assertJson(['mode' => 'terminal', 'status' => 'pending', 'qr_image' => null])->json('reference');

        // Al aparato de la caja, por el monto exacto y con la referencia del cobro.
        $this->assertSame('SN-0001', $sent['terminal_serial']);
        $this->assertSame(25000, $sent['amount']['total_amount']);
        $this->assertSame($reference, $sent['reference']);
        $this->assertSame('INT-77', PaymentTransaction::query()->withoutGlobalScopes()->value('terminal_id'));
        $this->assertNotNull($this->terminal->fresh()->last_seen_at);

        $body = json_encode(['id' => 'evt-77', 'type' => 'SALE_APPROVED', 'subject' => 'bold-77', 'data' => ['payment_id' => 'bold-77', 'metadata' => ['reference' => $reference]]]);
        $this->call('POST', route('webhooks.payments', ['provider' => 'bold', 'token' => $this->bold->webhook_token]), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_BOLD_SIGNATURE' => hash_hmac('sha256', base64_encode($body), ''),
        ], $body)->assertOk();

        $sale = Sale::query()->withoutGlobalScopes()->with('payments')->firstOrFail();
        $this->assertSame(25000, $sale->total);
        $this->assertSame($this->method->id, (int) $sale->payments->first()->payment_method_id);
    }

    public function test_si_la_pasarela_rechaza_el_envio_no_queda_nada_a_medias(): void
    {
        $product = $this->makeProduct(['price' => 25000, 'tax_rate' => 0], 20);
        Http::fake(['integrations.api.bold.co/*' => Http::response(['errors' => [['message' => 'Terminal no vinculada']]], 422)]);

        $this->actingAs($this->cashier)->withSession($this->withActiveStore())->postJson(route('pos.gateway.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [],
            'register_session_id' => $this->session->id,
            'gateway' => ['payment_method_id' => $this->method->id, 'amount' => 25000, 'mode' => 'terminal'],
            'held' => ['label' => 'Cobro', 'total' => 25000, 'payload' => ['items' => []]],
        ])->assertStatus(422)->assertJsonFragment(['Terminal no vinculada']);

        // La fila pendiente quedo como error (hay rastro) y el tiquete guardado se descarto.
        $transaction = PaymentTransaction::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(PaymentTransaction::STATUS_ERROR, $transaction->status);
        $this->assertSame(0, \App\Models\HeldSale::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, Sale::query()->withoutGlobalScopes()->count());
    }
}

<?php

namespace Tests\Feature;

use App\Models\HeldSale;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\RegisterSession;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\RegisterSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\CreatesPaymentGateways;
use Tests\TestCase;

/**
 * Cobro con QR o link de la pasarela en la caja. La venta no existe hasta que la pasarela
 * aprueba; el sondeo de la caja y el webhook terminan en la misma liquidacion y nunca dan dos
 * ventas.
 */
class PosGatewayPaymentTest extends TestCase
{
    use CreatesCompanyEnvironment, CreatesPaymentGateways, RefreshDatabase;

    protected User $cashier;

    protected Product $product;

    protected RegisterSession $session;

    protected PaymentGatewayAccount $account;

    protected PaymentMethod $qr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->cashier = $this->makeUser('admin');
        // 2 x 6.150 = 12.300.
        $this->product = $this->makeProduct(['price' => 6150, 'tax_rate' => 0], 80);
        $this->session = app(RegisterSessionService::class)->open($this->register, $this->cashier, 100000);
        $this->account = $this->wompiAccount();
        $this->qr = $this->gatewayMethod(PaymentMethod::KIND_GATEWAY_LINK, $this->account, ['name' => 'QR Wompi']);
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     */
    protected function start(int $gatewayAmount = 10300, array $payments = [], ?User $user = null)
    {
        $payments = $payments ?: [['payment_method_id' => $this->systemMethod('cash')->id, 'amount' => 2000, 'received_amount' => 2000]];

        return $this->actingAs($user ?? $this->cashier)->withSession($this->withActiveStore())->postJson(route('pos.gateway.store'), [
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
            'payments' => $payments,
            'register_session_id' => $this->session->id,
            'gateway' => ['payment_method_id' => $this->qr->id, 'amount' => $gatewayAmount, 'mode' => 'qr'],
            'held' => ['label' => 'Cobro QR', 'total' => 12300, 'customer_id' => null, 'payload' => ['items' => [['product_id' => $this->product->id, 'quantity' => 2]], 'customer_id' => null]],
        ]);
    }

    protected function poll(string $reference)
    {
        return $this->actingAs($this->cashier)->withSession($this->withActiveStore())->getJson(route('pos.gateway.show', $reference));
    }

    public function test_la_venta_nace_solo_cuando_la_pasarela_aprueba(): void
    {
        $response = $this->start()->assertCreated()->assertJson(['status' => 'pending', 'mode' => 'qr', 'amount' => 10300, 'applied' => false, 'sale' => null]);
        $reference = $response->json('reference');

        $this->assertNotNull($response->json('qr_image'));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $response->json('qr_image'));
        $this->assertSame(0, Sale::query()->withoutGlobalScopes()->count());

        // El tiquete queda guardado en el servidor, pero no se puede retomar mientras se espera.
        $this->assertSame(1, HeldSale::query()->withoutGlobalScopes()->count());
        $this->actingAs($this->cashier)->withSession($this->withActiveStore())->getJson(route('pos.hold.index'))->assertJsonCount(0, 'held');

        // Pendiente en la pasarela: la caja sigue esperando.
        $this->fakeWompi($this->wompiTransaction('tx-pos', $reference, 10300, 'PENDING'));
        $this->poll($reference)->assertJson(['status' => 'pending', 'applied' => false]);

        // Aprobado: el sondeo trae la venta hecha.
        $this->fakeWompi($this->wompiTransaction('tx-pos', $reference, 10300));
        $poll = $this->poll($reference)->assertJson(['status' => 'approved', 'applied' => true]);
        $this->assertNotNull($poll->json('sale.number'));

        $sale = Sale::query()->withoutGlobalScopes()->with('payments')->firstOrFail();
        $this->assertSame(12300, $sale->total);
        $gateway = $sale->payments->firstWhere('payment_method_id', $this->qr->id);
        $this->assertSame(10300, $gateway->amount);
        $this->assertSame(SalePayment::METHOD_GATEWAY_LINK, $gateway->method);
        $this->assertSame(PaymentTransaction::query()->withoutGlobalScopes()->value('id'), (int) $gateway->payment_transaction_id);
        $this->assertSame(0, HeldSale::query()->withoutGlobalScopes()->count());

        // Llega tambien el webhook: misma liquidacion, ninguna venta de mas.
        $this->postJson(route('webhooks.payments', ['provider' => 'wompi', 'token' => $this->account->webhook_token]), $this->wompiEvent($this->wompiTransaction('tx-pos', $reference, 10300)))->assertOk();
        $this->poll($reference)->assertJson(['applied' => true]);

        $this->assertSame(1, Sale::query()->withoutGlobalScopes()->count());
    }

    public function test_el_tiquete_se_valida_antes_de_cobrarle_al_cliente(): void
    {
        // QR por 5.000 + 2.000 en efectivo no alcanzan para 12.300: no se abre ningun cobro.
        $this->start(5000)->assertStatus(422);

        $this->assertSame(0, PaymentTransaction::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, HeldSale::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, Sale::query()->withoutGlobalScopes()->count());
    }

    public function test_sin_permiso_no_se_abre_el_cobro(): void
    {
        $cashier = $this->makeUser('cajero');
        $cashier->revokePermissionTo('pos.sale.gateway');

        $this->start(user: $cashier->fresh())->assertForbidden();
        $this->assertSame(0, PaymentTransaction::query()->withoutGlobalScopes()->count());
    }

    public function test_cancelar_vuelve_al_cobro_y_conserva_el_tiquete(): void
    {
        $reference = $this->start()->assertCreated()->json('reference');
        $this->fakeWompi(null);

        $cancelled = $this->actingAs($this->cashier)->withSession($this->withActiveStore())
            ->postJson(route('pos.gateway.cancel', $reference))
            ->assertOk()
            ->assertJson(['status' => PaymentTransaction::STATUS_VOIDED, 'applied' => false]);

        $this->assertNotNull($cancelled->json('held_sale_id'));
        $this->assertSame(0, Sale::query()->withoutGlobalScopes()->count());
        // Ya no espera nada: el tiquete guardado se puede retomar.
        $this->actingAs($this->cashier)->withSession($this->withActiveStore())->getJson(route('pos.hold.index'))->assertJsonCount(1, 'held');
    }

    public function test_si_aprueba_despues_de_cancelar_queda_por_conciliar_y_se_puede_aplicar(): void
    {
        $reference = $this->start()->assertCreated()->json('reference');
        $this->fakeWompi(null);
        $this->actingAs($this->cashier)->withSession($this->withActiveStore())->postJson(route('pos.gateway.cancel', $reference))->assertOk();

        // La plata llego de todos modos.
        $this->fakeWompi($this->wompiTransaction('tx-tarde', $reference, 10300));
        $this->postJson(route('webhooks.payments', ['provider' => 'wompi', 'token' => $this->account->webhook_token]), $this->wompiEvent($this->wompiTransaction('tx-tarde', $reference, 10300)))->assertOk();

        $transaction = PaymentTransaction::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(PaymentTransaction::STATUS_APPROVED, $transaction->status);
        $this->assertNull($transaction->applied_at);
        $this->assertSame(0, Sale::query()->withoutGlobalScopes()->count());

        // Conciliar: se registra la venta en la caja abierta.
        $this->actingAs($this->cashier)->withSession($this->withActiveStore())
            ->post(route('payment_methods.transactions.reconcile', $transaction), [
                'action' => 'apply_sale',
                'register_session_id' => $this->session->id,
                'note' => 'El cliente pagó con el QR después de cancelar.',
            ])
            ->assertSessionHasNoErrors();

        $transaction->refresh();
        $this->assertSame(PaymentTransaction::RESOLUTION_APPLIED, $transaction->resolution);
        $this->assertNotNull($transaction->applied_at);
        $this->assertSame(1, Sale::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, PaymentTransaction::query()->withoutGlobalScopes()->toReconcile()->count());
    }

    public function test_un_monto_distinto_no_crea_la_venta(): void
    {
        $reference = $this->start()->assertCreated()->json('reference');
        $this->fakeWompi($this->wompiTransaction('tx-pos', $reference, 9000));

        $this->poll($reference)->assertJson(['status' => PaymentTransaction::STATUS_ERROR, 'applied' => false]);
        $this->assertSame(0, Sale::query()->withoutGlobalScopes()->count());
    }
}

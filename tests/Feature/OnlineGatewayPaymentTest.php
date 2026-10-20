<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\StoreCreditMovement;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\CreatesPaymentGateways;
use Tests\TestCase;

/**
 * Pago en linea de un pedido con la pasarela de la empresa: el pedido espera el pago con la
 * mercancia apartada, la confirmacion llega por webhook o por relectura (nunca por lo que diga la
 * URL de vuelta) y lo que vence se cancela solo y libera lo apartado.
 */
class OnlineGatewayPaymentTest extends TestCase
{
    use CreatesCompanyEnvironment, CreatesPaymentGateways, RefreshDatabase;

    protected PaymentGatewayAccount $account;

    protected PaymentMethod $method;

    protected Customer $customer;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->shopAt();

        $this->account = $this->wompiAccount();
        $this->method = $this->gatewayMethod(PaymentMethod::KIND_GATEWAY_CHECKOUT, $this->account, ['name' => 'Paga con Wompi']);
        $this->customer = $this->makeCustomer([
            'is_online_user' => true,
            'password' => Hash::make('Cliente123*'),
            'address' => 'Calle 30 # 65-12',
            'email' => 'luz@example.com',
        ]);
        // 3 x 15.000 + 5.000 de domicilio = 50.000.
        $this->product = $this->makeProduct(['price' => 15000, 'online_price' => 15000, 'tax_rate' => 0, 'is_online_visible' => true], 40);
    }

    /** Arma el carrito y confirma con la pasarela. Devuelve la transaccion pendiente. */
    protected function checkout(): PaymentTransaction
    {
        $this->actingAs($this->customer, 'customer');
        $this->post(route('storefront.cart.store'), ['product_id' => $this->product->id, 'quantity' => 3])->assertSessionHasNoErrors();

        $transaction = null;
        $response = $this->post(route('storefront.checkout.store'), [
            'delivery_address' => 'Calle 30 # 65-12',
            'payment_method_id' => $this->method->id,
        ]);

        $response->assertSessionHasNoErrors();
        $transaction = PaymentTransaction::query()->withoutGlobalScopes()->firstOrFail();
        $response->assertRedirect(route('storefront.payments.show', $transaction->reference));

        return $transaction;
    }

    protected function order(): Order
    {
        return Order::query()->withoutGlobalScopes()->firstOrFail();
    }

    protected function reserved(): float
    {
        return (float) ProductStock::query()->withoutGlobalScopes()->where('product_id', $this->product->id)->value('reserved_quantity');
    }

    protected function webhook(array $payload)
    {
        return $this->postJson(route('webhooks.payments', ['provider' => 'wompi', 'token' => $this->account->webhook_token]), $payload);
    }

    public function test_el_pedido_espera_el_pago_con_la_mercancia_apartada(): void
    {
        $transaction = $this->checkout();
        $order = $this->order();

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->status);
        $this->assertSame(Order::PAYMENT_GATEWAY, $order->payment_method);
        $this->assertSame($this->method->id, (int) $order->payment_method_id);
        $this->assertSame(50000, $transaction->amount);
        $this->assertSame(PaymentTransaction::STATUS_PENDING, $transaction->status);
        $this->assertEqualsWithDelta(3, $this->reserved(), 0.001);
        // La URL de pago va firmada a la pasarela, sin llaves privadas.
        $this->assertStringContainsString('checkout.wompi.co', (string) $transaction->checkout_url);
        $this->assertStringNotContainsString('prv_test', (string) $transaction->checkout_url);
    }

    public function test_el_webhook_aprueba_una_sola_vez(): void
    {
        $transaction = $this->checkout();
        $this->fakeWompi($this->wompiTransaction('tx-1', $transaction->reference, 50000));

        $event = $this->wompiEvent($this->wompiTransaction('tx-1', $transaction->reference, 50000));

        $this->webhook($event)->assertOk();
        $this->webhook($event)->assertOk();

        $order = $this->order();
        $this->assertSame(Order::STATUS_NEW, $order->status);
        $this->assertSame('paid', $order->payment_status);
        // Un solo paso de «esperando el pago» a «nuevo».
        $this->assertSame(1, $order->histories()->where('to_status', Order::STATUS_NEW)->count());

        $transaction->refresh();
        $this->assertSame(PaymentTransaction::STATUS_APPROVED, $transaction->status);
        $this->assertNotNull($transaction->applied_at);
        $this->assertSame('tx-1', $transaction->gateway_transaction_id);
    }

    public function test_una_firma_invalida_no_toca_nada(): void
    {
        $transaction = $this->checkout();
        Http::fake();
        $this->faked = true;

        $this->webhook($this->wompiEvent($this->wompiTransaction('tx-1', $transaction->reference, 50000), 'otro_secreto'))->assertStatus(401);

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $this->order()->status);
        $this->assertSame(PaymentTransaction::STATUS_PENDING, $transaction->fresh()->status);
        // Ni siquiera se le pregunta a la pasarela.
        Http::assertNothingSent();
    }

    public function test_el_aviso_no_manda_la_relectura_si(): void
    {
        $transaction = $this->checkout();
        // El aviso dice aprobado; la pasarela, al releer, dice rechazado.
        $this->fakeWompi($this->wompiTransaction('tx-1', $transaction->reference, 50000, 'DECLINED'));

        $this->webhook($this->wompiEvent($this->wompiTransaction('tx-1', $transaction->reference, 50000)))->assertOk();

        $this->assertSame(PaymentTransaction::STATUS_DECLINED, $transaction->fresh()->status);
        $this->assertSame(Order::STATUS_CANCELLED, $this->order()->status);
        $this->assertEqualsWithDelta(0, $this->reserved(), 0.001);
    }

    public function test_un_monto_distinto_no_aprueba_el_pedido(): void
    {
        $transaction = $this->checkout();
        $this->fakeWompi($this->wompiTransaction('tx-1', $transaction->reference, 40000));

        $this->webhook($this->wompiEvent($this->wompiTransaction('tx-1', $transaction->reference, 40000)))->assertOk();

        $transaction->refresh();
        $this->assertSame(PaymentTransaction::STATUS_ERROR, $transaction->status);
        $this->assertStringContainsString('no coinciden', (string) $transaction->status_detail);
        $this->assertNull($transaction->applied_at);
        // El pedido no queda esperando para siempre: se cancela y suelta lo apartado.
        $this->assertSame(Order::STATUS_CANCELLED, $this->order()->status);
        $this->assertEqualsWithDelta(0, $this->reserved(), 0.001);
    }

    public function test_la_vuelta_no_le_cree_a_la_url(): void
    {
        $transaction = $this->checkout();
        $this->fakeWompi($this->wompiTransaction('tx-9', $transaction->reference, 50000, 'PENDING'));

        // La URL dice aprobado; la pasarela todavia no.
        $this->get(route('storefront.payments.show', ['reference' => $transaction->reference, 'id' => 'tx-9', 'status' => 'APPROVED']))->assertOk();
        $this->getJson(route('storefront.payments.status', ['reference' => $transaction->reference, 'id' => 'tx-9']))
            ->assertOk()
            ->assertJson(['status' => PaymentTransaction::STATUS_PENDING, 'approved' => false]);

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $this->order()->status);

        // Cuando la pasarela aprueba, la misma consulta lo confirma.
        $this->fakeWompi($this->wompiTransaction('tx-9', $transaction->reference, 50000));
        $this->getJson(route('storefront.payments.status', ['reference' => $transaction->reference, 'id' => 'tx-9']))->assertJson(['approved' => true]);
        $this->assertSame(Order::STATUS_NEW, $this->order()->status);
    }

    public function test_otro_cliente_no_ve_el_pago(): void
    {
        $transaction = $this->checkout();
        $other = $this->makeCustomer(['is_online_user' => true, 'password' => Hash::make('Otro123*')]);

        $this->actingAs($other, 'customer')->get(route('storefront.payments.show', $transaction->reference))->assertNotFound();
    }

    public function test_lo_que_vence_se_cancela_y_libera(): void
    {
        $transaction = $this->checkout();
        // La pasarela no tiene ningun intento con esa referencia.
        $this->fakeWompi(null);

        $this->travel(31)->minutes();
        $this->artisan('payments:expire')->assertSuccessful();

        $this->assertSame(PaymentTransaction::STATUS_EXPIRED, $transaction->fresh()->status);
        $order = $this->order();
        $this->assertSame(Order::STATUS_CANCELLED, $order->status);
        $this->assertStringContainsString('venció', (string) $order->cancel_reason);
        $this->assertEqualsWithDelta(0, $this->reserved(), 0.001);

        // Un aviso tardio de aprobacion ya no revive el pedido: queda por conciliar.
        $this->fakeWompi($this->wompiTransaction('tx-late', $transaction->reference, 50000));
        $this->webhook($this->wompiEvent($this->wompiTransaction('tx-late', $transaction->reference, 50000)))->assertOk();

        $transaction->refresh();
        $this->assertSame(PaymentTransaction::STATUS_APPROVED, $transaction->status);
        $this->assertNull($transaction->applied_at);
        $this->assertSame(1, PaymentTransaction::query()->withoutGlobalScopes()->toReconcile()->count());
        $this->assertSame(Order::STATUS_CANCELLED, $this->order()->status);
    }

    public function test_entregar_menos_de_lo_pagado_deja_la_diferencia_como_saldo_a_favor(): void
    {
        $transaction = $this->checkout();
        $this->fakeWompi($this->wompiTransaction('tx-1', $transaction->reference, 50000));
        $this->webhook($this->wompiEvent($this->wompiTransaction('tx-1', $transaction->reference, 50000)))->assertOk();

        $admin = $this->makeUser('admin');
        $orders = app(OrderService::class);
        $order = $this->order();
        $item = $order->items()->firstOrFail();

        // Solo habia dos: se alistan 2 de 3.
        $orders->pick($order, [['id' => $item->id, 'picked_quantity' => 2]], $admin);

        foreach ([Order::STATUS_READY, Order::STATUS_ON_ROUTE, Order::STATUS_DELIVERED] as $status) {
            $orders->changeStatus($this->order(), $status, $admin);
        }

        $order = $this->order();
        $sale = Sale::query()->withoutGlobalScopes()->with('payments')->findOrFail($order->sale_id);

        // 2 x 15.000 + 5.000 = 35.000 cobrados; 15.000 vuelven como saldo a favor.
        $this->assertSame(35000, $sale->total);
        $this->assertSame($transaction->id, (int) $sale->payments->first()->payment_transaction_id);
        $this->assertSame(15000, (int) StoreCreditMovement::query()->withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('type', StoreCreditMovement::TYPE_ONLINE_OVERPAID)
            ->sum('amount'));
        $this->assertSame(15000, (int) $this->customer->fresh()->store_credit_balance);
    }
}

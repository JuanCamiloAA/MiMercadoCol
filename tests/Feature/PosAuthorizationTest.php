<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\SalePayment;
use App\Services\RegisterSessionService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

class PosAuthorizationTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        app(SettingsService::class)->set($this->company->id, 'pos.allow_discount_percent', 10);
        app(SettingsService::class)->set($this->company->id, 'pos.cash_rounding', 0);
    }

    public function test_el_efectivo_se_cobra_redondeado_a_la_centena(): void
    {
        app(SettingsService::class)->set($this->company->id, 'pos.cash_rounding', 100);

        $cashier = $this->makeUser('cajero');
        // 1,7 kg a 3.190 son 5.423: en efectivo se cobran 5.400.
        $product = $this->makeProduct(['price' => 3190, 'tax_rate' => 0, 'sold_by_weight' => true], 50);

        app(RegisterSessionService::class)->open($this->register, $cashier, 100000);

        $this->actingAs($cashier)
            ->withSession($this->withActiveStore())
            ->post(route('pos.sales.store'), [
                'items' => [['product_id' => $product->id, 'quantity' => 1.7]],
                'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 5400, 'received_amount' => 10000]],
            ])
            ->assertSessionHasNoErrors();

        $sale = Sale::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(5400, $sale->total);
        $this->assertSame(-23, $sale->rounding_total);
        $this->assertSame($sale->subtotal + $sale->tax_total + $sale->rounding_total, $sale->total);
        $this->assertSame(4600, $sale->payments->first()->change_amount);
    }

    public function test_con_tarjeta_se_cobra_exacto(): void
    {
        app(SettingsService::class)->set($this->company->id, 'pos.cash_rounding', 100);

        $cashier = $this->makeUser('cajero');
        $product = $this->makeProduct(['price' => 3190, 'tax_rate' => 0, 'sold_by_weight' => true], 50);

        app(RegisterSessionService::class)->open($this->register, $cashier, 100000);

        $this->actingAs($cashier)
            ->withSession($this->withActiveStore())
            ->post(route('pos.sales.store'), [
                'items' => [['product_id' => $product->id, 'quantity' => 1.7]],
                'payments' => [['method' => SalePayment::METHOD_CARD, 'amount' => 5423]],
            ])
            ->assertSessionHasNoErrors();

        $sale = Sale::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(5423, $sale->total);
        $this->assertSame(0, $sale->rounding_total);
    }

    public function test_un_descuento_sobre_el_tope_se_rechaza_sin_autorizacion(): void
    {
        $cashier = $this->makeUser('cajero');
        $product = $this->makeProduct(['price' => 10000, 'tax_rate' => 0], 50);

        app(RegisterSessionService::class)->open($this->register, $cashier, 100000);

        // 30 % sobre 50.000: muy por encima del 10 % permitido.
        $this->actingAs($cashier)
            ->withSession($this->withActiveStore())
            ->post(route('pos.sales.store'), [
                'items' => [['product_id' => $product->id, 'quantity' => 5, 'discount' => 15000]],
                'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 35000]],
            ])
            ->assertSessionHasErrors('discount');

        $this->assertSame(0, Sale::query()->withoutGlobalScopes()->count());
    }

    public function test_el_pin_del_supervisor_habilita_el_descuento_y_queda_registrado(): void
    {
        $cashier = $this->makeUser('cajero');
        $supervisor = $this->makeUser('supervisor');
        $supervisor->forceFill(['pos_pin' => Hash::make('9876')])->save();

        $product = $this->makeProduct(['price' => 10000, 'tax_rate' => 0], 50);
        app(RegisterSessionService::class)->open($this->register, $cashier, 100000);

        $this->actingAs($cashier)
            ->withSession($this->withActiveStore())
            ->postJson(route('pos.authorization'), ['action' => 'discount', 'pin' => '9876'])
            ->assertOk()
            ->assertJson(['authorized' => true]);

        $this->post(route('pos.sales.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'discount' => 15000]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 35000]],
        ])->assertSessionHasNoErrors();

        $sale = Sale::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(35000, $sale->total);
        $this->assertSame($supervisor->id, $sale->authorized_by_user_id);
        $this->assertDatabaseHas('access_logs', ['action' => 'pos_authorization', 'user_id' => $cashier->id]);
    }

    public function test_un_pin_que_no_puede_autorizar_se_rechaza(): void
    {
        $cashier = $this->makeUser('cajero');
        $other = $this->makeUser('domiciliario');
        $other->forceFill(['pos_pin' => Hash::make('1111')])->save();

        $this->actingAs($cashier)
            ->withSession($this->withActiveStore())
            ->postJson(route('pos.authorization'), ['action' => 'credit', 'pin' => '1111'])
            ->assertStatus(422)
            ->assertJson(['authorized' => false]);
    }

    public function test_el_pin_habilita_una_venta_a_credito_del_cajero(): void
    {
        $cashier = $this->makeUser('cajero');
        $admin = $this->makeUser('admin');
        $admin->forceFill(['pos_pin' => Hash::make('4321')])->save();

        $customer = $this->makeCustomer(['credit_enabled' => true, 'credit_limit' => 500000, 'credit_term_days' => 15]);
        $product = $this->makeProduct(['price' => 10000, 'tax_rate' => 0], 50);

        app(RegisterSessionService::class)->open($this->register, $cashier, 100000);

        $this->actingAs($cashier)
            ->withSession($this->withActiveStore())
            ->postJson(route('pos.authorization'), ['action' => 'credit', 'pin' => '4321'])
            ->assertOk();

        $this->post(route('pos.sales.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'payments' => [['method' => SalePayment::METHOD_CREDIT, 'amount' => 30000]],
            'customer_id' => $customer->id,
        ])->assertSessionHasNoErrors();

        $sale = Sale::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(Sale::STATUS_CREDIT, $sale->status);
        $this->assertSame($admin->id, $sale->authorized_by_user_id);
    }

    public function test_la_autorizacion_solo_vale_para_una_venta(): void
    {
        $cashier = $this->makeUser('cajero');
        $supervisor = $this->makeUser('supervisor');
        $supervisor->forceFill(['pos_pin' => Hash::make('9876')])->save();

        $product = $this->makeProduct(['price' => 10000, 'tax_rate' => 0], 100);
        app(RegisterSessionService::class)->open($this->register, $cashier, 100000);

        $this->actingAs($cashier)
            ->withSession($this->withActiveStore())
            ->postJson(route('pos.authorization'), ['action' => 'discount', 'pin' => '9876'])
            ->assertOk();

        $this->post(route('pos.sales.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'discount' => 15000]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 35000]],
        ])->assertSessionHasNoErrors();

        // La segunda venta con el mismo descuento ya no pasa: hay que volver a pedir el PIN.
        $this->post(route('pos.sales.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'discount' => 15000]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 35000]],
        ])->assertSessionHasErrors('discount');

        $this->assertSame(1, Sale::query()->withoutGlobalScopes()->count());
    }
}

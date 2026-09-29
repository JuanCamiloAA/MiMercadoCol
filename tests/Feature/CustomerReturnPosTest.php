<?php

namespace Tests\Feature;

use App\Models\CustomerReturn;
use App\Models\CustomerReturnItem;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Models\StoreCreditMovement;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\ReturnsSales;
use Tests\TestCase;

/**
 * Devolucion en caja: queda un documento DV con motivo y destino por articulo, el inventario
 * entra o va a averia, y la plata sale por la caja del turno.
 */
class CustomerReturnPosTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase, ReturnsSales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->seedReturnScenario();
    }

    public function test_devolucion_con_un_articulo_al_inventario_y_otro_a_averia(): void
    {
        $sale = $this->saleOf([[$this->leche, 3], [$this->huevos, 2]]);
        $expectedBefore = app(RegisterSessionService::class)->expectedAmount($this->session);

        $this->returnAtPos($sale, [
            ['sale_item_id' => $this->lineOf($sale, $this->leche), 'quantity' => 2, 'reason' => 'changed_mind'],
            ['sale_item_id' => $this->lineOf($sale, $this->huevos), 'quantity' => 1, 'reason' => 'damaged'],
        ])->assertSessionHasNoErrors()->assertSessionHas('last_return');

        $return = CustomerReturn::query()->withoutGlobalScopes()->with('items')->firstOrFail();
        $this->assertSame('DV-0001', $return->number);
        $this->assertSame(CustomerReturn::STATUS_RESOLVED, $return->status);
        $this->assertSame(CustomerReturn::ORIGIN_POS, $return->origin);
        $this->assertSame(2 * 4000 + 9000, $return->total);
        $this->assertSame(17000, $return->refunded_total);

        // Por motivo: la leche vuelve al estante, los huevos rotos van a averia.
        $this->assertSame(CustomerReturnItem::DISPOSITION_RESTOCK, $return->items->firstWhere('product_id', $this->leche->id)->disposition);
        $this->assertSame(CustomerReturnItem::DISPOSITION_WASTE, $return->items->firstWhere('product_id', $this->huevos->id)->disposition);

        $this->assertEqualsWithDelta(49, $this->stockOf($this->leche), 0.001);
        $this->assertEqualsWithDelta(48, $this->stockOf($this->huevos), 0.001);
        $this->assertSame(1, StockMovement::query()->withoutGlobalScopes()->where('type', StockMovement::TYPE_WASTE)->where('product_id', $this->huevos->id)->count());
        $this->assertSame($return->getMorphClass(), StockMovement::query()->withoutGlobalScopes()->where('type', StockMovement::TYPE_WASTE)->value('reference_type'));

        // La plata sale por la venta en negativo, de la caja del turno.
        $refund = Sale::query()->withoutGlobalScopes()->findOrFail($return->refund_sale_id);
        $this->assertSame(-17000, $refund->total);
        $this->assertSame($expectedBefore - 17000, app(RegisterSessionService::class)->expectedAmount($this->session));
    }

    public function test_el_cajero_puede_cambiar_el_destino_sugerido(): void
    {
        $sale = $this->saleOf([[$this->huevos, 1]]);

        $this->returnAtPos($sale, [
            ['sale_item_id' => $this->lineOf($sale, $this->huevos), 'quantity' => 1, 'reason' => 'damaged', 'disposition' => 'restock'],
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(50, $this->stockOf($this->huevos), 0.001);
        $this->assertSame(0, StockMovement::query()->withoutGlobalScopes()->where('type', StockMovement::TYPE_WASTE)->count());
    }

    public function test_saldo_a_favor_se_abona_y_se_usa_en_otra_compra(): void
    {
        $sale = $this->saleOf([[$this->huevos, 2]]);

        $this->returnAtPos($sale, [
            ['sale_item_id' => $this->lineOf($sale, $this->huevos), 'quantity' => 2, 'reason' => 'changed_mind'],
        ], ['resolution' => 'store_credit'])->assertSessionHasNoErrors();

        $return = CustomerReturn::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(18000, $return->credited_total);
        $this->assertSame(0, $return->refunded_total);
        $this->assertSame(18000, $this->customer->fresh()->store_credit_balance);

        // Paga una compra de 12.000 con saldo; no puede usar mas del que tiene.
        $pay = fn (int $amount) => $this->actingAs($this->cashier)->withSession($this->withActiveStore())->post(route('pos.sales.store'), [
            'items' => [['product_id' => $this->leche->id, 'quantity' => 3]],
            'payments' => [['method' => SalePayment::METHOD_STORE_CREDIT, 'amount' => $amount]],
            'customer_id' => $this->customer->id,
            'register_session_id' => $this->session->id,
        ]);

        $pay(12000)->assertSessionHasNoErrors();
        $this->assertSame(6000, $this->customer->fresh()->store_credit_balance);

        $this->actingAs($this->cashier)->withSession($this->withActiveStore())->post(route('pos.sales.store'), [
            'items' => [['product_id' => $this->huevos->id, 'quantity' => 1]],
            'payments' => [['method' => SalePayment::METHOD_STORE_CREDIT, 'amount' => 9000]],
            'customer_id' => $this->customer->id,
            'register_session_id' => $this->session->id,
        ])->assertSessionHasErrors('payments');

        // Anular la venta pagada con saldo lo devuelve.
        $paid = Sale::query()->withoutGlobalScopes()->where('status', Sale::STATUS_PAID)->latest('id')->firstOrFail();
        app(SaleService::class)->cancel($paid, $this->cashier, 'Se equivocó de producto');
        $this->assertSame(18000, $this->customer->fresh()->store_credit_balance);
    }

    public function test_saldo_a_favor_pide_cliente(): void
    {
        $sale = app(SaleService::class)->create([
            'items' => [['product_id' => $this->leche->id, 'quantity' => 1]],
            'payments' => [['method' => SalePayment::METHOD_CARD, 'amount' => 4000]],
        ], $this->cashier, $this->store, $this->session)->load('items');

        $line = [['sale_item_id' => $this->lineOf($sale, $this->leche), 'quantity' => 1, 'reason' => 'quality']];

        $this->returnAtPos($sale, $line, ['resolution' => 'store_credit'])->assertSessionHasErrors('customer_id');
        $this->returnAtPos($sale, $line, ['resolution' => 'store_credit', 'customer_id' => $this->customer->id])->assertSessionHasNoErrors();
        $this->assertSame(4000, $this->customer->fresh()->store_credit_balance);
        $this->assertSame($this->customer->id, CustomerReturn::query()->withoutGlobalScopes()->value('customer_id'));
    }

    public function test_al_consumidor_final_generico_no_se_le_abona_saldo(): void
    {
        $generic = $this->makeCustomer(['name' => 'Consumidor final', 'document_number' => '222222222222']);
        $sale = $this->saleOf([[$this->leche, 1]], ['customer_id' => $generic->id]);
        $line = [['sale_item_id' => $this->lineOf($sale, $this->leche), 'quantity' => 1, 'reason' => 'other']];

        $this->returnAtPos($sale, $line, ['resolution' => 'store_credit'])->assertSessionHasErrors('customer_id');
        $this->assertSame(0, $generic->fresh()->store_credit_balance);

        // Con la ficha real del cliente si.
        $this->returnAtPos($sale, $line, ['resolution' => 'store_credit', 'customer_id' => $this->customer->id])->assertSessionHasNoErrors();
        $this->assertSame(4000, $this->customer->fresh()->store_credit_balance);
        $this->assertSame(0, $generic->fresh()->store_credit_balance);
    }

    public function test_el_saldo_vencido_ya_no_se_puede_usar(): void
    {
        $sale = $this->saleOf([[$this->leche, 2]]);
        $this->returnAtPos($sale, [['sale_item_id' => $this->lineOf($sale, $this->leche), 'quantity' => 2, 'reason' => 'other']], ['resolution' => 'store_credit'])
            ->assertSessionHasNoErrors();

        $this->travel(400)->days();
        $this->artisan('store-credit:expire')->assertSuccessful();

        $this->assertSame(0, $this->customer->fresh()->store_credit_balance);
        $this->assertDatabaseHas('store_credit_movements', ['customer_id' => $this->customer->id, 'type' => 'expired', 'amount' => -8000]);
    }

    public function test_un_rechazo_queda_documentado_sin_mover_nada(): void
    {
        $sale = $this->saleOf([[$this->leche, 1]]);

        $this->returnAtPos($sale, [
            ['sale_item_id' => $this->lineOf($sale, $this->leche), 'quantity' => 1, 'reason' => 'changed_mind'],
        ], ['resolution' => 'rejected', 'reason' => 'El empaque viene abierto y usado'])->assertSessionHasNoErrors();

        $return = CustomerReturn::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(CustomerReturn::STATUS_REJECTED, $return->status);
        $this->assertSame('El empaque viene abierto y usado', $return->reject_reason);
        $this->assertNull($return->refund_sale_id);
        $this->assertEqualsWithDelta(49, $this->stockOf($this->leche), 0.001);
        $this->assertSame(0, Sale::query()->withoutGlobalScopes()->where('status', Sale::STATUS_REFUNDED)->count());
    }

    public function test_fuera_de_plazo_pide_el_pin_de_un_supervisor(): void
    {
        $sale = $this->saleOf([[$this->leche, 1]], ['sold_at' => now()->subDays(45)->toDateTimeString()]);
        $line = [['sale_item_id' => $this->lineOf($sale, $this->leche), 'quantity' => 1, 'reason' => 'quality']];

        $this->returnAtPos($sale, $line)->assertSessionHasErrors('window');

        $supervisor = $this->supervisorWithPin('4455');
        $this->actingAs($this->cashier)->withSession($this->withActiveStore())
            ->postJson(route('pos.authorization'), ['action' => 'return_window', 'pin' => '4455'])
            ->assertOk()->assertJson(['authorized' => true]);

        $this->returnAtPos($sale, $line)->assertSessionHasNoErrors();

        $return = CustomerReturn::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertTrue($return->out_of_window);
        $this->assertSame($supervisor->id, $return->window_approved_by_user_id);
    }

    public function test_el_plazo_y_la_vigencia_se_ajustan_en_reglas_de_caja(): void
    {
        $admin = $this->makeUser('admin', ['email' => 'jefe@lacanasta.co']);

        $this->actingAs($admin)->withSession($this->withActiveStore())
            ->put(route('settings.pos_rules.update'), [
                'pos' => ['allow_discount_percent' => 10, 'cash_rounding' => 100],
                'returns' => ['max_days' => 5, 'store_credit_days' => 0],
            ])->assertSessionHasNoErrors();

        $settings = app(SettingsService::class);
        $this->assertSame(5, (int) $settings->get($this->company->id, 'returns.max_days'));
        $this->assertSame(0, (int) $settings->get($this->company->id, 'returns.store_credit_days'));

        // Con 5 dias, una venta de hace 8 ya esta fuera de plazo.
        $sale = $this->saleOf([[$this->leche, 1]], ['sold_at' => now()->subDays(8)->toDateTimeString()]);
        $this->returnAtPos($sale, [['sale_item_id' => $this->lineOf($sale, $this->leche), 'quantity' => 1, 'reason' => 'other']])
            ->assertSessionHasErrors('window');

        // Vigencia 0: el saldo que se abona no vence.
        $recent = $this->saleOf([[$this->leche, 1]]);
        $this->returnAtPos($recent, [['sale_item_id' => $this->lineOf($recent, $this->leche), 'quantity' => 1, 'reason' => 'other']], ['resolution' => 'store_credit'])
            ->assertSessionHasNoErrors();
        $this->assertNull(StoreCreditMovement::query()->withoutGlobalScopes()->where('customer_id', $this->customer->id)->value('expires_at'));
    }

    public function test_cambio_aplica_lo_devuelto_y_cobra_la_diferencia(): void
    {
        $sale = $this->saleOf([[$this->leche, 2]]);

        // Devuelve 8.000 de leche y se lleva 9.000 de huevos: paga 1.000.
        $this->actingAs($this->cashier)->withSession($this->withActiveStore())->post(route('pos.sales.exchange', $sale), [
            'items' => [['product_id' => $this->huevos->id, 'quantity' => 1]],
            'payments' => [
                ['method' => SalePayment::METHOD_EXCHANGE, 'amount' => 8000],
                ['method' => SalePayment::METHOD_CARD, 'amount' => 1000],
            ],
            'customer_id' => $this->customer->id,
            'register_session_id' => $this->session->id,
            'return' => [
                'items' => [['sale_item_id' => $this->lineOf($sale, $this->leche), 'quantity' => 2, 'reason' => 'wrong_item']],
                'reason' => 'Queria huevos',
            ],
        ])->assertSessionHasNoErrors()->assertRedirect(route('pos.index'));

        $return = CustomerReturn::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(CustomerReturn::RESOLUTION_EXCHANGE, $return->resolution);
        $this->assertSame(8000, $return->exchanged_total);
        $this->assertSame(0, $return->refunded_total);

        $newSale = Sale::query()->withoutGlobalScopes()->findOrFail($return->exchange_sale_id);
        $this->assertSame(9000, $newSale->total);
        $this->assertSame(8000, (int) $newSale->payments()->where('method', SalePayment::METHOD_EXCHANGE)->value('amount'));

        // En el informe por medio de pago, el cambio se anula solo.
        $this->assertSame(0, (int) SalePayment::query()->where('method', SalePayment::METHOD_EXCHANGE)->sum('amount'));
        $this->assertEqualsWithDelta(50, $this->stockOf($this->leche), 0.001);
        $this->assertEqualsWithDelta(49, $this->stockOf($this->huevos), 0.001);
    }

    public function test_cambio_por_menos_devuelve_la_diferencia_en_efectivo(): void
    {
        $sale = $this->saleOf([[$this->huevos, 1]]);
        $expectedBefore = app(RegisterSessionService::class)->expectedAmount($this->session);

        // Devuelve 9.000 y se lleva 4.000: le sobran 5.000 en efectivo.
        $this->actingAs($this->cashier)->withSession($this->withActiveStore())->post(route('pos.sales.exchange', $sale), [
            'items' => [['product_id' => $this->leche->id, 'quantity' => 1]],
            'payments' => [['method' => SalePayment::METHOD_EXCHANGE, 'amount' => 4000]],
            'register_session_id' => $this->session->id,
            'return' => [
                'items' => [['sale_item_id' => $this->lineOf($sale, $this->huevos), 'quantity' => 1, 'reason' => 'changed_mind']],
                'leftover_method' => 'cash',
            ],
        ])->assertSessionHasNoErrors();

        $return = CustomerReturn::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(4000, $return->exchanged_total);
        $this->assertSame(5000, $return->refunded_total);
        $this->assertSame($expectedBefore - 5000, app(RegisterSessionService::class)->expectedAmount($this->session));
    }

    public function test_el_cambio_no_se_inventa_en_un_cobro_normal(): void
    {
        $this->actingAs($this->cashier)->withSession($this->withActiveStore())->post(route('pos.sales.store'), [
            'items' => [['product_id' => $this->leche->id, 'quantity' => 1]],
            'payments' => [['method' => SalePayment::METHOD_EXCHANGE, 'amount' => 4000]],
            'register_session_id' => $this->session->id,
        ])->assertSessionHasErrors('payments.0.method');
    }

    public function test_una_venta_con_devoluciones_no_se_anula_y_no_se_devuelve_de_mas(): void
    {
        $sale = $this->saleOf([[$this->leche, 2]]);
        $line = $this->lineOf($sale, $this->leche);

        $this->returnAtPos($sale, [['sale_item_id' => $line, 'quantity' => 1, 'reason' => 'other']])->assertSessionHasNoErrors();
        $this->returnAtPos($sale, [['sale_item_id' => $line, 'quantity' => 2, 'reason' => 'other']])->assertSessionHasErrors('items');

        $this->expectException(ValidationException::class);
        app(SaleService::class)->cancel($sale->refresh(), $this->cashier, 'Anular');
    }

    public function test_el_detalle_trae_lo_que_queda_el_plazo_y_el_saldo(): void
    {
        $sale = $this->saleOf([[$this->leche, 2]]);
        $this->returnAtPos($sale, [['sale_item_id' => $this->lineOf($sale, $this->leche), 'quantity' => 1, 'reason' => 'other']])->assertSessionHasNoErrors();

        $this->actingAs($this->cashier)->withSession($this->withActiveStore())
            ->getJson(route('pos.sales.detail', $sale))
            ->assertOk()
            ->assertJsonPath('sale.items.0.available', 1)
            ->assertJsonPath('sale.window.within', true)
            ->assertJsonPath('sale.customer.id', $this->customer->id);

        $this->actingAs($this->cashier)->withSession($this->withActiveStore())
            ->getJson(route('pos.sales.lookup', ['numero' => $sale->number]))
            ->assertOk()
            ->assertJsonPath('sale.id', $sale->id);

        $this->actingAs($this->cashier)->withSession($this->withActiveStore())
            ->getJson(route('pos.sales.lookup', ['numero' => 'NO-EXISTE']))
            ->assertNotFound();

        $return = CustomerReturn::query()->withoutGlobalScopes()->firstOrFail();
        $this->actingAs($this->cashier)->withSession($this->withActiveStore())
            ->get(route('pos.returns.print', $return))
            ->assertOk()
            ->assertSee($return->number);
    }
}

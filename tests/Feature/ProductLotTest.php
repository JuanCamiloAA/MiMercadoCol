<?php

namespace Tests\Feature;

use App\Models\ProductLot;
use App\Models\SalePayment;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use App\Services\SettingsService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

class ProductLotTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        app(SettingsService::class)->set($this->company->id, 'pos.cash_rounding', 0);
    }

    public function test_la_venta_consume_primero_el_lote_que_vence_antes(): void
    {
        $admin = $this->makeUser('admin');
        $product = $this->makeProduct(['track_lots' => true, 'price' => 10000, 'tax_rate' => 0], 0);
        $stock = app(StockService::class);

        // El lote que vence en tres dias entro despues, pero sale primero.
        $stock->receiveLot($product, $this->warehouse(), 10, 'VIEJO', now()->addDays(30)->toDateString(), 5000, now()->subDays(5));
        $stock->receiveLot($product, $this->warehouse(), 8, 'PRONTO', now()->addDays(3)->toDateString(), 5200, now()->subDay());

        // El saldo se ajusta aparte para no duplicar la entrada de los lotes.
        \App\Models\ProductStock::query()->withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->where('store_id', $this->store->id)
            ->update(['quantity' => 18]);

        $session = app(RegisterSessionService::class)->open($this->register, $admin, 100000);

        app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 10]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 100000]],
        ], $admin, $this->store, $session);

        $pronto = ProductLot::query()->withoutGlobalScopes()->where('code', 'PRONTO')->firstOrFail();
        $viejo = ProductLot::query()->withoutGlobalScopes()->where('code', 'VIEJO')->firstOrFail();

        $this->assertEqualsWithDelta(0, $pronto->quantity, 0.001, 'El lote proximo a vencer debio agotarse primero.');
        $this->assertEqualsWithDelta(8, $viejo->quantity, 0.001, 'Del otro lote solo debieron salir 2.');
    }

    public function test_recibir_una_compra_crea_el_lote_con_su_vencimiento(): void
    {
        $admin = $this->makeUser('admin');

        $supplier = \App\Models\Supplier::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Lacteos del Oriente',
            'lead_time_days' => 1,
            'is_active' => true,
        ]);

        $product = $this->makeProduct(['track_lots' => true, 'supplier_id' => $supplier->id], 0);

        $this->actingAs($admin)
            ->withSession($this->withActiveStore())
            ->post(route('purchases.store'), [
                'supplier_id' => $supplier->id,
                'status' => 'sent',
                'items' => [['product_id' => $product->id, 'quantity' => 24, 'unit_cost' => 3000]],
            ])
            ->assertSessionHasNoErrors();

        $order = \App\Models\PurchaseOrder::query()->withoutGlobalScopes()->firstOrFail();
        $expires = now()->addDays(20)->toDateString();

        $this->actingAs($admin)
            ->withSession($this->withActiveStore())
            ->post(route('purchases.receive', $order), [
                'items' => [[
                    'id' => $order->items()->first()->id,
                    'received_quantity' => 24,
                    'lot_code' => 'L-2609',
                    'expires_at' => $expires,
                ]],
            ])
            ->assertSessionHasNoErrors();

        $lot = ProductLot::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame('L-2609', $lot->code);
        $this->assertEqualsWithDelta(24, $lot->quantity, 0.001);
        $this->assertSame($expires, $lot->expires_at->toDateString());
    }

    public function test_los_lotes_por_vencer_salen_en_inventario(): void
    {
        $admin = $this->makeUser('admin');
        $product = $this->makeProduct(['track_lots' => true], 40);
        $stock = app(StockService::class);

        $stock->receiveLot($product, $this->warehouse(), 5, 'CERCA', now()->addDays(4)->toDateString(), 3000);
        $stock->receiveLot($product, $this->warehouse(), 5, 'LEJOS', now()->addDays(90)->toDateString(), 3000);

        $lots = $stock->expiringLots($this->company->id, $this->store->id, 20);

        $this->assertCount(1, $lots);
        $this->assertSame('CERCA', $lots->first()->code);

        $this->actingAs($admin)
            ->withSession($this->withActiveStore())
            ->get(route('inventory.index'))
            ->assertOk()
            ->assertSee('CERCA', false);
    }

    public function test_un_articulo_sin_control_de_lotes_no_genera_lotes(): void
    {
        $admin = $this->makeUser('admin');
        $product = $this->makeProduct(['track_lots' => false, 'price' => 10000, 'tax_rate' => 0], 20);

        $session = app(RegisterSessionService::class)->open($this->register, $admin, 100000);

        app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 20000]],
        ], $admin, $this->store, $session);

        $this->assertSame(0, ProductLot::query()->withoutGlobalScopes()->count());
    }
}

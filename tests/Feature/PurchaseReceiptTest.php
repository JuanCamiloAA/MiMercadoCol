<?php

namespace Tests\Feature;

use App\Models\ProductStock;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

class PurchaseReceiptTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
    }

    public function test_recibir_una_compra_sube_el_stock_y_recalcula_el_costo_promedio(): void
    {
        $admin = $this->makeUser('admin');

        $supplier = Supplier::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Distribuidora El Trigal',
            'lead_time_days' => 2,
            'is_active' => true,
        ]);

        // 10 unidades a 4.000 en existencia.
        $product = $this->makeProduct(['cost' => 4000, 'price' => 6000, 'tax_rate' => 0, 'supplier_id' => $supplier->id], 10);

        $this->actingAs($admin)
            ->withSession($this->withActiveStore())
            ->post(route('purchases.store'), [
                'supplier_id' => $supplier->id,
                'status' => PurchaseOrder::STATUS_SENT,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 30, 'unit_cost' => 5000],
                ],
            ])
            ->assertSessionHasNoErrors();

        $order = PurchaseOrder::query()->withoutGlobalScopes()->firstOrFail();

        $this->actingAs($admin)
            ->withSession($this->withActiveStore())
            ->post(route('purchases.receive', $order), [
                'items' => [
                    ['id' => $order->items()->first()->id, 'received_quantity' => 30],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->fresh()->status);

        $stock = ProductStock::query()->withoutGlobalScopes()->where('product_id', $product->id)->first();
        $this->assertEqualsWithDelta(40, $stock->quantity, 0.001);

        // (10 x 4.000 + 30 x 5.000) / 40 = 4.750
        $this->assertSame(4750, $product->fresh()->cost);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_PURCHASE,
        ]);
    }

    public function test_un_ajuste_deja_rastro_en_el_kardex(): void
    {
        $admin = $this->makeUser('admin');
        $product = $this->makeProduct([], 25);

        $this->actingAs($admin)
            ->withSession($this->withActiveStore())
            ->post(route('inventory.adjust'), [
                'warehouse_id' => $this->warehouse()->id,
                'product_id' => $product->id,
                'type' => StockMovement::TYPE_WASTE,
                'quantity' => 3,
                'note' => 'Producto vencido',
            ])
            ->assertSessionHasNoErrors();

        $stock = ProductStock::query()->withoutGlobalScopes()->where('product_id', $product->id)->first();
        $this->assertEqualsWithDelta(22, $stock->quantity, 0.001);

        $movement = StockMovement::query()->withoutGlobalScopes()->latest('id')->first();
        $this->assertSame(StockMovement::TYPE_WASTE, $movement->type);
        $this->assertEqualsWithDelta(-3, $movement->quantity, 0.001);
        $this->assertSame('Producto vencido', $movement->note);
    }
}

<?php

namespace Tests\Feature;

use App\Models\ProductLot;
use App\Models\ProductStock;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuysCatalog;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Recibir la mercancia.
 *
 * Es el momento en que se sabe cuanto costo de verdad: lo que se pidio era una intencion. De
 * ahi sale el costo promedio del articulo y a como lo deja ese proveedor, que es lo que despues
 * propone la proxima orden.
 */
class PurchaseReceiveTest extends TestCase
{
    use BuysCatalog, CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected Supplier $granos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->admin = $this->makeUser('admin');
        $this->granos = $this->makeSupplier('Granos del Campo');
    }

    public function test_recibir_actualiza_el_costo_del_proveedor_y_el_promedio_del_articulo(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, cost: 4000, stock: 10);

        $order = $this->orderFor([['product' => $arroz, 'quantity' => 10, 'cost' => 5000]]);

        $this->as()->post(route('purchases.receive', $order), [
            'items' => [['id' => $order->items()->firstOrFail()->id, 'received_quantity' => 10]],
        ])->assertSessionHasNoErrors();

        $link = ProductSupplier::query()->withoutGlobalScopes()
            ->where('product_id', $arroz->id)
            ->where('supplier_id', $this->granos->id)
            ->firstOrFail();

        // A como lo dejo este proveedor, para proponerlo la proxima vez.
        $this->assertSame(5000, $link->last_cost);
        $this->assertNotNull($link->last_purchased_at);

        // Diez a 4000 mas diez a 5000: el promedio queda en 4500.
        $this->assertSame(4500, $arroz->refresh()->cost);

        // Y la mercancia entro a la sede.
        $stock = ProductStock::query()->withoutGlobalScopes()->where('product_id', $arroz->id)->firstOrFail();
        $this->assertEqualsWithDelta(20, $stock->quantity, 0.001);
    }

    public function test_el_costo_se_le_anota_al_proveedor_del_renglon_no_al_de_la_orden(): void
    {
        $lacteos = $this->makeSupplier('Lacteos del Valle');

        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, cost: 4000);
        $queso = $this->makeSuppliedProduct('Queso', $lacteos, cost: 9000);

        // Orden de Granos con un articulo de Lacteos adentro.
        $order = $this->orderFor([
            ['product' => $arroz, 'quantity' => 10, 'cost' => 4100],
            ['product' => $queso, 'quantity' => 5, 'cost' => 9500],
        ]);

        $this->as()->post(route('purchases.receive', $order), [
            'items' => $order->items()->get()->map(fn ($item) => [
                'id' => $item->id,
                'received_quantity' => $item->quantity,
            ])->all(),
        ])->assertSessionHasNoErrors();

        $quesoLink = ProductSupplier::query()->withoutGlobalScopes()
            ->where('product_id', $queso->id)
            ->where('supplier_id', $lacteos->id)
            ->firstOrFail();

        // El queso se le anota a Lacteos, aunque la orden fuera de Granos: si no, la proxima
        // sugerencia de precio saldria del proveedor equivocado.
        $this->assertSame(9500, $quesoLink->last_cost);

        $this->assertSame(0, ProductSupplier::query()->withoutGlobalScopes()
            ->where('product_id', $queso->id)
            ->where('supplier_id', $this->granos->id)
            ->count());
    }

    public function test_comprarle_algo_nuevo_a_un_proveedor_crea_la_relacion(): void
    {
        $nuevo = $this->makeSupplier('Distribuidora Nueva');
        // Sin proveedor: nadie se lo ha surtido nunca.
        $sal = $this->makeSuppliedProduct('Sal', null, cost: 1000);

        $order = $this->orderFor([['product' => $sal, 'quantity' => 4, 'cost' => 1200]], $nuevo);

        $this->as()->post(route('purchases.receive', $order), [
            'items' => [['id' => $order->items()->firstOrFail()->id, 'received_quantity' => 4]],
        ])->assertSessionHasNoErrors();

        $link = ProductSupplier::query()->withoutGlobalScopes()
            ->where('product_id', $sal->id)
            ->where('supplier_id', $nuevo->id)
            ->firstOrFail();

        $this->assertSame(1200, $link->last_cost);
        $this->assertSame($this->company->id, $link->company_id);
    }

    public function test_recibir_de_menos_solo_suma_lo_que_llego(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, cost: 4000, stock: 0);

        $order = $this->orderFor([['product' => $arroz, 'quantity' => 100, 'cost' => 4000]]);

        $this->as()->post(route('purchases.receive', $order), [
            'items' => [['id' => $order->items()->firstOrFail()->id, 'received_quantity' => 30]],
        ])->assertSessionHasNoErrors();

        $stock = ProductStock::query()->withoutGlobalScopes()->where('product_id', $arroz->id)->firstOrFail();

        $this->assertEqualsWithDelta(30, $stock->quantity, 0.001);
        $this->assertEqualsWithDelta(30, $order->items()->firstOrFail()->received_quantity, 0.001);
    }

    public function test_recibir_de_menos_deja_la_orden_abierta(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, cost: 4000, stock: 0);

        $order = $this->orderFor([['product' => $arroz, 'quantity' => 120, 'cost' => 4000]]);

        // Llegaron 100 de 120: el resto viene despues, en otro lote.
        $this->as()->post(route('purchases.receive', $order), [
            'items' => [['id' => $order->items()->firstOrFail()->id, 'received_quantity' => 100, 'lot_code' => 'L-001']],
        ])->assertSessionHasNoErrors();

        $order->refresh()->load('items');

        $this->assertSame(PurchaseOrder::STATUS_PARTIAL, $order->status);
        // Mientras falte, la orden sigue viva: no tiene fecha de recepcion.
        $this->assertNull($order->received_at);
        $this->assertEqualsWithDelta(20, $order->pendingQuantity(), 0.001);
        $this->assertEqualsWithDelta(100, $order->items->first()->received_quantity, 0.001);

        $stock = ProductStock::query()->withoutGlobalScopes()->where('product_id', $arroz->id)->firstOrFail();
        $this->assertEqualsWithDelta(100, $stock->quantity, 0.001);
    }

    public function test_lo_que_falta_se_recibe_despues_y_se_acumula(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, cost: 4000, stock: 0, attributes: ['track_lots' => true]);

        $order = $this->orderFor([['product' => $arroz, 'quantity' => 120, 'cost' => 4000]]);
        $itemId = $order->items()->firstOrFail()->id;

        // Primera remesa: 100 del lote L-001.
        $this->as()->post(route('purchases.receive', $order), [
            'items' => [['id' => $itemId, 'received_quantity' => 100, 'lot_code' => 'L-001']],
        ])->assertSessionHasNoErrors();

        // Segunda remesa: las 20 que faltaban, de otro lote.
        $this->as()->post(route('purchases.receive', $order), [
            'items' => [['id' => $itemId, 'received_quantity' => 20, 'lot_code' => 'L-002']],
        ])->assertSessionHasNoErrors();

        $order->refresh()->load('items');

        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->status);
        $this->assertNotNull($order->received_at);
        $this->assertEqualsWithDelta(0, $order->pendingQuantity(), 0.001);
        $this->assertEqualsWithDelta(120, $order->items->first()->received_quantity, 0.001);

        $stock = ProductStock::query()->withoutGlobalScopes()->where('product_id', $arroz->id)->firstOrFail();
        $this->assertEqualsWithDelta(120, $stock->quantity, 0.001);

        // Cada remesa dejo su lote: el segundo no se llevo por delante al primero.
        $lotes = ProductLot::query()->withoutGlobalScopes()->where('product_id', $arroz->id)->orderBy('id')->get();

        $this->assertCount(2, $lotes);
        $this->assertSame(['L-001', 'L-002'], $lotes->pluck('code')->all());
        $this->assertEqualsWithDelta(100, $lotes[0]->quantity, 0.001);
        $this->assertEqualsWithDelta(20, $lotes[1]->quantity, 0.001);
    }

    public function test_cada_remesa_queda_registrada(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 0);
        $order = $this->orderFor([['product' => $arroz, 'quantity' => 120, 'cost' => 4000]]);
        $itemId = $order->items()->firstOrFail()->id;

        $this->as()->post(route('purchases.receive', $order), [
            'items' => [['id' => $itemId, 'received_quantity' => 100]],
        ]);
        $this->as()->post(route('purchases.receive', $order), [
            'items' => [['id' => $itemId, 'received_quantity' => 20]],
        ]);

        $movimientos = StockMovement::query()->withoutGlobalScopes()
            ->where('reference_type', PurchaseOrder::class)
            ->where('reference_id', $order->id)
            ->orderBy('id')
            ->get();

        // Dos entradas, no una de 120: el kardex cuenta como llego de verdad.
        $this->assertCount(2, $movimientos);
        $this->assertEqualsWithDelta(100, $movimientos[0]->quantity, 0.001);
        $this->assertEqualsWithDelta(20, $movimientos[1]->quantity, 0.001);
    }

    public function test_una_orden_parcial_se_puede_cerrar_con_lo_que_llego(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 0);
        $order = $this->orderFor([['product' => $arroz, 'quantity' => 120, 'cost' => 4000]]);

        $this->as()->post(route('purchases.receive', $order), [
            'items' => [['id' => $order->items()->firstOrFail()->id, 'received_quantity' => 100]],
        ]);

        $this->as()->post(route('purchases.close', $order))->assertSessionHasNoErrors();

        $order->refresh();

        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->status);
        $this->assertNotNull($order->received_at);

        // Cerrar no mueve inventario: lo que llego ya entro cuando llego.
        $stock = ProductStock::query()->withoutGlobalScopes()->where('product_id', $arroz->id)->firstOrFail();
        $this->assertEqualsWithDelta(100, $stock->quantity, 0.001);
    }

    public function test_una_orden_completa_no_se_vuelve_a_recibir(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 0);
        $order = $this->orderFor([['product' => $arroz, 'quantity' => 10, 'cost' => 4000]]);
        $itemId = $order->items()->firstOrFail()->id;

        $this->as()->post(route('purchases.receive', $order), [
            'items' => [['id' => $itemId, 'received_quantity' => 10]],
        ]);

        $this->as()->from(route('purchases.show', $order))->post(route('purchases.receive', $order), [
            'items' => [['id' => $itemId, 'received_quantity' => 5]],
        ]);

        // Sigue en 10: la segunda no entro.
        $stock = ProductStock::query()->withoutGlobalScopes()->where('product_id', $arroz->id)->firstOrFail();
        $this->assertEqualsWithDelta(10, $stock->quantity, 0.001);
    }

    public function test_recibir_nada_no_hace_nada(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 0);
        $order = $this->orderFor([['product' => $arroz, 'quantity' => 10, 'cost' => 4000]]);

        $this->as()->from(route('purchases.show', $order))->post(route('purchases.receive', $order), [
            'items' => [['id' => $order->items()->firstOrFail()->id, 'received_quantity' => 0]],
        ])->assertSessionHasErrors('items');

        $this->assertSame(PurchaseOrder::STATUS_SENT, $order->refresh()->status);
    }

    public function test_una_orden_con_mercancia_adentro_no_se_anula(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 0);
        $order = $this->orderFor([['product' => $arroz, 'quantity' => 120, 'cost' => 4000]]);

        $this->as()->post(route('purchases.receive', $order), [
            'items' => [['id' => $order->items()->firstOrFail()->id, 'received_quantity' => 100]],
        ]);

        // Anularla dejaria el inventario contando algo que la orden dice que nunca paso.
        $this->as()->from(route('purchases.show', $order))
            ->post(route('purchases.cancel', $order))
            ->assertSessionHasErrors('status');

        $this->assertSame(PurchaseOrder::STATUS_PARTIAL, $order->refresh()->status);
    }

    /**
     * @param  list<array{product: \App\Models\Product, quantity: int, cost: int}>  $items
     */
    protected function orderFor(array $items, ?Supplier $supplier = null): PurchaseOrder
    {
        $this->as()->post(route('purchases.store'), [
            'supplier_id' => ($supplier ?? $this->granos)->id,
            'status' => PurchaseOrder::STATUS_SENT,
            'items' => array_map(fn (array $item) => [
                'product_id' => $item['product']->id,
                'quantity' => $item['quantity'],
                'unit_cost' => $item['cost'],
            ], $items),
        ])->assertSessionHasNoErrors();

        return PurchaseOrder::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    protected function as(): static
    {
        return $this->actingAs($this->admin)->withSession($this->withActiveStore());
    }
}

<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\ReturnsToSupplier;
use Tests\TestCase;

/**
 * La orden de compra y sus devoluciones: se devuelve solo lo que entro, y devolver no cambia lo
 * que la orden dice que llego.
 */
class PurchaseOrderReturnFlowTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase, ReturnsToSupplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->admin = $this->makeUser('admin');
        $this->granos = $this->makeSupplier('Granos del Campo');
    }

    public function test_solo_se_devuelve_de_ordenes_con_mercancia_adentro(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 0);

        $this->actingAs($this->admin)->withSession($this->withActiveStore())->post(route('purchases.store'), [
            'supplier_id' => $this->granos->id,
            'status' => PurchaseOrder::STATUS_SENT,
            'items' => [['product_id' => $arroz->id, 'quantity' => 10, 'unit_cost' => 4000]],
        ]);
        $order = PurchaseOrder::query()->withoutGlobalScopes()->latest('id')->firstOrFail()->load('items');
        $this->assertFalse($order->canReturn());

        // Llega la mitad: ya se puede devolver.
        $this->actingAs($this->admin)->withSession($this->withActiveStore())->post(route('purchases.receive', $order), [
            'items' => [['id' => $order->items->first()->id, 'received_quantity' => 5]],
        ]);
        $order->refresh()->load('items');
        $this->assertSame(PurchaseOrder::STATUS_PARTIAL, $order->status);
        $this->assertTrue($order->canReturn());

        // Devuelto todo lo que llego, ya no queda nada por devolver.
        $this->returns()->dispatch($this->draft([$this->lineFor($order, $arroz, 5)], $order), $this->admin);
        $order->refresh()->load('items');
        $this->assertFalse($order->canReturn());

        // Y lo recibido y lo pendiente de la orden no cambiaron.
        $item = $order->items->first();
        $this->assertEqualsWithDelta(5, $item->received_quantity, 0.001);
        $this->assertEqualsWithDelta(5, $item->pendingQuantity(), 0.001);
        $this->assertEqualsWithDelta(5, $item->returned_quantity, 0.001);
    }

    public function test_una_orden_con_devoluciones_sigue_sin_poder_anularse(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 0);
        $order = $this->receivedOrder([['product' => $arroz, 'quantity' => 10, 'cost' => 4000]]);
        $this->returns()->dispatch($this->draft([$this->lineFor($order, $arroz, 10)], $order), $this->admin);

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('purchases.cancel', $order))
            ->assertSessionHas('error');

        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->refresh()->status);
    }

    public function test_una_anulada_no_admite_devoluciones(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 0);

        $this->actingAs($this->admin)->withSession($this->withActiveStore())->post(route('purchases.store'), [
            'supplier_id' => $this->granos->id,
            'status' => PurchaseOrder::STATUS_SENT,
            'items' => [['product_id' => $arroz->id, 'quantity' => 10, 'unit_cost' => 4000]],
        ]);
        $order = PurchaseOrder::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $order->update(['status' => PurchaseOrder::STATUS_CANCELLED]);

        $this->assertFalse($order->load('items')->canReturn());
    }
}

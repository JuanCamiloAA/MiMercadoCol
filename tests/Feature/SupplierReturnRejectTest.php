<?php

namespace Tests\Feature;

use App\Models\ProductLot;
use App\Models\StockMovement;
use App\Models\SupplierReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\ReturnsToSupplier;
use Tests\TestCase;

/**
 * Lo que el proveedor no acepta y vuelve entra otra vez a la misma bodega, con sus lotes.
 */
class SupplierReturnRejectTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase, ReturnsToSupplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->admin = $this->makeUser('admin');
        $this->granos = $this->makeSupplier('Granos del Campo');
    }

    public function test_el_rechazo_vuelve_a_meter_la_mercancia_con_sus_lotes(): void
    {
        $leche = $this->makeSuppliedProduct('Leche', $this->granos, cost: 2800, stock: 0, attributes: ['track_lots' => true]);
        $expires = now()->addDays(30)->toDateString();
        $order = $this->receivedOrder([['product' => $leche, 'quantity' => 20, 'cost' => 3000, 'lot' => 'L-PO', 'expires' => $expires]]);
        $averageCost = $leche->refresh()->cost;

        $return = $this->returns()->dispatch($this->draft([$this->lineFor($order, $leche, 10)], $order), $this->admin);
        $item = $return->items()->first();

        $rejected = $this->returns()->reject($return, [$item->id => 4], $this->admin, 'Llegó sellado');

        $this->assertEqualsWithDelta(14, $this->stockOf($leche), 0.001);
        $this->assertEqualsWithDelta(4, $item->refresh()->rejected_quantity, 0.001);
        $this->assertSame(12000, $rejected->rejected_total);

        $movement = StockMovement::query()->withoutGlobalScopes()
            ->where('type', StockMovement::TYPE_SUPPLIER_RETURN)
            ->where('quantity', '>', 0)
            ->firstOrFail();
        $this->assertSame('Rechazo de DP-0001: Llegó sellado', $movement->note);
        $this->assertSame($return->id, $movement->reference_id);

        // Vuelve con el lote y el vencimiento con que salio, y ligado a la orden.
        $lot = ProductLot::query()->withoutGlobalScopes()->where('product_id', $leche->id)->latest('id')->firstOrFail();
        $this->assertSame('L-PO', $lot->code);
        $this->assertSame($expires, $lot->expires_at->toDateString());
        $this->assertEqualsWithDelta(4, $lot->quantity, 0.001);
        $this->assertSame($this->orderItem($order, $leche)->id, $lot->purchase_order_item_id);

        // La orden vuelve a contar como suyo lo rechazado, y el costo promedio no se movio.
        $this->assertEqualsWithDelta(6, $this->orderItem($order, $leche)->returned_quantity, 0.001);
        $this->assertSame($averageCost, $leche->refresh()->cost);
    }

    public function test_no_se_rechaza_mas_de_lo_neto(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 30);
        $return = $this->returns()->dispatch($this->draft([['product_id' => $arroz->id, 'quantity' => 5]]), $this->admin);
        $item = $return->items()->first();

        $this->returns()->reject($return, [$item->id => 3], $this->admin);

        $this->expectException(ValidationException::class);
        $this->returns()->reject($return->refresh(), [$item->id => 3], $this->admin);
    }

    public function test_rechazar_todo_cierra_la_devolucion(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 30);
        $return = $this->returns()->dispatch($this->draft([['product_id' => $arroz->id, 'quantity' => 5]]), $this->admin);

        $closed = $this->returns()->reject($return, [$return->items()->first()->id => 5], $this->admin);

        $this->assertSame(SupplierReturn::STATUS_CLOSED, $closed->status);
        $this->assertNotNull($closed->closed_at);
        $this->assertSame(0, $closed->pendingTotal());
        $this->assertEqualsWithDelta(30, $this->stockOf($arroz), 0.001);
    }

    public function test_lo_que_ya_respondio_el_proveedor_no_se_puede_rechazar(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, cost: 1000, stock: 30);
        $return = $this->returns()->dispatch($this->draft([['product_id' => $arroz->id, 'quantity' => 10, 'unit_cost' => 1000]]), $this->admin);

        // Nota credito por ocho: solo quedan dos por rechazar.
        $this->returns()->settle($return, ['type' => 'credit_note', 'document_number' => 'NC-1', 'amount' => 8000], $this->admin);

        $this->expectException(ValidationException::class);
        $this->returns()->reject($return->refresh(), [$return->items()->first()->id => 3], $this->admin);
    }

    public function test_un_borrador_no_admite_rechazo(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 30);
        $return = $this->draft([['product_id' => $arroz->id, 'quantity' => 5]]);

        $this->expectException(ValidationException::class);
        $this->returns()->reject($return, [$return->items->first()->id => 1], $this->admin);
    }
}

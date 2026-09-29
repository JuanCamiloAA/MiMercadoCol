<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductLot;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\SupplierReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\ReturnsToSupplier;
use Tests\TestCase;

/**
 * Despachar: la mercancia sale con su propio tipo de kardex, al costo vigente, sin tocar el
 * promedio, y de los lotes que corresponden.
 */
class SupplierReturnDispatchTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase, ReturnsToSupplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->admin = $this->makeUser('admin');
        $this->granos = $this->makeSupplier('Granos del Campo');
    }

    public function test_despachar_escribe_el_kardex_con_su_tipo_y_su_referencia(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, cost: 4000, stock: 0);
        $order = $this->receivedOrder([['product' => $arroz, 'quantity' => 40, 'cost' => 4600]]);
        $averageCost = $arroz->refresh()->cost;

        $return = $this->returns()->dispatch($this->draft([$this->lineFor($order, $arroz, 8)], $order), $this->admin);

        $this->assertSame(SupplierReturn::STATUS_DISPATCHED, $return->status);
        $this->assertNotNull($return->dispatched_at);
        $this->assertSame($this->admin->id, $return->dispatched_by_user_id);

        $movement = StockMovement::query()->withoutGlobalScopes()->where('type', StockMovement::TYPE_SUPPLIER_RETURN)->firstOrFail();
        $this->assertEqualsWithDelta(-8, $movement->quantity, 0.001);
        $this->assertSame($return->getMorphClass(), $movement->reference_type);
        $this->assertSame($return->id, $movement->reference_id);
        $this->assertSame('Devolución DP-0001 a Granos del Campo', $movement->note);
        $this->assertSame('Devolución a proveedor', $movement->typeLabel());
        $this->assertEqualsWithDelta(32, $this->stockOf($arroz), 0.001);

        // Sale al costo promedio vigente, que se congela en el renglon; el promedio no se mueve.
        $this->assertSame($averageCost, $movement->unit_cost);
        $this->assertSame($averageCost, $return->items()->first()->inventory_unit_cost);
        $this->assertSame($averageCost, $arroz->refresh()->cost);
        // Lo que se reclama es otra cosa: el costo de la orden.
        $this->assertSame(4600, $return->items()->first()->unit_cost);
    }

    public function test_la_orden_lleva_la_cuenta_de_lo_devuelto_sin_tocar_lo_recibido(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 0);
        $order = $this->receivedOrder([['product' => $arroz, 'quantity' => 40, 'cost' => 4600]]);

        $this->returns()->dispatch($this->draft([$this->lineFor($order, $arroz, 8)], $order), $this->admin);

        $item = $this->orderItem($order, $arroz);
        $this->assertEqualsWithDelta(8, $item->returned_quantity, 0.001);
        $this->assertEqualsWithDelta(40, $item->received_quantity, 0.001);
        $this->assertEqualsWithDelta(32, $item->returnableQuantity(), 0.001);
        $this->assertEqualsWithDelta(0, $item->pendingQuantity(), 0.001);
    }

    public function test_con_orden_sale_primero_el_lote_que_entro_con_ella(): void
    {
        $leche = $this->lotProduct();
        $this->oldLot($leche, 'L-VIEJO', 10, now()->addDays(5));
        $order = $this->receivedOrder([['product' => $leche, 'quantity' => 20, 'cost' => 3000, 'lot' => 'L-PO', 'expires' => now()->addDays(30)->toDateString()]]);

        $return = $this->returns()->dispatch($this->draft([$this->lineFor($order, $leche, 8)], $order), $this->admin);

        $lots = $return->items()->first()->lots;
        $this->assertCount(1, $lots);
        $this->assertSame('L-PO', $lots[0]['code']);
        $this->assertEqualsWithDelta(8, $lots[0]['quantity'], 0.001);
        $this->assertSame([['code' => 'L-VIEJO', 'quantity' => 10.0], ['code' => 'L-PO', 'quantity' => 12.0]], $this->lotsOf($leche));
    }

    public function test_un_lote_viejo_sin_vinculo_se_encuentra_por_codigo(): void
    {
        $leche = $this->lotProduct();
        $order = $this->receivedOrder([['product' => $leche, 'quantity' => 20, 'cost' => 3000, 'lot' => 'L-PO', 'expires' => now()->addDays(30)->toDateString()]]);
        // El lote entro antes de que existiera el vinculo con la orden.
        ProductLot::query()->withoutGlobalScopes()->where('code', 'L-PO')->update(['purchase_order_item_id' => null]);
        $this->oldLot($leche, 'L-OTRO', 10, now()->addDays(2));

        $return = $this->returns()->dispatch($this->draft([$this->lineFor($order, $leche, 5)], $order), $this->admin);

        $this->assertSame('L-PO', $return->items()->first()->lots[0]['code']);
    }

    public function test_suelta_sale_por_el_que_vence_antes(): void
    {
        $leche = $this->lotProduct();
        $this->oldLot($leche, 'L-TARDE', 10, now()->addDays(40));
        $this->oldLot($leche, 'L-PRONTO', 10, now()->addDays(3));

        $return = $this->returns()->dispatch($this->draft([['product_id' => $leche->id, 'quantity' => 12]]), $this->admin);

        $lots = collect($return->items()->first()->lots);
        $this->assertSame(['L-PRONTO', 'L-TARDE'], $lots->pluck('code')->all());
        $this->assertEquals([10, 2], $lots->pluck('quantity')->all());
    }

    public function test_el_lote_elegido_manda_y_si_ya_no_alcanza_falla(): void
    {
        $leche = $this->lotProduct();
        $this->oldLot($leche, 'L-PRONTO', 10, now()->addDays(3));
        $tarde = $this->oldLot($leche, 'L-TARDE', 10, now()->addDays(40));

        $return = $this->draft([['product_id' => $leche->id, 'quantity' => 6, 'lot_id' => $tarde->id]]);
        $this->assertSame($tarde->id, $return->items->first()->lot_id);

        // Alguien saco de ese lote despues del borrador.
        $tarde->update(['quantity' => 4]);

        try {
            $this->returns()->dispatch($return, $this->admin);
            $this->fail('Se esperaba que el despacho fallara.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('El lote L-TARDE de Leche solo tiene 4', collect($exception->errors())->flatten()->first());
        }

        $tarde->update(['quantity' => 10]);
        $dispatched = $this->returns()->dispatch($return, $this->admin);
        $this->assertSame('L-TARDE', $dispatched->items()->first()->lots[0]['code']);
    }

    public function test_si_los_lotes_no_alcanzan_sale_el_resto_sin_lote(): void
    {
        // Diez de antes de controlar lotes y cinco en un lote.
        $leche = $this->lotProduct(stock: 10);
        $this->oldLot($leche, 'L-1', 5, now()->addDays(10));

        $return = $this->returns()->dispatch($this->draft([['product_id' => $leche->id, 'quantity' => 12]]), $this->admin);

        $this->assertEqualsWithDelta(5, collect($return->items()->first()->lots)->sum('quantity'), 0.001);
        $this->assertEqualsWithDelta(3, $this->stockOf($leche), 0.001);
    }

    public function test_una_devolucion_ya_despachada_no_se_despacha_otra_vez(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 30);
        $return = $this->draft([['product_id' => $arroz->id, 'quantity' => 5]]);
        $this->returns()->dispatch($return, $this->admin);

        $this->expectException(ValidationException::class);
        $this->returns()->dispatch($return, $this->admin);
    }

    protected function lotProduct(float $stock = 0): Product
    {
        return $this->makeSuppliedProduct('Leche', $this->granos, cost: 2800, stock: $stock, attributes: ['track_lots' => true]);
    }

    /** Un lote que ya estaba en la bodega, con su saldo. */
    protected function oldLot(Product $product, string $code, float $quantity, \DateTimeInterface $expires): ProductLot
    {
        ProductStock::query()->withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $this->warehouse()->id)
            ->increment('quantity', $quantity);

        return ProductLot::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'store_id' => $this->store->id,
            'warehouse_id' => $this->warehouse()->id,
            'code' => $code,
            'expires_at' => $expires->format('Y-m-d'),
            'quantity' => $quantity,
            'unit_cost' => 2800,
            'received_at' => now()->subDays(20),
        ]);
    }
}

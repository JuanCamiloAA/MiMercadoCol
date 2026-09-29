<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\SupplierReturn;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\ReturnsToSupplier;
use Tests\TestCase;

/**
 * Devolver nunca deja existencias negativas, permita o no el articulo quedar en negativo, y lo
 * apartado para un pedido no se devuelve.
 */
class SupplierReturnStockGuardTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase, ReturnsToSupplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->admin = $this->makeUser('admin');
        $this->granos = $this->makeSupplier('Granos del Campo');
    }

    public function test_despachar_mas_de_lo_disponible_falla_aunque_el_articulo_permita_negativo(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz 500 g', $this->granos, stock: 30, attributes: ['allow_negative_stock' => true]);
        $return = $this->draft([['product_id' => $arroz->id, 'quantity' => 30]]);

        // Entre el borrador y el despacho se vendieron cinco en caja.
        $this->sell($arroz, 5);

        $message = $this->dispatchFails($return);

        $this->assertSame('No hay suficiente Arroz 500 g en Bodega principal para devolver 30: hay 25.', $message);
        $this->assertEqualsWithDelta(25, $this->stockOf($arroz), 0.001);
        $this->assertSame(SupplierReturn::STATUS_DRAFT, $return->refresh()->status);
    }

    public function test_lo_apartado_para_pedidos_no_cuenta_como_disponible(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz 500 g', $this->granos, stock: 25, attributes: ['allow_negative_stock' => true]);
        $return = $this->draft([['product_id' => $arroz->id, 'quantity' => 20]]);
        $this->reserve($arroz, 5);
        $this->sell($arroz, 0);

        // Veinte si alcanzan (25 menos 5 apartadas); treinta no.
        DB::table('supplier_return_items')->where('supplier_return_id', $return->id)->update(['quantity' => 30]);

        $this->assertSame(
            'No hay suficiente Arroz 500 g en Bodega principal para devolver 30: hay 25 y 5 están apartadas para pedidos (disponibles 20).',
            $this->dispatchFails($return)
        );
    }

    public function test_un_renglon_corto_no_deja_salir_los_demas(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 30);
        $frijol = $this->makeSuppliedProduct('Frijol', $this->granos, stock: 10);
        $return = $this->draft([
            ['product_id' => $arroz->id, 'quantity' => 10],
            ['product_id' => $frijol->id, 'quantity' => 8],
        ]);

        $this->sell($frijol, 5);
        $this->dispatchFails($return);

        // Todo o nada: el arroz tampoco salio.
        $this->assertEqualsWithDelta(30, $this->stockOf($arroz), 0.001);
        $this->assertSame(0, StockMovement::query()->withoutGlobalScopes()->where('type', StockMovement::TYPE_SUPPLIER_RETURN)->count());
        $this->assertNull($return->refresh()->items->first()->inventory_unit_cost);
    }

    public function test_dos_despachos_seguidos_no_dejan_la_bodega_en_negativo(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 20, attributes: ['allow_negative_stock' => true]);

        // Los dos borradores se guardaron cuando habia veinte: cada uno, solo, alcanzaba.
        $first = $this->draft([['product_id' => $arroz->id, 'quantity' => 15]]);
        $second = $this->draft([['product_id' => $arroz->id, 'quantity' => 15]]);

        $this->returns()->dispatch($first, $this->admin);
        $this->dispatchFails($second);

        $this->assertEqualsWithDelta(5, $this->stockOf($arroz), 0.001);
    }

    public function test_el_chequeo_vive_en_el_movimiento_y_no_en_quien_lo_llama(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 3, attributes: ['allow_negative_stock' => true]);

        // Sin la bandera, el articulo que permite negativo sigue pudiendo quedar en negativo (ventas).
        DB::transaction(fn () => app(StockService::class)->move($arroz, $this->warehouse(), StockMovement::TYPE_SALE, -5));
        $this->assertEqualsWithDelta(-2, $this->stockOf($arroz), 0.001);

        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(StockService::class)->move($arroz, $this->warehouse(), StockMovement::TYPE_SUPPLIER_RETURN, -1, requireAvailable: true));
    }

    protected function sell(Product $product, float $quantity): void
    {
        if ($quantity <= 0) {
            return;
        }

        DB::transaction(fn () => app(StockService::class)->move($product, $this->warehouse(), StockMovement::TYPE_SALE, -$quantity));
    }

    protected function dispatchFails(SupplierReturn $return): string
    {
        try {
            $this->returns()->dispatch($return, $this->admin);
        } catch (ValidationException $exception) {
            return collect($exception->errors())->flatten()->first();
        }

        $this->fail('Se esperaba que el despacho fallara.');
    }
}

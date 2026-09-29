<?php

namespace Tests\Feature;

use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\WarehouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\SellsServicesAtPos;
use Tests\TestCase;

/**
 * Anular una venta con servicios: todo lo que salio vuelve a su bodega; la venta no existio.
 */
class ServiceCancelTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase, SellsServicesAtPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->seedServiceScenario();
    }

    public function test_anular_devuelve_todo_el_consumo_a_la_bodega_de_origen(): void
    {
        $deposito = $this->makeWarehouse('Deposito');

        $this->sell([
            ['service_id' => $this->ancheta->id, 'quantity' => 1, 'components' => [
                ['product_id' => $this->vino->id, 'quantity' => 1],
                ['product_id' => $this->galletas->id, 'quantity' => 1],
            ]],
            ['service_id' => $this->arreglo->id, 'quantity' => 1],
        ])->assertSessionHasNoErrors();

        $sale = $this->lastSale();

        // Aunque despues la caja venda de otra bodega, lo anulado vuelve a donde salio.
        app(WarehouseService::class)->assignFlags($deposito, [Warehouse::FLAG_POS]);

        $admin = $this->makeUser('admin');
        $this->actingAs($admin)->withSession($this->withActiveStore())
            ->post(route('pos.sales.cancel', $sale), ['reason' => 'Error de caja'])
            ->assertSessionHasNoErrors();

        foreach ([$this->vino, $this->galletas, $this->chocolate, $this->fresa, $this->banano] as $product) {
            $this->assertEqualsWithDelta(50, $this->stockOf($product), 0.001, "{$product->name} no volvio.");
        }

        // La linea del servicio no mueve nada: cuatro articulos, cuatro devoluciones.
        $this->assertSame(4, StockMovement::query()->withoutGlobalScopes()->where('type', StockMovement::TYPE_RETURN)->count());
        $this->assertStringContainsString('Anulacion', StockMovement::query()->withoutGlobalScopes()->where('type', StockMovement::TYPE_RETURN)->value('note'));
    }
}

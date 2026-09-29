<?php

namespace Tests\Feature;

use App\Models\SaleItem;
use App\Models\Service;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\SellsServicesAtPos;
use Tests\TestCase;

/**
 * Vender servicios en la caja: el servicio es una linea y lo que consume son lineas hijas que
 * mueven inventario. El maestro no cambia por lo que se ajuste en una venta.
 */
class PosServiceSaleTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase, SellsServicesAtPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->seedServiceScenario();
    }

    public function test_precio_cerrado_con_ajustes_cobra_lo_mismo_y_descuenta_solo_lo_usado(): void
    {
        // Sin chocolates y con una sola galleta: el precio no cambia.
        $this->sell([[
            'service_id' => $this->ancheta->id,
            'quantity' => 1,
            'components' => [
                ['product_id' => $this->vino->id, 'quantity' => 1],
                ['product_id' => $this->galletas->id, 'quantity' => 1],
            ],
        ]])->assertSessionHasNoErrors();

        $sale = $this->lastSale();
        $this->assertSame(60000, $sale->total);

        $line = $sale->items->firstWhere('service_id', $this->ancheta->id);
        $this->assertSame(60000, $line->line_total);
        $this->assertSame(5000, $line->unit_cost);
        $this->assertNull($line->product_id);

        $components = $sale->items->where('parent_sale_item_id', $line->id);
        $this->assertCount(2, $components);
        $this->assertSame(0, (int) $components->sum('line_total'));

        $galletas = $components->firstWhere('product_id', $this->galletas->id);
        $this->assertSame(SaleItem::CHANGE_REDUCED, $galletas->component_change);
        $this->assertEqualsWithDelta(2, $galletas->master_quantity, 0.001);
        $this->assertSame(3000, $galletas->unit_cost);

        // La foto de la venta: el maestro y lo que se quito.
        $this->assertSame('bundled', $line->service_snapshot['pricing_mode']);
        $this->assertCount(3, $line->service_snapshot['master']);
        $this->assertSame($this->chocolate->id, $line->service_snapshot['removed'][0]['product_id']);

        $this->assertEqualsWithDelta(49, $this->stockOf($this->vino), 0.001);
        $this->assertEqualsWithDelta(49, $this->stockOf($this->galletas), 0.001);
        $this->assertEqualsWithDelta(50, $this->stockOf($this->chocolate), 0.001);

        $movement = StockMovement::query()->withoutGlobalScopes()->where('product_id', $this->galletas->id)->where('type', StockMovement::TYPE_SALE)->firstOrFail();
        $this->assertSame("Servicio Armado de ancheta — venta {$sale->number}", $movement->note);

        // El maestro sigue intacto.
        $this->assertEqualsWithDelta(2, $this->ancheta->refresh()->components->firstWhere('product_id', $this->galletas->id)->quantity, 0.001);
        $this->assertCount(3, $this->ancheta->components);
    }

    public function test_sin_detalle_se_vende_tal_cual_el_maestro(): void
    {
        $this->sell([['service_id' => $this->ancheta->id, 'quantity' => 1]])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(48, $this->stockOf($this->galletas), 0.001);
        $this->assertEqualsWithDelta(49, $this->stockOf($this->chocolate), 0.001);
        $this->assertTrue($this->lastSale()->items->whereNotNull('parent_sale_item_id')->every(fn (SaleItem $item) => $item->component_change === null));
    }

    public function test_servicio_detallado_cobra_el_servicio_mas_sus_articulos(): void
    {
        // 5.000 + 2 kg de fresa a 3.000 + 3 bananos a 1.000.
        $this->sell([['service_id' => $this->arreglo->id, 'quantity' => 1]])->assertSessionHasNoErrors();
        $this->assertSame(14000, $this->lastSale()->total);

        // Sin el banano baja el total.
        $this->sell([[
            'service_id' => $this->arreglo->id,
            'quantity' => 1,
            'components' => [['product_id' => $this->fresa->id, 'quantity' => 2]],
        ]])->assertSessionHasNoErrors();

        $sale = $this->lastSale();
        $this->assertSame(11000, $sale->total);
        $this->assertSame(6000, $sale->items->firstWhere('product_id', $this->fresa->id)->line_total);
    }

    public function test_solo_por_valor_no_mueve_inventario(): void
    {
        $porcionado = $this->makeService('Porcionado al vacío', 3000, attributes: ['tax_rate' => 19]);
        $before = StockMovement::query()->withoutGlobalScopes()->count();

        $this->sell([['service_id' => $porcionado->id, 'quantity' => 2]])->assertSessionHasNoErrors();

        $sale = $this->lastSale();
        $this->assertSame(6000, $sale->total);
        $this->assertSame(5042, $sale->subtotal);
        $this->assertSame($before, StockMovement::query()->withoutGlobalScopes()->count());
    }

    public function test_precio_variable_exige_el_valor(): void
    {
        $recarga = $this->makeService('Recarga de celular', 0, attributes: ['open_price' => true]);

        $this->sell([['service_id' => $recarga->id, 'quantity' => 1]])->assertSessionHasErrors('items');

        $this->sell([['service_id' => $recarga->id, 'quantity' => 1, 'unit_price' => 20000]])->assertSessionHasNoErrors();
        $this->assertSame(20000, $this->lastSale()->total);
    }

    public function test_el_precio_de_un_servicio_normal_no_lo_pone_el_navegador(): void
    {
        $this->sell([['service_id' => $this->ancheta->id, 'quantity' => 1, 'unit_price' => 1]])->assertSessionHasNoErrors();

        $this->assertSame(60000, $this->lastSale()->total);
    }

    public function test_cantidad_dos_multiplica_los_articulos(): void
    {
        $this->sell([['service_id' => $this->ancheta->id, 'quantity' => 2]])->assertSessionHasNoErrors();

        $sale = $this->lastSale();
        $this->assertSame(120000, $sale->total);
        $this->assertEqualsWithDelta(4, $sale->items->firstWhere('product_id', $this->galletas->id)->quantity, 0.001);
        $this->assertEqualsWithDelta(46, $this->stockOf($this->galletas), 0.001);
    }

    public function test_el_mismo_articulo_suelto_y_en_un_servicio_son_lineas_distintas(): void
    {
        $this->sell([
            ['product_id' => $this->galletas->id, 'quantity' => 3],
            ['service_id' => $this->ancheta->id, 'quantity' => 1],
        ])->assertSessionHasNoErrors();

        $sale = $this->lastSale();
        $this->assertSame(15000 + 60000, $sale->total);
        $this->assertCount(2, $sale->items->where('product_id', $this->galletas->id));
        $this->assertEqualsWithDelta(45, $this->stockOf($this->galletas), 0.001);
    }

    public function test_un_articulo_inactivo_no_se_cobra_dentro_del_servicio(): void
    {
        $this->chocolate->update(['is_active' => false]);

        $this->sell([['service_id' => $this->ancheta->id, 'quantity' => 1]])->assertSessionHasErrors('items');

        // Quitandolo (el cajero tiene el permiso), si.
        $this->sell([[
            'service_id' => $this->ancheta->id,
            'quantity' => 1,
            'components' => [
                ['product_id' => $this->vino->id, 'quantity' => 1],
                ['product_id' => $this->galletas->id, 'quantity' => 2],
            ],
        ]])->assertSessionHasNoErrors();
    }

    public function test_un_servicio_inactivo_no_se_vende(): void
    {
        $this->ancheta->update(['is_active' => false]);

        $this->sell([['service_id' => $this->ancheta->id, 'quantity' => 1]])->assertSessionHasErrors();
    }

    public function test_la_caja_recibe_los_servicios_en_su_catalogo(): void
    {
        $response = $this->actingAs($this->cashier)->withSession($this->withActiveStore())
            ->getJson(route('pos.products', ['search' => 'ancheta']))
            ->assertOk();

        $response->assertJsonPath('services.0.code', 'SRV-ANCHETA');
        $response->assertJsonPath('services.0.total', 60000);
        $response->assertJsonCount(3, 'services.0.components');
        $response->assertJsonPath('services.0.components.1.quantity', 2);

        $this->actingAs($this->cashier)->withSession($this->withActiveStore())
            ->getJson(route('pos.products', ['services' => 1]))
            ->assertJsonCount(0, 'products')
            ->assertJsonCount(2, 'services');

        $this->assertSame(Service::PRICING_ITEMIZED, $this->arreglo->pricing_mode);
    }
}

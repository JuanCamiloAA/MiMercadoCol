<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\SellsServicesAtPos;
use Tests\TestCase;

/**
 * Devolver un servicio: el cajero decide articulo por articulo si vuelve al estante; la plata
 * de un servicio cerrado es la del servicio y la de uno detallado incluye sus articulos.
 */
class ServiceRefundTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase, SellsServicesAtPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->seedServiceScenario();
        $this->cashier->givePermissionTo('pos.sale.refund');
        $this->cashier = $this->cashier->fresh();
    }

    public function test_ancheta_devuelta_con_dos_articulos_que_vuelven_y_uno_que_no(): void
    {
        $this->sell([['service_id' => $this->ancheta->id, 'quantity' => 1]])->assertSessionHasNoErrors();
        $sale = $this->lastSale();
        $line = $sale->items->firstWhere('service_id', $this->ancheta->id);
        $component = fn ($product) => $sale->items->where('parent_sale_item_id', $line->id)->firstWhere('product_id', $product->id);

        $this->refund($sale, [
            ['sale_item_id' => $line->id, 'quantity' => 1],
            ['sale_item_id' => $component($this->vino)->id, 'quantity' => 1, 'restock' => true],
            ['sale_item_id' => $component($this->galletas)->id, 'quantity' => 2, 'restock' => true],
            ['sale_item_id' => $component($this->chocolate)->id, 'quantity' => 1, 'restock' => false],
        ])->assertSessionHasNoErrors();

        $refund = Sale::query()->withoutGlobalScopes()->where('status', Sale::STATUS_REFUNDED)->firstOrFail()->load('items');

        // La plata devuelta es la del servicio: sus articulos iban incluidos.
        $this->assertSame(-60000, $refund->total);

        $refundLine = $refund->items->firstWhere('service_id', $this->ancheta->id);
        $this->assertSame($line->id, $refundLine->refunded_from_sale_item_id);
        $this->assertCount(3, $refund->items->where('parent_sale_item_id', $refundLine->id));
        $this->assertFalse($refund->items->firstWhere('product_id', $this->chocolate->id)->restocked);

        $this->assertEqualsWithDelta(50, $this->stockOf($this->vino), 0.001);
        $this->assertEqualsWithDelta(50, $this->stockOf($this->galletas), 0.001);
        $this->assertEqualsWithDelta(49, $this->stockOf($this->chocolate), 0.001);
        // Dos articulos volvieron al estante: dos entradas en el kardex; el chocolate no.
        $this->assertSame(2, StockMovement::query()->withoutGlobalScopes()->where('type', StockMovement::TYPE_RETURN)->count());
    }

    public function test_sin_decir_nada_vale_lo_que_trae_la_venta(): void
    {
        $this->sell([['service_id' => $this->arreglo->id, 'quantity' => 1]])->assertSessionHasNoErrors();
        $sale = $this->lastSale();
        $line = $sale->items->firstWhere('service_id', $this->arreglo->id);
        $fresa = $sale->items->firstWhere('product_id', $this->fresa->id);

        // Las frutas no vuelven por defecto (el maestro lo dice).
        $this->refund($sale, [
            ['sale_item_id' => $line->id, 'quantity' => 1],
            ['sale_item_id' => $fresa->id, 'quantity' => 2],
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(48, $this->stockOf($this->fresa), 0.001);
    }

    public function test_detallado_devuelve_la_plata_de_los_articulos_devueltos(): void
    {
        $this->sell([['service_id' => $this->arreglo->id, 'quantity' => 1]])->assertSessionHasNoErrors();
        $sale = $this->lastSale();
        $line = $sale->items->firstWhere('service_id', $this->arreglo->id);
        $fresa = $sale->items->firstWhere('product_id', $this->fresa->id);

        // El servicio (5.000) y 1 de los 2 kg de fresa (3.000); el banano no.
        $this->refund($sale, [
            ['sale_item_id' => $line->id, 'quantity' => 1],
            ['sale_item_id' => $fresa->id, 'quantity' => 1, 'restock' => true],
        ])->assertSessionHasNoErrors();

        $refund = Sale::query()->withoutGlobalScopes()->where('status', Sale::STATUS_REFUNDED)->firstOrFail();
        $this->assertSame(-8000, $refund->total);
        $this->assertEqualsWithDelta(49, $this->stockOf($this->fresa), 0.001);
    }

    public function test_un_articulo_de_servicio_no_se_devuelve_sin_su_servicio_ni_de_mas(): void
    {
        $this->sell([['service_id' => $this->ancheta->id, 'quantity' => 2]])->assertSessionHasNoErrors();
        $sale = $this->lastSale();
        $line = $sale->items->firstWhere('service_id', $this->ancheta->id);
        $galletas = $sale->items->where('parent_sale_item_id', $line->id)->firstWhere('product_id', $this->galletas->id);

        $this->refund($sale, [['sale_item_id' => $galletas->id, 'quantity' => 1]])->assertSessionHasErrors('items');

        // Una de dos anchetas: la mitad de las cuatro galletas, no mas.
        $this->refund($sale, [
            ['sale_item_id' => $line->id, 'quantity' => 1],
            ['sale_item_id' => $galletas->id, 'quantity' => 3, 'restock' => true],
        ])->assertSessionHasErrors('items');

        $this->refund($sale, [
            ['sale_item_id' => $line->id, 'quantity' => 1],
            ['sale_item_id' => $galletas->id, 'quantity' => 2, 'restock' => true],
        ])->assertSessionHasNoErrors();

        // Queda una ancheta por devolver, no dos.
        $refunded = app(SaleService::class)->refundedQuantities($sale->refresh()->load('items'));
        $this->assertEqualsWithDelta(1, $refunded[$line->id], 0.001);
        $this->assertEqualsWithDelta(2, $refunded[$galletas->id], 0.001);

        $this->refund($sale, [['sale_item_id' => $line->id, 'quantity' => 2]])->assertSessionHasErrors('items');
    }

    public function test_el_mismo_articulo_suelto_y_en_un_servicio_se_devuelve_sin_confundirse(): void
    {
        $this->sell([
            ['product_id' => $this->galletas->id, 'quantity' => 3],
            ['service_id' => $this->ancheta->id, 'quantity' => 1],
        ])->assertSessionHasNoErrors();
        $sale = $this->lastSale();
        $loose = $sale->items->whereNull('parent_sale_item_id')->firstWhere('product_id', $this->galletas->id);
        $line = $sale->items->firstWhere('service_id', $this->ancheta->id);
        $inService = $sale->items->where('parent_sale_item_id', $line->id)->firstWhere('product_id', $this->galletas->id);

        $this->refund($sale, [['sale_item_id' => $loose->id, 'quantity' => 2]])->assertSessionHasNoErrors();

        $refunded = app(SaleService::class)->refundedQuantities($sale->refresh()->load('items'));
        $this->assertEqualsWithDelta(2, $refunded[$loose->id], 0.001);
        $this->assertEqualsWithDelta(0, $refunded[$inService->id], 0.001);

        // Lo que queda del suelto es uno.
        $this->refund($sale, [['sale_item_id' => $loose->id, 'quantity' => 2]])->assertSessionHasErrors('items');
        $this->refund($sale, [['sale_item_id' => $loose->id, 'quantity' => 1]])->assertSessionHasNoErrors();

        // El detalle de la caja trae el servicio con sus articulos debajo.
        $detail = $this->actingAs($this->cashier)->withSession($this->withActiveStore())
            ->getJson(route('pos.sales.detail', $sale))
            ->assertOk()
            ->json('sale.items');

        $service = collect($detail)->firstWhere('is_service', true);
        $this->assertCount(3, $service['components']);
        $this->assertCount(2, $detail);
    }

    public function test_el_articulo_devuelto_dos_veces_en_una_venta_vieja_sigue_contando_bien(): void
    {
        // Una venta de antes: dos lineas del mismo articulo.
        $this->sell([
            ['product_id' => $this->banano->id, 'quantity' => 2],
            ['product_id' => $this->vino->id, 'quantity' => 1],
        ])->assertSessionHasNoErrors();
        $sale = $this->lastSale();
        $banano = $sale->items->firstWhere('product_id', $this->banano->id);

        $this->refund($sale, [['sale_item_id' => $banano->id, 'quantity' => 1]])->assertSessionHasNoErrors();

        $line = SaleItem::query()->where('refunded_from_sale_item_id', $banano->id)->firstOrFail();
        $this->assertTrue($line->restocked);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    protected function refund(Sale $sale, array $items)
    {
        return $this->actingAs($this->cashier)->withSession($this->withActiveStore())
            ->post(route('pos.sales.refund', $sale), ['items' => $items, 'method' => 'cash', 'reason' => 'No le gusto']);
    }
}

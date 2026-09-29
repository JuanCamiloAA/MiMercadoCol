<?php

namespace Tests\Feature;

use App\Models\ProductStock;
use App\Models\Store;
use App\Models\User;
use App\Services\PurchaseSuggestionService;
use App\Services\WarehouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuysCatalog;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Cuanto pedir de cada articulo.
 *
 * Es el numero que el comprador acepta sin pensar cincuenta veces al dia, asi que tiene que
 * estar bien: de mas, sobra plata en la bodega; de menos, se agota antes del proximo pedido.
 */
class PurchaseSuggestionTest extends TestCase
{
    use BuysCatalog, CreatesCompanyEnvironment, RefreshDatabase;

    protected PurchaseSuggestionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->service = app(PurchaseSuggestionService::class);
    }

    public function test_el_sugerido_llega_al_minimo_por_el_multiplicador(): void
    {
        // minimo 20, se quiere el doble: 40 menos los 6 que hay.
        $this->assertSame(34, $this->service->suggest(6, 20, 2.0, 1));
    }

    public function test_el_sugerido_se_redondea_al_empaque(): void
    {
        // 34 no es multiplo de 12: la caja siguiente es 36.
        $this->assertSame(36, $this->service->suggest(6, 20, 2.0, 12));
        $this->assertSame(12, $this->service->suggest(0, 10, 1.0, 6));
    }

    public function test_lo_que_ya_alcanza_no_se_pide(): void
    {
        $this->assertSame(0, $this->service->suggest(45, 20, 2.0, 1));
        $this->assertSame(0, $this->service->suggest(40, 20, 2.0, 1));
    }

    public function test_nunca_devuelve_negativo(): void
    {
        $this->assertSame(0, $this->service->suggest(500, 20, 2.0, 12));
    }

    public function test_lo_reservado_no_cuenta_como_existencia(): void
    {
        $producto = $this->makeSuppliedProduct('Arroz', $this->makeSupplier('Granos'), stock: 30, minStock: 20);

        // Veinticinco estan comprometidos con pedidos en linea: en la estanteria quedan cinco.
        ProductStock::query()->withoutGlobalScopes()
            ->where('product_id', $producto->id)
            ->update(['reserved_quantity' => 25]);

        $stock = $this->service->stockByProduct($this->company->id, $this->store->id, [$producto->id]);

        $this->assertEqualsWithDelta(5, $stock[$producto->id], 0.001);
        $this->assertSame(35, $this->service->suggestFor($producto, $stock[$producto->id]));
    }

    public function test_solo_toma_las_existencias_de_la_sede_activa(): void
    {
        $norte = Store::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Norte', 'code' => 'NOR',
            'opens_at' => '07:00:00', 'closes_at' => '21:00:00',
            'suggested_cash_base' => 0, 'is_active' => true,
        ]);

        $producto = $this->makeSuppliedProduct('Arroz', $this->makeSupplier('Granos'), stock: 2, minStock: 20);

        // En la otra sede hay de sobra, pero esta sede pide lo que le falta a ella.
        ProductStock::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'product_id' => $producto->id,
            'store_id' => $norte->id,
            'warehouse_id' => app(WarehouseService::class)->receivingDefaultFor($norte->id)->id,
            'quantity' => 500,
            'reserved_quantity' => 0,
        ]);

        $aqui = $this->service->stockByProduct($this->company->id, $this->store->id, [$producto->id]);
        $alla = $this->service->stockByProduct($this->company->id, $norte->id, [$producto->id]);

        $this->assertEqualsWithDelta(2, $aqui[$producto->id], 0.001);
        $this->assertEqualsWithDelta(500, $alla[$producto->id], 0.001);
        $this->assertSame(38, $this->service->suggestFor($producto, $aqui[$producto->id]));
    }

    public function test_cuenta_los_que_estan_bajo_minimo_de_la_sede(): void
    {
        $granos = $this->makeSupplier('Granos');

        $this->makeSuppliedProduct('Bajo uno', $granos, stock: 1, minStock: 20);
        $this->makeSuppliedProduct('Bajo dos', $granos, stock: 20, minStock: 20);
        $this->makeSuppliedProduct('Sobrado', $granos, stock: 100, minStock: 20);
        // Sin minimo definido no hay contra que compararlo.
        $this->makeSuppliedProduct('Sin minimo', $granos, stock: 0, minStock: 0);

        $this->assertSame(2, $this->service->lowStockCount($this->company->id, $this->store->id));
    }

    public function test_el_endpoint_de_sugerencias_trae_lo_bajo_minimo_con_su_cantidad(): void
    {
        $granos = $this->makeSupplier('Granos');
        $this->makeSuppliedProduct('Bajo', $granos, stock: 2, minStock: 20);
        $this->makeSuppliedProduct('Sobrado', $granos, stock: 100, minStock: 20);

        $admin = $this->makeUser('admin');

        $products = $this->actingAs($admin)->withSession($this->withActiveStore())
            ->getJson(route('purchases.suggestions', ['supplier' => $granos->id]))
            ->assertOk()
            ->json('products');

        $this->assertCount(1, $products);
        $this->assertSame('Bajo', $products[0]['name']);
        $this->assertSame(38, $products[0]['suggested']);
    }
}

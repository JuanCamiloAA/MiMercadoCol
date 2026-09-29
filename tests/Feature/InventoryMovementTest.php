<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Entradas y salidas de bodega con varios articulos.
 *
 * Lo que se comprueba aqui no es que sume y reste, sino que el movimiento sea uno solo: quien
 * da de baja los vencidos del dia esta contando un hecho, no nueve. Si el noveno no alcanza,
 * los ocho primeros tampoco pueden quedar guardados.
 */
class InventoryMovementTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $bodeguero;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->bodeguero = $this->makeUser('bodega');
    }

    public function test_una_entrada_suma_varios_articulos_de_una_vez(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz', 'sku' => 'ARR-1'], stock: 10);
        $panela = $this->makeProduct(['name' => 'Panela', 'sku' => 'PAN-1'], stock: 4);

        $this->as()->post(route('inventory.movement.store'), [
            'warehouse_id' => $this->warehouse()->id,
            'direction' => StockMovement::DIRECTION_IN,
            'reason' => 'count',
            'note' => 'Conteo de bodega',
            'items' => [
                ['product_id' => $arroz->id, 'quantity' => 6],
                ['product_id' => $panela->id, 'quantity' => 2.5],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(16, $this->stockOf($arroz), 0.001);
        $this->assertEqualsWithDelta(6.5, $this->stockOf($panela), 0.001);

        // Un renglon del kardex por articulo: dos articulos no son un movimiento de 8.5.
        $movements = $this->movements();

        $this->assertCount(2, $movements);
        $this->assertSame([StockMovement::TYPE_ADJUSTMENT, StockMovement::TYPE_ADJUSTMENT], $movements->pluck('type')->all());
        $this->assertEqualsWithDelta(6, $movements[0]->quantity, 0.001);
        $this->assertEqualsWithDelta(16, $movements[0]->balance_after, 0.001);
    }

    public function test_una_salida_resta_y_la_averia_queda_registrada_como_averia(): void
    {
        $huevos = $this->makeProduct(['name' => 'Huevos AA'], stock: 30);

        $this->as()->post(route('inventory.movement.store'), [
            'warehouse_id' => $this->warehouse()->id,
            'direction' => StockMovement::DIRECTION_OUT,
            'reason' => 'damage',
            'note' => 'Se cayo la bandeja',
            'items' => [['product_id' => $huevos->id, 'quantity' => 12]],
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(18, $this->stockOf($huevos), 0.001);

        $movement = $this->movements()->firstOrFail();

        // El formulario pide cantidades positivas; el signo lo pone la direccion.
        $this->assertEqualsWithDelta(-12, $movement->quantity, 0.001);
        $this->assertSame(StockMovement::TYPE_WASTE, $movement->type);
    }

    public function test_un_faltante_de_conteo_no_se_registra_como_averia(): void
    {
        $azucar = $this->makeProduct(['name' => 'Azucar'], stock: 30);

        $this->as()->post(route('inventory.movement.store'), [
            'warehouse_id' => $this->warehouse()->id,
            'direction' => StockMovement::DIRECTION_OUT,
            'reason' => 'count',
            'items' => [['product_id' => $azucar->id, 'quantity' => 3]],
        ])->assertSessionHasNoErrors();

        // Faltaron tres, pero nadie dijo que se hubieran dañado: eso es un ajuste.
        $this->assertSame(StockMovement::TYPE_ADJUSTMENT, $this->movements()->firstOrFail()->type);
    }

    public function test_el_motivo_queda_al_frente_de_la_nota(): void
    {
        $leche = $this->makeProduct(['name' => 'Leche'], stock: 20);

        $this->as()->post(route('inventory.movement.store'), [
            'warehouse_id' => $this->warehouse()->id,
            'direction' => StockMovement::DIRECTION_OUT,
            'reason' => 'expired',
            'note' => 'Lote de agosto',
            'items' => [['product_id' => $leche->id, 'quantity' => 4]],
        ])->assertSessionHasNoErrors();

        // Sin el motivo por delante, en el kardex una averia y un faltante se leen igual.
        $this->assertSame('Vencido: Lote de agosto', $this->movements()->firstOrFail()->note);
    }

    public function test_sin_nota_queda_el_motivo_solo(): void
    {
        $leche = $this->makeProduct(['name' => 'Leche'], stock: 20);

        $this->as()->post(route('inventory.movement.store'), [
            'warehouse_id' => $this->warehouse()->id,
            'direction' => StockMovement::DIRECTION_IN,
            'reason' => 'production',
            'items' => [['product_id' => $leche->id, 'quantity' => 4]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('Produccion o armado interno', $this->movements()->firstOrFail()->note);
    }

    public function test_un_motivo_de_salida_no_sirve_para_una_entrada(): void
    {
        $leche = $this->makeProduct(['name' => 'Leche'], stock: 20);

        $this->as()->from(route('inventory.entry.create'))->post(route('inventory.movement.store'), [
            'warehouse_id' => $this->warehouse()->id,
            'direction' => StockMovement::DIRECTION_IN,
            // «Vencido» no es una entrada: aceptarlo dejaria una entrada contada como averia.
            'reason' => 'expired',
            'items' => [['product_id' => $leche->id, 'quantity' => 4]],
        ])->assertSessionHasErrors('reason');

        $this->assertEqualsWithDelta(20, $this->stockOf($leche), 0.001);
        $this->assertCount(0, $this->movements());
    }

    public function test_si_un_articulo_no_alcanza_no_entra_ninguno(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 50);
        $panela = $this->makeProduct(['name' => 'Panela'], stock: 2);

        $this->as()->from(route('inventory.exit.create'))->post(route('inventory.movement.store'), [
            'warehouse_id' => $this->warehouse()->id,
            'direction' => StockMovement::DIRECTION_OUT,
            'reason' => 'damage',
            'items' => [
                ['product_id' => $arroz->id, 'quantity' => 10],
                ['product_id' => $panela->id, 'quantity' => 8],
            ],
        ])->assertSessionHasErrors('stock');

        // El primero alcanzaba, pero guardar solo ese dejaria el conteo a medio corregir.
        $this->assertEqualsWithDelta(50, $this->stockOf($arroz), 0.001);
        $this->assertEqualsWithDelta(2, $this->stockOf($panela), 0.001);
        $this->assertCount(0, $this->movements());
    }

    public function test_un_articulo_que_admite_negativos_si_baja_de_cero(): void
    {
        $hielo = $this->makeProduct(['name' => 'Hielo', 'allow_negative_stock' => true], stock: 2);

        $this->as()->post(route('inventory.movement.store'), [
            'warehouse_id' => $this->warehouse()->id,
            'direction' => StockMovement::DIRECTION_OUT,
            'reason' => 'internal',
            'items' => [['product_id' => $hielo->id, 'quantity' => 5]],
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(-3, $this->stockOf($hielo), 0.001);
    }

    public function test_un_articulo_repetido_se_rechaza(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 10);

        $this->as()->from(route('inventory.entry.create'))->post(route('inventory.movement.store'), [
            'warehouse_id' => $this->warehouse()->id,
            'direction' => StockMovement::DIRECTION_IN,
            'reason' => 'count',
            'items' => [
                ['product_id' => $arroz->id, 'quantity' => 2],
                ['product_id' => $arroz->id, 'quantity' => 3],
            ],
        ])->assertSessionHasErrors('items.1.product_id');

        $this->assertEqualsWithDelta(10, $this->stockOf($arroz), 0.001);
    }

    public function test_una_cantidad_en_cero_se_rechaza(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 10);

        $this->as()->from(route('inventory.entry.create'))->post(route('inventory.movement.store'), [
            'warehouse_id' => $this->warehouse()->id,
            'direction' => StockMovement::DIRECTION_IN,
            'reason' => 'count',
            'items' => [['product_id' => $arroz->id, 'quantity' => 0]],
        ])->assertSessionHasErrors('items.0.quantity');

        $this->assertCount(0, $this->movements());
    }

    public function test_sin_articulos_no_hay_movimiento(): void
    {
        $this->as()->from(route('inventory.entry.create'))->post(route('inventory.movement.store'), [
            'warehouse_id' => $this->warehouse()->id,
            'direction' => StockMovement::DIRECTION_IN,
            'reason' => 'count',
            'items' => [],
        ])->assertSessionHasErrors('items');
    }

    public function test_el_cajero_no_registra_movimientos_de_bodega(): void
    {
        $cajero = $this->makeUser('cajero');
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 10);

        $this->actingAs($cajero)->withSession($this->withActiveStore())
            ->get(route('inventory.exit.create'))
            ->assertForbidden();

        $this->actingAs($cajero)->withSession($this->withActiveStore())
            ->post(route('inventory.movement.store'), [
                'warehouse_id' => $this->warehouse()->id,
            'direction' => StockMovement::DIRECTION_OUT,
                'reason' => 'damage',
                'items' => [['product_id' => $arroz->id, 'quantity' => 1]],
            ])
            ->assertForbidden();

        $this->assertEqualsWithDelta(10, $this->stockOf($arroz), 0.001);
    }

    public function test_las_dos_pantallas_abren_con_los_motivos_de_su_direccion(): void
    {
        $this->makeProduct(['name' => 'Arroz'], stock: 10);

        $this->as()->get(route('inventory.entry.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inventory/Movement')
                ->where('direction', StockMovement::DIRECTION_IN)
                ->where('reasons.0.value', 'count'));

        $this->as()->get(route('inventory.exit.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inventory/Movement')
                ->where('direction', StockMovement::DIRECTION_OUT)
                ->where('reasons.0.value', 'damage'));
    }

    public function test_el_catalogo_encuentra_por_codigo_de_barras(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz', 'barcode' => '7702011000011'], stock: 7);

        $this->as()->getJson(route('inventory.products', ['barcode' => '7702011000011']))
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('products.0.id', $arroz->id)
            ->assertJsonPath('products.0.stock', 7);

        $this->as()->getJson(route('inventory.products', ['barcode' => '0000000000000']))
            ->assertOk()
            ->assertJsonPath('found', false);
    }

    public function test_el_catalogo_muestra_lo_apartado_aparte(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 20);

        ProductStock::query()->withoutGlobalScopes()
            ->where('product_id', $arroz->id)
            ->update(['reserved_quantity' => 5]);

        $this->as()->getJson(route('inventory.products', ['search' => 'Arroz']))
            ->assertOk()
            ->assertJsonPath('products.0.stock', 20)
            ->assertJsonPath('products.0.reserved', 5)
            // Lo apartado ya tiene dueno: sacarlo deja un pedido sin con que despacharse.
            ->assertJsonPath('products.0.available', 15);
    }

    public function test_el_ajuste_de_un_solo_articulo_sigue_funcionando(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz'], stock: 10);

        $this->as()->post(route('inventory.adjust'), [
            'product_id' => $arroz->id,
            'warehouse_id' => $this->warehouse()->id,
            'type' => StockMovement::TYPE_ADJUSTMENT,
            'quantity' => 3,
            'note' => 'Sobro en el conteo',
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(13, $this->stockOf($arroz), 0.001);
    }

    protected function as(): static
    {
        return $this->actingAs($this->bodeguero)->withSession($this->withActiveStore());
    }

    protected function stockOf(Product $product): float
    {
        return (float) ProductStock::query()->withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->where('store_id', $this->store->id)
            ->value('quantity');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, StockMovement>
     */
    protected function movements()
    {
        return StockMovement::query()->withoutGlobalScopes()->orderBy('id')->get();
    }
}

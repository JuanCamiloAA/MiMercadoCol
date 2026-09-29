<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\ReturnsToSupplier;
use Tests\TestCase;

/**
 * El borrador de una devolucion: no mueve inventario, pero ya valida lo que se puede devolver.
 */
class SupplierReturnDraftTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase, ReturnsToSupplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->admin = $this->makeUser('admin');
        $this->granos = $this->makeSupplier('Granos del Campo');
    }

    public function test_el_borrador_con_orden_toma_el_costo_de_la_orden_y_no_mueve_nada(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz 500 g', $this->granos, cost: 4000, stock: 0, attributes: ['tax_rate' => 19]);
        $order = $this->receivedOrder([['product' => $arroz, 'quantity' => 120, 'cost' => 5950]]);
        $movements = StockMovement::query()->withoutGlobalScopes()->count();

        // El navegador manda otro costo: no cuenta.
        $return = $this->draft([$this->lineFor($order, $arroz, 5, ['unit_cost' => 1])], $order);

        $this->assertSame(SupplierReturn::STATUS_DRAFT, $return->status);
        $this->assertSame('DP-0001', $return->number);
        $this->assertSame(5950, $return->items->first()->unit_cost);
        $this->assertSame(29750, $return->total);
        // La base sale hacia atras con el IVA del articulo.
        $this->assertSame(25000, $return->subtotal);
        $this->assertSame(4750, $return->tax_total);
        $this->assertSame($movements, StockMovement::query()->withoutGlobalScopes()->count());
        $this->assertEqualsWithDelta(120, $this->stockOf($arroz), 0.001);
    }

    public function test_los_renglones_en_cero_no_se_guardan(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 0);
        $frijol = $this->makeSuppliedProduct('Frijol', $this->granos, stock: 0);
        $order = $this->receivedOrder([
            ['product' => $arroz, 'quantity' => 10, 'cost' => 4000],
            ['product' => $frijol, 'quantity' => 10, 'cost' => 6000],
        ]);

        $return = $this->draft([$this->lineFor($order, $arroz, 5), $this->lineFor($order, $frijol, 0)], $order);

        $this->assertCount(1, $return->items);
        $this->assertSame($arroz->id, $return->items->first()->product_id);
    }

    public function test_sin_ninguna_cantidad_no_hay_borrador(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 0);
        $order = $this->receivedOrder([['product' => $arroz, 'quantity' => 10, 'cost' => 4000]]);

        $this->expectValidation('items', fn () => $this->draft([$this->lineFor($order, $arroz, 0)], $order));
    }

    public function test_con_orden_solo_entran_renglones_de_esa_orden(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 0);
        $frijol = $this->makeSuppliedProduct('Frijol', $this->granos, stock: 0);
        $order = $this->receivedOrder([['product' => $arroz, 'quantity' => 10, 'cost' => 4000]]);
        $other = $this->receivedOrder([['product' => $frijol, 'quantity' => 10, 'cost' => 6000]]);

        $this->expectValidation('items.0.purchase_order_item_id', fn () => $this->draft([
            ['purchase_order_item_id' => $this->orderItem($other, $frijol)->id, 'quantity' => 2],
        ], $order));
    }

    public function test_el_tope_es_lo_recibido_menos_lo_devuelto_y_lo_que_hay_en_otros_borradores(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz 500 g', $this->granos, stock: 0);
        $order = $this->receivedOrder([['product' => $arroz, 'quantity' => 120, 'cost' => 4000]]);

        $this->returns()->dispatch($this->draft([$this->lineFor($order, $arroz, 20)], $order), $this->admin);
        $this->draft([$this->lineFor($order, $arroz, 10)], $order);

        $error = $this->expectValidation('items.0.quantity', fn () => $this->draft([$this->lineFor($order, $arroz, 91)], $order));
        $this->assertSame('De Arroz 500 g se recibieron 120; ya se devolvieron 20 y hay 10 en otro borrador: puedes devolver hasta 90.', $error);

        // Justo el tope si pasa.
        $this->assertCount(1, $this->draft([$this->lineFor($order, $arroz, 90)], $order)->items);
    }

    public function test_al_corregir_un_borrador_no_cuenta_contra_si_mismo(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 0);
        $order = $this->receivedOrder([['product' => $arroz, 'quantity' => 10, 'cost' => 4000]]);
        $return = $this->draft([$this->lineFor($order, $arroz, 10)], $order);

        $updated = $this->returns()->update($return, [
            'warehouse_id' => $this->warehouse()->id,
            'items' => [$this->lineFor($order, $arroz, 8, ['reason' => 'expired'])],
        ], $this->admin);

        $this->assertEqualsWithDelta(8, $updated->items->first()->quantity, 0.001);
        $this->assertSame('expired', $updated->items->first()->reason);
        $this->assertNotNull($updated->edited_at);
    }

    public function test_la_orden_debe_tener_mercancia_adentro(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 50);

        $this->actingAs($this->admin)->withSession($this->withActiveStore())->post(route('purchases.store'), [
            'supplier_id' => $this->granos->id,
            'status' => 'sent',
            'items' => [['product_id' => $arroz->id, 'quantity' => 10, 'unit_cost' => 4000]],
        ]);
        $sent = PurchaseOrder::query()->withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->expectValidation('purchase_order_id', fn () => $this->draft([
            ['purchase_order_item_id' => $sent->items()->first()->id, 'quantity' => 1],
        ], $sent));
    }

    public function test_suelta_propone_el_ultimo_costo_con_ese_proveedor(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, cost: 4000, stock: 30);
        ProductSupplier::query()->withoutGlobalScopes()->where('product_id', $arroz->id)->update(['last_cost' => 4300]);

        $return = $this->draft([['product_id' => $arroz->id, 'quantity' => 3]]);

        $this->assertSame(4300, $return->items->first()->unit_cost);
        $this->assertNull($return->purchase_order_id);
    }

    public function test_suelta_sin_costo_con_el_proveedor_propone_el_costo_del_articulo_y_se_puede_cambiar(): void
    {
        $otro = $this->makeSupplier('Otro');
        $sal = $this->makeSuppliedProduct('Sal', $otro, cost: 1500, stock: 30);

        $this->assertSame(1500, $this->draft([['product_id' => $sal->id, 'quantity' => 1]])->items->first()->unit_cost);
        $this->assertSame(1800, $this->draft([['product_id' => $sal->id, 'quantity' => 1, 'unit_cost' => 1800]])->items->first()->unit_cost);
    }

    public function test_un_articulo_por_unidad_se_devuelve_entero_y_uno_por_peso_no(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 30);
        $queso = $this->makeSuppliedProduct('Queso', $this->granos, stock: 30, attributes: ['sold_by_weight' => true, 'unit' => 'kg']);

        $this->expectValidation('items.0.quantity', fn () => $this->draft([['product_id' => $arroz->id, 'quantity' => 2.5]]));

        $return = $this->draft([['product_id' => $queso->id, 'quantity' => 2.375]]);
        $this->assertEqualsWithDelta(2.375, $return->items->first()->quantity, 0.0001);
    }

    public function test_el_motivo_otro_exige_nota(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 30);

        $this->expectValidation('items.0.note', fn () => $this->draft([['product_id' => $arroz->id, 'quantity' => 1, 'reason' => 'other']]));

        $return = $this->draft([['product_id' => $arroz->id, 'quantity' => 1, 'reason' => 'other', 'note' => 'Empaque distinto al pactado']]);
        $this->assertSame('Empaque distinto al pactado', $return->items->first()->note);
    }

    public function test_el_borrador_no_se_guarda_si_pide_mas_de_lo_disponible(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 10);
        $this->reserve($arroz, 4);

        $error = $this->expectValidation('items.0.quantity', fn () => $this->draft([['product_id' => $arroz->id, 'quantity' => 7]]));
        $this->assertStringContainsString('hay 6', $error);
    }

    public function test_un_proveedor_de_otra_empresa_no_sirve(): void
    {
        $otherCompany = $this->makeOtherCompany()['company'];
        $ajeno = Supplier::query()->withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Ajeno',
            'is_active' => true,
        ]);
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 30);

        $this->expectValidation('supplier_id', fn () => $this->draft([['product_id' => $arroz->id, 'quantity' => 1]], extra: ['supplier_id' => $ajeno->id]));
    }

    public function test_la_bodega_tiene_que_ser_de_la_sede_de_la_devolucion(): void
    {
        $norte = Store::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Norte', 'code' => 'NOR',
            'opens_at' => '07:00:00', 'closes_at' => '21:00:00', 'suggested_cash_base' => 0, 'is_active' => true,
        ]);
        $bodegaNorte = Warehouse::query()->withoutGlobalScopes()->where('store_id', $norte->id)->firstOrFail();
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 30);

        $this->expectValidation('warehouse_id', fn () => $this->draft([['product_id' => $arroz->id, 'quantity' => 1]], warehouse: $bodegaNorte));
    }

    public function test_un_articulo_de_otra_empresa_no_entra(): void
    {
        $otherCompany = $this->makeOtherCompany()['company'];
        $categoria = Category::query()->withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id, 'name' => 'Varios', 'slug' => 'varios', 'is_active' => true,
        ]);
        $ajeno = Product::query()->withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id, 'category_id' => $categoria->id, 'name' => 'Ajeno', 'sku' => 'AJ-1', 'unit' => 'unit',
            'cost' => 100, 'price' => 200, 'tax_rate' => 0, 'is_active' => true,
        ]);

        $this->expectValidation('items.0.product_id', fn () => $this->draft([['product_id' => $ajeno->id, 'quantity' => 1]]));
    }

    /** Corre la accion, espera el error de validacion en esa clave y devuelve el mensaje. */
    protected function expectValidation(string $key, callable $action): string
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors(), 'Errores: '.json_encode($exception->errors(), JSON_UNESCAPED_UNICODE));

            return $exception->errors()[$key][0];
        }

        $this->fail("Se esperaba un error de validacion en {$key}.");
    }
}

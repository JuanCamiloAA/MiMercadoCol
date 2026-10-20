<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuysCatalog;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Armar una orden de compra.
 *
 * El cambio de fondo del modulo: el proveedor **resalta**, no filtra. Antes solo se podia pedir
 * lo que el proveedor elegido surtia, y comprarle dos cosas a dos proveedores obligaba a
 * recorrer la tienda dos veces. Aqui se fija que el catalogo venga completo y que una orden
 * mezclada se acepte, se marque y se pueda separar.
 */
class PurchaseOrderCreateTest extends TestCase
{
    use BuysCatalog, CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected Supplier $lacteos;

    protected Supplier $granos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->admin = $this->makeUser('admin');
        $this->lacteos = $this->makeSupplier('Lacteos del Valle');
        $this->granos = $this->makeSupplier('Granos del Campo');
    }

    public function test_crea_la_orden_con_sus_renglones_y_suma_el_total(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, cost: 4000, stock: 2, minStock: 20);

        $this->as()->post(route('purchases.store'), [
            'supplier_id' => $this->granos->id,
            'status' => PurchaseOrder::STATUS_SENT,
            'items' => [['product_id' => $arroz->id, 'quantity' => 40, 'unit_cost' => 4200, 'origin' => 'suggestion']],
        ])->assertRedirect();

        $order = PurchaseOrder::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(PurchaseOrder::STATUS_SENT, $order->status);
        $this->assertSame(40 * 4200, $order->total);
        $this->assertSame(PurchaseOrder::SOURCE_SUGGESTED, $order->source);
        $this->assertSame(1, $order->items()->count());

        $item = $order->items()->firstOrFail();

        $this->assertSame($this->granos->id, $item->supplier_id);
        $this->assertFalse($item->is_off_supplier);
        // La foto del momento: dentro de tres meses explica por que se pidieron cuarenta.
        $this->assertEqualsWithDelta(2, $item->stock_at_order, 0.001);
        $this->assertEqualsWithDelta(20, $item->min_at_order, 0.001);
        $this->assertEqualsWithDelta(38, $item->suggested_quantity, 0.001);
    }

    public function test_el_catalogo_no_esta_filtrado_por_proveedor(): void
    {
        $this->makeSuppliedProduct('Arroz', $this->granos);
        $this->makeSuppliedProduct('Queso', $this->lacteos);
        $this->makeSuppliedProduct('Leche', $this->lacteos);

        $response = $this->as()->getJson(route('purchases.products', ['supplier' => $this->granos->id]));

        $response->assertOk();

        $names = collect($response->json('products'))->pluck('name');

        // Los tres, aunque dos no los surta el proveedor elegido.
        $this->assertCount(3, $names);
        $this->assertContains('Queso', $names);

        $rows = collect($response->json('products'))->keyBy('name');

        $this->assertTrue($rows['Arroz']['is_from_supplier']);
        $this->assertFalse($rows['Queso']['is_from_supplier']);
        // El de otro proveedor dice de quien es, para que el comprador lo sepa antes de pedirlo.
        $this->assertSame('Lacteos del Valle', $rows['Queso']['supplier_label']);
    }

    public function test_lo_del_proveedor_y_bajo_minimo_va_primero(): void
    {
        $this->makeSuppliedProduct('Zzz normal ajeno', $this->lacteos, stock: 100, minStock: 5);
        $this->makeSuppliedProduct('Yyy bajo ajeno', $this->lacteos, stock: 1, minStock: 30);
        $this->makeSuppliedProduct('Xxx normal propio', $this->granos, stock: 100, minStock: 5);
        $this->makeSuppliedProduct('Www bajo propio', $this->granos, stock: 1, minStock: 30);

        $names = collect(
            $this->as()->getJson(route('purchases.products', ['supplier' => $this->granos->id]))->json('products')
        )->pluck('name')->all();

        // Nunca se esconde nada; solo se ordena por lo que urge.
        $this->assertSame(
            ['Www bajo propio', 'Xxx normal propio', 'Yyy bajo ajeno', 'Zzz normal ajeno'],
            $names
        );
    }

    public function test_marca_is_off_supplier_en_los_renglones_de_otro_proveedor(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos);
        $queso = $this->makeSuppliedProduct('Queso', $this->lacteos);

        $this->as()->post(route('purchases.store'), [
            'supplier_id' => $this->granos->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'items' => [
                ['product_id' => $arroz->id, 'quantity' => 10, 'unit_cost' => 4000],
                ['product_id' => $queso->id, 'quantity' => 5, 'unit_cost' => 9000],
            ],
        ])->assertRedirect();

        $order = PurchaseOrder::query()->withoutGlobalScopes()->firstOrFail();
        $items = $order->items()->get()->keyBy('product_id');

        $this->assertFalse($items[$arroz->id]->is_off_supplier);
        $this->assertTrue($items[$queso->id]->is_off_supplier);
        // Cada renglon conserva su proveedor, que es lo que despues permite separar.
        $this->assertSame($this->lacteos->id, $items[$queso->id]->supplier_id);
    }

    public function test_el_servidor_no_se_cree_el_proveedor_que_mande_el_cliente(): void
    {
        $queso = $this->makeSuppliedProduct('Queso', $this->lacteos);

        $this->as()->post(route('purchases.store'), [
            'supplier_id' => $this->granos->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'items' => [[
                'product_id' => $queso->id,
                'quantity' => 5,
                'unit_cost' => 9000,
                // El cliente intenta colar otro proveedor y otra foto de existencias.
                'supplier_id' => $this->granos->id,
                'stock_at_order' => 999,
                'suggested_quantity' => 999,
            ]],
        ])->assertRedirect();

        $item = PurchaseOrderItem::query()->firstOrFail();

        $this->assertSame($this->lacteos->id, $item->supplier_id);
        $this->assertTrue($item->is_off_supplier);
        $this->assertNotEqualsWithDelta(999, $item->stock_at_order, 0.001);
    }

    public function test_rechaza_cantidad_cero_orden_vacia_y_articulos_de_otra_empresa(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos);

        // Cantidad cero.
        $this->as()->from(route('purchases.create'))->post(route('purchases.store'), [
            'supplier_id' => $this->granos->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'items' => [['product_id' => $arroz->id, 'quantity' => 0, 'unit_cost' => 4000]],
        ])->assertSessionHasErrors('items.0.quantity');

        // Orden vacia.
        $this->as()->from(route('purchases.create'))->post(route('purchases.store'), [
            'supplier_id' => $this->granos->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'items' => [],
        ])->assertSessionHasErrors('items');

        // Articulo de otra empresa.
        $otherCompany = Company::create([
            'name' => 'Otra', 'legal_name' => 'Otra S.A.S.', 'tax_id' => '901222333-4',
            'currency' => 'COP', 'is_active' => true,
        ]);

        $foreign = Product::query()->withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id,
            'category_id' => $this->category->id,
            'name' => 'Ajeno', 'sku' => 'AJE-1', 'unit' => 'unit',
            'cost' => 100, 'price' => 200, 'tax_rate' => 0, 'min_stock' => 0, 'is_active' => true,
        ]);

        $this->as()->from(route('purchases.create'))->post(route('purchases.store'), [
            'supplier_id' => $this->granos->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'items' => [['product_id' => $foreign->id, 'quantity' => 1, 'unit_cost' => 100]],
        ])->assertSessionHasErrors('items.0.product_id');

        $this->assertSame(0, PurchaseOrder::query()->withoutGlobalScopes()->count());
    }

    public function test_un_articulo_no_puede_ir_dos_veces_en_la_misma_orden(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos);

        $this->as()->from(route('purchases.create'))->post(route('purchases.store'), [
            'supplier_id' => $this->granos->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'items' => [
                ['product_id' => $arroz->id, 'quantity' => 5, 'unit_cost' => 4000],
                ['product_id' => $arroz->id, 'quantity' => 3, 'unit_cost' => 4000],
            ],
        ])->assertSessionHasErrors('items.0.product_id');
    }

    public function test_la_pantalla_abre_con_el_catalogo_completo(): void
    {
        $this->makeSuppliedProduct('Arroz', $this->granos);
        $this->makeSuppliedProduct('Queso', $this->lacteos);

        $this->as()->get(route('purchases.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Purchases/Create')
                ->has('products', 2)
                ->has('suppliers', 2)
                ->where('counts.total', 2)
            );
    }

    public function test_el_costo_sugerido_sale_del_ultimo_que_cobro_ese_proveedor(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, cost: 4000);

        ProductSupplier::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'product_id' => $arroz->id,
            'supplier_id' => $this->lacteos->id,
            'last_cost' => 7777,
            'last_purchased_at' => now(),
        ]);

        // Con el proveedor que lo surtio a 7777, ese es el costo.
        $rows = collect($this->as()->getJson(route('purchases.products', ['supplier' => $this->lacteos->id]))->json('products'));
        $this->assertSame(7777, $rows->firstWhere('name', 'Arroz')['unit_cost']);

        // Con Granos, que lo surte a 4000, manda el suyo aunque el otro sea mas reciente.
        $rows = collect($this->as()->getJson(route('purchases.products', ['supplier' => $this->granos->id]))->json('products'));
        $this->assertSame(4000, $rows->firstWhere('name', 'Arroz')['unit_cost']);

        // Con un tercero que nunca lo ha surtido: cae en el mas reciente de cualquiera.
        $nuevo = $this->makeSupplier('Distribuidora Nueva');

        $rows = collect($this->as()->getJson(route('purchases.products', ['supplier' => $nuevo->id]))->json('products'));
        $this->assertSame(7777, $rows->firstWhere('name', 'Arroz')['unit_cost']);
    }

    protected function as(): static
    {
        return $this->actingAs($this->admin)->withSession($this->withActiveStore());
    }
}

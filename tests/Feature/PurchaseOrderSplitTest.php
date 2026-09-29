<?php

namespace Tests\Feature;

use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuysCatalog;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Separar una orden mezclada en una por proveedor.
 *
 * Es lo que hace viable pedirle a varios en una sola pasada: el comprador recorre la tienda una
 * vez, arma todo lo que falta sin pensar en quien se lo surte, y al final la orden se parte.
 * Sin esto habria que recorrer la tienda una vez por proveedor.
 */
class PurchaseOrderSplitTest extends TestCase
{
    use BuysCatalog, CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected Supplier $granos;

    protected Supplier $lacteos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->admin = $this->makeUser('admin');
        $this->granos = $this->makeSupplier('Granos del Campo');
        $this->lacteos = $this->makeSupplier('Lacteos del Valle');
    }

    public function test_separar_crea_una_orden_por_proveedor_y_conserva_el_total(): void
    {
        $order = $this->mixedOrder();
        $totalBefore = $order->total;

        $this->as()->post(route('purchases.split', $order))->assertRedirect();

        $order->refresh();
        $children = PurchaseOrder::query()->withoutGlobalScopes()->where('parent_order_id', $order->id)->get();

        $this->assertCount(1, $children);

        // Nada se pierde ni se duplica al partir.
        $this->assertSame($totalBefore, $order->total + $children->sum('total'));
        $this->assertSame(1, $order->items()->count());
        $this->assertSame(1, $children->first()->items()->count());
    }

    public function test_las_ordenes_hijas_apuntan_a_la_original(): void
    {
        $order = $this->mixedOrder();

        $this->as()->post(route('purchases.split', $order))->assertRedirect();

        $child = PurchaseOrder::query()->withoutGlobalScopes()->where('parent_order_id', $order->id)->firstOrFail();

        $this->assertSame($order->id, $child->parent_order_id);
        $this->assertSame($this->lacteos->id, $child->supplier_id);
        // Hereda lo que define la compra: estado, sede, fecha esperada y quien la hizo.
        $this->assertSame($order->status, $child->status);
        $this->assertSame($order->store_id, $child->store_id);
        $this->assertSame($order->user_id, $child->user_id);
        $this->assertSame(1, $order->children()->count());
    }

    public function test_cada_renglon_queda_con_su_proveedor_correcto(): void
    {
        $order = $this->mixedOrder();

        $this->as()->post(route('purchases.split', $order))->assertRedirect();

        $child = PurchaseOrder::query()->withoutGlobalScopes()->where('parent_order_id', $order->id)->firstOrFail();

        // En su orden nueva ya no es «de otro proveedor».
        $this->assertFalse($child->items()->firstOrFail()->is_off_supplier);
        $this->assertFalse($order->refresh()->items()->firstOrFail()->is_off_supplier);
    }

    public function test_una_orden_de_un_solo_proveedor_no_se_separa(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos);

        $this->as()->post(route('purchases.store'), [
            'supplier_id' => $this->granos->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'items' => [['product_id' => $arroz->id, 'quantity' => 10, 'unit_cost' => 4000]],
        ]);

        $order = PurchaseOrder::query()->withoutGlobalScopes()->firstOrFail();

        $this->as()->from(route('purchases.show', $order))
            ->post(route('purchases.split', $order))
            ->assertSessionHasErrors('items');

        $this->assertSame(0, PurchaseOrder::query()->withoutGlobalScopes()->whereNotNull('parent_order_id')->count());
    }

    public function test_una_orden_recibida_ya_no_se_separa(): void
    {
        $order = $this->mixedOrder();
        $order->update(['status' => PurchaseOrder::STATUS_RECEIVED]);

        $this->as()->from(route('purchases.show', $order))
            ->post(route('purchases.split', $order))
            ->assertSessionHasErrors('status');
    }

    public function test_se_puede_separar_al_crear(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos);
        $queso = $this->makeSuppliedProduct('Queso', $this->lacteos);

        $this->as()->post(route('purchases.store'), [
            'supplier_id' => $this->granos->id,
            'status' => PurchaseOrder::STATUS_SENT,
            'split' => true,
            'items' => [
                ['product_id' => $arroz->id, 'quantity' => 10, 'unit_cost' => 4000],
                ['product_id' => $queso->id, 'quantity' => 5, 'unit_cost' => 9000],
            ],
        ])->assertRedirect();

        // Dos ordenes: la original con lo de Granos y la hija con lo de Lacteos.
        $this->assertSame(2, PurchaseOrder::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, PurchaseOrder::query()->withoutGlobalScopes()->whereNotNull('parent_order_id')->count());
    }

    public function test_tres_proveedores_dan_tres_ordenes(): void
    {
        $aseo = $this->makeSupplier('Aseo Total');

        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos);
        $queso = $this->makeSuppliedProduct('Queso', $this->lacteos);
        $jabon = $this->makeSuppliedProduct('Jabon', $aseo);

        $this->as()->post(route('purchases.store'), [
            'supplier_id' => $this->granos->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'items' => [
                ['product_id' => $arroz->id, 'quantity' => 10, 'unit_cost' => 4000],
                ['product_id' => $queso->id, 'quantity' => 5, 'unit_cost' => 9000],
                ['product_id' => $jabon->id, 'quantity' => 3, 'unit_cost' => 2000],
            ],
        ]);

        $order = PurchaseOrder::query()->withoutGlobalScopes()->firstOrFail();
        $totalBefore = $order->total;

        $this->as()->post(route('purchases.split', $order))->assertRedirect();

        $children = PurchaseOrder::query()->withoutGlobalScopes()->where('parent_order_id', $order->id)->get();

        $this->assertCount(2, $children);
        $this->assertSame($totalBefore, $order->refresh()->total + $children->sum('total'));
    }

    /** Una orden de Granos con un articulo de Lacteos adentro. */
    protected function mixedOrder(): PurchaseOrder
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, cost: 4000);
        $queso = $this->makeSuppliedProduct('Queso', $this->lacteos, cost: 9000);

        $this->as()->post(route('purchases.store'), [
            'supplier_id' => $this->granos->id,
            'status' => PurchaseOrder::STATUS_SENT,
            'items' => [
                ['product_id' => $arroz->id, 'quantity' => 10, 'unit_cost' => 4000],
                ['product_id' => $queso->id, 'quantity' => 5, 'unit_cost' => 9000],
            ],
        ]);

        return PurchaseOrder::query()->withoutGlobalScopes()->whereNull('parent_order_id')->firstOrFail();
    }

    protected function as(): static
    {
        return $this->actingAs($this->admin)->withSession($this->withActiveStore());
    }
}

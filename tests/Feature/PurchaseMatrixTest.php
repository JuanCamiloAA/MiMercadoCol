<?php

namespace Tests\Feature;

use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsVariants;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Compras con matriz: el articulo con subarticulos abre su matriz (valor 1 en filas, valor 2 en
 * columnas) con el costo sugerido de cada subarticulo y el proveedor de la orden. Al servidor
 * llegan renglones normales por subarticulo, y se reciben por partes como siempre.
 */
class PurchaseMatrixTest extends TestCase
{
    use BuildsVariants, CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->seedAttributes();
        $this->admin = $this->makeUser('admin');
    }

    public function test_la_matriz_trae_cada_subarticulo_con_su_costo_del_proveedor(): void
    {
        $supplier = Supplier::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Gaseosas del Valle', 'is_active' => true]);
        $family = $this->makeFamily('Gaseosa', 'Sabor', 'Volumen', [
            ['Cola', '1,5 L', 5200], ['Cola', '3 L', 8900], ['Naranja', '1,5 L', 5200],
        ], user: $this->admin);
        $colaSmall = $family->variants()->first();

        ProductSupplier::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'product_id' => $colaSmall->id, 'supplier_id' => $supplier->id, 'last_cost' => 3100, 'is_primary' => true]);

        $matrix = $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->getJson(route('purchases.family', ['family' => $family->id, 'supplier' => $supplier->id]))
            ->assertOk()
            ->assertJsonPath('name', 'Gaseosa')
            ->assertJsonPath('attribute_1.name', 'Sabor')
            ->assertJsonPath('attribute_2.name', 'Volumen')
            ->assertJsonCount(3, 'rows')
            ->json();

        $row = collect($matrix['rows'])->firstWhere('id', $colaSmall->id);
        $this->assertSame(3100, $row['unit_cost']);
        $this->assertSame($family->id, $row['family_id']);
        $this->assertSame(['Cola', 'Naranja'], array_column($matrix['attribute_1']['values'], 'value'));
    }

    public function test_los_renglones_de_la_matriz_se_reciben_por_partes(): void
    {
        $supplier = Supplier::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Gaseosas del Valle', 'is_active' => true]);
        $family = $this->makeFamily('Gaseosa', 'Sabor', 'Volumen', [['Cola', '1,5 L', 5200], ['Naranja', '1,5 L', 5200]], user: $this->admin);
        [$cola, $orange] = $family->variants()->get()->all();

        $this->actingAs($this->admin)->withSession($this->withActiveStore())->post(route('purchases.store'), [
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrder::STATUS_SENT,
            'items' => [
                ['product_id' => $cola->id, 'quantity' => 5, 'unit_cost' => 3100],
                ['product_id' => $orange->id, 'quantity' => 3, 'unit_cost' => 3000],
            ],
        ])->assertSessionHasNoErrors();

        $order = PurchaseOrder::query()->withoutGlobalScopes()->sole();
        $items = PurchaseOrderItem::query()->where('purchase_order_id', $order->id)->get()->keyBy('product_id');

        $this->actingAs($this->admin)->withSession($this->withActiveStore())->post(route('purchases.receive', $order), [
            'items' => [
                ['id' => $items[$cola->id]->id, 'received_quantity' => 5, 'unit_cost' => 3100],
                ['id' => $items[$orange->id]->id, 'received_quantity' => 1, 'unit_cost' => 3000],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(PurchaseOrder::STATUS_PARTIAL, $order->fresh()->status);

        // La ficha agrupa los renglones de la misma familia.
        $this->actingAs($this->admin)->get(route('purchases.show', $order))->assertInertia(fn ($page) => $page
            ->where('order.items.0.family_id', $family->id)
            ->where('order.items.0.family_name', 'Gaseosa'));
    }
}

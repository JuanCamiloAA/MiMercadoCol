<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use App\Support\SearchText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsVariants;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Editar un articulo con subarticulos: lo comun se propaga (categoria, IVA, marca, nombre y la
 * llave del buscador), un subarticulo con movimientos se desactiva en vez de borrarse, y una venta
 * de ayer conserva su IVA.
 */
class ProductFamilyUpdateTest extends TestCase
{
    use BuildsVariants, CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected ProductFamily $family;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->seedAttributes();
        $this->admin = $this->makeUser('admin');
        $this->family = $this->makeFamily('Aceite de girasol', 'Volumen', null, [
            ['500 ml', null, 7900], ['900 ml', null, 13900],
            // Sin existencia inicial: no tiene ningun movimiento.
            ['2 L', null, 27500, ['initial_stocks' => []]],
        ], ['brand' => 'Premier'], $this->admin);
    }

    /** La matriz tal como la manda el formulario al editar. */
    protected function payload(array $overrides = [], ?callable $rows = null): array
    {
        $variants = $this->family->variants()->get()->map(fn (Product $variant) => [
            'id' => $variant->id,
            'value_1_id' => $variant->variant_value_1_id,
            'sku' => $variant->sku,
            'price' => $variant->price,
            'cost' => $variant->cost,
            'min_stock' => 0,
            'is_active' => true,
            'is_online_visible' => true,
        ])->all();

        return array_merge([
            'name' => $this->family->name,
            'category_id' => $this->family->category_id,
            'brand' => $this->family->brand,
            'tax_rate' => 19,
            'attribute_1_id' => $this->family->attribute_1_id,
            'is_active' => true,
            'is_online_visible' => true,
            'variants' => $rows ? $rows($variants) : $variants,
        ], $overrides);
    }

    public function test_lo_comun_se_propaga_y_la_venta_de_ayer_conserva_su_iva(): void
    {
        $variant = $this->family->variants()->first();
        $sale = $this->sell($variant, 2);

        $bebidas = Category::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Aceites', 'slug' => 'aceites', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->put(route('products.families.update', $this->family), $this->payload([
                'name' => 'Aceite de girasol Premier',
                'category_id' => $bebidas->id,
                'brand' => 'Premier Oro',
                'tax_rate' => 5,
            ]))
            ->assertSessionHasNoErrors();

        $this->family->variants()->get()->each(function (Product $each) use ($bebidas) {
            $this->assertSame($bebidas->id, $each->category_id);
            $this->assertSame(5.0, $each->tax_rate);
            $this->assertSame('Premier Oro', $each->brand);
            $this->assertStringStartsWith('Aceite de girasol Premier · ', $each->name);
            $this->assertSame(mb_substr(SearchText::key($each->name.' Premier Oro'), 0, 255), $each->search_name);
        });

        $this->assertSame(19.0, (float) SaleItem::query()->where('sale_id', $sale->id)->value('tax_rate'));
        $this->assertSame('aceite-de-girasol-premier', $this->family->fresh()->slug);
    }

    public function test_quitar_con_movimientos_desactiva_y_sin_nada_elimina(): void
    {
        [$sold, , $untouched] = $this->family->variants()->get()->all();
        $this->sell($sold, 1);

        $this->actingAs($this->admin)
            ->put(route('products.families.update', $this->family), $this->payload(rows: fn ($rows) => array_values(array_filter($rows, fn ($row) => ! in_array($row['id'], [$sold->id, $untouched->id], true)))))
            ->assertSessionHasNoErrors();

        $this->assertFalse($sold->fresh()->is_active);
        $this->assertNull($sold->fresh()->deleted_at);
        $this->assertSoftDeleted($untouched->fresh());

        // La combinacion eliminada se puede volver a crear.
        $this->actingAs($this->admin)
            ->put(route('products.families.update', $this->family), $this->payload(rows: fn ($rows) => [...$rows, [
                'value_1_id' => $untouched->variant_value_1_id, 'sku' => 'ACE-NUEVO', 'price' => 14500, 'is_active' => true, 'is_online_visible' => true,
            ]]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Product::query()->where('product_family_id', $this->family->id)->where('sku', 'ACE-NUEVO')->count());
    }

    public function test_la_primera_caracteristica_no_cambia_y_se_puede_sumar_una_segunda(): void
    {
        $this->actingAs($this->admin)
            ->put(route('products.families.update', $this->family), $this->payload(['attribute_1_id' => $this->attribute('Sabor')->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->attribute('Volumen')->id, $this->family->fresh()->attribute_1_id);
    }

    public function test_sin_subarticulos_activos_la_familia_se_apaga(): void
    {
        $this->actingAs($this->admin)
            ->put(route('products.families.update', $this->family), $this->payload(rows: fn ($rows) => array_map(fn ($row) => [...$row, 'is_active' => false], $rows)))
            ->assertSessionHasErrors('variants');
    }

    protected function sell(Product $product, float $quantity): Sale
    {
        $session = app(RegisterSessionService::class)->open($this->register, $this->admin, 100000);

        return app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 1_000_000]],
        ], $this->admin, $this->store, $session);
    }
}

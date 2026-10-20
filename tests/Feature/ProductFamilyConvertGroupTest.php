<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductStock;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsVariants;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Convertir un articulo individual en el primer subarticulo, agrupar articulos que ya existen,
 * separar un subarticulo y deshacer la agrupacion. En todos los caminos el articulo conserva su
 * id, su existencia, su kardex y sus ventas.
 */
class ProductFamilyConvertGroupTest extends TestCase
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

    public function test_convertir_conserva_id_kardex_y_ventas(): void
    {
        $oil = $this->makeProduct(['name' => 'Aceite girasol 900 ml', 'sku' => 'ACE-900', 'price' => 13900, 'tax_rate' => 19], 30);
        $session = app(RegisterSessionService::class)->open($this->register, $this->admin, 100000);
        app(SaleService::class)->create([
            'items' => [['product_id' => $oil->id, 'quantity' => 2]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 50000]],
        ], $this->admin, $this->store, $session);

        $movements = StockMovement::query()->where('product_id', $oil->id)->count();

        $this->actingAs($this->admin)->post(route('products.convert', $oil), [
            'name' => 'Aceite de girasol',
            'category_id' => $this->category->id,
            'tax_rate' => 19,
            'attribute_1_id' => $this->attribute('Volumen')->id,
            'variants' => [
                ['id' => $oil->id, 'value_1_id' => $this->value('Volumen', '900 ml')->id, 'sku' => 'ACE-900', 'price' => 13900, 'is_active' => true, 'is_online_visible' => true],
                ['value_1_id' => $this->value('Volumen', '2 L')->id, 'sku' => 'ACE-2000', 'price' => 27500, 'is_active' => true, 'is_online_visible' => true],
            ],
        ])->assertSessionHasNoErrors();

        $oil->refresh();
        $family = ProductFamily::query()->sole();

        $this->assertSame($family->id, $oil->product_family_id);
        $this->assertSame('Aceite de girasol · 900 ml', $oil->name);
        $this->assertSame(28.0, (float) ProductStock::query()->where('product_id', $oil->id)->sum('quantity'));
        $this->assertSame($movements, StockMovement::query()->where('product_id', $oil->id)->count());
        $this->assertSame(1, SaleItem::query()->where('product_id', $oil->id)->count());
        $this->assertSame(2, $family->variants()->count());
    }

    public function test_las_casillas_vacias_del_formulario_no_rompen_la_conversion(): void
    {
        // Lo que manda el formulario con «Empaque de compra», mayorista y código vacíos.
        $oil = $this->makeProduct(['name' => 'Aceite de girasol 1 L', 'sku' => 'DES-039', 'price' => 12500, 'tax_rate' => 19, 'purchase_pack_size' => 12]);
        $row = fn (array $extra) => array_merge([
            'barcode' => '', 'online_price' => null, 'wholesale_price' => null, 'cost' => 1000, 'content_amount' => null, 'content_unit' => null,
            'min_stock' => 10, 'is_active' => true, 'is_online_visible' => true, 'unit' => 'unit', 'sold_by_weight' => false, 'track_lots' => false,
            'allow_negative_stock' => false, 'purchase_pack_size' => null, 'description' => '',
        ], $extra);

        $this->actingAs($this->admin)->post(route('products.convert', $oil), [
            'name' => 'Aceite de girasol',
            'category_id' => $this->category->id,
            'tax_rate' => 19,
            'attribute_1_id' => $this->attribute('Volumen')->id,
            'variants' => [
                $row(['id' => $oil->id, 'value_1_id' => $this->value('Volumen', '1 L')->id, 'sku' => 'DES-039', 'price' => 12500, 'initial_stocks' => []]),
                $row(['value_1_id' => $this->value('Volumen', '500 ml')->id, 'sku' => 'DES-039-500', 'price' => 18000, 'initial_stocks' => [['store_id' => $this->store->id, 'quantity' => 5]]]),
            ],
        ])->assertSessionHasNoErrors();

        $family = ProductFamily::query()->sole();
        $new = $family->variants()->where('sku', 'DES-039-500')->sole();

        // El nuevo queda con el defecto de la base y el que ya existia conserva el suyo.
        $this->assertSame(1, (int) $new->refresh()->purchase_pack_size);
        $this->assertSame(12, (int) $oil->refresh()->purchase_pack_size);
        $this->assertSame(5.0, (float) ProductStock::query()->where('product_id', $new->id)->sum('quantity'));
    }

    public function test_agrupar_dos_articulos_existentes(): void
    {
        $small = $this->makeProduct(['name' => 'Aceite 500', 'sku' => 'A-500', 'price' => 7900, 'tax_rate' => 5]);
        $big = $this->makeProduct(['name' => 'Aceite 1000', 'sku' => 'A-1000', 'price' => 15000, 'tax_rate' => 19]);

        $this->actingAs($this->admin)->get(route('products.families.group.form', ['ids' => [$small->id, $big->id]]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Products/FamilyForm')->where('mode', 'group')->has('rows', 2));

        $this->actingAs($this->admin)->post(route('products.families.group'), [
            'name' => 'Aceite de girasol',
            'category_id' => $this->category->id,
            'tax_rate' => 19,
            'attribute_1_id' => $this->attribute('Volumen')->id,
            'variants' => [
                ['id' => $small->id, 'value_1_id' => $this->value('Volumen', '500 ml')->id, 'sku' => 'A-500', 'price' => 7900, 'is_active' => true, 'is_online_visible' => true],
                ['id' => $big->id, 'value_1_id' => $this->value('Volumen', '1 L')->id, 'sku' => 'A-1000', 'price' => 15000, 'is_active' => true, 'is_online_visible' => true],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['Aceite de girasol · 500 ml', 'Aceite de girasol · 1 L'], ProductFamily::query()->sole()->variants()->pluck('name')->all());
        // El IVA es comun: el de 500 ml toma el de la familia.
        $this->assertSame(19.0, $small->fresh()->tax_rate);

        // Uno que ya es subarticulo no se puede volver a agrupar.
        $other = $this->makeProduct(['name' => 'Aceite 2000', 'sku' => 'A-2000']);
        $this->actingAs($this->admin)->post(route('products.families.group'), [
            'name' => 'Otra familia',
            'category_id' => $this->category->id,
            'tax_rate' => 19,
            'attribute_1_id' => $this->attribute('Volumen')->id,
            'variants' => [
                ['id' => $small->id, 'value_1_id' => $this->value('Volumen', '500 ml')->id, 'sku' => 'A-500', 'price' => 7900, 'is_active' => true],
                ['id' => $other->id, 'value_1_id' => $this->value('Volumen', '2 L')->id, 'sku' => 'A-2000', 'price' => 9000, 'is_active' => true],
            ],
        ])->assertSessionHasErrors('variants.0.id');
    }

    public function test_separar_y_deshacer(): void
    {
        $family = $this->makeFamily('Aceite de girasol', 'Volumen', null, [['500 ml', null, 7900], ['900 ml', null, 13900], ['2 L', null, 27500]], user: $this->admin);
        [$first, $second] = $family->variants()->take(2)->get()->all();

        $this->actingAs($this->admin)->post(route('products.detach', $first))->assertSessionHas('success');
        $this->assertNull($first->fresh()->product_family_id);
        $this->assertSame('Aceite de girasol · 500 ml', $first->fresh()->name);
        $this->assertSame(2, $family->variants()->count());
        $this->assertNotSame($first->id, $family->fresh()->default_variant_id);

        $this->actingAs($this->admin)->post(route('products.families.ungroup', $family))->assertRedirect(route('products.index'));
        $this->assertNull($second->fresh()->product_family_id);
        $this->assertSoftDeleted($family->fresh());
        $this->assertSame(0, Product::query()->whereNotNull('product_family_id')->count());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductStock;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsVariants;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Crear un articulo con subarticulos desde la matriz: con una y con dos caracteristicas, el
 * nombre compuesto, el contenido que trae una medida, codigos unicos (tambien dentro de la matriz
 * y contra servicios y familias) y la existencia inicial por sede.
 */
class ProductFamilyCreateTest extends TestCase
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

    /** @param  list<array<string, mixed>>  $variants */
    protected function payload(array $variants, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Aceite de girasol',
            'code' => 'ACE-GIR',
            'category_id' => $this->category->id,
            'brand' => 'Premier',
            'tax_rate' => 19,
            'attribute_1_id' => $this->attribute('Volumen')->id,
            'is_active' => true,
            'is_online_visible' => true,
            'variants' => $variants,
        ], $overrides);
    }

    protected function row(string $value, string $sku, int $price, array $extra = []): array
    {
        return array_merge([
            'value_1_id' => $this->value('Volumen', $value)->id,
            'sku' => $sku,
            'barcode' => null,
            'price' => $price,
            'cost' => (int) ($price * 0.7),
            'min_stock' => 5,
            'is_active' => true,
            'is_online_visible' => true,
        ], $extra);
    }

    public function test_una_caracteristica_de_medida(): void
    {
        $this->actingAs($this->admin)->post(route('products.families.store'), $this->payload([
            $this->row('500 ml', 'ACE-500', 7900, ['barcode' => '7702001000011', 'initial_stocks' => [['store_id' => $this->store->id, 'quantity' => 12]]]),
            $this->row('900 ml', 'ACE-900', 13900, ['barcode' => '7702001000028']),
            $this->row('2 L', 'ACE-2000', 27500),
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $family = ProductFamily::query()->where('name', 'Aceite de girasol')->sole();
        $variants = $family->variants()->get();

        $this->assertSame(['Aceite de girasol · 500 ml', 'Aceite de girasol · 900 ml', 'Aceite de girasol · 2 L'], $variants->pluck('name')->all());
        $this->assertSame(['500 ml', '900 ml', '2 L'], $variants->pluck('variant_label')->all());

        // La medida pone el contenido: la tienda calcula el precio por litro sin que nadie lo digite.
        $this->assertSame([500.0, 900.0, 2.0], $variants->pluck('content_amount')->all());
        $this->assertSame(['ml', 'ml', 'l'], $variants->pluck('content_unit')->all());

        // Lo comun se copia a cada subarticulo.
        $variants->each(function (Product $variant) {
            $this->assertSame($this->category->id, $variant->category_id);
            $this->assertSame(19.0, $variant->tax_rate);
            $this->assertSame('Premier', $variant->brand);
        });

        // La existencia inicial entra como un ajuste, por `StockService`.
        $this->assertSame(12.0, (float) ProductStock::query()->where('product_id', $variants[0]->id)->sum('quantity'));
        $this->assertSame($variants[0]->id, $family->default_variant_id);
    }

    public function test_dos_caracteristicas_con_nombre_en_la_talla(): void
    {
        $family = $this->makeFamily('Camiseta básica', 'Talla', 'Color', [
            ['M', 'Azul', 29900], ['M', 'Negro', 29900], ['L', 'Azul', 31900],
        ], user: $this->admin);

        $this->assertSame(
            // En el orden del catalogo (M antes que L; Negro antes que Azul), no en el que se escribio.
            ['Camiseta básica · Talla M · Negro', 'Camiseta básica · Talla M · Azul', 'Camiseta básica · Talla L · Azul'],
            $family->variants()->pluck('name')->all(),
        );
    }

    public function test_codigos_unicos_dentro_de_la_matriz_y_contra_lo_que_ya_existe(): void
    {
        $this->makeProduct(['name' => 'Aceite viejo', 'sku' => 'VIEJO-1', 'barcode' => '7700000000017']);
        Service::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Ancheta', 'code' => 'SRV-ANCH', 'category_id' => $this->category->id,
            'price' => 50000, 'pricing_mode' => 'bundled', 'tax_rate' => 19, 'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->post(route('products.families.store'), $this->payload([
            $this->row('500 ml', 'ACE-500', 7900),
            $this->row('900 ml', 'ACE-500', 13900),
            $this->row('2 L', 'VIEJO-1', 27500),
            $this->row('1 L', 'ACE-1000', 15000, ['barcode' => 'SRV-ANCH']),
        ]));

        $response->assertSessionHasErrors([
            'variants.1.sku' => 'Fila 900 ml: ese SKU ya está en otra fila de la matriz.',
            'variants.2.sku' => 'Fila 2 L: ese SKU ya es de Aceite viejo.',
        ]);
        $this->assertStringContainsString('servicio Ancheta', session('errors')->first('variants.3.barcode'));
        $this->assertSame(0, ProductFamily::query()->count());

        // El codigo de la familia tampoco puede ser el de un articulo.
        $this->actingAs($this->admin)->post(route('products.families.store'), $this->payload([$this->row('500 ml', 'ACE-500', 7900)], ['code' => 'VIEJO-1']))
            ->assertSessionHasErrors('code');
    }

    public function test_combinacion_repetida_y_precio_cero_visible(): void
    {
        $this->actingAs($this->admin)->post(route('products.families.store'), $this->payload([
            $this->row('500 ml', 'A-1', 7900),
            $this->row('500 ml', 'A-2', 7900),
        ]))->assertSessionHasErrors(['variants.1.value_1_id' => 'Fila 500 ml: esa combinación está repetida.']);

        $this->actingAs($this->admin)->post(route('products.families.store'), $this->payload([
            $this->row('500 ml', 'A-1', 0),
        ]))->assertSessionHasErrors(['variants.0.price']);

        // Inactivo en cero si se puede: no se vende hasta tener precio.
        $this->actingAs($this->admin)->post(route('products.families.store'), $this->payload([
            $this->row('500 ml', 'A-1', 7900),
            $this->row('900 ml', 'A-2', 0, ['is_active' => false]),
        ]))->assertSessionHasNoErrors();
    }

    public function test_el_codigo_de_la_familia_no_lo_puede_usar_un_articulo_ni_un_servicio(): void
    {
        $this->makeFamily('Aceite de girasol', 'Volumen', null, [['500 ml', null, 7900]], ['code' => 'ACE-GIR'], $this->admin);

        $this->actingAs($this->admin)->post(route('products.store'), [
            'category_id' => $this->category->id, 'name' => 'Otro', 'sku' => 'ACE-GIR', 'unit' => 'unit',
            'cost' => 100, 'price' => 200, 'tax_rate' => 0, 'min_stock' => 0,
        ])->assertSessionHasErrors('sku');
    }

    public function test_sin_permiso_de_crear_no_entra(): void
    {
        $cashier = $this->makeUser('cajero');

        $this->actingAs($cashier)->post(route('products.families.store'), $this->payload([$this->row('500 ml', 'A-1', 7900)]))->assertForbidden();
        $this->actingAs($this->admin)->get(route('products.families.create'))->assertOk()->assertInertia(fn ($page) => $page->component('Products/FamilyForm')->where('mode', 'create'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\ProductFamily;
use App\Models\ProductFamilyImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsStorefront;
use Tests\Concerns\BuildsVariants;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * La pagina del articulo con subarticulos: lo que el navegador necesita para cruzar la
 * disponibilidad de dos caracteristicas, la galeria por color, el enlace viejo del subarticulo
 * y el carrito, que siempre recibe una presentacion.
 */
class StorefrontFamilyPageTest extends TestCase
{
    use BuildsStorefront, BuildsVariants, CreatesCompanyEnvironment, RefreshDatabase;

    protected ProductFamily $shirt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->seedAttributes();
        $this->publishSite();
        $this->shopAt();

        // La M azul esta agotada y quedo como la de por defecto: la pagina no abre en ella.
        $this->shirt = $this->makeFamily('Camiseta básica', 'Talla', 'Color', [
            ['S', 'Azul', 24900],
            ['M', 'Azul', 24900, ['initial_stocks' => []]],
            ['M', 'Negro', 26900],
        ], ['default_index' => 1]);
    }

    public function test_trae_lo_necesario_para_cruzar_dos_caracteristicas(): void
    {
        $outOfStock = $this->variant('M', 'Azul');

        $this->get(route('storefront.family', $this->shirt->slug))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Storefront/Family')
            ->where('family.name', 'Camiseta básica')
            ->has('attributes', 2)
            ->where('attributes.0.name', 'Talla')
            ->where('attributes.0.values', fn ($values) => collect($values)->pluck('value')->all() === ['S', 'M'])
            ->where('attributes.1.values', fn ($values) => collect($values)->pluck('value')->all() === ['Negro', 'Azul'])
            ->has('variants', 3)
            ->where('variants', fn ($variants) => collect($variants)->firstWhere('id', $outOfStock->id)['stock']['out'] === true
                && collect($variants)->firstWhere('id', $outOfStock->id)['value_1_id'] === $this->value('Talla', 'M')->id
                && collect($variants)->firstWhere('id', $outOfStock->id)['label'] === 'Talla M · Azul')
            ->where('default_id', fn ($id) => $id !== $outOfStock->id)
            ->where('selected_id', fn ($id) => $id !== $outOfStock->id));
    }

    public function test_la_variante_del_enlace_queda_elegida(): void
    {
        $black = $this->variant('M', 'Negro');

        $this->get(route('storefront.family', ['familySlug' => $this->shirt->slug, 'variante' => $black->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('selected_id', $black->id));

        // Una que no es de este articulo no se elige: queda la de por defecto.
        $this->get(route('storefront.family', ['familySlug' => $this->shirt->slug, 'variante' => 999999]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('selected_id', fn ($id) => $id !== 999999));
    }

    public function test_la_galeria_trae_las_fotos_marcadas_por_color(): void
    {
        $blue = $this->value('Color', 'Azul');
        ProductFamilyImage::query()->create(['product_family_id' => $this->shirt->id, 'path' => 'products/1/comun.jpg', 'sort_order' => 1]);
        ProductFamilyImage::query()->create(['product_family_id' => $this->shirt->id, 'path' => 'products/1/azul.jpg', 'attribute_value_id' => $blue->id, 'sort_order' => 2]);

        $this->get(route('storefront.family', $this->shirt->slug))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('images', 2)
            ->where('images.0.value_id', null)
            ->where('images.1.value_id', $blue->id)
            ->where('images.1.url', '/storage/products/1/azul.jpg'));

        // Las opciones para escoger desde la tarjeta son las mismas, en JSON.
        $this->getJson(route('storefront.family.options', $this->shirt->slug))
            ->assertOk()
            ->assertJsonCount(3, 'variants')
            ->assertJsonPath('family.href', '/tienda/articulo/'.$this->shirt->slug);
    }

    public function test_el_enlace_viejo_del_subarticulo_lleva_a_su_articulo(): void
    {
        $small = $this->variant('S', 'Azul');

        $this->get(route('storefront.product', $small->id))
            ->assertStatus(301)
            ->assertRedirect('/tienda/articulo/'.$this->shirt->slug.'?variante='.$small->id);
    }

    public function test_al_carrito_va_la_presentacion_elegida(): void
    {
        $black = $this->variant('M', 'Negro');

        $this->putJson(route('storefront.cart.quantity'), ['product_id' => $black->id, 'quantity' => 2])
            ->assertOk()
            ->assertJsonPath('cart.lines.0.key', 'p:'.$black->id);

        $this->assertSame($black->id, (int) CartItem::query()->value('product_id'));
        $this->assertEquals(2, CartItem::query()->value('quantity'));

        // La agotada no entra.
        $this->putJson(route('storefront.cart.quantity'), ['product_id' => $this->variant('M', 'Azul')->id, 'quantity' => 1])->assertStatus(422);
    }

    public function test_un_articulo_oculto_no_abre(): void
    {
        $this->shirt->forceFill(['is_online_visible' => false])->save();

        $this->get(route('storefront.family', $this->shirt->slug))->assertNotFound();
        $this->getJson(route('storefront.family.options', $this->shirt->slug))->assertNotFound();
    }

    protected function variant(string $size, string $color): \App\Models\Product
    {
        return $this->shirt->variants()
            ->where('variant_value_1_id', $this->value('Talla', $size)->id)
            ->where('variant_value_2_id', $this->value('Color', $color)->id)
            ->firstOrFail();
    }
}

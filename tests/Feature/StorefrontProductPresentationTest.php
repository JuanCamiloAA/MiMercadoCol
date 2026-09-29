<?php

namespace Tests\Feature;

use App\Services\Storefront\StorefrontProducts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsStorefront;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * La tarjeta de producto: la imagen llega resuelta, el precio por unidad lo calcula el servidor
 * y la existencia se dice segun la regla de la sede, sin soltar la cifra si no se quiere.
 */
class StorefrontProductPresentationTest extends TestCase
{
    use BuildsStorefront, CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->publishSite();
        $this->shopAt();
    }

    public function test_la_imagen_cae_hasta_la_del_catalogo_externo(): void
    {
        $this->assertSame(['url' => '/storage/products/thumb.webp', 'external' => false], StorefrontProducts::image('products/thumb.webp', 'https://images.openfoodfacts.org/x.jpg'));
        $this->assertSame(['url' => 'https://images.openfoodfacts.org/x.jpg', 'external' => true], StorefrontProducts::image(null, 'https://images.openfoodfacts.org/x.jpg'));
        $this->assertSame(['url' => null, 'external' => false], StorefrontProducts::image(null, 'javascript:alert(1)'));

        $importado = $this->makeProduct([
            'name' => 'Atún en agua 160 g', 'is_online_visible' => true,
            'image_url' => 'https://images.openfoodfacts.org/images/products/770/236/700/3917/front_es.99.400.jpg',
        ], 10);

        $this->get(route('storefront.product', $importado))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('product.image', $importado->image_url)
            ->where('product.image_external', true)
            ->has('product.category_icon'));
    }

    public function test_el_precio_por_unidad_lo_calcula_el_servidor(): void
    {
        $this->assertSame('$ 14.333 x L', StorefrontProducts::pricePerUnit(12900, 900, 'ml'));
        $this->assertSame('$ 32.000 x kg', StorefrontProducts::pricePerUnit(8000, 250, 'g'));
        // Un kilo o un litro no lo dice: seria repetir el precio.
        $this->assertNull(StorefrontProducts::pricePerUnit(5100, 1, 'kg'));
        $this->assertSame('900 ml', StorefrontProducts::content(900, 'ml'));

        $aceite = $this->makeProduct(['name' => 'Aceite 900 ml', 'price' => 12900, 'online_price' => 12900, 'content_amount' => 900, 'content_unit' => 'ml', 'is_online_visible' => true], 10);

        $this->get(route('storefront.product', $aceite))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('product.unit_price', '$ 14.333 x L')
            ->where('product.content', '900 ml'));
    }

    public function test_la_existencia_se_dice_segun_la_regla_de_la_sede(): void
    {
        $low = ['stock_display' => 'low_only', 'low_stock_threshold' => 5];

        $this->assertSame('Últimas 3 unidades', StorefrontProducts::stock(3, false, false, $low)['note']);
        $this->assertNull(StorefrontProducts::stock(35, false, false, $low)['note']);
        $this->assertSame('Agotado', StorefrontProducts::stock(0, false, false, $low)['note']);
        $this->assertNull(StorefrontProducts::stock(3, false, false, ['stock_display' => 'hidden'])['note']);
        $this->assertSame('35 disponibles', StorefrontProducts::stock(35, false, false, ['stock_display' => 'exact'])['note']);
        // Lo que admite negativo nunca sale agotado.
        $this->assertFalse(StorefrontProducts::stock(0, true, false, $low)['out']);

        // Por defecto la tienda no muestra la cifra exacta.
        $arroz = $this->makeProduct(['name' => 'Arroz 1 kg', 'is_online_visible' => true], 35);

        $this->get(route('storefront.product', $arroz))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('product.stock.note', null)
            ->missing('product.available'));

        $this->publishSetting('card', ['stock_display' => 'exact']);

        $this->get(route('storefront.product', $arroz))->assertInertia(fn (AssertableInertia $page) => $page->where('product.stock.note', '35 disponibles'));
    }

    public function test_un_articulo_en_cero_no_se_muestra(): void
    {
        $this->makeProduct(['name' => 'Importado sin precio', 'price' => 0, 'online_price' => 0, 'is_online_visible' => true], 10);
        $this->makeProduct(['name' => 'Arroz con precio', 'price' => 5100, 'is_online_visible' => true], 10);

        $this->get(route('storefront.search'))->assertOk()->assertSee('Arroz con precio', false)->assertDontSee('Importado sin precio', false);
    }
}

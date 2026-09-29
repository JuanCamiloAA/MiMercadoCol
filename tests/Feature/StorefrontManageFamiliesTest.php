<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsStorefront;
use Tests\Concerns\BuildsVariants;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * «Tienda en linea» publica la tarjeta del articulo con subarticulos; que presentaciones se
 * ofrecen se decide en cada subarticulo.
 */
class StorefrontManageFamiliesTest extends TestCase
{
    use BuildsStorefront, BuildsVariants, CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->seedAttributes();
        $this->admin = $this->makeUser('admin');
    }

    public function test_la_pestana_lista_los_articulos_y_cuenta_sus_presentaciones(): void
    {
        $oil = $this->makeFamily('Aceite de girasol', 'Volumen', null, [['500 ml', null, 8200], ['1 L', null, 12500, ['is_online_visible' => false]]]);

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->get(route('storefront.catalog.index', ['type' => 'families']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Storefront/Manage')
                ->where('filters.type', 'families')
                ->has('products.data', 1)
                ->where('products.data.0.id', $oil->id)
                ->where('products.data.0.price', 8200)
                ->where('products.data.0.variants_total', 2)
                ->where('products.data.0.variants_published', 1));

        // Desde ahi se ven sus subarticulos en la pestaña de articulos.
        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->get(route('storefront.catalog.index', ['type' => 'products', 'family' => $oil->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('products.data', 2)
                ->where('products.data.0.family', 'Aceite de girasol'));
    }

    public function test_ocultar_la_tarjeta_la_saca_de_la_tienda(): void
    {
        $oil = $this->makeFamily('Aceite de girasol', 'Volumen', null, [['500 ml', null, 8200], ['1 L', null, 12500]]);
        $this->publishSite();

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('storefront.catalog.update'), ['type' => 'family', 'product_ids' => [$oil->id], 'field' => 'is_online_visible', 'value' => false])
            ->assertSessionHas('success');

        $this->assertFalse($oil->refresh()->is_online_visible);
        // Los subarticulos no se tocan: volver a publicarla trae las mismas presentaciones.
        $this->assertSame(2, $oil->variants()->where('is_online_visible', true)->count());

        $this->shopAt()->get(route('storefront.family', $oil->slug))->assertNotFound();
        $this->shopAt()->get(route('storefront.category', 'despensa'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('results.total', 0));
    }
}

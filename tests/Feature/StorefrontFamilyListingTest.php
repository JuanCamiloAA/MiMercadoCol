<?php

namespace Tests\Feature;

use App\Models\ProductFamily;
use App\Models\Promotion;
use App\Models\PromotionTarget;
use App\Models\Storefront;
use App\Services\Storefront\StorefrontRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsStorefront;
use Tests\Concerns\BuildsVariants;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\EditsStorefront;
use Tests\TestCase;

/**
 * En la tienda un articulo con subarticulos es una sola tarjeta: «Desde» con el menor precio
 * vigente, agotada solo si todas sus presentaciones lo estan, sin repetirse en estantes ni entre
 * paginas, y con su faceta por caracteristica.
 */
class StorefrontFamilyListingTest extends TestCase
{
    use BuildsStorefront, BuildsVariants, CreatesCompanyEnvironment, EditsStorefront, RefreshDatabase;

    protected Storefront $storefront;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->seedAttributes();
        $this->storefront = $this->site();
        $this->publishSite($this->storefront);
        $this->shopAt();
    }

    public function test_una_ficha_por_familia_con_desde_el_menor_precio_vigente(): void
    {
        $oil = $this->oil();
        $this->makeProduct(['name' => 'Arroz blanco 1 kg', 'price' => 5100, 'is_online_visible' => true], 10);

        // El de 2 L en oferta queda por debajo del de 500 ml: el «Desde» es el de hoy.
        $big = $oil->variants()->where('variant_label', '2 L')->firstOrFail();
        $promotion = Promotion::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Aceite grande', 'type' => Promotion::TYPE_PERCENT_OFF,
            'config' => ['percent' => 70], 'applies_online' => true, 'applies_pos' => true, 'is_active' => true,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        ]);
        PromotionTarget::query()->create(['promotion_id' => $promotion->id, 'target_type' => PromotionTarget::TYPE_PRODUCT, 'target_id' => $big->id, 'quantity' => 1]);

        $this->get(route('storefront.category', 'despensa'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Storefront/Catalog')
            ->where('results.total', 2)
            ->has('results.items', 2)
            ->where('results.items', fn ($items) => collect($items)->where('kind', 'family')->count() === 1
                && collect($items)->firstWhere('kind', 'family')['price'] === 7170
                && collect($items)->firstWhere('kind', 'family')['price_from'] === true
                && collect($items)->firstWhere('kind', 'family')['deal']['upto'] === true
                && collect($items)->firstWhere('kind', 'family')['href'] === '/tienda/articulo/'.$oil->slug
                && collect($items)->firstWhere('kind', 'family')['options']['summary'] === '3 presentaciones'));

        // La busqueda tambien la muestra una vez.
        $this->get(route('storefront.search', ['q' => 'aceite']))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('results.total', 1)
            ->where('results.items.0.kind', 'family')
            ->where('results.items.0.id', $oil->id));
    }

    public function test_las_sugerencias_de_busqueda_no_repiten_tallas(): void
    {
        $oil = $this->oil();
        $this->makeProduct(['name' => 'Aceite de oliva 500 ml', 'is_online_visible' => true], 10);

        $this->getJson(route('storefront.search.suggestions', ['q' => 'aceite']))
            ->assertOk()
            ->assertJsonCount(2, 'products')
            ->assertJsonFragment(['name' => 'Aceite de girasol', 'href' => '/tienda/articulo/'.$oil->slug]);
    }

    public function test_sin_presentaciones_disponibles_la_familia_no_tiene_tarjeta(): void
    {
        $this->makeFamily('Aceite de oliva', 'Volumen', null, [['250 ml', null, 18900], ['500 ml', null, 32900, ['initial_stocks' => []]]]);
        $this->makeFamily('Vinagre', 'Volumen', null, [['250 ml', null, 2900, ['initial_stocks' => []]], ['500 ml', null, 4900, ['initial_stocks' => []]]]);

        // Antes salia «Agotada»; ahora la tienda no ofrece lo que no puede entregar.
        $this->get(route('storefront.category', ['categorySlug' => 'despensa', 'sort' => 'name']))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('results.total', 1)
            ->where('results.items.0.name', 'Aceite de oliva')
            // Con una presentacion agotada, el «Desde» sale de las que hay.
            ->where('results.items.0.price', 18900));
    }

    public function test_un_estante_no_repite_la_familia(): void
    {
        $oil = $this->oil();
        $rice = $this->makeProduct(['name' => 'Arroz blanco 1 kg', 'is_online_visible' => true], 10);

        $this->addBlock('product_grid', ['source' => 'new_arrivals', 'limit' => 10]);
        $products = app(StorefrontRenderer::class)->draft($this->store)['blocks'][0]['data']['products'];

        $this->assertCount(2, $products);
        $this->assertSame(['f:'.$oil->id, 'p:'.$rice->id], collect($products)->pluck('key')->sort()->values()->all());
    }

    public function test_pagina_y_ordena_sin_partir_la_familia(): void
    {
        foreach (range(1, 13) as $number) {
            $this->makeProduct(['name' => sprintf('Galleta %02d', $number), 'price' => 10000 + $number * 100, 'is_online_visible' => true], 10);
        }

        $oil = $this->oil();
        $this->publishSetting('category_page', ['page_size' => 12]);

        $first = [];
        $second = [];

        $this->get(route('storefront.category', ['categorySlug' => 'despensa', 'sort' => 'price_asc']))
            ->assertInertia(function (AssertableInertia $page) use (&$first, $oil) {
                $page->where('results.total', 14)->where('results.last_page', 2)->has('results.items', 12)
                    // La familia se ordena por su menor precio: 8.200, antes que todas las galletas.
                    ->where('results.items.0.key', 'f:'.$oil->id);
                $first = $page->toArray()['props']['results']['items'];
            });

        $this->get(route('storefront.category', ['categorySlug' => 'despensa', 'sort' => 'price_asc', 'page' => 2]))
            ->assertInertia(function (AssertableInertia $page) use (&$second) {
                $page->has('results.items', 2);
                $second = $page->toArray()['props']['results']['items'];
            });

        $keys = array_column([...$first, ...$second], 'key');
        $this->assertCount(14, array_unique($keys));
        $this->assertSame(1, count(array_filter($keys, fn ($key) => $key === 'f:'.$oil->id)));
    }

    public function test_la_faceta_por_caracteristica_filtra_los_articulos(): void
    {
        $oil = $this->oil();
        $this->makeProduct(['name' => 'Arroz blanco 1 kg', 'is_online_visible' => true], 10);
        $this->publishSetting('category_page', ['filters' => ['subcategories', 'brand', 'attributes', 'price', 'deals', 'in_stock']]);

        $twoLiters = $this->value('Volumen', '2 L');

        $this->get(route('storefront.category', 'despensa'))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('results.facets.attributes', 1)
            ->where('results.facets.attributes.0.name', 'Volumen')
            ->where('results.facets.attributes.0.values', fn ($values) => collect($values)->pluck('value')->all() === ['500 ml', '1 L', '2 L']));

        $this->get(route('storefront.category', ['categorySlug' => 'despensa', 'attr' => [$twoLiters->id]]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.attr', [$twoLiters->id])
                ->where('results.total', 1)
                ->where('results.items.0.key', 'f:'.$oil->id));
    }

    protected function oil(): ProductFamily
    {
        return $this->makeFamily('Aceite de girasol', 'Volumen', null, [['500 ml', null, 8200], ['1 L', null, 12500], ['2 L', null, 23900]]);
    }
}

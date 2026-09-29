<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Promotion;
use App\Models\PromotionTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsStorefront;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Categoria, ofertas y busqueda: filtros, orden por precio vigente, subcategorias, paginacion y
 * sugerencias que perdonan tildes y ortografia.
 */
class StorefrontCategoryPageTest extends TestCase
{
    use BuildsStorefront, CreatesCompanyEnvironment, RefreshDatabase;

    protected Category $aseo;

    protected Category $detergentes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->publishSite();
        $this->shopAt();

        $this->aseo = Category::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Aseo', 'slug' => 'aseo', 'is_active' => true]);
        $this->detergentes = Category::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Detergentes', 'slug' => 'detergentes', 'parent_id' => $this->aseo->id, 'is_active' => true]);
    }

    public function test_filtra_por_subcategoria_marca_y_ofertas_y_ordena_por_precio_vigente(): void
    {
        $fab = $this->makeProduct(['name' => 'Detergente Fab 2 kg', 'brand' => 'Fab', 'category_id' => $this->detergentes->id, 'price' => 22900, 'online_price' => 22900, 'is_online_visible' => true], 10);
        $ariel = $this->makeProduct(['name' => 'Detergente Ariel 2 kg', 'brand' => 'Ariel', 'category_id' => $this->detergentes->id, 'price' => 20000, 'online_price' => 20000, 'is_online_visible' => true], 10);
        $this->makeProduct(['name' => 'Escoba', 'brand' => 'Hogar', 'category_id' => $this->aseo->id, 'price' => 9000, 'is_online_visible' => true], 10);

        // Fab en oferta queda mas barato que Ariel: el orden es por el precio de hoy.
        $promotion = Promotion::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Aseo en oferta', 'type' => Promotion::TYPE_PERCENT_OFF,
            'config' => ['percent' => 30], 'applies_online' => true, 'applies_pos' => true, 'is_active' => true,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        ]);
        PromotionTarget::query()->create(['promotion_id' => $promotion->id, 'target_type' => PromotionTarget::TYPE_PRODUCT, 'target_id' => $fab->id, 'quantity' => 1]);

        $this->get(route('storefront.category', 'aseo'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Storefront/Catalog')
            ->where('results.total', 3)
            ->has('results.facets.subcategories', 1)
            ->where('results.facets.subcategories.0.name', 'Detergentes'));

        $this->get(route('storefront.category', ['categorySlug' => 'aseo', 'sub' => $this->detergentes->id, 'sort' => 'price_asc']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('results.total', 2)
                ->where('results.items.0.id', $fab->id)
                ->where('results.items.0.price', 16030)
                ->where('results.items.1.id', $ariel->id));

        $this->get(route('storefront.category', ['categorySlug' => 'aseo', 'brands' => ['Ariel']]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('results.total', 1)->where('results.items.0.id', $ariel->id));

        $this->get(route('storefront.category', ['categorySlug' => 'aseo', 'deals' => 1]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('results.total', 1)->where('results.items.0.id', $fab->id));

        $this->get(route('storefront.category', ['categorySlug' => 'aseo', 'min' => 15000, 'max' => 18000]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('results.total', 1)->where('results.items.0.id', $fab->id));

        // Las ofertas son lo que hoy tiene precio rebajado.
        $this->get(route('storefront.deals'))->assertInertia(fn (AssertableInertia $page) => $page->where('mode', 'deals')->where('results.total', 1));
    }

    public function test_pagina_con_el_tamano_de_la_sede(): void
    {
        foreach (range(1, 15) as $number) {
            $this->makeProduct(['name' => sprintf('Limpiador %02d', $number), 'category_id' => $this->aseo->id, 'is_online_visible' => true], 10);
        }

        $this->publishSetting('category_page', ['page_size' => 12]);

        $this->get(route('storefront.category', ['categorySlug' => 'aseo', 'sort' => 'name']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('results.total', 15)->where('results.last_page', 2)->has('results.items', 12));

        $this->get(route('storefront.category', ['categorySlug' => 'aseo', 'sort' => 'name', 'page' => 2]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('results.page', 2)->has('results.items', 3));
    }

    public function test_la_categoria_es_la_de_la_empresa_de_la_sede(): void
    {
        ['company' => $other] = $this->makeOtherCompany();
        Category::query()->withoutGlobalScopes()->create(['company_id' => $other->id, 'name' => 'Granos', 'slug' => 'granos', 'is_active' => true]);

        $this->get(route('storefront.category', 'granos'))->assertNotFound();

        // Los enlaces viejos siguen sirviendo.
        $this->get(route('storefront.home', ['category' => $this->aseo->id]))->assertRedirect(route('storefront.category', 'aseo'));
        $this->get(route('storefront.home', ['search' => 'arroz']))->assertRedirect(route('storefront.search', ['q' => 'arroz']));
    }

    public function test_la_busqueda_y_las_sugerencias_perdonan_tildes_y_ortografia(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz Diana 1 kg', 'brand' => 'Diana', 'is_online_visible' => true], 10);
        $this->makeProduct(['name' => 'Jabón en barra', 'is_online_visible' => true], 10);

        $this->getJson(route('storefront.search.suggestions', ['q' => 'arros']))
            ->assertOk()
            ->assertJsonPath('products.0.name', 'Arroz Diana 1 kg')
            ->assertJsonCount(1, 'products');

        $this->getJson(route('storefront.search.suggestions', ['q' => 'javon']))->assertJsonPath('products.0.name', 'Jabón en barra');
        $this->getJson(route('storefront.search.suggestions', ['q' => 'detergentes']))->assertJsonPath('categories.0.name', 'Detergentes');

        // Con el campo vacio: las busquedas populares de la sede.
        $this->getJson(route('storefront.search.suggestions', ['q' => '']))->assertJsonPath('popular.0', 'Arroz');

        $this->get(route('storefront.search', ['q' => 'ARROS']))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('results.total', 1)
            ->where('results.items.0.id', $arroz->id));
    }
}

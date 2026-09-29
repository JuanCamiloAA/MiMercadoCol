<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\SalePayment;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsStorefront;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * El panel del menú de categorías: lo más vendido de la categoría señalada (con sus
 * subcategorías) y un buscador que filtra dentro de ella.
 */
class StorefrontMegaMenuTest extends TestCase
{
    use BuildsStorefront, CreatesCompanyEnvironment, RefreshDatabase;

    protected Category $panaderia;

    protected Category $tortas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->publishSite();
        $this->shopAt();

        $this->panaderia = Category::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Panadería', 'slug' => 'panaderia', 'is_active' => true]);
        $this->tortas = Category::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Tortas', 'slug' => 'tortas', 'parent_id' => $this->panaderia->id, 'is_active' => true]);
    }

    public function test_lo_mas_vendido_de_la_categoria_con_sus_subcategorias(): void
    {
        $tajado = $this->makeProduct(['name' => 'Pan tajado', 'category_id' => $this->panaderia->id, 'is_online_visible' => true], 50);
        $torta = $this->makeProduct(['name' => 'Torta de zanahoria', 'category_id' => $this->tortas->id, 'is_online_visible' => true], 50);
        $this->makeProduct(['name' => 'Almojábana', 'category_id' => $this->panaderia->id, 'is_online_visible' => true], 50);
        $otra = $this->makeProduct(['name' => 'Arroz', 'category_id' => $this->category->id, 'is_online_visible' => true], 50);

        // La torta se vende más que el pan: va primero, aunque sea de la subcategoría.
        $this->sell([$torta->id => 5, $tajado->id => 2, $otra->id => 9]);

        $this->getJson(route('storefront.menu.category', $this->panaderia->id))
            ->assertOk()
            ->assertJsonPath('by_sales', true)
            ->assertJsonPath('total', 3)
            ->assertJsonPath('products.0.id', $torta->id)
            ->assertJsonPath('products.1.id', $tajado->id)
            ->assertJsonPath('products.2.name', 'Almojábana')
            ->assertJsonStructure(['products' => [['key', 'price', 'compare_at', 'image', 'href', 'stock']]]);
    }

    public function test_el_buscador_filtra_dentro_de_la_categoria(): void
    {
        $this->makeProduct(['name' => 'Pan tajado', 'category_id' => $this->panaderia->id, 'is_online_visible' => true], 50);
        $this->makeProduct(['name' => 'Pan integral', 'category_id' => $this->panaderia->id, 'is_online_visible' => true], 50);
        $this->makeProduct(['name' => 'Torta de zanahoria', 'category_id' => $this->tortas->id, 'is_online_visible' => true], 50);
        // Otro pan, pero de otra categoría: no sale en el panel de Panadería.
        $this->makeProduct(['name' => 'Pan rallado', 'category_id' => $this->category->id, 'is_online_visible' => true], 50);

        $this->getJson(route('storefront.menu.category', ['category' => $this->panaderia->id, 'q' => 'pan']))
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('search_href', '/tienda/buscar?q=pan')
            ->assertJsonMissing(['name' => 'Pan rallado'])
            ->assertJsonMissing(['name' => 'Torta de zanahoria']);

        // Sin tilde y con otra ortografía, igual que el buscador de la tienda.
        $this->getJson(route('storefront.menu.category', ['category' => $this->panaderia->id, 'q' => 'sanaoria']))
            ->assertJsonPath('total', 1)
            ->assertJsonPath('products.0.name', 'Torta de zanahoria');
    }

    public function test_sin_ventas_salen_los_destacados(): void
    {
        $this->makeProduct(['name' => 'Almojábana', 'category_id' => $this->panaderia->id, 'is_online_visible' => true], 50);
        $destacado = $this->makeProduct(['name' => 'Pan de bono', 'category_id' => $this->panaderia->id, 'is_online_visible' => true, 'is_featured' => true], 50);

        $this->getJson(route('storefront.menu.category', $this->panaderia->id))
            ->assertJsonPath('by_sales', false)
            ->assertJsonPath('products.0.id', $destacado->id);
    }

    public function test_una_categoria_de_otra_empresa_no_existe_aqui(): void
    {
        ['company' => $other] = $this->makeOtherCompany();
        $ajena = Category::query()->withoutGlobalScopes()->create(['company_id' => $other->id, 'name' => 'Ajena', 'slug' => 'ajena', 'is_active' => true]);

        $this->getJson(route('storefront.menu.category', $ajena->id))->assertNotFound();
        $this->getJson('/tienda/menu/categoria/abc')->assertNotFound();
    }

    /** @param  array<int, int>  $quantities  id del artículo => unidades */
    protected function sell(array $quantities): void
    {
        $cashier = $this->makeUser('admin');
        $session = app(RegisterSessionService::class)->open($this->register, $cashier, 100000);

        foreach ($quantities as $id => $quantity) {
            $sale = app(SaleService::class)->create([
                'items' => [['product_id' => $id, 'quantity' => $quantity]],
                'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 10_000_000]],
            ], $cashier, $this->store, $session);

            $this->assertNotNull($sale->id);
        }
    }
}

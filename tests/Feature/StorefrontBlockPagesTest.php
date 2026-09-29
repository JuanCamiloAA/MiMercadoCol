<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Storefront;
use App\Models\StorefrontBlock;
use App\Models\User;
use App\Services\Storefront\StorefrontManager;
use App\Services\Storefront\StorefrontRenderer;
use App\Storefront\BlockCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsStorefront;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\EditsStorefront;
use Tests\TestCase;

/**
 * Los bloques por pagina: cada tipo dice donde vale, un retrato viejo es todo portada y un
 * bloque de categoria puede limitarse a unas categorias.
 */
class StorefrontBlockPagesTest extends TestCase
{
    use BuildsStorefront, CreatesCompanyEnvironment, EditsStorefront, RefreshDatabase;

    protected User $admin;

    protected Storefront $storefront;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->admin = $this->makeUser('admin');
        $this->storefront = $this->site();
    }

    public function test_cada_tipo_solo_se_agrega_donde_vale(): void
    {
        $this->assertSame(StorefrontBlock::PAGES, BlockCatalogue::for('announcement')->pages());
        $this->assertSame(['home'], BlockCatalogue::for('store_info')->pages());
        $this->assertSame(['home', 'product'], BlockCatalogue::for('faq')->pages());

        $this->editor()->post(route('storefront.editor.blocks.store'), ['type' => 'store_info', 'page' => 'category'])->assertSessionHasErrors('type');
        $this->editor()->post(route('storefront.editor.blocks.store'), ['type' => 'product_grid', 'page' => 'cart'])->assertSessionHasNoErrors();

        $this->assertSame(0, $this->storefront->blocks()->where('type', 'store_info')->count());
        $this->assertSame('cart', $this->storefront->blocks()->where('type', 'product_grid')->value('page'));
    }

    public function test_cada_pagina_pinta_sus_bloques(): void
    {
        $portada = $this->addBlock('announcement', ['text' => 'En la portada']);
        $carrito = $this->addBlock('announcement', ['text' => 'En el carrito']);
        $carrito->update(['page' => 'cart']);

        $renderer = app(StorefrontRenderer::class);

        $this->assertSame([$portada->uuid], array_column($renderer->draft($this->store)['blocks'], 'uuid'));
        $this->assertSame([$carrito->uuid], array_column($renderer->draft($this->store, page: 'cart')['blocks'], 'uuid'));
    }

    public function test_un_retrato_viejo_sin_pagina_es_todo_portada(): void
    {
        $snapshot = app(StorefrontManager::class)->snapshot($this->storefront);
        $snapshot['blocks'] = [[
            'uuid' => (string) Str::uuid(),
            'type' => 'announcement',
            'sort_order' => 0,
            'is_visible' => true,
            'devices' => 'all',
            'payload' => ['text' => 'De antes de las paginas'],
        ]];

        app(StorefrontManager::class)->restore($this->storefront, $snapshot);

        $this->assertSame('home', $this->storefront->blocks()->value('page'));
        $this->assertSame('De antes de las paginas', app(StorefrontRenderer::class)->render($snapshot, $this->store)['blocks'][0]['data']['text']);
    }

    public function test_un_bloque_de_categoria_se_limita_a_sus_categorias(): void
    {
        $aseo = Category::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Aseo', 'slug' => 'aseo', 'is_active' => true]);
        $detergentes = Category::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Detergentes', 'slug' => 'detergentes', 'parent_id' => $aseo->id, 'is_active' => true]);
        $bebidas = Category::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Bebidas', 'slug' => 'bebidas', 'is_active' => true]);

        $banner = $this->addBlock('announcement', ['text' => 'Todo para el aseo', 'only_categories' => [$aseo->id]]);
        $banner->update(['page' => 'category']);
        $this->publishSite($this->storefront);
        $this->shopAt();

        $this->get(route('storefront.category', 'aseo'))->assertInertia(fn (AssertableInertia $page) => $page->where('blocks.0.data.text', 'Todo para el aseo'));
        // Una subcategoria cuenta como su madre.
        $this->get(route('storefront.category', 'detergentes'))->assertInertia(fn (AssertableInertia $page) => $page->has('blocks', 1));
        $this->get(route('storefront.category', 'bebidas'))->assertInertia(fn (AssertableInertia $page) => $page->has('blocks', 0));

        $this->assertNotNull($detergentes->id);
        $this->assertNotNull($bebidas->id);
    }
}

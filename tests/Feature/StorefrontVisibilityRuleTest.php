<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Promotion;
use App\Models\PromotionTarget;
use App\Models\StockMovement;
use App\Models\Storefront;
use App\Services\CartService;
use App\Services\StockService;
use App\Services\Storefront\StorefrontRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsStorefront;
use Tests\Concerns\BuildsVariants;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\EditsStorefront;
use Tests\TestCase;

/**
 * Publicado no es visible: la tienda solo ofrece lo que tiene disponible (existencia menos
 * apartado) en la bodega que la respalda. Lo demas sigue publicado, oculto, y vuelve solo.
 */
class StorefrontVisibilityRuleTest extends TestCase
{
    use BuildsStorefront, BuildsVariants, CreatesCompanyEnvironment, EditsStorefront, RefreshDatabase;

    protected Storefront $storefront;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->storefront = $this->site();
        $this->publishSite($this->storefront);
        $this->shopAt();
    }

    public function test_publicado_sin_disponible_no_aparece_en_ningun_lugar_de_la_tienda(): void
    {
        $empty = $this->makeProduct(['name' => 'Arroz agotado', 'brand' => 'Diana', 'is_online_visible' => true, 'is_featured' => true], 0);
        $full = $this->makeProduct(['name' => 'Arroz con existencia', 'brand' => 'Diana', 'is_online_visible' => true, 'is_featured' => true], 10);
        $this->promote([$empty->id, $full->id]);

        $only = fn ($items) => collect($items)->pluck('id')->all() === [$full->id];

        $this->get(route('storefront.category', 'despensa'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('results.total', 1)->where('results.items', $only));
        $this->get(route('storefront.search', ['q' => 'arroz']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('results.total', 1)->where('results.items', $only));
        $this->get(route('storefront.deals'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('results.total', 1));
        // Sin bloques publicados la portada cae a ofertas y destacados.
        $this->get(route('storefront.home'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('fallback.featured', $only)->where('fallback.deals', $only));

        $suggestions = $this->getJson(route('storefront.search.suggestions', ['q' => 'arroz']))->assertOk()->json('products');
        $this->assertSame(['Arroz con existencia'], collect($suggestions)->pluck('name')->all());

        $menu = $this->getJson(route('storefront.menu.category', $this->category->id))->assertOk()->json('products');
        $this->assertSame([$full->id], collect($menu)->pluck('id')->all());

        $other = $this->makeProduct(['name' => 'Frijol', 'is_online_visible' => true], 10);
        $this->get(route('storefront.product', $other->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('related', $only));

        // El estante del editor, publicado y en la vista previa.
        $this->addBlock('product_grid', ['source' => 'category', 'category_id' => $this->category->id, 'limit' => 10]);
        $this->publishSite($this->storefront);
        $renderer = app(StorefrontRenderer::class);

        $this->assertNotContains($empty->id, collect($renderer->published($this->store)['blocks'][0]['data']['products'])->pluck('id')->all());
        $this->assertNotContains($empty->id, collect($renderer->draft($this->store)['blocks'][0]['data']['products'])->pluck('id')->all());
    }

    public function test_el_apartado_cuenta_contra_la_existencia(): void
    {
        $reserved = $this->makeProduct(['name' => 'Panela apartada', 'is_online_visible' => true], 5);
        $this->stockOf($reserved)->update(['reserved_quantity' => 5]);
        $spare = $this->makeProduct(['name' => 'Panela con una de sobra', 'is_online_visible' => true], 6);
        $this->stockOf($spare)->update(['reserved_quantity' => 5]);

        $this->get(route('storefront.category', 'despensa'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('results.total', 1)
            ->where('results.items.0.id', $spare->id));
    }

    public function test_la_existencia_de_otra_bodega_de_la_sede_no_cuenta(): void
    {
        $product = $this->makeProduct(['name' => 'Azucar en el deposito', 'is_online_visible' => true], 0);
        app(StockService::class)->move($product, $this->makeWarehouse(), StockMovement::TYPE_ADJUSTMENT, 40);

        $this->get(route('storefront.category', 'despensa'))->assertInertia(fn (AssertableInertia $page) => $page->where('results.total', 0));
        $this->get(route('storefront.product', $product->id))->assertNotFound();
    }

    public function test_la_ficha_directa_de_un_agotado_no_existe_y_el_subarticulo_lleva_a_su_familia(): void
    {
        $product = $this->makeProduct(['name' => 'Cafe agotado', 'is_online_visible' => true], 0);
        $this->get(route('storefront.product', $product->id))->assertNotFound();

        $this->seedAttributes();
        $oil = $this->makeFamily('Aceite de girasol', 'Volumen', null, [['500 ml', null, 8200], ['1 L', null, 12500]]);
        $small = $oil->variants()->where('variant_label', '500 ml')->firstOrFail();
        $this->stockOf($small)->update(['quantity' => 0]);

        $this->get(route('storefront.product', $small->id))->assertRedirect('/tienda/articulo/'.$oil->slug.'?variante='.$small->id);
        // En la familia la presentacion agotada no se ofrece.
        $this->get(route('storefront.family', $oil->slug))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('variants', fn ($variants) => collect($variants)->pluck('label')->all() === ['1 L']));
    }

    public function test_una_familia_sin_presentaciones_disponibles_no_tiene_tarjeta(): void
    {
        $this->seedAttributes();
        $oil = $this->makeFamily('Aceite de girasol', 'Volumen', null, [['500 ml', null, 8200], ['1 L', null, 12500]]);
        $oil->variants()->each(fn (Product $variant) => $this->stockOf($variant)->update(['quantity' => 0]));

        $this->get(route('storefront.category', 'despensa'))->assertInertia(fn (AssertableInertia $page) => $page->where('results.total', 0));
        $this->get(route('storefront.family', $oil->slug))->assertNotFound();

        // Con una disponible vuelve la tarjeta, y el selector solo ofrece esa.
        $this->stockOf($oil->variants()->where('variant_label', '1 L')->firstOrFail())->update(['quantity' => 3]);

        $this->get(route('storefront.category', 'despensa'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('results.total', 1)
            ->where('results.items.0.key', 'f:'.$oil->id));
        $this->get(route('storefront.family', $oil->slug))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('variants', fn ($variants) => collect($variants)->pluck('label')->all() === ['1 L']));
    }

    public function test_lo_que_admite_negativo_se_sigue_mostrando_en_cero(): void
    {
        $product = $this->makeProduct(['name' => 'Pan del dia', 'is_online_visible' => true, 'allow_negative_stock' => true], 0);

        $this->get(route('storefront.category', 'despensa'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('results.total', 1)
            ->where('results.items.0.id', $product->id));
    }

    public function test_al_entrar_mercancia_vuelve_sin_tocar_la_publicacion(): void
    {
        $product = $this->makeProduct(['name' => 'Lentejas', 'is_online_visible' => true], 0);
        $this->get(route('storefront.category', 'despensa'))->assertInertia(fn (AssertableInertia $page) => $page->where('results.total', 0));

        app(StockService::class)->move($product, $this->warehouse(), StockMovement::TYPE_ADJUSTMENT, 12);

        $this->assertTrue($product->refresh()->is_online_visible);
        $this->get(route('storefront.category', 'despensa'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('results.total', 1)
            ->where('results.items.0.id', $product->id));
    }

    public function test_el_ajuste_viejo_de_mostrar_agotados_ya_no_hace_nada(): void
    {
        $this->makeProduct(['name' => 'Garbanzo agotado', 'is_online_visible' => true], 0);
        $this->publishSetting('catalog', ['show_out_of_stock' => true]);

        $this->get(route('storefront.category', 'despensa'))->assertInertia(fn (AssertableInertia $page) => $page->where('results.total', 0));
    }

    public function test_el_carrito_marca_la_linea_agotada_y_el_pago_no_sigue(): void
    {
        $customer = $this->makeCustomer(['is_online_user' => true, 'password' => Hash::make('Cliente123*'), 'address' => 'Calle 30 # 65-12']);
        $product = $this->makeProduct(['name' => 'Atun en lata', 'price' => 6000, 'is_online_visible' => true], 4);

        $this->actingAs($customer, 'customer');
        $this->post(route('storefront.cart.store'), ['product_id' => $product->id, 'quantity' => 2])->assertSessionHasNoErrors();

        // Se vendio todo en la caja mientras estaba en el carrito.
        app(StockService::class)->move($product, $this->warehouse(), StockMovement::TYPE_SALE, -4);

        $summary = app(CartService::class)->summary(Cart::query()->withoutGlobalScopes()->firstOrFail(), $this->store);
        $this->assertTrue($summary['items'][0]['unavailable']);
        $this->assertSame('out_of_stock', $summary['items'][0]['unavailable_reason']);
        $this->assertSame(0, $summary['subtotal']);
        $this->assertTrue($summary['has_unavailable']);

        $this->putJson(route('storefront.cart.quantity'), ['product_id' => $product->id, 'quantity' => 1])->assertStatus(422);
        $this->postJson(route('storefront.cart.store'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Este artículo se agotó.');

        $this->post(route('storefront.checkout.store'), ['delivery_address' => 'Calle 30 # 65-12', 'payment_method' => Order::PAYMENT_CASH_ON_DELIVERY])
            ->assertRedirect(route('storefront.cart.index'));
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_sin_stock_desde_se_llena_al_llegar_a_cero_y_se_limpia_al_reponer(): void
    {
        $product = $this->makeProduct(['name' => 'Harina', 'is_online_visible' => true], 3);
        $stock = app(StockService::class);

        $stock->move($product, $this->warehouse(), StockMovement::TYPE_SALE, -3);
        $this->assertNotNull($this->stockOf($product)->out_of_stock_since);

        $stock->move($product, $this->warehouse(), StockMovement::TYPE_ADJUSTMENT, 2);
        $this->assertNull($this->stockOf($product)->out_of_stock_since);

        // Apartar lo ultimo por un pedido tambien la deja sin disponible.
        $stock->reserve($product, $this->warehouse(), 2);
        $this->assertNotNull($this->stockOf($product)->out_of_stock_since);

        $stock->releaseReservation($product, $this->warehouse(), 1);
        $this->assertNull($this->stockOf($product)->out_of_stock_since);
    }

    protected function stockOf(Product $product): ProductStock
    {
        return ProductStock::query()->withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $this->warehouse()->id)
            ->firstOrFail();
    }

    /** @param  list<int>  $ids */
    protected function promote(array $ids): void
    {
        $promotion = Promotion::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Arroz en oferta', 'type' => Promotion::TYPE_PERCENT_OFF,
            'config' => ['percent' => 10], 'applies_online' => true, 'applies_pos' => true, 'is_active' => true,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        ]);

        foreach ($ids as $id) {
            PromotionTarget::query()->create(['promotion_id' => $promotion->id, 'target_type' => PromotionTarget::TYPE_PRODUCT, 'target_id' => $id, 'quantity' => 1]);
        }
    }
}

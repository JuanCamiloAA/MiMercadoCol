<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsStorefront;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * «Agregar» sin recargar: la cantidad que se manda es absoluta, y el resumen que vuelve es el
 * mismo total que cobra el checkout.
 */
class StorefrontCartQuantityTest extends TestCase
{
    use BuildsStorefront, CreatesCompanyEnvironment, RefreshDatabase;

    protected Product $arroz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->publishSite();
        $this->shopAt();

        $this->arroz = $this->makeProduct(['name' => 'Arroz Diana 1 kg', 'price' => 5100, 'online_price' => 5100, 'is_online_visible' => true], 20);
    }

    public function test_la_cantidad_es_absoluta_e_idempotente(): void
    {
        // Dos toques rapidos que mandan «3» dejan 3, no 6.
        foreach ([1, 2] as $attempt) {
            $this->putJson(route('storefront.cart.quantity'), ['product_id' => $this->arroz->id, 'quantity' => 3])
                ->assertOk()
                ->assertJsonPath('cart.count', 3)
                ->assertJsonPath('cart.total', 15300)
                ->assertJsonPath('cart.lines.0.key', 'p:'.$this->arroz->id)
                ->assertJsonPath('cart.lines.0.quantity', 3);
        }

        $this->assertSame(1, CartItem::query()->count());
        $this->assertEquals(3, CartItem::query()->value('quantity'));
    }

    public function test_cero_quita_la_linea(): void
    {
        $this->putJson(route('storefront.cart.quantity'), ['product_id' => $this->arroz->id, 'quantity' => 2])->assertOk();
        $this->putJson(route('storefront.cart.quantity'), ['product_id' => $this->arroz->id, 'quantity' => 0])
            ->assertOk()
            ->assertJsonPath('cart.count', 0)
            ->assertJsonPath('cart.lines', []);

        $this->assertSame(0, CartItem::query()->count());
    }

    public function test_sin_existencias_devuelve_lo_disponible_y_no_cambia_nada(): void
    {
        $poco = $this->makeProduct(['name' => 'Aceite 1 L', 'is_online_visible' => true], 2);

        $this->putJson(route('storefront.cart.quantity'), ['product_id' => $poco->id, 'quantity' => 1])->assertOk();

        $this->putJson(route('storefront.cart.quantity'), ['product_id' => $poco->id, 'quantity' => 5])
            ->assertStatus(422)
            ->assertJsonPath('available', 2)
            ->assertJsonPath('cart.lines.0.quantity', 1)
            ->assertJson(fn ($json) => $json->where('error', 'Solo quedan 2 de Aceite 1 L.')->etc());

        $this->assertEquals(1, CartItem::query()->value('quantity'));
    }

    public function test_un_articulo_de_otra_empresa_no_entra(): void
    {
        ['company' => $other] = $this->makeOtherCompany();

        $categoria = Category::query()->withoutGlobalScopes()->create(['company_id' => $other->id, 'name' => 'Granos', 'slug' => 'granos', 'is_active' => true]);
        $ajeno = Product::query()->withoutGlobalScopes()->create([
            'company_id' => $other->id, 'category_id' => $categoria->id, 'name' => 'Frijol del Vecino', 'sku' => 'VEC-1', 'unit' => 'unit',
            'cost' => 1000, 'price' => 2000, 'tax_rate' => 0, 'is_active' => true, 'is_online_visible' => true,
        ]);

        $this->putJson(route('storefront.cart.quantity'), ['product_id' => $ajeno->id, 'quantity' => 1])->assertNotFound();
        $this->assertSame(0, CartItem::query()->count());
    }

    public function test_el_resumen_es_el_total_del_checkout(): void
    {
        $leche = $this->makeProduct(['name' => 'Leche 1 L', 'price' => 4200, 'online_price' => 4300, 'is_online_visible' => true], 30);

        $this->putJson(route('storefront.cart.quantity'), ['product_id' => $this->arroz->id, 'quantity' => 2])->assertOk();
        $brief = $this->putJson(route('storefront.cart.quantity'), ['product_id' => $leche->id, 'quantity' => 3])->assertOk()->json('cart');

        $cart = Cart::query()->withoutGlobalScopes()->firstOrFail();
        $summary = app(CartService::class)->summary($cart, $this->store);

        $this->assertSame($summary['subtotal'], $brief['total']);
        $this->assertSame(2 * 5100 + 3 * 4300, $brief['total']);

        // La misma cifra viaja en la prop compartida de cada pagina de la tienda.
        $this->get(route('storefront.home'))->assertInertia(fn (AssertableInertia $page) => $page->where('cart.total', $summary['subtotal'])->where('cart.count', 5));
    }

    public function test_mirar_la_tienda_no_crea_carrito(): void
    {
        $this->get(route('storefront.home'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('cart.count', 0));
        $this->get(route('storefront.cart.index'))->assertOk();

        $this->assertSame(0, Cart::query()->withoutGlobalScopes()->count());
    }
}

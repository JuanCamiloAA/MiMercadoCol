<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Services\Storefront\StorefrontStructure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsStorefront;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * El domicilio gratis y el pedido minimo, como se los dice la tienda al cliente.
 *
 * `free_from = 0` es «nunca es gratis»: ni barra, ni texto, ni «gratis desde $ 0».
 */
class StorefrontFreeDeliveryTest extends TestCase
{
    use BuildsStorefront, CreatesCompanyEnvironment, RefreshDatabase;

    protected Product $arroz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->shopAt();

        $this->arroz = $this->makeProduct(['name' => 'Arroz Diana 1 kg', 'price' => 10000, 'online_price' => 10000, 'is_online_visible' => true], 50);
    }

    public function test_sin_domicilio_gratis_no_hay_texto_ni_barra(): void
    {
        $this->publishSetting('delivery', ['fee' => 5000, 'free_from' => 0]);
        $this->putJson(route('storefront.cart.quantity'), ['product_id' => $this->arroz->id, 'quantity' => 2])
            ->assertOk()
            ->assertJsonPath('cart.free_delivery', null);

        $this->get(route('storefront.home'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('cart.free_delivery', null)
            ->where('storefront.settings.delivery.free_from', 0)
            ->where('storefront.settings.topbar.messages', fn ($messages) => collect($messages)->every(fn ($message) => ! str_contains($message['text'], 'gratis desde')))
        );

        $this->assertStringNotContainsString('gratis desde', json_encode($this->get(route('storefront.cart.index'))->viewData('page')['props'], JSON_UNESCAPED_UNICODE));
    }

    public function test_el_faltante_para_el_domicilio_gratis_es_el_del_servidor(): void
    {
        $this->publishSetting('delivery', ['fee' => 5000, 'free_from' => 80000]);

        $this->putJson(route('storefront.cart.quantity'), ['product_id' => $this->arroz->id, 'quantity' => 3])
            ->assertOk()
            ->assertJsonPath('cart.free_delivery.from', 80000)
            ->assertJsonPath('cart.free_delivery.missing', 50000)
            ->assertJsonPath('cart.free_delivery.reached', false);

        $this->putJson(route('storefront.cart.quantity'), ['product_id' => $this->arroz->id, 'quantity' => 8])
            ->assertOk()
            ->assertJsonPath('cart.free_delivery.missing', 0)
            ->assertJsonPath('cart.free_delivery.reached', true);

        // La barra superior lo dice con la cifra publicada.
        $this->get(route('storefront.home'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('storefront.settings.topbar.messages', fn ($messages) => collect($messages)->contains(fn ($message) => $message['text'] === 'Domicilio gratis desde $ 80.000')));
    }

    public function test_una_variable_sin_valor_oculta_su_mensaje(): void
    {
        $this->assertNull(StorefrontStructure::fill('Gratis desde {gratis_desde}', ['gratis_desde' => null]));
        $this->assertSame('Domicilio $ 5.000', StorefrontStructure::fill('Domicilio {domicilio}', ['domicilio' => '$ 5.000']));
    }

    public function test_el_pedido_minimo_bloquea_el_pago_en_el_servidor(): void
    {
        $this->publishSetting('delivery', ['fee' => 5000, 'min_order_total' => 50000]);

        $customer = $this->makeCustomer(['is_online_user' => true, 'password' => Hash::make('Cliente123*'), 'address' => 'Calle 30 # 65-12']);

        $this->actingAs($customer, 'customer');
        $this->putJson(route('storefront.cart.quantity'), ['product_id' => $this->arroz->id, 'quantity' => 2])
            ->assertOk()
            ->assertJsonPath('cart.min_order_total', 50000)
            ->assertJsonPath('cart.min_order_missing', 30000);

        // La pagina del carrito lo explica…
        $this->get(route('storefront.cart.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('summary.min_order_missing', 30000));

        // …y el servidor no deja confirmar.
        $this->from(route('storefront.checkout.create'))->post(route('storefront.checkout.store'), [
            'delivery_address' => 'Calle 30 # 65-12',
            'payment_method' => 'cash_on_delivery',
        ])->assertSessionHasErrors();

        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
    }
}

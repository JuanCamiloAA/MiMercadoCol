<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Service;
use App\Services\CartService;
use App\Services\PromotionService;
use App\Services\ServicePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\SellsServices;
use Tests\TestCase;

/**
 * Servicios en la tienda en linea: visibles solo publicados, activos y con existencia en la
 * bodega de la tienda; precio por `ServicePricing`; carrito con `service_id`.
 */
class StorefrontServiceTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase, SellsServices;

    protected Service $arreglo;

    protected Product $fresa;

    protected Product $banano;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->shopAt();

        $this->fresa = $this->makeProduct(['name' => 'Fresa', 'sku' => 'FRU-001', 'price' => 3000, 'online_price' => 3200, 'unit' => 'kg', 'sold_by_weight' => true], 10);
        $this->banano = $this->makeProduct(['name' => 'Banano', 'sku' => 'FRU-002', 'price' => 1000, 'online_price' => 1000], 30);

        $this->arreglo = $this->makeService('Arreglo de frutas', 5000, [[$this->fresa, 2, false], [$this->banano, 3, false]], Service::PRICING_ITEMIZED, [
            'code' => 'SRV-ARREGLO', 'online_price' => 5500, 'is_online_visible' => true,
        ]);
    }

    public function test_se_ve_con_su_precio_en_linea_y_lo_que_incluye(): void
    {
        // 5.500 + 2 kg a 3.200 + 3 a 1.000 en linea.
        $expected = 5500 + 6400 + 3000;
        $this->assertSame($expected, app(ServicePricing::class)->priceFor($this->arreglo, PromotionService::CHANNEL_ONLINE));

        // Los servicios entran al surtido como siempre, con su distintivo.
        $this->get(route('storefront.search'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('results.items.0.id', $this->arreglo->id)
                ->where('results.items.0.kind', 'service')
                ->where('results.items.0.price', $expected)
                ->has('results.items.0.includes', 2));

        $this->get(route('storefront.service', $this->arreglo->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Storefront/Service')->where('service.includes.0.name', 'Fresa'));
    }

    public function test_solo_se_ve_publicado_activo_y_sin_precio_variable(): void
    {
        $oculto = $this->makeService('Oculto', 3000, attributes: ['is_online_visible' => false]);
        $inactivo = $this->makeService('Inactivo', 3000, attributes: ['is_online_visible' => true, 'is_active' => false]);
        $recarga = $this->makeService('Recarga', 0, attributes: ['open_price' => true]);
        $recarga->forceFill(['is_online_visible' => true])->save();

        foreach ([$oculto, $inactivo, $recarga] as $service) {
            $this->get(route('storefront.service', $service->id))->assertNotFound();
        }

        $this->banano->update(['is_active' => false]);
        $this->get(route('storefront.service', $this->arreglo->id))->assertNotFound();
    }

    public function test_sin_existencia_en_la_bodega_de_la_tienda_no_se_ofrece(): void
    {
        // No alcanza la fresa para un arreglo: el servicio sigue publicado, pero no se muestra.
        ProductStock::query()->withoutGlobalScopes()->where('product_id', $this->fresa->id)->update(['quantity' => 1]);

        $this->get(route('storefront.service', $this->arreglo->id))->assertNotFound();
        $this->post(route('storefront.cart.store'), ['service_id' => $this->arreglo->id, 'quantity' => 1])->assertNotFound();
        $this->assertSame(0, CartItem::query()->whereNotNull('service_id')->count());
        $this->assertTrue($this->arreglo->refresh()->is_online_visible);
    }

    public function test_el_carrito_guarda_el_servicio_y_cobra_lo_que_mostro(): void
    {
        $this->post(route('storefront.cart.store'), ['service_id' => $this->arreglo->id, 'quantity' => 2])->assertSessionHasNoErrors();

        $item = CartItem::query()->firstOrFail();
        $this->assertNull($item->product_id);
        $this->assertSame($this->arreglo->id, $item->service_id);

        $summary = app(CartService::class)->summary(Cart::query()->withoutGlobalScopes()->firstOrFail(), $this->store);
        $this->assertSame(2 * (5500 + 6400 + 3000), $summary['subtotal']);
        $this->assertSame('service', $summary['items'][0]['kind']);

        // Pedir el mismo servicio otra vez suma, no duplica.
        $this->post(route('storefront.cart.store'), ['service_id' => $this->arreglo->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $this->assertSame(1, CartItem::query()->count());
        $this->assertEqualsWithDelta(3, CartItem::query()->value('quantity'), 0.001);
    }

    public function test_un_renglon_no_puede_ser_articulo_y_servicio(): void
    {
        $this->post(route('storefront.cart.store'), ['service_id' => $this->arreglo->id, 'product_id' => $this->banano->id, 'quantity' => 1])
            ->assertSessionHasErrors('product_id');
    }

    public function test_el_checkout_con_servicio_y_cupon_cuadra(): void
    {
        $customer = $this->makeCustomer(['is_online_user' => true, 'password' => Hash::make('Cliente123*'), 'address' => 'Calle 30']);
        $this->actingAs($customer, 'customer');

        $this->publishStorefront();
        Coupon::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'FRUTA10',
            'type' => Coupon::TYPE_PERCENT,
            'value' => 10,
            'min_order_total' => 0,
            'scope' => 'all',
            'is_active' => true,
        ]);

        $this->post(route('storefront.cart.store'), ['service_id' => $this->arreglo->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $this->post(route('storefront.checkout.coupon.apply'), ['coupon' => 'FRUTA10'])->assertSessionHasNoErrors();

        $this->post(route('storefront.checkout.store'), [
            'delivery_address' => 'Calle 30 # 65-12',
            'payment_method' => Order::PAYMENT_CASH_ON_DELIVERY,
        ])->assertSessionHasNoErrors();

        $order = Order::query()->withoutGlobalScopes()->with('items')->firstOrFail();
        $subtotal = 5500 + 6400 + 3000;
        $this->assertSame((int) round($subtotal * 0.1), $order->discount_total);
        $this->assertSame($subtotal + $order->delivery_fee - $order->discount_total, $order->total);
        $this->assertCount(3, $order->items);
        $this->assertSame($this->arreglo->id, $order->items->first()->service_id);
    }
}

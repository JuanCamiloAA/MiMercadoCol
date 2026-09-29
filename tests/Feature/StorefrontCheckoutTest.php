<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductStock;
use App\Models\Store;
use App\Models\User;
use App\Services\CartService;
use App\Services\Storefront\StorefrontManager;
use App\Services\Storefront\StorefrontPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * La tienda en linea de punta a punta.
 *
 * Lo que se cuida es que el checkout cobre lo que la sede publico: su tarifa, su cobertura,
 * sus franjas y sus medios de pago. Un pedido que se confirma con otras reglas es plata que
 * no cuadra.
 */
class StorefrontCheckoutTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected ?User $publisher = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        // La tienda en linea atiende la sede que el cliente eligio.
        $this->shopAt();
    }

    public function test_el_catalogo_solo_muestra_lo_publicado(): void
    {
        $this->makeProduct(['name' => 'Arroz publicado', 'is_online_visible' => true], 20);
        $this->makeProduct(['name' => 'Arroz interno', 'is_online_visible' => false], 20);

        // El surtido completo vive en la busqueda sin palabra («Todo el surtido»).
        $response = $this->get(route('storefront.search'));

        $response->assertOk();
        $response->assertSee('Arroz publicado', false);
        $response->assertDontSee('Arroz interno', false);
    }

    public function test_el_checkout_a_credito_respeta_el_cupo_disponible(): void
    {
        $customer = $this->onlineCustomer(['credit_enabled' => true, 'credit_limit' => 20000, 'credit_term_days' => 15]);
        $product = $this->makeProduct(['price' => 15000, 'online_price' => 15000, 'tax_rate' => 0, 'is_online_visible' => true], 40);

        $this->actingAs($customer, 'customer');
        $this->addToCart($product->id, 3);

        // 3 x 15.000 + 5.000 de domicilio = 50.000, muy por encima del cupo de 20.000.
        $response = $this->from(route('storefront.checkout.create'))->post(route('storefront.checkout.store'), [
            'delivery_address' => 'Calle 30 # 65-12',
            'payment_method' => Order::PAYMENT_CREDIT,
        ]);

        $response->assertSessionHasErrors('payment_method');
        $this->assertStringContainsString('cupo disponible', session('errors')->first('payment_method'));
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_el_checkout_a_credito_pasa_cuando_alcanza_el_cupo(): void
    {
        $customer = $this->onlineCustomer(['credit_enabled' => true, 'credit_limit' => 200000, 'credit_term_days' => 15]);
        $product = $this->makeProduct(['price' => 15000, 'online_price' => 15000, 'tax_rate' => 0, 'is_online_visible' => true], 40);

        $this->actingAs($customer, 'customer');
        $this->addToCart($product->id, 3);

        $this->post(route('storefront.checkout.store'), [
            'delivery_address' => 'Calle 30 # 65-12',
            'payment_method' => Order::PAYMENT_CREDIT,
        ])->assertSessionHasNoErrors();

        $order = Order::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(Order::STATUS_NEW, $order->status);
        $this->assertSame(Order::CHANNEL_APP, $order->channel);
        $this->assertSame(50000, $order->total);

        // La mercancia queda reservada hasta que salga a ruta.
        $stock = ProductStock::query()->withoutGlobalScopes()->where('product_id', $product->id)->first();
        $this->assertEqualsWithDelta(3, $stock->reserved_quantity, 0.001);
        $this->assertEqualsWithDelta(40, $stock->quantity, 0.001);

        // El carrito queda vacio tras confirmar.
        $this->assertSame(0, Cart::query()->withoutGlobalScopes()->firstOrFail()->items()->count());
    }

    public function test_el_pedido_de_la_tienda_aparece_en_el_panel(): void
    {
        $admin = $this->makeUser('admin');
        $customer = $this->onlineCustomer();
        $product = $this->makeProduct(['price' => 12000, 'online_price' => 12000, 'tax_rate' => 0, 'is_online_visible' => true], 40);

        $this->actingAs($customer, 'customer');
        $this->addToCart($product->id, 2);

        $this->post(route('storefront.checkout.store'), [
            'delivery_address' => 'Calle 30 # 65-12',
            'payment_method' => Order::PAYMENT_CASH_ON_DELIVERY,
        ])->assertSessionHasNoErrors();

        $order = Order::query()->withoutGlobalScopes()->firstOrFail();

        $this->actingAs($admin, 'web')
            ->withSession($this->withActiveStore())
            ->get(route('orders.index'))
            ->assertOk()
            ->assertSee($order->number, false);
    }

    public function test_el_pedido_minimo_frena_el_checkout(): void
    {
        $this->publishSettings(['delivery' => ['min_order_total' => 60000]]);

        $customer = $this->onlineCustomer();
        $product = $this->makeProduct(['price' => 12000, 'online_price' => 12000, 'tax_rate' => 0, 'is_online_visible' => true], 40);

        $this->actingAs($customer, 'customer');
        $this->addToCart($product->id, 2);

        $this->from(route('storefront.checkout.create'))->post(route('storefront.checkout.store'), [
            'delivery_address' => 'Calle 30 # 65-12',
            'payment_method' => Order::PAYMENT_CASH_ON_DELIVERY,
        ])->assertSessionHasErrors('delivery_address');

        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_un_medio_de_pago_apagado_no_se_acepta(): void
    {
        $this->publishSettings(['payments' => [
            'cash_on_delivery' => true,
            'card_on_delivery' => false,
            'transfer' => false,
            'customer_credit' => false,
        ]]);

        $customer = $this->onlineCustomer();
        $product = $this->makeProduct(['price' => 12000, 'online_price' => 12000, 'tax_rate' => 0, 'is_online_visible' => true], 40);

        $this->actingAs($customer, 'customer');
        $this->addToCart($product->id, 2);

        $this->from(route('storefront.checkout.create'))->post(route('storefront.checkout.store'), [
            'delivery_address' => 'Calle 30 # 65-12',
            'payment_method' => Order::PAYMENT_CARD,
        ])->assertSessionHasErrors('payment_method');

        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_la_zona_decide_la_tarifa_del_domicilio(): void
    {
        $this->publishSettings([], [
            ['name' => 'Centro', 'fee' => 3000, 'eta_minutes' => 20, 'is_active' => true, 'geometry' => null, 'sort_order' => 0],
            ['name' => 'Norte lejano', 'fee' => 9000, 'eta_minutes' => 55, 'is_active' => true, 'geometry' => null, 'sort_order' => 1],
        ]);

        $customer = $this->onlineCustomer();
        $product = $this->makeProduct(['price' => 12000, 'online_price' => 12000, 'tax_rate' => 0, 'is_online_visible' => true], 40);

        $this->actingAs($customer, 'customer');
        $this->addToCart($product->id, 2);

        $this->post(route('storefront.checkout.store'), [
            'delivery_address' => 'Calle 30 # 65-12',
            'zone' => 1,
            'payment_method' => Order::PAYMENT_CASH_ON_DELIVERY,
        ])->assertSessionHasNoErrors();

        $order = Order::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(9000, $order->delivery_fee);
        $this->assertSame(24000 + 9000, $order->total);
    }

    public function test_una_zona_que_no_existe_se_rechaza(): void
    {
        $this->publishSettings([], [
            ['name' => 'Centro', 'fee' => 3000, 'eta_minutes' => 20, 'is_active' => true, 'geometry' => null, 'sort_order' => 0],
        ]);

        $customer = $this->onlineCustomer();
        $product = $this->makeProduct(['price' => 12000, 'online_price' => 12000, 'tax_rate' => 0, 'is_online_visible' => true], 40);

        $this->actingAs($customer, 'customer');
        $this->addToCart($product->id, 2);

        $this->from(route('storefront.checkout.create'))->post(route('storefront.checkout.store'), [
            'delivery_address' => 'Calle 30 # 65-12',
            'zone' => 7,
            'payment_method' => Order::PAYMENT_CASH_ON_DELIVERY,
        ])->assertSessionHasErrors('zone');

        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_una_franja_llena_no_se_puede_elegir(): void
    {
        $tomorrow = now()->addDay();

        $this->publishSettings(['slots' => ['enabled' => true]], [], [[
            'weekday' => (int) $tomorrow->dayOfWeek,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
            'capacity' => 1,
            'is_active' => true,
        ]]);

        $customer = $this->onlineCustomer();
        $product = $this->makeProduct(['price' => 12000, 'online_price' => 12000, 'tax_rate' => 0, 'is_online_visible' => true], 40);

        $key = $tomorrow->copy()->setTime(8, 0)->format('Y-m-d H:i').'|10:00';

        $this->actingAs($customer, 'customer');
        $this->addToCart($product->id, 2);

        $this->post(route('storefront.checkout.store'), [
            'delivery_address' => 'Calle 30 # 65-12',
            'slot' => $key,
            'payment_method' => Order::PAYMENT_CASH_ON_DELIVERY,
        ])->assertSessionHasNoErrors();

        // El cupo era uno: el segundo pedido de la misma franja ya no cabe.
        $this->addToCart($product->id, 1);

        $this->from(route('storefront.checkout.create'))->post(route('storefront.checkout.store'), [
            'delivery_address' => 'Calle 30 # 65-12',
            'slot' => $key,
            'payment_method' => Order::PAYMENT_CASH_ON_DELIVERY,
        ])->assertSessionHasErrors('slot');

        $this->assertSame(1, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_un_cupon_rebaja_el_pedido_y_queda_registrado(): void
    {
        $this->publishSettings();

        $customer = $this->onlineCustomer();
        $product = $this->makeProduct(['price' => 20000, 'online_price' => 20000, 'tax_rate' => 0, 'is_online_visible' => true], 40);

        Coupon::create([
            'company_id' => $this->company->id,
            'code' => 'BIENVENIDO',
            'type' => Coupon::TYPE_PERCENT,
            'value' => 10,
            'min_order_total' => 0,
            'scope' => 'all',
            'is_active' => true,
        ]);

        $this->actingAs($customer, 'customer');
        $this->addToCart($product->id, 2);

        $this->post(route('storefront.checkout.coupon.apply'), ['coupon' => 'bienvenido'])->assertSessionHasNoErrors();

        $this->post(route('storefront.checkout.store'), [
            'delivery_address' => 'Calle 30 # 65-12',
            'payment_method' => Order::PAYMENT_CASH_ON_DELIVERY,
        ])->assertSessionHasNoErrors();

        $order = Order::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(4000, $order->discount_total);
        $this->assertSame(40000 + 5000 - 4000, $order->total);
        $this->assertSame(1, $order->couponRedemption()->count());
    }

    public function test_un_cupon_que_no_alcanza_el_minimo_se_explica(): void
    {
        $this->publishSettings();

        $customer = $this->onlineCustomer();
        $product = $this->makeProduct(['price' => 10000, 'online_price' => 10000, 'tax_rate' => 0, 'is_online_visible' => true], 40);

        Coupon::create([
            'company_id' => $this->company->id,
            'code' => 'GRANDE',
            'type' => Coupon::TYPE_AMOUNT,
            'value' => 5000,
            'min_order_total' => 80000,
            'scope' => 'all',
            'is_active' => true,
        ]);

        $this->actingAs($customer, 'customer');
        $this->addToCart($product->id, 2);

        $this->from(route('storefront.checkout.create'))
            ->post(route('storefront.checkout.coupon.apply'), ['coupon' => 'GRANDE'])
            ->assertSessionHasErrors('coupon');

        $this->assertStringContainsString('80.000', session('errors')->first('coupon'));
    }

    public function test_sin_sesion_el_pago_pide_entrar(): void
    {
        $this->get(route('storefront.checkout.create'))->assertRedirect(route('storefront.login'));
    }

    /**
     * Publica el sitio de la sede con los ajustes indicados encima de los de fabrica.
     *
     * @param  array<string, array<string, mixed>>  $settings
     * @param  list<array<string, mixed>>  $zones
     * @param  list<array<string, mixed>>  $slots
     */
    protected function publishSettings(array $settings = [], array $zones = [], array $slots = [], ?Store $store = null): void
    {
        $store ??= $this->store;
        $manager = app(StorefrontManager::class);
        $storefront = $manager->for($store);

        // Sin zonas no se puede publicar con cobertura por zonas: la sede que no las usa
        // trabaja por radio, igual que en la pantalla de Funciones.
        if ($zones === []) {
            $settings['coverage'] = array_merge($settings['coverage'] ?? [], ['mode' => 'radius']);
        }

        foreach ($settings as $key => $value) {
            $storefront->settings()->updateOrCreate(
                ['key' => $key],
                ['value' => array_merge($manager->defaultSettings()[$key] ?? [], $value)]
            );
        }

        $storefront->zones()->delete();
        $storefront->slots()->delete();

        foreach ($zones as $zone) {
            $storefront->zones()->create($zone);
        }

        foreach ($slots as $slot) {
            $storefront->slots()->create($slot);
        }

        app(StorefrontPublisher::class)->publish($storefront->refresh(), $this->publisher(), 'Prueba');
    }

    protected function publisher(): User
    {
        return $this->publisher ??= $this->makeUser('admin');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function onlineCustomer(array $attributes = []): Customer
    {
        return $this->makeCustomer(array_merge([
            'is_online_user' => true,
            'password' => Hash::make('Cliente123*'),
            'address' => 'Calle 30 # 65-12',
        ], $attributes));
    }

    protected function addToCart(int $productId, float $quantity): void
    {
        $this->post(route('storefront.cart.store'), [
            'product_id' => $productId,
            'quantity' => $quantity,
        ])->assertSessionHasNoErrors();

        $this->assertGreaterThan(0, app(CartService::class)->summary(
            Cart::query()->withoutGlobalScopes()->firstOrFail(),
            $this->store
        )['count']);
    }
}

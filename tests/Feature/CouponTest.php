<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use App\Models\Storefront;
use App\Models\User;
use App\Services\CouponService;
use App\Services\Storefront\StorefrontManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Cupones de la tienda.
 *
 * Un cupon mal medido regala plata: el servicio es el unico que decide si sirve y cuanto
 * rebaja, y cada condicion tiene que negar con un motivo que el cliente entienda.
 */
class CouponTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected Storefront $storefront;

    protected CouponService $coupons;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->admin = $this->makeUser('admin');
        $this->storefront = app(StorefrontManager::class)->for($this->store);
        $this->coupons = app(CouponService::class);
    }

    public function test_un_porcentaje_rebaja_sobre_el_subtotal(): void
    {
        $coupon = $this->makeCoupon(['type' => Coupon::TYPE_PERCENT, 'value' => 15]);

        $applied = $this->apply($coupon->code, 60000);

        $this->assertSame(9000, $applied['discount']);
        $this->assertFalse($applied['free_delivery']);
    }

    public function test_el_tope_recorta_el_descuento(): void
    {
        $coupon = $this->makeCoupon(['type' => Coupon::TYPE_PERCENT, 'value' => 50, 'max_discount' => 10000]);

        $this->assertSame(10000, $this->apply($coupon->code, 80000)['discount']);
    }

    public function test_el_minimo_del_pedido_se_respeta(): void
    {
        $coupon = $this->makeCoupon(['type' => Coupon::TYPE_AMOUNT, 'value' => 5000, 'min_order_total' => 50000]);

        $this->assertSame(5000, $this->apply($coupon->code, 50000)['discount']);

        $this->expectExceptionMessageMatches('/50\.000/');
        $this->apply($coupon->code, 49999);
    }

    public function test_un_cupon_que_no_ha_empezado_o_ya_vencio_no_sirve(): void
    {
        $futuro = $this->makeCoupon(['code' => 'FUTURO', 'starts_at' => now()->addWeek()]);
        $vencido = $this->makeCoupon(['code' => 'VENCIDO', 'ends_at' => now()->subDay()]);

        $this->assertSame('Programado', $futuro->status());
        $this->assertSame('Vencido', $vencido->status());

        $this->assertMessage(fn () => $this->apply('FUTURO', 60000), 'empieza el');
        $this->assertMessage(fn () => $this->apply('VENCIDO', 60000), 'venció');
    }

    public function test_un_cupon_apagado_no_sirve(): void
    {
        $this->makeCoupon(['code' => 'APAGADO', 'is_active' => false]);

        $this->assertMessage(fn () => $this->apply('APAGADO', 60000), 'apagado');
    }

    public function test_el_tope_de_usos_se_agota(): void
    {
        $coupon = $this->makeCoupon(['usage_limit' => 2]);
        $customer = $this->makeCustomer();

        foreach (range(1, 2) as $number) {
            $this->coupons->redeem($coupon, $this->makeOrder($customer), 1000, $customer);
        }

        $this->assertSame('Agotado', $coupon->refresh()->status());
        $this->assertMessage(fn () => $this->apply($coupon->code, 60000), 'agotó');
    }

    public function test_el_tope_por_cliente_se_cuenta_aparte(): void
    {
        $coupon = $this->makeCoupon(['usage_limit_per_customer' => 1]);

        $primero = $this->makeCustomer();
        $segundo = $this->makeCustomer();

        $this->coupons->redeem($coupon, $this->makeOrder($primero), 1000, $primero);

        // El primero ya lo uso; el segundo todavia puede.
        $this->assertMessage(fn () => $this->apply($coupon->code, 60000, $primero), 'Ya usaste');
        $this->assertSame(6000, $this->apply($coupon->code, 60000, $segundo)['discount']);
    }

    public function test_el_cupon_de_primer_pedido_solo_sirve_una_vez(): void
    {
        $coupon = $this->makeCoupon(['first_order_only' => true]);
        $customer = $this->makeCustomer();

        $this->assertSame(6000, $this->apply($coupon->code, 60000, $customer)['discount']);

        $this->makeOrder($customer);

        $this->assertMessage(fn () => $this->apply($coupon->code, 60000, $customer), 'primer pedido');
    }

    public function test_un_cupon_de_otra_sede_no_sirve_aqui(): void
    {
        $north = Store::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Norte',
            'code' => 'NOR',
            'opens_at' => '07:00:00',
            'closes_at' => '21:00:00',
            'suggested_cash_base' => 0,
            'is_active' => true,
        ]);

        $coupon = $this->makeCoupon(['storefront_id' => app(StorefrontManager::class)->for($north)->id]);

        $this->assertMessage(fn () => $this->apply($coupon->code, 60000), 'no sirve en esta sede');
    }

    public function test_el_alcance_por_categoria_solo_mira_esos_articulos(): void
    {
        $otra = \App\Models\Category::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Aseo',
            'slug' => 'aseo',
            'is_active' => true,
        ]);

        $despensa = $this->makeProduct(['name' => 'Arroz']);
        $aseo = $this->makeProduct(['name' => 'Jabón', 'category_id' => $otra->id]);

        $coupon = $this->makeCoupon([
            'type' => Coupon::TYPE_PERCENT,
            'value' => 10,
            'scope' => 'category',
            'scope_ids' => [$this->category->id],
        ]);

        $applied = $this->coupons->apply($coupon->code, $this->storefront, [
            ['product_id' => $despensa->id, 'line_total' => 40000],
            ['product_id' => $aseo->id, 'line_total' => 20000],
        ], 60000, 5000);

        // Solo los 40.000 de despensa entran en la cuenta.
        $this->assertSame(4000, $applied['discount']);
    }

    public function test_el_domicilio_gratis_no_descuenta_articulos(): void
    {
        $coupon = $this->makeCoupon(['type' => Coupon::TYPE_FREE_DELIVERY, 'value' => 0]);

        $applied = $this->apply($coupon->code, 60000);

        $this->assertSame(0, $applied['discount']);
        $this->assertTrue($applied['free_delivery']);
    }

    public function test_un_codigo_que_no_existe_lo_dice(): void
    {
        $this->assertMessage(fn () => $this->apply('NOEXISTE', 60000), 'no existe');
    }

    public function test_el_panel_crea_y_apaga_cupones(): void
    {
        $this->actingAs($this->admin, 'web')->withSession($this->withActiveStore());

        $this->post(route('coupons.store'), [
            'code' => 'bienvenido',
            'type' => Coupon::TYPE_PERCENT,
            'value' => 10,
            'min_order_total' => 0,
            'scope' => 'all',
            'first_order_only' => true,
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $coupon = Coupon::query()->withoutGlobalScopes()->firstOrFail();

        // El codigo se guarda en mayusculas, venga como venga.
        $this->assertSame('BIENVENIDO', $coupon->code);
        $this->assertSame($this->admin->id, $coupon->created_by_user_id);

        $this->patch(route('coupons.toggle', $coupon))->assertSessionHasNoErrors();
        $this->assertFalse($coupon->refresh()->is_active);
    }

    public function test_un_porcentaje_mayor_que_cien_se_rechaza(): void
    {
        $this->actingAs($this->admin, 'web')->withSession($this->withActiveStore());

        $this->from(route('coupons.index'))->post(route('coupons.store'), [
            'code' => 'REGALO',
            'type' => Coupon::TYPE_PERCENT,
            'value' => 120,
            'scope' => 'all',
        ])->assertSessionHasErrors('value');

        $this->assertSame(0, Coupon::query()->withoutGlobalScopes()->count());
    }

    public function test_dos_cupones_no_comparten_codigo(): void
    {
        $this->makeCoupon(['code' => 'UNICO']);

        $this->actingAs($this->admin, 'web')->withSession($this->withActiveStore());

        $this->from(route('coupons.index'))->post(route('coupons.store'), [
            'code' => 'UNICO',
            'type' => Coupon::TYPE_AMOUNT,
            'value' => 1000,
            'scope' => 'all',
        ])->assertSessionHasErrors('code');
    }

    /**
     * @return array{coupon: Coupon, discount: int, free_delivery: bool}
     */
    protected function apply(string $code, int $subtotal, ?Customer $customer = null): array
    {
        $product = $this->product ??= $this->makeProduct();

        return $this->coupons->apply(
            $code,
            $this->storefront,
            [['product_id' => $product->id, 'line_total' => $subtotal]],
            $subtotal,
            5000,
            $customer,
        );
    }

    protected ?\App\Models\Product $product = null;

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeCoupon(array $attributes = []): Coupon
    {
        return Coupon::create(array_merge([
            'company_id' => $this->company->id,
            'code' => 'PRUEBA'.random_int(100, 999),
            'type' => Coupon::TYPE_PERCENT,
            'value' => 10,
            'min_order_total' => 0,
            'scope' => 'all',
            'is_active' => true,
            'created_by_user_id' => $this->admin->id,
        ], $attributes));
    }

    protected function makeOrder(Customer $customer): Order
    {
        return Order::create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'number' => 'P-'.random_int(1000, 999999),
            'channel' => Order::CHANNEL_APP,
            'status' => Order::STATUS_NEW,
            'payment_method' => Order::PAYMENT_CASH_ON_DELIVERY,
            'payment_status' => 'pending',
            'delivery_address' => 'Calle 30 # 65-12',
            'subtotal' => 60000,
            'delivery_fee' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'total' => 60000,
            'placed_at' => now(),
        ]);
    }

    protected function assertMessage(callable $action, string $needle): void
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($needle, $exception->validator->errors()->first('coupon'));

            return;
        }

        $this->fail("Se esperaba que el cupón fuera rechazado con «{$needle}».");
    }
}

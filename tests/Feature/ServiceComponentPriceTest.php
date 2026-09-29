<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Promotion;
use App\Models\PromotionTarget;
use App\Models\Service;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PromotionService;
use App\Services\ServicePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\SellsServicesAtPos;
use Tests\TestCase;

/**
 * El precio de un articulo dentro de un servicio «servicio + articulos»: se fija en el
 * formulario y lo cobran igual la caja, la cotizacion, la tienda y el pedido. Vacio, el articulo
 * va a su precio vigente, como siempre.
 */
class ServiceComponentPriceTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase, SellsServicesAtPos;

    protected User $admin;

    protected Service $fijo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->seedServiceScenario();
        $this->admin = $this->makeUser('admin');

        // Fresa a $2.500 dentro del servicio (su precio es $3.000); el banano, al vigente.
        $this->fijo = $this->makeService('Arreglo fijo', 5000, [[$this->fresa, 2, false, 2500], [$this->banano, 3, false]], Service::PRICING_ITEMIZED, [
            'code' => 'SRV-FIJO',
        ]);
    }

    public function test_el_formulario_guarda_el_precio_de_cada_articulo(): void
    {
        $payload = fn (string $mode) => [
            'code' => 'SRV-'.$mode,
            'name' => 'Arreglo '.$mode,
            'price' => 5000,
            'tax_rate' => 19,
            'pricing_mode' => $mode,
            'is_active' => true,
            'components' => [
                ['product_id' => $this->fresa->id, 'quantity' => 2, 'unit_price' => 2800, 'returnable_by_default' => false],
                // Vacio, como llega de un formulario con imagen: es «el vigente», no cero.
                ['product_id' => $this->banano->id, 'quantity' => 3, 'unit_price' => '', 'returnable_by_default' => false],
            ],
        ];

        $this->as()->post(route('services.store'), $payload('itemized'))->assertSessionHasNoErrors();
        $service = Service::query()->withoutGlobalScopes()->where('code', 'SRV-itemized')->firstOrFail();
        $this->assertSame(2800, $service->components[0]->unit_price);
        $this->assertNull($service->components[1]->unit_price);

        // En precio cerrado los articulos valen $0: no se guarda precio fijo.
        $this->as()->post(route('services.store'), $payload('bundled'))->assertSessionHasNoErrors();
        $this->assertNull(Service::query()->withoutGlobalScopes()->where('code', 'SRV-bundled')->firstOrFail()->components[0]->unit_price);

        $this->as()->get(route('services.edit', $service))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('service.components.0.custom_price', 2800)
                // La referencia sigue siendo el precio vigente del articulo.
                ->where('service.components.0.unit_price', 3000)
                ->where('service.components.1.custom_price', null));

        $this->as()->get(route('services.show', $service))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('components.0.unit_price', 2800)
                ->where('components.0.fixed_price', true)
                ->where('components.1.fixed_price', false)
                // 5.000 + 2 x 2.800 + 3 x 1.000.
                ->where('service.price_pos', 13600));

        $this->as()->post(route('services.store'), [...$payload('itemized'), 'code' => 'SRV-MAL', 'components' => [
            ['product_id' => $this->fresa->id, 'quantity' => 1, 'unit_price' => -5],
        ]])->assertSessionHasErrors('components.0.unit_price');
    }

    public function test_la_caja_cobra_el_precio_fijo_y_lo_agregado_al_vigente(): void
    {
        // Tal cual el maestro: 5.000 + 2 x 2.500 + 3 x 1.000.
        $this->sell([['service_id' => $this->fijo->id, 'quantity' => 1]])->assertSessionHasNoErrors();
        $sale = $this->lastSale();
        $this->assertSame(13000, $sale->total);
        $this->assertSame(2500, $sale->items->firstWhere('product_id', $this->fresa->id)->unit_price);
        $this->assertSame(1000, $sale->items->firstWhere('product_id', $this->banano->id)->unit_price);
        $this->assertSame(2500, $sale->items->firstWhere('service_id', $this->fijo->id)->service_snapshot['master'][0]['unit_price']);

        // Mas fresa sigue al precio fijo; un articulo que no esta en el maestro va al vigente.
        $this->cashier->givePermissionTo('pos.sale.service_add');
        $this->cashier = $this->cashier->fresh();

        $this->sell([['service_id' => $this->fijo->id, 'quantity' => 1, 'components' => [
            ['product_id' => $this->fresa->id, 'quantity' => 3],
            ['product_id' => $this->banano->id, 'quantity' => 3],
            ['product_id' => $this->galletas->id, 'quantity' => 1],
        ]]])->assertSessionHasNoErrors();

        $sale = $this->lastSale();
        $this->assertSame(7500, $sale->items->firstWhere('product_id', $this->fresa->id)->line_total);
        $this->assertSame(5000, $sale->items->firstWhere('product_id', $this->galletas->id)->unit_price);
        $this->assertSame(5000 + 7500 + 3000 + 5000, $sale->total);
    }

    public function test_el_precio_fijo_no_toma_promociones_y_la_cotizacion_cuadra(): void
    {
        $promotion = Promotion::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Fresa', 'type' => Promotion::TYPE_SPECIAL_PRICE,
            'applies_online' => true, 'applies_pos' => true, 'is_active' => true, 'config' => [],
        ]);
        $promotion->targets()->create(['target_type' => PromotionTarget::TYPE_PRODUCT, 'target_id' => $this->fresa->id, 'value' => 2000]);
        app(PromotionService::class)->forget();

        $items = [['service_id' => $this->fijo->id, 'quantity' => 1]];
        $quote = $this->actingAs($this->cashier)->withSession($this->withActiveStore())
            ->postJson(route('pos.quote'), ['items' => $items])
            ->assertOk()
            ->json('lines');

        // El precio que el comerciante fijo manda sobre la promocion del estante.
        $this->assertSame(2500, $quote[0]['components'][0]['unit_price']);

        $this->sell($items)->assertSessionHasNoErrors();
        $this->assertSame(13000, $this->lastSale()->total);
    }

    public function test_la_tienda_y_el_pedido_cobran_el_precio_fijo(): void
    {
        $this->fresa->update(['online_price' => 3200]);
        $this->banano->update(['online_price' => 1100]);
        $this->fijo->load('components.product');

        // Fijo en los dos canales: 5.000 + 2 x 2.500; el banano, a su precio en linea.
        $this->assertSame(5000 + 5000 + 3300, app(ServicePricing::class)->priceFor($this->fijo, PromotionService::CHANNEL_ONLINE));

        $order = app(OrderService::class)->create([
            'customer_id' => $this->makeCustomer()->id,
            'items' => [['service_id' => $this->fijo->id, 'quantity' => 1]],
            'delivery_address' => 'Calle 30 # 65-12',
            'delivery_fee' => 0,
            'payment_method' => Order::PAYMENT_CASH_ON_DELIVERY,
        ], $this->store, $this->admin)->load('items');

        $this->assertSame(2500, $order->items->firstWhere('product_id', $this->fresa->id)->unit_price);
        $this->assertSame(13300, $order->total);
    }

    public function test_sin_permiso_de_precios_no_se_toca_el_precio_de_un_articulo(): void
    {
        $editor = $this->makeUser('bodega', ['email' => 'editor@lacanasta.co']);
        $editor->givePermissionTo(['services.index.view', 'services.index.edit', 'services.show.view']);
        $editor = $editor->fresh();

        $payload = fn (?int $fresa, string $mode = 'itemized') => [
            'code' => 'SRV-FIJO', 'name' => 'Arreglo de temporada', 'price' => 5000, 'tax_rate' => 0, 'pricing_mode' => $mode, 'is_active' => true,
            'components' => [
                ['product_id' => $this->fresa->id, 'quantity' => 2, 'unit_price' => $fresa, 'returnable_by_default' => false],
                ['product_id' => $this->banano->id, 'quantity' => 3, 'unit_price' => null, 'returnable_by_default' => false],
            ],
        ];

        $this->as($editor)->put(route('services.update', $this->fijo), $payload(2000))->assertSessionHasErrors('price');
        $this->as($editor)->put(route('services.update', $this->fijo), $payload(null))->assertSessionHasErrors('price');
        $this->as($editor)->put(route('services.update', $this->fijo), $payload(2500, 'bundled'))->assertSessionHasErrors('price');
        $this->assertSame(2500, $this->fijo->components()->where('product_id', $this->fresa->id)->value('unit_price'));

        // Con los mismos precios si guarda lo demas.
        $this->as($editor)->put(route('services.update', $this->fijo), $payload(2500))->assertSessionHasNoErrors();
        $this->assertSame('Arreglo de temporada', $this->fijo->refresh()->name);

        $editor->givePermissionTo('services.show.edit_price');
        $this->as($editor->fresh())->put(route('services.update', $this->fijo), $payload(2000))->assertSessionHasNoErrors();
        $this->assertSame(2000, $this->fijo->components()->where('product_id', $this->fresa->id)->value('unit_price'));
    }

    protected function as(?User $user = null): static
    {
        return $this->actingAs($user ?? $this->admin)->withSession($this->withActiveStore());
    }
}

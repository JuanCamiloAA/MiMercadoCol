<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductStock;
use App\Models\SaleItem;
use App\Models\Service;
use App\Services\OrderService;
use App\Services\ServiceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsVariants;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\SellsServicesAtPos;
use Tests\TestCase;

/**
 * Un servicio puede llevar un articulo con subarticulos completo («Aceite de girasol», en la
 * presentacion que se elija). La presentacion por defecto se cambia libre en caja y en la tienda,
 * sin autorizacion, y se cobra y se descuenta la elegida.
 */
class ServiceFamilyComponentTest extends TestCase
{
    use BuildsVariants, CreatesCompanyEnvironment, RefreshDatabase, SellsServicesAtPos;

    protected ProductFamily $oils;

    protected Product $small;

    protected Product $big;

    protected Service $basket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->seedAttributes();
        $this->seedServiceScenario();

        $this->oils = $this->makeFamily('Aceite de girasol', 'Volumen', null, [['500 ml', null, 8000], ['900 ml', null, 14000]], ['tax_rate' => 0]);
        [$this->small, $this->big] = $this->oils->variants()->get()->all();

        // Canasta detallada: el aceite completo (por defecto el de 500 ml) y dos galletas.
        $this->basket = app(ServiceManager::class)->save(null, [
            'code' => 'SRV-CANASTA',
            'name' => 'Canasta de regalo',
            'price' => 5000,
            'cost' => 0,
            'tax_rate' => 0,
            'pricing_mode' => Service::PRICING_ITEMIZED,
            'open_price' => false,
            'is_active' => true,
            'is_online_visible' => true,
            'is_featured' => false,
            'components' => [
                ['product_id' => $this->small->id, 'family' => true, 'quantity' => 1, 'returnable_by_default' => true],
                ['product_id' => $this->galletas->id, 'quantity' => 2, 'returnable_by_default' => true],
            ],
        ], $this->company->id);
    }

    public function test_el_componente_guarda_la_familia_y_la_presentacion_por_defecto(): void
    {
        $component = $this->basket->components->first();

        $this->assertSame($this->oils->id, $component->product_family_id);
        $this->assertSame($this->small->id, $component->product_id);
        $this->assertNull($component->unit_price);
    }

    public function test_en_caja_se_cambia_de_presentacion_sin_autorizacion(): void
    {
        $bigBefore = $this->stockOf($this->big);
        $smallBefore = $this->stockOf($this->small);

        $this->sell([[
            'service_id' => $this->basket->id,
            'quantity' => 1,
            'components' => [
                ['product_id' => $this->big->id, 'quantity' => 1],
                ['product_id' => $this->galletas->id, 'quantity' => 2],
            ],
        ]])->assertSessionHasNoErrors();

        $sale = $this->lastSale();
        $oil = $sale->items->firstWhere('product_id', $this->big->id);

        // Se cobra la elegida y no cuenta como ajuste del servicio.
        $this->assertSame(14000, $oil->unit_price);
        $this->assertNull($oil->component_change);
        $this->assertSame(5000 + 14000 + 2 * 5000, $sale->total);
        $this->assertSame($bigBefore - 1, $this->stockOf($this->big));
        $this->assertSame($smallBefore, $this->stockOf($this->small));

        $snapshot = $sale->items->firstWhere('service_id', $this->basket->id)->service_snapshot;
        $this->assertSame($this->big->id, $snapshot['swapped'][0]['product_id']);
    }

    public function test_dos_presentaciones_en_la_misma_linea_es_agregar(): void
    {
        $this->sell([[
            'service_id' => $this->basket->id,
            'quantity' => 1,
            'components' => [
                ['product_id' => $this->small->id, 'quantity' => 1],
                ['product_id' => $this->big->id, 'quantity' => 1],
                ['product_id' => $this->galletas->id, 'quantity' => 2],
            ],
        ]])->assertSessionHasErrors();

        $this->assertSame(0, SaleItem::query()->count());
    }

    public function test_el_maestro_no_admite_familia_mal_puesta(): void
    {
        try {
            app(ServiceManager::class)->assertComponents([['product_id' => $this->galletas->id, 'family' => true, 'quantity' => 1]], $this->company->id);
            $this->fail('Un articulo sin subarticulos no puede ir como articulo completo.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('components.0.family', $exception->errors());
        }

        try {
            app(ServiceManager::class)->assertComponents([
                ['product_id' => $this->small->id, 'family' => true, 'quantity' => 1],
                ['product_id' => $this->big->id, 'quantity' => 1],
            ], $this->company->id);
            $this->fail('El articulo completo y una de sus presentaciones no van juntos.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('components.1.product_id', $exception->errors());
        }
    }

    public function test_en_la_tienda_el_cliente_escoge_y_el_pedido_la_respeta(): void
    {
        $this->publishStorefront();
        $this->shopAt();

        $this->putJson(route('storefront.cart.quantity'), [
            'service_id' => $this->basket->id,
            'quantity' => 1,
            'choices' => [$this->oils->id => $this->big->id],
        ])->assertOk();

        $this->assertSame([(string) $this->oils->id => $this->big->id], CartItem::query()->sole()->choices);

        $this->getJson(route('storefront.cart.detail'))
            ->assertOk()
            ->assertJsonPath('items.0.price', 5000 + 14000 + 2 * 5000);

        // Una eleccion ajena (otro articulo) se ignora: queda la del maestro.
        $this->putJson(route('storefront.cart.quantity'), [
            'service_id' => $this->basket->id,
            'quantity' => 1,
            'choices' => [$this->oils->id => $this->galletas->id],
        ])->assertOk();
        $this->assertSame([], CartItem::query()->sole()->choices);

        $customer = $this->makeCustomer();
        $order = app(OrderService::class)->create([
            'customer_id' => $customer->id,
            'items' => [['service_id' => $this->basket->id, 'quantity' => 1, 'choices' => [$this->oils->id => $this->big->id]]],
            'channel' => Order::CHANNEL_APP,
            'payment_method' => Order::PAYMENT_CASH_ON_DELIVERY,
            'delivery_address' => 'Calle 10',
        ], $this->store);

        $oil = OrderItem::query()->where('order_id', $order->id)->where('product_id', $this->big->id)->sole();
        $this->assertSame(14000, $oil->unit_price);
        $this->assertSame(0.0, (float) ProductStock::query()->where('product_id', $this->small->id)->sum('reserved_quantity'));
    }
}

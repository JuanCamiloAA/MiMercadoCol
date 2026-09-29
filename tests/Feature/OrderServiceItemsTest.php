<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Service;
use App\Models\User;
use App\Services\OrderService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\SellsServices;
use Tests\TestCase;

/**
 * Pedidos con servicios: se reservan los articulos en la bodega del pedido, se alista por
 * articulo y la venta nace con lo alistado, al precio que el cliente vio al confirmar.
 */
class OrderServiceItemsTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase, SellsServices;

    protected User $admin;

    protected Service $arreglo;

    protected Product $fresa;

    protected Product $banano;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->admin = $this->makeUser('admin');
        app(SettingsService::class)->set($this->company->id, 'pos.cash_rounding', 100);

        $this->fresa = $this->makeProduct(['name' => 'Fresa', 'sku' => 'FRU-001', 'price' => 3000, 'online_price' => 3050, 'unit' => 'kg', 'sold_by_weight' => true], 20);
        $this->banano = $this->makeProduct(['name' => 'Banano', 'sku' => 'FRU-002', 'price' => 1000], 30);
        $this->arreglo = $this->makeService('Arreglo de frutas', 5000, [[$this->fresa, 2, false], [$this->banano, 3, false]], Service::PRICING_ITEMIZED, [
            'code' => 'SRV-ARREGLO', 'is_online_visible' => true,
        ]);
    }

    public function test_el_pedido_reserva_los_articulos_de_cada_servicio_en_su_bodega(): void
    {
        $order = $this->order(2);

        $this->assertEqualsWithDelta(4, $this->reserved($this->fresa), 0.001);
        $this->assertEqualsWithDelta(6, $this->reserved($this->banano), 0.001);

        $service = $order->items->firstWhere('service_id', $this->arreglo->id);
        $this->assertNull($service->product_id);
        $this->assertCount(2, $order->items->where('parent_order_item_id', $service->id));
        // 2 x (5.000 + 2 x 3.050 + 3 x 1.000).
        $this->assertSame(2 * (5000 + 6100 + 3000), $order->total - $order->delivery_fee);
    }

    public function test_si_no_alcanza_se_rechaza_con_el_nombre_del_articulo_y_del_servicio(): void
    {
        try {
            $this->order(11);
            $this->fail('Se esperaba que el pedido se rechazara.');
        } catch (ValidationException $exception) {
            $this->assertSame('No hay existencias suficientes de Fresa para Arreglo de frutas.', $exception->errors()['stock'][0]);
        }

        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
        $this->assertEqualsWithDelta(0, $this->reserved($this->banano), 0.001);
    }

    public function test_alistar_menos_deja_la_venta_con_el_ajuste_y_cobra_lo_entregado(): void
    {
        $order = $this->order(1);
        $service = $order->items->firstWhere('service_id', $this->arreglo->id);
        $fresa = $order->items->where('parent_order_item_id', $service->id)->firstWhere('product_id', $this->fresa->id);
        $banano = $order->items->where('parent_order_item_id', $service->id)->firstWhere('product_id', $this->banano->id);

        // Solo alcanzo para 1,5 kg de fresa. La fila del servicio no se alista.
        app(OrderService::class)->pick($order, [
            ['id' => $service->id, 'picked_quantity' => 0],
            ['id' => $fresa->id, 'picked_quantity' => 1.5],
            ['id' => $banano->id, 'picked_quantity' => 3],
        ], $this->admin);

        $this->assertEqualsWithDelta(1.5, $this->reserved($this->fresa), 0.001);
        $this->assertNull($service->refresh()->picked_quantity);

        // El precio del servicio cambia en el catalogo: el pedido no se vuelve a consultar.
        $this->arreglo->update(['price' => 9000]);

        $this->deliver($order->refresh());

        $sale = Sale::query()->withoutGlobalScopes()->with('items')->findOrFail($order->refresh()->sale_id);
        // 5.000 + 1,5 x 3.050 + 3 x 1.000, sin redondear: no es caja.
        // Mas el domicilio de 5.000, que va como una linea mas.
        $this->assertSame(5000 + 4575 + 3000 + 5000, $sale->total);
        $this->assertSame(0, $sale->rounding_total);

        $saleFresa = $sale->items->firstWhere('product_id', $this->fresa->id);
        $this->assertSame(SaleItem::CHANGE_REDUCED, $saleFresa->component_change);
        $this->assertNotNull($saleFresa->parent_sale_item_id);
        $this->assertSame($this->arreglo->id, $sale->items->firstWhere('service_id', '!=', null)->service_id);

        // Salio lo alistado, y la reserva quedo en cero.
        $this->assertEqualsWithDelta(18.5, $this->stock($this->fresa), 0.001);
        $this->assertEqualsWithDelta(0, $this->reserved($this->fresa), 0.001);
    }

    public function test_anular_el_pedido_libera_las_reservas(): void
    {
        $order = $this->order(2);

        app(OrderService::class)->changeStatus($order, Order::STATUS_CANCELLED, $this->admin, 'El cliente cancelo');

        $this->assertEqualsWithDelta(0, $this->reserved($this->fresa), 0.001);
        $this->assertEqualsWithDelta(0, $this->reserved($this->banano), 0.001);
        $this->assertEqualsWithDelta(20, $this->stock($this->fresa), 0.001);
    }

    public function test_el_panel_crea_pedidos_con_servicios(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->get(route('orders.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('services.0.value', $this->arreglo->id));

        $this->actingAs($this->admin)->withSession($this->withActiveStore())->post(route('orders.store'), [
            'customer_id' => $customer->id,
            'delivery_address' => 'Calle 1',
            'payment_method' => Order::PAYMENT_CASH_ON_DELIVERY,
            'delivery_fee' => 0,
            'items' => [['service_id' => $this->arreglo->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $order = Order::query()->withoutGlobalScopes()->with('items')->latest('id')->firstOrFail();
        $this->assertCount(3, $order->items);

        $this->actingAs($this->admin)->withSession($this->withActiveStore())->get(route('orders.show', $order))->assertOk()
            ->assertInertia(fn ($page) => $page->where('order.items.0.is_service', true)->where('order.items.1.parent_id', $order->items->first()->id));
    }

    protected function order(float $quantity): Order
    {
        return app(OrderService::class)->create([
            'customer_id' => $this->makeCustomer()->id,
            'items' => [['service_id' => $this->arreglo->id, 'quantity' => $quantity]],
            'delivery_address' => 'Calle 30 # 65-12',
            'delivery_fee' => 5000,
            'payment_method' => Order::PAYMENT_CASH_ON_DELIVERY,
        ], $this->store, $this->admin)->load('items');
    }

    protected function deliver(Order $order): void
    {
        $service = app(OrderService::class);

        foreach ([Order::STATUS_PICKING, Order::STATUS_READY, Order::STATUS_ON_ROUTE, Order::STATUS_DELIVERED] as $status) {
            if ($order->refresh()->status !== $status && $order->canTransitionTo($status)) {
                $service->changeStatus($order, $status, $this->admin);
            }
        }

        $this->assertSame(Order::STATUS_DELIVERED, $order->refresh()->status);
    }

    protected function reserved(Product $product): float
    {
        return (float) ProductStock::query()->withoutGlobalScopes()->where('product_id', $product->id)->where('warehouse_id', $this->warehouse()->id)->value('reserved_quantity');
    }

    protected function stock(Product $product): float
    {
        return (float) ProductStock::query()->withoutGlobalScopes()->where('product_id', $product->id)->where('warehouse_id', $this->warehouse()->id)->value('quantity');
    }
}

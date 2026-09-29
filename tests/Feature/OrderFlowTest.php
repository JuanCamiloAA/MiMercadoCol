<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

class OrderFlowTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
    }

    public function test_un_salto_de_estado_invalido_se_rechaza(): void
    {
        $admin = $this->makeUser('admin');
        $order = $this->makeOrder($admin);

        $this->expectException(ValidationException::class);

        app(OrderService::class)->changeStatus($order, Order::STATUS_DELIVERED, $admin);
    }

    public function test_el_pedido_recorre_los_cinco_estados_y_deja_trazabilidad(): void
    {
        $admin = $this->makeUser('admin');
        $order = $this->makeOrder($admin);
        $orders = app(OrderService::class);

        foreach ([Order::STATUS_PICKING, Order::STATUS_READY, Order::STATUS_ON_ROUTE, Order::STATUS_DELIVERED] as $status) {
            $order = $orders->changeStatus($order->fresh(), $status, $admin);
        }

        $this->assertSame(Order::STATUS_DELIVERED, $order->status);

        $statuses = $order->fresh()->histories->pluck('to_status')->all();

        $this->assertSame(
            [Order::STATUS_NEW, Order::STATUS_PICKING, Order::STATUS_READY, Order::STATUS_ON_ROUTE, Order::STATUS_DELIVERED],
            $statuses
        );
    }

    public function test_entregar_genera_la_venta_y_el_movimiento_de_inventario(): void
    {
        $admin = $this->makeUser('admin');
        $product = $this->makeProduct(['price' => 10000, 'tax_rate' => 0], 40);
        $order = $this->makeOrder($admin, $product, 4);
        $orders = app(OrderService::class);

        // Al crear el pedido la mercancia queda reservada, no descontada.
        $stock = ProductStock::query()->withoutGlobalScopes()->where('product_id', $product->id)->first();
        $this->assertEqualsWithDelta(40, $stock->quantity, 0.001);
        $this->assertEqualsWithDelta(4, $stock->reserved_quantity, 0.001);

        foreach ([Order::STATUS_PICKING, Order::STATUS_READY, Order::STATUS_ON_ROUTE, Order::STATUS_DELIVERED] as $status) {
            $order = $orders->changeStatus($order->fresh(), $status, $admin);
        }

        $order = $order->fresh();

        $this->assertNotNull($order->sale_id);

        $sale = Sale::query()->withoutGlobalScopes()->findOrFail($order->sale_id);
        $this->assertSame(Sale::CHANNEL_ONLINE, $sale->channel);

        $stock = ProductStock::query()->withoutGlobalScopes()->where('product_id', $product->id)->first();
        $this->assertEqualsWithDelta(36, $stock->quantity, 0.001);
        $this->assertEqualsWithDelta(0, $stock->reserved_quantity, 0.001);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_SALE,
        ]);
    }

    public function test_cancelar_libera_la_reserva(): void
    {
        $admin = $this->makeUser('admin');
        $product = $this->makeProduct([], 40);
        $order = $this->makeOrder($admin, $product, 5);

        app(OrderService::class)->changeStatus($order, Order::STATUS_CANCELLED, $admin, 'El cliente no estaba');

        $stock = ProductStock::query()->withoutGlobalScopes()->where('product_id', $product->id)->first();

        $this->assertEqualsWithDelta(40, $stock->quantity, 0.001);
        $this->assertEqualsWithDelta(0, $stock->reserved_quantity, 0.001);
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
    }

    public function test_un_pedido_entregado_no_se_puede_cancelar(): void
    {
        $admin = $this->makeUser('admin');
        $order = $this->makeOrder($admin);
        $orders = app(OrderService::class);

        foreach ([Order::STATUS_PICKING, Order::STATUS_READY, Order::STATUS_ON_ROUTE, Order::STATUS_DELIVERED] as $status) {
            $order = $orders->changeStatus($order->fresh(), $status, $admin);
        }

        $this->expectException(ValidationException::class);

        $orders->changeStatus($order->fresh(), Order::STATUS_CANCELLED, $admin);
    }

    protected function makeOrder($actor, $product = null, float $quantity = 2): Order
    {
        $product = $product ?? $this->makeProduct([], 40);
        $customer = $this->makeCustomer();

        return app(OrderService::class)->create([
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
            'delivery_address' => 'Calle 30 # 65-12',
            'delivery_fee' => 5000,
            'payment_method' => Order::PAYMENT_CASH_ON_DELIVERY,
        ], $this->store, $actor);
    }
}

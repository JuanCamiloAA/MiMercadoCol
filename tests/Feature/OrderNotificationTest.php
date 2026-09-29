<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Notifications\OrderStatusChanged;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

class OrderNotificationTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        Notification::fake();
    }

    public function test_el_cliente_recibe_aviso_en_los_estados_que_le_importan(): void
    {
        $admin = $this->makeUser('admin');
        $customer = $this->makeCustomer(['email' => 'cliente@correo.co']);
        $order = $this->makeOrder($admin, $customer);
        $orders = app(OrderService::class);

        foreach ([Order::STATUS_PICKING, Order::STATUS_READY, Order::STATUS_ON_ROUTE, Order::STATUS_DELIVERED] as $status) {
            $order = $orders->changeStatus($order->fresh(), $status, $admin);
        }

        // «Listo» es un estado interno de la bodega: al cliente no se le escribe por eso.
        Notification::assertSentToTimes($customer, OrderStatusChanged::class, 3);
    }

    public function test_un_cliente_sin_correo_no_genera_avisos(): void
    {
        $admin = $this->makeUser('admin');
        $customer = $this->makeCustomer(['email' => null]);
        $order = $this->makeOrder($admin, $customer);

        app(OrderService::class)->changeStatus($order, Order::STATUS_PICKING, $admin);

        Notification::assertNothingSent();
    }

    public function test_cancelar_tambien_avisa(): void
    {
        $admin = $this->makeUser('admin');
        $customer = $this->makeCustomer(['email' => 'cliente@correo.co']);
        $order = $this->makeOrder($admin, $customer);

        app(OrderService::class)->changeStatus($order, Order::STATUS_CANCELLED, $admin, 'Sin quien reciba');

        Notification::assertSentTo(
            $customer,
            fn (OrderStatusChanged $notification) => $notification->order->status === Order::STATUS_CANCELLED
        );
    }

    protected function makeOrder($actor, $customer): Order
    {
        $product = $this->makeProduct([], 40);

        return app(OrderService::class)->create([
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'delivery_address' => 'Calle 30 # 65-12',
            'delivery_fee' => 5000,
            'payment_method' => Order::PAYMENT_CASH_ON_DELIVERY,
        ], $this->store, $actor);
    }
}

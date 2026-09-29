<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Cobro contraentrega de un pedido.
 *
 * El domiciliario llega con el pedido y el cliente paga como puede: en efectivo, con tarjeta
 * o combinando. Lo que quede guardado tiene que ser lo que de verdad paso, no lo que se
 * supuso cuando se tomo el pedido.
 */
class OrderCollectionTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->admin = $this->makeUser('admin');
    }

    public function test_la_venta_del_pedido_incluye_el_domicilio(): void
    {
        $order = $this->deliver();
        $sale = Sale::query()->withoutGlobalScopes()->findOrFail($order->sale_id);

        // 4 x 10.000 de mercancia + 5.000 de domicilio.
        $this->assertSame(45000, $sale->total);
        $this->assertSame($order->total, $sale->total);

        $fee = $sale->items->firstWhere('description', 'Domicilio');
        $this->assertNotNull($fee, 'La venta deberia traer la linea del domicilio.');
        $this->assertNull($fee->product_id);
        $this->assertSame(5000, $fee->line_total);
    }

    public function test_el_cobro_repartido_reemplaza_lo_que_se_supuso_al_entregar(): void
    {
        $order = $this->deliver();

        app(OrderService::class)->collect($order, $this->admin, [
            ['method' => SalePayment::METHOD_CASH, 'amount' => 25000],
            ['method' => SalePayment::METHOD_TRANSFER, 'amount' => 20000, 'reference' => 'NEQ-8891'],
        ]);

        $sale = Sale::query()->withoutGlobalScopes()->with('payments')->findOrFail($order->sale_id);

        $this->assertCount(2, $sale->payments);
        $this->assertSame(25000, $sale->payments->firstWhere('method', SalePayment::METHOD_CASH)->amount);
        $this->assertSame('NEQ-8891', $sale->payments->firstWhere('method', SalePayment::METHOD_TRANSFER)->reference);
        $this->assertSame(Sale::STATUS_PAID, $sale->status);
        $this->assertSame(45000, $sale->paid_total);
        $this->assertSame(0, $sale->due_total);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_el_cambio_sale_solo_del_efectivo(): void
    {
        $order = $this->deliver();

        app(OrderService::class)->collect($order, $this->admin, [
            ['method' => SalePayment::METHOD_CARD, 'amount' => 15000],
            ['method' => SalePayment::METHOD_CASH, 'amount' => 50000],
        ]);

        $sale = Sale::query()->withoutGlobalScopes()->with('payments')->findOrFail($order->sale_id);
        $cash = $sale->payments->firstWhere('method', SalePayment::METHOD_CASH);

        $this->assertSame(20000, $cash->change_amount);
        $this->assertNull($sale->payments->firstWhere('method', SalePayment::METHOD_CARD)->change_amount);
        $this->assertSame(45000, (int) $sale->payments->sum('amount') - (int) $sale->payments->sum('change_amount'));
    }

    public function test_contraentrega_no_admite_credito(): void
    {
        $order = $this->deliver();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Medio de pago no reconocido.');

        app(OrderService::class)->collect($order, $this->admin, [
            ['method' => SalePayment::METHOD_CREDIT, 'amount' => 45000],
        ]);
    }

    public function test_rechaza_cuando_el_cobro_no_alcanza(): void
    {
        $order = $this->deliver();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Faltan');

        app(OrderService::class)->collect($order, $this->admin, [
            ['method' => SalePayment::METHOD_CASH, 'amount' => 20000],
        ]);
    }

    public function test_cobrar_un_pedido_a_credito_deja_la_cartera_en_cero(): void
    {
        $customer = $this->makeCustomer([
            'credit_enabled' => true,
            'credit_limit' => 200000,
            'credit_term_days' => 15,
        ]);

        $order = $this->deliver($customer, Order::PAYMENT_CREDIT);

        $this->assertSame(45000, (int) $customer->fresh()->balance->balance);

        app(OrderService::class)->collect($order, $this->admin, [
            ['method' => SalePayment::METHOD_CASH, 'amount' => 45000],
        ]);

        $this->assertSame(0, (int) $customer->fresh()->balance->balance);
        $this->assertSame(0, Sale::query()->withoutGlobalScopes()->findOrFail($order->sale_id)->due_total);
    }

    public function test_el_endpoint_registra_el_cobro_repartido(): void
    {
        $order = $this->deliver();

        $this->actingAs($this->admin)
            ->withSession($this->withActiveStore())
            ->post(route('orders.collect', $order), [
                'payments' => [
                    ['method' => SalePayment::METHOD_CASH, 'amount' => 25000],
                    ['method' => SalePayment::METHOD_CARD, 'amount' => 20000, 'reference' => '4821'],
                ],
            ])
            ->assertRedirect();

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertCount(2, Sale::query()->withoutGlobalScopes()->findOrFail($order->sale_id)->payments);
    }

    public function test_el_endpoint_rechaza_dos_pagos_del_mismo_medio(): void
    {
        $order = $this->deliver();

        $this->actingAs($this->admin)
            ->withSession($this->withActiveStore())
            ->post(route('orders.collect', $order), [
                'payments' => [
                    ['method' => SalePayment::METHOD_CASH, 'amount' => 25000],
                    ['method' => SalePayment::METHOD_CASH, 'amount' => 20000],
                ],
            ])
            ->assertSessionHasErrors('payments.0.method');
    }

    /** Un pedido de 4 unidades a 10.000 mas 5.000 de domicilio, ya entregado. */
    protected function deliver(?Customer $customer = null, string $paymentMethod = Order::PAYMENT_CASH_ON_DELIVERY): Order
    {
        $product = $this->makeProduct(['price' => 10000, 'tax_rate' => 0], 40);
        $customer = $customer ?? $this->makeCustomer();
        $orders = app(OrderService::class);

        $order = $orders->create([
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
            'delivery_address' => 'Calle 30 # 65-12',
            'delivery_fee' => 5000,
            'payment_method' => $paymentMethod,
        ], $this->store, $this->admin);

        foreach ([Order::STATUS_PICKING, Order::STATUS_READY, Order::STATUS_ON_ROUTE, Order::STATUS_DELIVERED] as $status) {
            $order = $orders->changeStatus($order->fresh(), $status, $this->admin);
        }

        return $order->fresh();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerReturn;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Models\StoreCreditMovement;
use App\Models\User;
use App\Notifications\CustomerReturnUpdated;
use App\Services\CustomerReturnService;
use App\Services\OrderService;
use App\Services\StoreCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Devoluciones de la tienda en linea: el cliente las pide sobre un pedido entregado, el equipo
 * las aprueba, recibe y resuelve. Y el saldo a favor como medio de pago de un pedido.
 */
class CustomerReturnOnlineTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected Customer $customer;

    protected Product $leche;

    protected Product $huevos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        Storage::fake('local');
        Notification::fake();

        $this->admin = $this->makeUser('admin');
        $this->customer = $this->makeCustomer(['name' => 'Luz Marina', 'email' => 'luz@correo.co', 'is_online_user' => true]);
        $this->leche = $this->makeProduct(['name' => 'Leche entera', 'sku' => 'LAC-001', 'price' => 4000, 'online_price' => 4000, 'cost' => 3000, 'tax_rate' => 0], 50);
        $this->huevos = $this->makeProduct(['name' => 'Huevos x12', 'sku' => 'LAC-002', 'price' => 9000, 'online_price' => 9000, 'cost' => 7000, 'tax_rate' => 0], 50);
    }

    public function test_el_cliente_pide_la_devolucion_y_aparta_las_cantidades(): void
    {
        $order = $this->deliveredOrder([[$this->leche, 3]]);
        $line = $this->saleLine($order, $this->leche);

        $this->asCustomer()->get(route('storefront.returns.create', $order))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Storefront/ReturnRequest')->where('items.0.available', 3));

        $this->asCustomer()->post(route('storefront.returns.store', $order), [
            'items' => [['sale_item_id' => $line, 'quantity' => 2, 'reason' => 'damaged']],
            'note' => 'Llegaron rotas las bolsas',
            'photos' => [UploadedFile::fake()->create('foto.jpg', 120, 'image/jpeg')],
        ])->assertSessionHasNoErrors()->assertRedirect(route('storefront.orders.show', $order));

        $return = CustomerReturn::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(CustomerReturn::STATUS_REQUESTED, $return->status);
        $this->assertSame(CustomerReturn::ORIGIN_STOREFRONT, $return->origin);
        $this->assertSame($order->id, $return->order_id);
        $this->assertSame('Llegaron rotas las bolsas', $return->customer_note);
        $this->assertCount(1, $return->photos);
        Storage::disk('local')->assertExists($return->photos[0]);
        Notification::assertSentTo($this->customer, CustomerReturnUpdated::class);

        // Lo pedido queda apartado: solo queda una por devolver, y no se ha movido nada.
        $sale = Sale::query()->withoutGlobalScopes()->findOrFail($order->sale_id);
        $this->assertEqualsWithDelta(1, app(CustomerReturnService::class)->returnableQuantities($sale)[$line], 0.001);
        $this->assertEqualsWithDelta(47, $this->stockOf($this->leche), 0.001);

        $this->asCustomer()->get(route('storefront.orders.show', $order))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('returns.0.status', 'requested')->where('canRequestReturn', true));

        // La foto la ve el cliente dueño.
        $this->asCustomer()->get(route('storefront.returns.photo', [$return->id, 0]))->assertOk();
    }

    public function test_fuera_de_plazo_ajeno_o_sin_entregar_no_se_puede(): void
    {
        $order = $this->deliveredOrder([[$this->leche, 1]]);
        $line = $this->saleLine($order, $this->leche);

        $other = $this->makeCustomer(['name' => 'Otro', 'email' => 'otro@correo.co', 'is_online_user' => true]);
        $this->actingAs($other, 'customer')->shopAt()->get(route('storefront.returns.create', $order))->assertForbidden();

        $pending = app(OrderService::class)->create([
            'customer_id' => $this->customer->id,
            'items' => [['product_id' => $this->huevos->id, 'quantity' => 1]],
            'delivery_address' => 'Calle 30', 'delivery_fee' => 0, 'payment_method' => Order::PAYMENT_TRANSFER,
        ], $this->store, $this->admin);
        $this->asCustomer()->get(route('storefront.returns.create', $pending))->assertRedirect(route('storefront.orders.show', $pending));

        $this->travel(40)->days();
        $this->asCustomer()->post(route('storefront.returns.store', $order), [
            'items' => [['sale_item_id' => $line, 'quantity' => 1, 'reason' => 'quality']],
        ])->assertSessionHasErrors('order');
        $this->assertSame(0, CustomerReturn::query()->withoutGlobalScopes()->count());
    }

    public function test_el_equipo_aprueba_recibe_y_abona_saldo(): void
    {
        $order = $this->deliveredOrder([[$this->leche, 3], [$this->huevos, 1]]);
        $return = $this->requestReturn($order, [[$this->leche, 2, 'damaged'], [$this->huevos, 1, 'changed_mind']]);

        $this->asAdmin()->post(route('customer_returns.approve', $return))->assertSessionHasNoErrors();
        $this->assertSame(CustomerReturn::STATUS_APPROVED, $return->refresh()->status);

        // Llego una leche de dos (rota, a averia) y los huevos (vuelven al estante).
        $items = $return->items()->get();
        $this->asAdmin()->post(route('customer_returns.receive', $return), ['items' => [
            ['id' => $items->firstWhere('product_id', $this->leche->id)->id, 'quantity' => 1, 'disposition' => 'waste'],
            ['id' => $items->firstWhere('product_id', $this->huevos->id)->id, 'quantity' => 1, 'disposition' => 'restock'],
        ]])->assertSessionHasNoErrors();

        $return->refresh();
        $this->assertSame(CustomerReturn::STATUS_RECEIVED, $return->status);
        $this->assertSame(4000 + 9000, $return->total);
        // Recibir no mueve inventario: se mueve al resolver.
        $this->assertEqualsWithDelta(49, $this->stockOf($this->huevos), 0.001);

        $this->asAdmin()->post(route('customer_returns.resolve', $return), ['resolution' => 'store_credit'])->assertSessionHasNoErrors();

        $return->refresh();
        $this->assertSame(CustomerReturn::STATUS_RESOLVED, $return->status);
        $this->assertSame(13000, $return->credited_total);
        $this->assertSame(13000, app(StoreCreditService::class)->available($this->customer));

        $this->assertEqualsWithDelta(50, $this->stockOf($this->huevos), 0.001);
        // La leche entro y se dio de baja: el inventario no cambia, pero la averia queda.
        $this->assertEqualsWithDelta(47, $this->stockOf($this->leche), 0.001);
        $this->assertSame(1, StockMovement::query()->withoutGlobalScopes()->where('type', StockMovement::TYPE_WASTE)->where('product_id', $this->leche->id)->count());

        $refund = Sale::query()->withoutGlobalScopes()->findOrFail($return->refund_sale_id);
        $this->assertSame(Sale::CHANNEL_ONLINE, $refund->channel);
        $this->assertSame(-13000, (int) $refund->payments()->where('method', SalePayment::METHOD_STORE_CREDIT)->value('amount'));

        Notification::assertSentToTimes($this->customer, CustomerReturnUpdated::class, 3);

        $this->asAdmin()->get(route('customer_returns.show', $return))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('CustomerReturns/Show')->where('customerReturn.status', 'resolved')->has('customerReturn.timeline', 4));
    }

    public function test_rechazar_libera_lo_apartado_y_pide_motivo(): void
    {
        $order = $this->deliveredOrder([[$this->leche, 2]]);
        $return = $this->requestReturn($order, [[$this->leche, 2, 'quality']]);

        $this->asAdmin()->post(route('customer_returns.reject', $return), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->asAdmin()->post(route('customer_returns.reject', $return), ['reason' => 'El producto se abrió y se usó'])->assertSessionHasNoErrors();

        $this->assertSame(CustomerReturn::STATUS_REJECTED, $return->refresh()->status);
        $sale = Sale::query()->withoutGlobalScopes()->findOrFail($order->sale_id);
        $this->assertEqualsWithDelta(2, app(CustomerReturnService::class)->returnableQuantities($sale)[$this->saleLine($order, $this->leche)], 0.001);
        $this->assertSame(0, Sale::query()->withoutGlobalScopes()->where('status', Sale::STATUS_REFUNDED)->count());
    }

    public function test_el_cliente_retira_su_solicitud_mientras_no_se_reciba(): void
    {
        $order = $this->deliveredOrder([[$this->leche, 2]]);
        $return = $this->requestReturn($order, [[$this->leche, 1, 'changed_mind']]);

        $this->asCustomer()->post(route('storefront.returns.cancel', $return->id))->assertSessionHasNoErrors();
        $this->assertSame(CustomerReturn::STATUS_CANCELLED, $return->refresh()->status);
        $this->assertTrue($return->cancelled_by_customer);

        $second = $this->requestReturn($order, [[$this->leche, 1, 'changed_mind']]);
        $this->asAdmin()->post(route('customer_returns.approve', $second))->assertSessionHasNoErrors();
        $this->asAdmin()->post(route('customer_returns.receive', $second), ['items' => [['id' => $second->items()->value('id'), 'quantity' => 1, 'disposition' => 'restock']]])->assertSessionHasNoErrors();
        $this->assertSame(CustomerReturn::STATUS_RECEIVED, $second->refresh()->status);

        $this->asCustomer()->post(route('storefront.returns.cancel', $second->id))->assertSessionHasErrors('status');
    }

    public function test_desde_el_panel_se_registra_recibida_y_se_reembolsa(): void
    {
        $order = $this->deliveredOrder([[$this->huevos, 2]]);
        $sale = Sale::query()->withoutGlobalScopes()->findOrFail($order->sale_id);

        $this->asAdmin()->get(route('customer_returns.create', ['venta' => $sale->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('CustomerReturns/Create')->where('sale.items.0.available', 2));

        $this->asAdmin()->post(route('customer_returns.store'), [
            'sale_id' => $sale->id,
            'received' => true,
            'items' => [['sale_item_id' => $this->saleLine($order, $this->huevos), 'quantity' => 1, 'reason' => 'expired', 'disposition' => 'waste']],
        ])->assertSessionHasNoErrors();

        $return = CustomerReturn::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(CustomerReturn::STATUS_RECEIVED, $return->status);
        $this->assertSame(CustomerReturn::ORIGIN_PANEL, $return->origin);

        // Sin caja abierta no se devuelve en efectivo; por transferencia si.
        $this->asAdmin()->post(route('customer_returns.resolve', $return), ['resolution' => 'refund', 'method' => 'cash'])->assertSessionHasErrors('refund_method');
        $this->asAdmin()->post(route('customer_returns.resolve', $return), ['resolution' => 'refund', 'method' => 'transfer'])->assertSessionHasNoErrors();

        $return->refresh();
        $this->assertSame(9000, $return->refunded_total);
        $this->assertSame(SalePayment::METHOD_TRANSFER, $return->refund_method);

        $this->asAdmin()->get(route('customer_returns.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('CustomerReturns/Index')->where('returns.data.0.number', $return->number));
        $this->asAdmin()->get(route('customer_returns.export'))->assertOk();
        $this->asAdmin()->get(route('customer_returns.document', $return))->assertOk();
    }

    public function test_cada_paso_pide_su_permiso(): void
    {
        $order = $this->deliveredOrder([[$this->leche, 2]]);
        $return = $this->requestReturn($order, [[$this->leche, 1, 'damaged']]);

        $bodega = $this->makeUser('bodega', ['email' => 'bodega@lacanasta.co']);
        $contable = $this->makeUser('auxiliar_contable', ['email' => 'conta@lacanasta.co']);
        $cajero = $this->makeUser('cajero', ['email' => 'caja@lacanasta.co']);

        $this->as($cajero)->get(route('customer_returns.index'))->assertForbidden();
        $this->as($bodega)->post(route('customer_returns.approve', $return))->assertForbidden();
        // Bodega no recibe una solicitud que nadie ha aprobado.
        $this->as($bodega)->post(route('customer_returns.receive', $return), ['items' => [['id' => $return->items()->value('id'), 'quantity' => 1]]])->assertForbidden();

        $this->asAdmin()->post(route('customer_returns.approve', $return))->assertSessionHasNoErrors();
        $this->as($bodega)->post(route('customer_returns.receive', $return), ['items' => [['id' => $return->items()->value('id'), 'quantity' => 1, 'disposition' => 'waste']]])->assertSessionHasNoErrors();
        $this->as($bodega)->post(route('customer_returns.resolve', $return), ['resolution' => 'store_credit'])->assertForbidden();
        $this->as($contable)->post(route('customer_returns.resolve', $return), ['resolution' => 'store_credit'])->assertSessionHasNoErrors();

        $this->assertSame(CustomerReturn::STATUS_RESOLVED, $return->refresh()->status);
    }

    public function test_el_pedido_se_paga_con_saldo_a_favor(): void
    {
        app(StoreCreditService::class)->grant($this->customer, 5000, StoreCreditMovement::TYPE_RETURN_CREDIT, [], $this->admin, 'Saldo inicial');

        // Se aparta al pedir y vuelve si se cancela.
        $cancelled = $this->order([[$this->leche, 2]], ['use_store_credit' => true]);
        $this->assertSame(5000, $cancelled->store_credit_amount);
        $this->assertSame(0, app(StoreCreditService::class)->available($this->customer));
        app(OrderService::class)->changeStatus($cancelled, Order::STATUS_CANCELLED, $this->admin, 'Se arrepintió');
        $this->assertSame(5000, app(StoreCreditService::class)->available($this->customer));

        // Contraentrega: el saldo paga una parte y se cobra el resto.
        $order = $this->order([[$this->huevos, 1]], ['use_store_credit' => true, 'payment_method' => Order::PAYMENT_CASH_ON_DELIVERY]);
        $this->deliver($order);

        $sale = Sale::query()->withoutGlobalScopes()->with('payments')->findOrFail($order->refresh()->sale_id);
        $this->assertSame(5000, (int) $sale->payments->firstWhere('method', SalePayment::METHOD_STORE_CREDIT)->amount);
        $this->assertSame(4000, (int) $sale->payments->firstWhere('method', SalePayment::METHOD_CASH)->amount);
        // Se desconto una sola vez: al pedir.
        $this->assertSame(0, app(StoreCreditService::class)->available($this->customer));

        $this->asAdmin()->post(route('orders.collect', $order), ['payments' => [['method' => 'transfer', 'amount' => 4000]]])->assertSessionHasNoErrors();
        $sale->refresh()->load('payments');
        $this->assertSame(5000, (int) $sale->payments->firstWhere('method', SalePayment::METHOD_STORE_CREDIT)->amount);
        $this->assertSame(4000, (int) $sale->payments->firstWhere('method', SalePayment::METHOD_TRANSFER)->amount);
        $this->assertSame('paid', $order->refresh()->payment_status);

        $this->asAdmin()->get(route('customers.show', $this->customer))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('storeCredit.available', 0)->has('storeCredit.movements', 4));
    }

    public function test_el_checkout_ofrece_y_aparta_el_saldo_a_favor(): void
    {
        app(StoreCreditService::class)->grant($this->customer, 3000, StoreCreditMovement::TYPE_RETURN_CREDIT, [], $this->admin, 'Saldo inicial');
        $this->publishStorefront();
        $this->huevos->update(['is_online_visible' => true]);

        $this->asCustomer()->post(route('storefront.cart.store'), ['product_id' => $this->huevos->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $this->asCustomer()->get(route('storefront.checkout.create'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Storefront/Checkout')->where('storeCredit', 3000));

        $this->asCustomer()->post(route('storefront.checkout.store'), [
            'delivery_address' => 'Calle 30 # 65-12',
            'payment_method' => Order::PAYMENT_CASH_ON_DELIVERY,
            'use_store_credit' => true,
        ])->assertSessionHasNoErrors();

        $order = Order::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame(3000, $order->store_credit_amount);
        $this->assertSame(0, app(StoreCreditService::class)->available($this->customer));

        $this->asCustomer()->get(route('storefront.orders.show', $order))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('order.store_credit_amount', 3000));
    }

    // ------------------------------------------------------------------ ayudantes

    /**
     * @param  list<array{0: Product, 1: float}>  $lines
     * @param  array<string, mixed>  $extra
     */
    protected function order(array $lines, array $extra = []): Order
    {
        return app(OrderService::class)->create(array_merge([
            'customer_id' => $this->customer->id,
            'items' => array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
            'delivery_address' => 'Calle 30 # 65-12',
            'delivery_fee' => 0,
            'payment_method' => Order::PAYMENT_TRANSFER,
        ], $extra), $this->store, $this->admin);
    }

    /**
     * @param  list<array{0: Product, 1: float}>  $lines
     */
    protected function deliveredOrder(array $lines): Order
    {
        $order = $this->order($lines);
        $this->deliver($order);

        return $order->refresh();
    }

    protected function deliver(Order $order): void
    {
        foreach ([Order::STATUS_PICKING, Order::STATUS_READY, Order::STATUS_ON_ROUTE, Order::STATUS_DELIVERED] as $status) {
            app(OrderService::class)->changeStatus($order->refresh(), $status, $this->admin);
        }
    }

    /**
     * @param  list<array{0: Product, 1: float, 2: string}>  $lines
     */
    protected function requestReturn(Order $order, array $lines): CustomerReturn
    {
        return app(CustomerReturnService::class)->requestOnline($order->refresh(), $this->customer, [
            'items' => array_map(fn (array $line) => ['sale_item_id' => $this->saleLine($order, $line[0]), 'quantity' => $line[1], 'reason' => $line[2]], $lines),
        ]);
    }

    protected function saleLine(Order $order, Product $product): int
    {
        return (int) Sale::query()->withoutGlobalScopes()->findOrFail($order->refresh()->sale_id)->items()->where('product_id', $product->id)->value('id');
    }

    protected function stockOf(Product $product): float
    {
        return (float) ProductStock::query()->withoutGlobalScopes()->where('product_id', $product->id)->where('warehouse_id', $this->warehouse()->id)->value('quantity');
    }

    protected function asCustomer(): static
    {
        return $this->actingAs($this->customer, 'customer')->shopAt();
    }

    protected function asAdmin(): static
    {
        return $this->as($this->admin);
    }

    protected function as(User $user): static
    {
        // Guard explicito: despues de un `actingAs` del cliente, el guard por defecto es el suyo.
        return $this->actingAs($user, 'web')->withSession($this->withActiveStore());
    }
}

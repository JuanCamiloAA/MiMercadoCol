<?php

namespace Tests\Feature;

use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Services\CreditService;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

class SaleRefundTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
    }

    public function test_una_devolucion_parcial_regresa_las_existencias_y_resta_en_el_dia(): void
    {
        $admin = $this->makeUser('admin');
        $product = $this->makeProduct(['price' => 10000, 'tax_rate' => 0], 50);

        $session = app(RegisterSessionService::class)->open($this->register, $admin, 200000);

        $sale = app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 50000, 'received_amount' => 50000]],
        ], $admin, $this->store, $session);

        $item = $sale->items->first();

        $refund = app(SaleService::class)->refund(
            $sale,
            [['sale_item_id' => $item->id, 'quantity' => 2]],
            $admin,
            $this->store,
            $session,
            SalePayment::METHOD_CASH,
            'El cliente se llevo de mas',
        );

        $this->assertSame(Sale::STATUS_REFUNDED, $refund->status);
        $this->assertSame(-20000, $refund->total);
        $this->assertSame(-20000, $refund->payments->first()->amount);

        $stock = ProductStock::query()->withoutGlobalScopes()->where('product_id', $product->id)->first();
        $this->assertEqualsWithDelta(47, $stock->quantity, 0.001);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_RETURN,
        ]);

        // La venta original no se toca: la devolucion vive aparte y resta el dia en que ocurrio.
        $this->assertSame(50000, $sale->fresh()->total);

        $netos = (int) Sale::query()->withoutGlobalScopes()->where('status', '!=', Sale::STATUS_CANCELLED)->sum('total');
        $this->assertSame(30000, $netos);
    }

    public function test_no_se_puede_devolver_mas_de_lo_vendido(): void
    {
        $admin = $this->makeUser('admin');
        $product = $this->makeProduct(['price' => 10000, 'tax_rate' => 0], 50);

        $session = app(RegisterSessionService::class)->open($this->register, $admin, 200000);

        $sale = app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 30000]],
        ], $admin, $this->store, $session);

        $item = $sale->items->first();

        app(SaleService::class)->refund(
            $sale,
            [['sale_item_id' => $item->id, 'quantity' => 2]],
            $admin,
            $this->store,
            $session,
            SalePayment::METHOD_CASH,
            'Primera devolucion',
        );

        $this->expectException(ValidationException::class);

        app(SaleService::class)->refund(
            $sale->fresh(),
            [['sale_item_id' => $item->id, 'quantity' => 2]],
            $admin,
            $this->store,
            $session,
            SalePayment::METHOD_CASH,
            'Segunda devolucion',
        );
    }

    public function test_devolver_una_venta_a_credito_baja_la_deuda(): void
    {
        $admin = $this->makeUser('admin');
        $customer = $this->makeCustomer(['credit_enabled' => true, 'credit_limit' => 500000, 'credit_term_days' => 15]);
        $product = $this->makeProduct(['price' => 10000, 'tax_rate' => 0], 50);

        $session = app(RegisterSessionService::class)->open($this->register, $admin, 200000);

        $sale = app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
            'payments' => [['method' => SalePayment::METHOD_CREDIT, 'amount' => 40000]],
            'customer_id' => $customer->id,
        ], $admin, $this->store, $session);

        $this->assertSame(40000, app(CreditService::class)->currentBalance($customer->fresh()));

        app(SaleService::class)->refund(
            $sale,
            [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
            $admin,
            $this->store,
            $session,
            SalePayment::METHOD_CASH,
            'Producto en mal estado',
        );

        // Lo devuelto se descuenta de lo que quedaba a credito, no se entrega en efectivo.
        $this->assertSame(30000, app(CreditService::class)->currentBalance($customer->fresh()));
        $this->assertSame(30000, $sale->fresh()->due_total);
    }

    public function test_la_devolucion_sale_de_la_caja_del_turno(): void
    {
        $admin = $this->makeUser('admin');
        $product = $this->makeProduct(['price' => 10000, 'tax_rate' => 0], 50);

        $sessions = app(RegisterSessionService::class);
        $session = $sessions->open($this->register, $admin, 200000);

        $sale = app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 50000, 'received_amount' => 50000]],
        ], $admin, $this->store, $session);

        $this->assertSame(250000, $sessions->expectedAmount($session));

        app(SaleService::class)->refund(
            $sale,
            [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
            $admin,
            $this->store,
            $session,
            SalePayment::METHOD_CASH,
            'Devolucion',
        );

        $this->assertSame(230000, $sessions->expectedAmount($session));
    }
}

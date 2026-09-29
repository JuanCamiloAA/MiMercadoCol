<?php

namespace Tests\Feature;

use App\Models\ProductStock;
use App\Models\RegisterSession;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

class SaleTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
    }

    public function test_una_venta_en_efectivo_descuenta_stock_y_deja_movimiento(): void
    {
        $cashier = $this->makeUser('cajero');
        $product = $this->makeProduct(['price' => 5100, 'tax_rate' => 0, 'cost' => 3600], 40);

        app(RegisterSessionService::class)->open($this->register, $cashier, 200000);

        $sale = app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 15300, 'received_amount' => 20000]],
        ], $cashier, $this->store, app(RegisterSessionService::class)->openSessionFor($cashier, $this->store->id));

        $this->assertSame(15300, $sale->total);
        $this->assertSame(Sale::STATUS_PAID, $sale->status);
        $this->assertSame('C1-000001', $sale->number);

        $stock = ProductStock::query()->withoutGlobalScopes()->where('product_id', $product->id)->first();
        $this->assertEqualsWithDelta(37, $stock->quantity, 0.001);

        $movement = StockMovement::query()->withoutGlobalScopes()->where('product_id', $product->id)->latest('id')->first();
        $this->assertSame(StockMovement::TYPE_SALE, $movement->type);
        $this->assertEqualsWithDelta(-3, $movement->quantity, 0.001);
        $this->assertEqualsWithDelta(37, $movement->balance_after, 0.001);

        $payment = $sale->payments->first();
        $this->assertSame(20000, $payment->received_amount);
        $this->assertSame(4700, $payment->change_amount);
    }

    public function test_los_totales_cuadran_con_el_iva_incluido(): void
    {
        $cashier = $this->makeUser('cajero');
        $product = $this->makeProduct(['price' => 11900, 'tax_rate' => 19, 'cost' => 8000], 20);

        app(RegisterSessionService::class)->open($this->register, $cashier, 100000);
        $session = app(RegisterSessionService::class)->openSessionFor($cashier, $this->store->id);

        $sale = app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payments' => [['method' => SalePayment::METHOD_CARD, 'amount' => 23800]],
        ], $cashier, $this->store, $session);

        $expectedBase = Money::baseFromGross(23800, 19);

        $this->assertSame(23800, $sale->total);
        $this->assertSame($expectedBase, $sale->subtotal);
        $this->assertSame(23800 - $expectedBase, $sale->tax_total);
        $this->assertSame($sale->subtotal + $sale->tax_total, $sale->total);
    }

    public function test_no_se_puede_vender_mas_de_lo_que_hay(): void
    {
        $cashier = $this->makeUser('cajero');
        $product = $this->makeProduct([], 2);

        app(RegisterSessionService::class)->open($this->register, $cashier, 100000);
        $session = app(RegisterSessionService::class)->openSessionFor($cashier, $this->store->id);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 25500]],
        ], $cashier, $this->store, $session);
    }

    public function test_anular_la_venta_devuelve_las_existencias(): void
    {
        $admin = $this->makeUser('admin');
        $product = $this->makeProduct([], 30);

        app(RegisterSessionService::class)->open($this->register, $admin, 100000);
        $session = app(RegisterSessionService::class)->openSessionFor($admin, $this->store->id);

        $sale = app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 20400]],
        ], $admin, $this->store, $session);

        app(SaleService::class)->cancel($sale, $admin, 'Cliente se arrepintio');

        $this->assertSame(Sale::STATUS_CANCELLED, $sale->fresh()->status);
        $this->assertEqualsWithDelta(
            30,
            ProductStock::query()->withoutGlobalScopes()->where('product_id', $product->id)->value('quantity'),
            0.001
        );
    }

    public function test_el_consecutivo_no_se_repite_entre_ventas(): void
    {
        $cashier = $this->makeUser('cajero');
        $product = $this->makeProduct([], 100);

        app(RegisterSessionService::class)->open($this->register, $cashier, 100000);
        $session = RegisterSession::query()->withoutGlobalScopes()->firstOrFail();

        $numbers = [];

        for ($index = 0; $index < 5; $index++) {
            $sale = app(SaleService::class)->create([
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 5100]],
            ], $cashier, $this->store, $session);

            $numbers[] = $sale->number;
        }

        $this->assertSame($numbers, array_unique($numbers));
        $this->assertSame('C1-000005', end($numbers));
    }
}

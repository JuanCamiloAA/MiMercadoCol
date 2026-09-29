<?php

namespace Tests\Feature;

use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Services\CreditService;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

class CreditSaleTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
    }

    public function test_una_venta_a_credito_sube_el_saldo_y_baja_el_cupo(): void
    {
        $admin = $this->makeUser('admin');
        $customer = $this->makeCustomer(['credit_enabled' => true, 'credit_limit' => 500000, 'credit_term_days' => 15]);
        $product = $this->makeProduct(['price' => 10000, 'tax_rate' => 0], 50);

        app(RegisterSessionService::class)->open($this->register, $admin, 100000);
        $session = app(RegisterSessionService::class)->openSessionFor($admin, $this->store->id);

        $sale = app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 6]],
            'payments' => [['method' => SalePayment::METHOD_CREDIT, 'amount' => 60000]],
            'customer_id' => $customer->id,
        ], $admin, $this->store, $session);

        $this->assertSame(Sale::STATUS_CREDIT, $sale->status);
        $this->assertSame(60000, $sale->due_total);
        $this->assertSame(0, $sale->paid_total);
        $this->assertNotNull($sale->due_date);

        $credit = app(CreditService::class);
        $customer = $customer->fresh();

        $this->assertSame(60000, $credit->currentBalance($customer));
        $this->assertSame(440000, $credit->availableCredit($customer));
    }

    public function test_se_rechaza_la_venta_que_supera_el_cupo(): void
    {
        $admin = $this->makeUser('admin');
        $customer = $this->makeCustomer(['credit_enabled' => true, 'credit_limit' => 50000, 'credit_term_days' => 15]);
        $product = $this->makeProduct(['price' => 10000, 'tax_rate' => 0], 50);

        app(RegisterSessionService::class)->open($this->register, $admin, 100000);
        $session = app(RegisterSessionService::class)->openSessionFor($admin, $this->store->id);

        try {
            app(SaleService::class)->create([
                'items' => [['product_id' => $product->id, 'quantity' => 8]],
                'payments' => [['method' => SalePayment::METHOD_CREDIT, 'amount' => 80000]],
                'customer_id' => $customer->id,
            ], $admin, $this->store, $session);

            $this->fail('Debio rechazar la venta por cupo insuficiente.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('cupo disponible', $exception->errors()['credit'][0]);
        }

        $this->assertSame(0, Sale::query()->withoutGlobalScopes()->count());
    }

    public function test_se_rechaza_la_venta_a_un_cliente_bloqueado(): void
    {
        $admin = $this->makeUser('admin');
        $customer = $this->makeCustomer([
            'credit_enabled' => true,
            'credit_limit' => 500000,
            'credit_blocked' => true,
            'blocked_reason' => 'Facturas vencidas',
        ]);
        $product = $this->makeProduct(['price' => 10000, 'tax_rate' => 0], 50);

        app(RegisterSessionService::class)->open($this->register, $admin, 100000);
        $session = app(RegisterSessionService::class)->openSessionFor($admin, $this->store->id);

        try {
            app(SaleService::class)->create([
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payments' => [['method' => SalePayment::METHOD_CREDIT, 'amount' => 10000]],
                'customer_id' => $customer->id,
            ], $admin, $this->store, $session);

            $this->fail('Debio rechazar la venta por credito bloqueado.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Facturas vencidas', $exception->errors()['credit'][0]);
        }
    }

    public function test_un_cajero_sin_permiso_no_puede_vender_a_credito(): void
    {
        $cashier = $this->makeUser('cajero');
        $customer = $this->makeCustomer(['credit_enabled' => true, 'credit_limit' => 500000]);
        $product = $this->makeProduct(['price' => 10000, 'tax_rate' => 0], 50);

        app(RegisterSessionService::class)->open($this->register, $cashier, 100000);
        $session = app(RegisterSessionService::class)->openSessionFor($cashier, $this->store->id);

        try {
            app(SaleService::class)->create([
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payments' => [['method' => SalePayment::METHOD_CREDIT, 'amount' => 10000]],
                'customer_id' => $customer->id,
            ], $cashier, $this->store, $session);

            $this->fail('El cajero no deberia poder vender a credito.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('permiso', $exception->errors()['credit'][0]);
        }
    }

    public function test_un_abono_baja_el_saldo_y_libera_cupo(): void
    {
        $admin = $this->makeUser('admin');
        $customer = $this->makeCustomer(['credit_enabled' => true, 'credit_limit' => 500000, 'credit_term_days' => 15]);
        $product = $this->makeProduct(['price' => 10000, 'tax_rate' => 0], 50);

        app(RegisterSessionService::class)->open($this->register, $admin, 100000);
        $session = app(RegisterSessionService::class)->openSessionFor($admin, $this->store->id);

        app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 6]],
            'payments' => [['method' => SalePayment::METHOD_CREDIT, 'amount' => 60000]],
            'customer_id' => $customer->id,
        ], $admin, $this->store, $session);

        $this->actingAs($admin)
            ->withSession($this->withActiveStore())
            ->post(route('credit.payments.store', $customer), [
                'amount' => 25000,
                'method' => CustomerPayment::METHOD_CASH,
            ])
            ->assertSessionHasNoErrors();

        $credit = app(CreditService::class);
        $customer = $customer->fresh();

        $this->assertSame(35000, $credit->currentBalance($customer));
        $this->assertSame(465000, $credit->availableCredit($customer));

        $sale = Sale::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(25000, $sale->paid_total);
        $this->assertSame(35000, $sale->due_total);
    }

    public function test_el_abono_salda_la_factura_y_la_marca_pagada(): void
    {
        $admin = $this->makeUser('admin');
        $customer = $this->makeCustomer(['credit_enabled' => true, 'credit_limit' => 500000, 'credit_term_days' => 15]);
        $product = $this->makeProduct(['price' => 10000, 'tax_rate' => 0], 50);

        app(RegisterSessionService::class)->open($this->register, $admin, 100000);
        $session = app(RegisterSessionService::class)->openSessionFor($admin, $this->store->id);

        app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'payments' => [['method' => SalePayment::METHOD_CREDIT, 'amount' => 30000]],
            'customer_id' => $customer->id,
        ], $admin, $this->store, $session);

        app(CreditService::class)->registerPayment($customer->fresh(), 30000, CustomerPayment::METHOD_CASH, $this->store->id, $admin);

        $sale = Sale::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(Sale::STATUS_PAID, $sale->status);
        $this->assertSame(0, $sale->due_total);
        $this->assertSame(0, app(CreditService::class)->currentBalance($customer->fresh()));
    }
}

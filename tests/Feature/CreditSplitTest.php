<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\RegisterSession;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Cobro mitad de contado y mitad fiado.
 *
 * Lo que se cuida aqui es que a cartera entre solo la linea de credito y no el total de la
 * venta: fiar 4.200 de una venta de 12.200 no puede dejarle al cliente una deuda de 12.200.
 */
class CreditSplitTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $cashier;

    protected Customer $customer;

    protected Product $product;

    protected RegisterSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->cashier = $this->makeUser('admin');
        $this->customer = $this->makeCustomer([
            'credit_enabled' => true,
            'credit_limit' => 100000,
            'credit_term_days' => 15,
        ]);
        $this->product = $this->makeProduct(['price' => 6100, 'tax_rate' => 0], 80);
        $this->session = app(RegisterSessionService::class)->open($this->register, $this->cashier, 100000);
    }

    public function test_efectivo_mas_credito_deja_la_venta_a_credito_con_los_totales_partidos(): void
    {
        $sale = $this->sell([
            ['method' => SalePayment::METHOD_CASH, 'amount' => 8000],
            ['method' => SalePayment::METHOD_CREDIT, 'amount' => 4200],
        ]);

        $this->assertSame(Sale::STATUS_CREDIT, $sale->status);
        $this->assertSame(12200, $sale->total);
        $this->assertSame(8000, $sale->paid_total);
        $this->assertSame(4200, $sale->due_total);
        $this->assertSame(now()->addDays(15)->toDateString(), $sale->due_date->toDateString());

        // A cartera entra solo lo fiado.
        $this->assertSame(4200, (int) $this->customer->fresh()->balance->balance);
    }

    public function test_el_cambio_del_efectivo_no_se_lleva_por_delante_el_credito(): void
    {
        $sale = $this->sell([
            ['method' => SalePayment::METHOD_CASH, 'amount' => 10000],
            ['method' => SalePayment::METHOD_CREDIT, 'amount' => 2200],
        ]);

        $cash = $sale->payments->firstWhere('method', SalePayment::METHOD_CASH);

        $this->assertSame(0, (int) $cash->change_amount);
        $this->assertSame(10000, $sale->paid_total);
        $this->assertSame(2200, $sale->due_total);
    }

    public function test_rechaza_cuando_el_credito_supera_el_cupo_disponible(): void
    {
        $this->customer->update(['credit_limit' => 3000]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cupo disponible');

        $this->sell([
            ['method' => SalePayment::METHOD_CASH, 'amount' => 8000],
            ['method' => SalePayment::METHOD_CREDIT, 'amount' => 4200],
        ]);
    }

    public function test_rechaza_si_el_cajero_no_tiene_el_permiso_de_credito(): void
    {
        $cashier = $this->makeUser('cajero');
        $session = app(RegisterSessionService::class)->open(
            $this->makeSecondRegister(),
            $cashier,
            100000,
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('permiso para vender a credito');

        app(SaleService::class)->create([
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
            'payments' => [
                ['method' => SalePayment::METHOD_CASH, 'amount' => 8000],
                ['method' => SalePayment::METHOD_CREDIT, 'amount' => 4200],
            ],
            'customer_id' => $this->customer->id,
        ], $cashier, $this->store, $session);
    }

    public function test_sin_cliente_no_hay_credito(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('elegir un cliente');

        app(SaleService::class)->create([
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
            'payments' => [
                ['method' => SalePayment::METHOD_CASH, 'amount' => 8000],
                ['method' => SalePayment::METHOD_CREDIT, 'amount' => 4200],
            ],
        ], $this->cashier, $this->store, $this->session);
    }

    public function test_la_segunda_caja_no_puede_pasarse_del_cupo(): void
    {
        $this->customer->update(['credit_limit' => 6000]);

        $this->sell([
            ['method' => SalePayment::METHOD_CASH, 'amount' => 8000],
            ['method' => SalePayment::METHOD_CREDIT, 'amount' => 4200],
        ]);

        // Al cliente ya solo le quedan 1.800 de cupo: el segundo cobro no cabe.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cupo disponible');

        $this->sell([
            ['method' => SalePayment::METHOD_CASH, 'amount' => 8000],
            ['method' => SalePayment::METHOD_CREDIT, 'amount' => 4200],
        ]);
    }

    protected function makeSecondRegister(): \App\Models\Register
    {
        return \App\Models\Register::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'name' => 'Caja 2',
            'code' => 'C2',
            'is_active' => true,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     */
    protected function sell(array $payments): Sale
    {
        return app(SaleService::class)->create([
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
            'payments' => $payments,
            'customer_id' => $this->customer->id,
        ], $this->cashier, $this->store, $this->session);
    }
}

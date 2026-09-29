<?php

namespace Tests\Feature;

use App\Models\SalePayment;
use App\Services\DashboardService;
use App\Services\RegisterSessionService;
use App\Services\ReportService;
use App\Services\SaleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

class ReportConsistencyTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
    }

    public function test_el_informe_del_dia_coincide_con_el_panel(): void
    {
        $cashier = $this->makeUser('cajero');
        $product = $this->makeProduct(['price' => 10000, 'tax_rate' => 0], 200);

        $session = app(RegisterSessionService::class)->open($this->register, $cashier, 200000);

        foreach ([2, 5, 3] as $quantity) {
            app(SaleService::class)->create([
                'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
                'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => $quantity * 10000]],
            ], $cashier, $this->store, $session);
        }

        $dashboard = app(DashboardService::class)->build($this->company->id, $this->store->id);

        $today = CarbonImmutable::today();
        $report = app(ReportService::class)->sales($this->company->id, $today, $today, $this->store->id, null);

        $this->assertSame(100000, $dashboard['metrics']['sales_total']['value']);
        $this->assertSame($dashboard['metrics']['sales_total']['value'], $report['summary']['total']);
        $this->assertSame($dashboard['metrics']['tickets']['value'], $report['summary']['tickets']);
        $this->assertSame(3, $report['summary']['tickets']);
    }

    public function test_una_venta_anulada_no_cuenta_en_ninguno_de_los_dos(): void
    {
        $admin = $this->makeUser('admin');
        $product = $this->makeProduct(['price' => 10000, 'tax_rate' => 0], 100);

        $session = app(RegisterSessionService::class)->open($this->register, $admin, 200000);

        $sale = app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 40000]],
        ], $admin, $this->store, $session);

        app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 20000]],
        ], $admin, $this->store, $session);

        app(SaleService::class)->cancel($sale, $admin, 'Error del cajero');

        $dashboard = app(DashboardService::class)->build($this->company->id, $this->store->id);
        $today = CarbonImmutable::today();
        $report = app(ReportService::class)->sales($this->company->id, $today, $today, $this->store->id, null);

        $this->assertSame(20000, $dashboard['metrics']['sales_total']['value']);
        $this->assertSame(20000, $report['summary']['total']);
        $this->assertSame(1, $report['summary']['tickets']);
    }
}

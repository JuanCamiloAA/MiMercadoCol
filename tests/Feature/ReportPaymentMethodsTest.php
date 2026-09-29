<?php

namespace Tests\Feature;

use App\Models\SalePayment;
use App\Services\RegisterSessionService;
use App\Services\ReportService;
use App\Services\SaleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Informe de ventas por medio de pago.
 *
 * Con el cobro repartido una misma venta alimenta dos columnas, asi que el informe tiene que
 * agrupar por linea de pago y no por venta. Y el efectivo va neto: el vuelto no es una venta.
 */
class ReportPaymentMethodsTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    public function test_una_venta_combinada_aporta_a_dos_medios(): void
    {
        $this->seedEnvironment();

        $cashier = $this->makeUser('admin');
        $product = $this->makeProduct(['price' => 6100, 'tax_rate' => 0], 80);
        $session = app(RegisterSessionService::class)->open($this->register, $cashier, 100000);

        app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payments' => [
                // 18.000 en efectivo deja 10.000 de vuelto: al informe solo entran 8.000.
                ['method' => SalePayment::METHOD_CASH, 'amount' => 18000, 'received_amount' => 18000],
                ['method' => SalePayment::METHOD_CARD, 'amount' => 4200, 'reference' => '4821'],
            ],
        ], $cashier, $this->store, $session);

        $today = CarbonImmutable::today();
        $report = app(ReportService::class)->sales($this->company->id, $today, $today, $this->store->id, null);
        $byMethod = collect($report['by_method'])->keyBy('method');

        $this->assertSame(8000, $byMethod['cash']['total']);
        $this->assertSame(4200, $byMethod['card']['total']);

        // Lo repartido suma exactamente el total de la venta.
        $this->assertSame(12200, $byMethod['cash']['total'] + $byMethod['card']['total']);
    }
}

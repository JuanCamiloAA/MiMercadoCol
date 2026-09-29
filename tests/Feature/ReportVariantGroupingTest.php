<?php

namespace Tests\Feature;

use App\Models\SalePayment;
use App\Models\User;
use App\Services\RegisterSessionService;
use App\Services\ReportService;
use App\Services\SaleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsVariants;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * «Mas vendidos» junta las presentaciones de un articulo en una linea con el nombre del principal,
 * y con la opcion apagada las separa. La plata es la misma de las dos maneras.
 */
class ReportVariantGroupingTest extends TestCase
{
    use BuildsVariants, CreatesCompanyEnvironment, RefreshDatabase;

    public function test_agrupado_y_separado_suman_lo_mismo(): void
    {
        $this->seedEnvironment();
        $this->seedAttributes();
        $admin = $this->makeUser('admin');
        $family = $this->makeFamily('Aceite de girasol', 'Volumen', null, [['500 ml', null, 8000], ['900 ml', null, 14000]], user: $admin);
        [$small, $big] = $family->variants()->get()->all();
        $rice = $this->makeProduct(['name' => 'Arroz', 'price' => 5000], 50);

        $session = app(RegisterSessionService::class)->open($this->register, $admin, 100000);
        app(SaleService::class)->create([
            'items' => [
                ['product_id' => $small->id, 'quantity' => 2],
                ['product_id' => $big->id, 'quantity' => 1],
                ['product_id' => $rice->id, 'quantity' => 1],
            ],
            'payments' => [['method' => SalePayment::METHOD_CASH, 'amount' => 100000]],
        ], $admin, $this->store, $session);

        $today = CarbonImmutable::today();
        $grouped = app(ReportService::class)->sales($this->company->id, $today, $today, null, null)['top_products'];
        $separate = app(ReportService::class)->sales($this->company->id, $today, $today, null, null, false)['top_products'];

        $oil = collect($grouped)->firstWhere('name', 'Aceite de girasol');
        $this->assertSame(30000, $oil['total']);
        $this->assertSame(3.0, $oil['quantity']);
        $this->assertSame(2, $oil['variants']);
        $this->assertCount(2, $grouped);

        $this->assertCount(3, $separate);
        $this->assertSame(16000, collect($separate)->firstWhere('name', 'Aceite de girasol · 500 ml')['total']);
        $this->assertSame(array_sum(array_column($grouped, 'total')), array_sum(array_column($separate, 'total')));

        $this->actingAs($admin)->get(route('reports.index', ['separate' => 1]))->assertInertia(fn ($page) => $page
            ->where('filters.separate', '1')
            ->has('sales.top_products', 3));
    }
}

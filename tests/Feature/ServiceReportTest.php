<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\SellsServicesAtPos;
use Tests\TestCase;

/**
 * Informes con servicios: el margen incluye lo que consumen, los articulos incluidos no son
 * «mas vendidos» y el consumo se ve aparte.
 */
class ServiceReportTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase, SellsServicesAtPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->seedServiceScenario();

        $this->ancheta->update(['category_id' => $this->makeCategory('Regalos')]);

        $this->sell([['service_id' => $this->ancheta->id, 'quantity' => 1, 'components' => [
            ['product_id' => $this->vino->id, 'quantity' => 1],
            ['product_id' => $this->galletas->id, 'quantity' => 1],
            ['product_id' => $this->chocolate->id, 'quantity' => 1],
        ]]])->assertSessionHasNoErrors();
        $this->sell([['service_id' => $this->arreglo->id, 'quantity' => 1]])->assertSessionHasNoErrors();
        $this->sell([['product_id' => $this->galletas->id, 'quantity' => 1]])->assertSessionHasNoErrors();
    }

    public function test_el_margen_incluye_el_costo_de_los_articulos(): void
    {
        $report = $this->report();

        // Ingreso: 60.000 + 14.000 + 5.000.
        // Costo: ancheta 5.000 + 20.000 + 3.000 + 2.000; arreglo 2 x 1.800 + 3 x 500; galletas 3.000.
        $this->assertSame(79000, $report['summary']['total']);
        $this->assertSame(30000 + 5100 + 3000, $report['summary']['cost']);
        $this->assertSame(79000 - 38100, $report['summary']['margin']);
    }

    public function test_mas_vendidos_sin_los_articulos_de_un_servicio_cerrado(): void
    {
        $top = collect($this->report()['top_products']);

        $this->assertNull($top->firstWhere('name', 'Vino tinto'));
        $this->assertNull($top->firstWhere('name', 'Chocolates'));
        // Las galletas sueltas si, y solo las sueltas.
        $this->assertEqualsWithDelta(1, $top->firstWhere('name', 'Galletas')['quantity'], 0.001);
        // Los servicios aparecen por su nombre, marcados.
        $this->assertTrue($top->firstWhere('name', 'Armado de ancheta')['is_service']);
        // Las frutas de un servicio detallado son ingreso de verdad.
        $this->assertSame(6000, $top->firstWhere('name', 'Fresa')['total']);
    }

    public function test_por_categoria_el_servicio_cuenta_en_la_suya(): void
    {
        $categories = collect($this->report()['by_category']);

        $this->assertSame(60000, $categories->firstWhere('name', 'Regalos')['total']);
        $this->assertEqualsWithDelta(1, $categories->firstWhere('name', 'Regalos')['quantity'], 0.001);
    }

    public function test_consumo_en_servicios(): void
    {
        $consumption = $this->report()['services'];

        $byProduct = collect($consumption['by_product']);
        $this->assertEqualsWithDelta(1, $byProduct->firstWhere('product_id', $this->galletas->id)['quantity'], 0.001);
        $this->assertSame(20000, $byProduct->firstWhere('product_id', $this->vino->id)['cost']);
        $this->assertEqualsWithDelta(3, $byProduct->firstWhere('product_id', $this->banano->id)['quantity'], 0.001);

        $ancheta = collect($consumption['by_service'])->firstWhere('service_id', $this->ancheta->id);
        $this->assertSame(1, $ancheta['times']);
        $this->assertSame(1, $ancheta['adjusted']);
        $this->assertSame(60000, $ancheta['revenue']);
        $this->assertSame(30000, $ancheta['margin']);

        $arreglo = collect($consumption['by_service'])->firstWhere('service_id', $this->arreglo->id);
        $this->assertSame(14000, $arreglo['revenue']);
        $this->assertSame(0, $arreglo['adjusted']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function report(): array
    {
        $today = CarbonImmutable::today();

        return app(ReportService::class)->sales($this->company->id, $today, $today, null, null);
    }

    protected function makeCategory(string $name): int
    {
        return Category::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => $name, 'slug' => Str::slug($name), 'is_active' => true,
        ])->id;
    }
}

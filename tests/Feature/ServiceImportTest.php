<?php

namespace Tests\Feature;

use App\Imports\ServiceImport;
use App\Models\Service;
use App\Models\User;
use App\Services\MasterImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\SellsServices;
use Tests\TestCase;

/**
 * Importar servicios con sus articulos, y que exportar, borrar y volver a importar deje lo mismo.
 */
class ServiceImportTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase, SellsServices;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->admin = $this->makeUser('admin');
    }

    public function test_la_columna_articulos_arma_los_componentes(): void
    {
        $vino = $this->makeProduct(['name' => 'Vino', 'sku' => 'LIC-001']);
        $queso = $this->makeProduct(['name' => 'Queso', 'sku' => 'LAC-009', 'unit' => 'kg', 'sold_by_weight' => true]);

        $result = $this->import([
            ['SRV-ANCHETA', 'Armado de ancheta', 'Despensa', '30000', '', '19', 'cerrado', 'no', '5000', 'si', 'no', 'LIC-001:2:si;LAC-009:0,5:no', ''],
        ]);

        $this->assertSame(1, $result['created'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $service = Service::query()->withoutGlobalScopes()->where('code', 'SRV-ANCHETA')->firstOrFail();
        $this->assertSame($this->category->id, $service->category_id);
        $this->assertCount(2, $service->components);
        $this->assertSame($vino->id, $service->components[0]->product_id);
        $this->assertEqualsWithDelta(0.5, $service->components[1]->quantity, 0.0001);
        $this->assertFalse($service->components[1]->returnable_by_default);
        $this->assertSame($queso->id, $service->components[1]->product_id);
    }

    public function test_un_sku_que_no_existe_hace_fallar_solo_esa_fila(): void
    {
        $this->makeProduct(['name' => 'Vino', 'sku' => 'LIC-001']);

        $result = $this->import([
            ['SRV-1', 'Bueno', '', '10000', '', '0', 'cerrado', 'no', '', 'si', 'no', 'LIC-001:1', ''],
            ['SRV-2', 'Malo', '', '10000', '', '0', 'cerrado', 'no', '', 'si', 'no', 'NO-EXISTE:1', ''],
        ]);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('el SKU NO-EXISTE no existe', json_encode($result['failures'], JSON_UNESCAPED_UNICODE));
        $this->assertFalse(Service::query()->withoutGlobalScopes()->where('code', 'SRV-2')->exists());
    }

    public function test_el_codigo_no_puede_ser_un_sku(): void
    {
        $this->makeProduct(['name' => 'Vino', 'sku' => 'LIC-001']);

        $result = $this->import([['LIC-001', 'Choque', '', '10000', '', '0', 'cerrado', 'no', '', 'si', 'no', '', '']]);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_el_precio_del_articulo_se_importa_solo_en_detallado(): void
    {
        $vino = $this->makeProduct(['name' => 'Vino', 'sku' => 'LIC-001', 'price' => 32000]);

        $result = $this->import([
            ['SRV-D', 'Detallado', '', '5000', '', '19', 'detallado', 'no', '', 'si', 'no', 'LIC-001:1:si:28000', ''],
            ['SRV-C', 'Cerrado', '', '30000', '', '19', 'cerrado', 'no', '', 'si', 'no', 'LIC-001:1:si:28000', ''],
            ['SRV-M', 'Mal precio', '', '5000', '', '19', 'detallado', 'no', '', 'si', 'no', 'LIC-001:1:si:28.000', ''],
        ]);

        $this->assertSame(2, $result['created'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('el precio de LIC-001 debe ser un número entero', json_encode($result['failures'], JSON_UNESCAPED_UNICODE));

        $detallado = Service::query()->withoutGlobalScopes()->where('code', 'SRV-D')->firstOrFail();
        $this->assertSame(28000, $detallado->components[0]->unit_price);
        $this->assertSame($vino->id, $detallado->components[0]->product_id);

        // En precio cerrado los articulos valen $0: el precio se ignora.
        $this->assertNull(Service::query()->withoutGlobalScopes()->where('code', 'SRV-C')->firstOrFail()->components[0]->unit_price);
    }

    public function test_exportar_borrar_e_importar_deja_lo_mismo(): void
    {
        $vino = $this->makeProduct(['name' => 'Vino', 'sku' => 'LIC-001']);
        $fruta = $this->makeProduct(['name' => 'Fresa', 'sku' => 'FRU-001', 'unit' => 'kg', 'sold_by_weight' => true]);
        $this->makeService('Armado de ancheta', 30000, [[$vino, 2]], attributes: ['code' => 'SRV-A', 'cost' => 4000, 'tax_rate' => 19]);
        // Un articulo al precio vigente y otro con precio fijo en el servicio: los dos vuelven igual.
        $this->makeService('Arreglo de frutas', 5000, [[$fruta, 0.75, false], [$vino, 1, true, 28000]], Service::PRICING_ITEMIZED, ['code' => 'SRV-F', 'is_online_visible' => true, 'online_price' => 5500]);
        $this->makeService('Recarga', 0, attributes: ['code' => 'SRV-R', 'open_price' => true]);

        $before = $this->snapshot();
        $csv = $this->actingAs($this->admin)->withSession($this->withActiveStore())->get(route('services.export'))->streamedContent();

        foreach (Service::query()->withoutGlobalScopes()->whereNull('deleted_at')->get() as $service) {
            $this->actingAs($this->admin)->withSession($this->withActiveStore())->delete(route('services.destroy', $service))->assertRedirect();
        }

        $this->assertSame(0, Service::query()->withoutGlobalScopes()->whereNull('deleted_at')->count());

        $path = tempnam(sys_get_temp_dir(), 'srv');
        file_put_contents($path, $csv);
        $result = app(MasterImportService::class)->run(new ServiceImport, $path, $this->company->id);

        $this->assertSame(3, $result['created'], json_encode($result['failures'] ?? [], JSON_UNESCAPED_UNICODE));
        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function snapshot(): array
    {
        return Service::query()->withoutGlobalScopes()->whereNull('deleted_at')->with('components')->orderBy('code')->get()
            ->map(fn (Service $service) => [
                'code' => $service->code, 'name' => $service->name, 'price' => $service->price, 'online_price' => $service->online_price,
                'cost' => $service->cost, 'tax_rate' => (float) $service->tax_rate, 'pricing_mode' => $service->pricing_mode,
                'open_price' => $service->open_price, 'is_active' => $service->is_active, 'is_online_visible' => $service->is_online_visible,
                'components' => $service->components->map(fn ($component) => [$component->product_id, (float) $component->quantity, $component->returnable_by_default, $component->unit_price])->all(),
            ])->all();
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array<string, mixed>
     */
    protected function import(array $rows): array
    {
        $path = tempnam(sys_get_temp_dir(), 'srv');
        $handle = fopen($path, 'w');

        // Como lo guarda Excel: la columna de articulos lleva punto y coma adentro y va entre comillas.
        foreach ([array_keys((new ServiceImport)->columns()), ...$rows] as $row) {
            fputcsv($handle, $row, ';');
        }

        fclose($handle);

        return app(MasterImportService::class)->run(new ServiceImport, $path, $this->company->id);
    }
}

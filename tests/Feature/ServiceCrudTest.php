<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\SellsServices;
use Tests\TestCase;

/**
 * El maestro de servicios: crear, editar, duplicar y borrar, con el codigo que el escaner no
 * puede confundir con un articulo.
 */
class ServiceCrudTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase, SellsServices;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->admin = $this->makeUser('admin');
    }

    public function test_se_crea_un_servicio_con_articulos(): void
    {
        $vino = $this->makeProduct(['name' => 'Vino tinto', 'sku' => 'LIC-001', 'cost' => 20000, 'price' => 32000]);
        $galletas = $this->makeProduct(['name' => 'Galletas', 'sku' => 'DUL-001', 'cost' => 3000, 'price' => 5000]);

        $this->as()->post(route('services.store'), [
            'code' => 'SRV-ANCHETA',
            'name' => 'Armado de ancheta',
            'price' => 60000,
            'cost' => 5000,
            'tax_rate' => 19,
            'pricing_mode' => 'bundled',
            'is_active' => true,
            'components' => [
                ['product_id' => $vino->id, 'quantity' => 1, 'returnable_by_default' => true],
                ['product_id' => $galletas->id, 'quantity' => 2, 'returnable_by_default' => true],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $service = Service::query()->withoutGlobalScopes()->where('code', 'SRV-ANCHETA')->firstOrFail();
        $this->assertSame(60000, $service->price);
        $this->assertSame(5000, $service->cost);
        $this->assertCount(2, $service->components);
        $this->assertEqualsWithDelta(2, $service->components[1]->quantity, 0.001);

        $this->as()->get(route('services.show', $service))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Services/Show')
                ->where('service.price_pos', 60000)
                // Costo propio mas los articulos: 5.000 + 20.000 + 2 x 3.000.
                ->where('service.cost_total', 31000)
                ->has('components', 2));

        $this->as()->get(route('services.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Services/Index')->has('services.data', 1));
        $this->as()->get(route('services.edit', $service))->assertOk();
        $this->as()->get(route('services.create'))->assertOk();
    }

    public function test_solo_por_valor_queda_con_precio_cerrado(): void
    {
        $this->as()->post(route('services.store'), [
            'code' => 'SRV-VACIO', 'name' => 'Porcionado al vacío', 'price' => 3000, 'tax_rate' => 19,
            'pricing_mode' => 'itemized', 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame(Service::PRICING_BUNDLED, Service::query()->withoutGlobalScopes()->where('code', 'SRV-VACIO')->value('pricing_mode'));
    }

    public function test_precio_variable_no_se_publica_y_guarda_cero(): void
    {
        $this->as()->post(route('services.store'), [
            'code' => 'SRV-RECARGA', 'name' => 'Recarga de celular', 'price' => 99999, 'tax_rate' => 0,
            'pricing_mode' => 'bundled', 'open_price' => true, 'is_active' => true, 'is_online_visible' => true,
        ])->assertSessionHasNoErrors();

        $service = Service::query()->withoutGlobalScopes()->where('code', 'SRV-RECARGA')->firstOrFail();
        $this->assertTrue($service->open_price);
        $this->assertSame(0, $service->price);
        $this->assertFalse($service->is_online_visible);

        $this->as()->patch(route('services.online', $service), ['value' => true])->assertSessionHas('error');
        $this->assertFalse($service->refresh()->is_online_visible);
    }

    public function test_sin_precio_variable_el_precio_debe_ser_positivo(): void
    {
        $this->as()->post(route('services.store'), [
            'code' => 'SRV-X', 'name' => 'Sin precio', 'price' => 0, 'tax_rate' => 0, 'pricing_mode' => 'bundled',
        ])->assertSessionHasErrors('price');
    }

    public function test_el_codigo_no_puede_ser_el_sku_de_un_articulo_ni_al_reves(): void
    {
        $arroz = $this->makeProduct(['name' => 'Arroz', 'sku' => 'ARR-001', 'barcode' => '7701234567890']);

        $this->as()->post(route('services.store'), [
            'code' => 'ARR-001', 'name' => 'Choque', 'price' => 1000, 'tax_rate' => 0, 'pricing_mode' => 'bundled',
        ])->assertSessionHasErrors('code');

        $this->as()->post(route('services.store'), [
            'code' => 'SRV-1', 'barcode' => '7701234567890', 'name' => 'Choque', 'price' => 1000, 'tax_rate' => 0, 'pricing_mode' => 'bundled',
        ])->assertSessionHasErrors('barcode');

        $service = $this->makeService('Porcionado', 3000, attributes: ['code' => 'SRV-PORC', 'barcode' => '5550001']);

        // Al reves: un articulo no puede tomar el codigo de un servicio.
        $this->as()->post(route('products.store'), [
            'category_id' => $this->category->id, 'name' => 'Choque', 'sku' => 'SRV-PORC', 'unit' => 'unit',
            'cost' => 100, 'price' => 200, 'tax_rate' => 0, 'min_stock' => 0,
        ])->assertSessionHasErrors('sku');

        $this->as()->put(route('products.update', $arroz), [
            'category_id' => $this->category->id, 'name' => 'Arroz', 'sku' => 'ARR-001', 'barcode' => '5550001', 'unit' => 'unit',
            'cost' => 100, 'price' => 200, 'tax_rate' => 0, 'min_stock' => 0,
        ])->assertSessionHasErrors('barcode');

        $this->assertNotNull($service->id);
    }

    public function test_editar_no_basta_para_cambiar_precios(): void
    {
        $service = $this->makeService('Porcionado', 3000);
        $editor = $this->makeUser('cajero');
        $editor->givePermissionTo(['services.index.view', 'services.index.edit', 'services.show.view']);

        $payload = fn (int $price) => [
            'code' => $service->code, 'name' => 'Porcionado y empaque', 'price' => $price, 'tax_rate' => 0, 'pricing_mode' => 'bundled', 'is_active' => true,
        ];

        $this->as($editor)->put(route('services.update', $service), $payload(3500))->assertSessionHasErrors('price');
        $this->assertSame(3000, $service->refresh()->price);

        // El mismo precio si deja guardar lo demas.
        $this->as($editor)->put(route('services.update', $service), $payload(3000))->assertSessionHasNoErrors();
        $this->assertSame('Porcionado y empaque', $service->refresh()->name);

        $editor->givePermissionTo('services.show.edit_price');
        $this->as($editor->fresh())->put(route('services.update', $service), $payload(3500))->assertSessionHasNoErrors();
        $this->assertSame(3500, $service->refresh()->price);
    }

    public function test_duplicar_deja_una_copia_inactiva_con_otro_codigo(): void
    {
        $leche = $this->makeProduct(['name' => 'Leche']);
        $service = $this->makeService('Ancheta', 20000, [[$leche, 2]], attributes: ['code' => 'SRV-A', 'barcode' => '999', 'is_online_visible' => true]);

        $this->as()->post(route('services.duplicate', $service))->assertRedirect();

        $copy = Service::query()->withoutGlobalScopes()->where('code', 'SRV-A-2')->firstOrFail();
        $this->assertFalse($copy->is_active);
        $this->assertFalse($copy->is_online_visible);
        $this->assertNull($copy->barcode);
        $this->assertCount(1, $copy->components);
    }

    public function test_eliminar_libera_el_codigo(): void
    {
        $service = $this->makeService('Porcionado', 3000, attributes: ['code' => 'SRV-P']);

        $this->as()->delete(route('services.destroy', $service))->assertRedirect(route('services.index'));

        $this->assertSoftDeleted('services', ['id' => $service->id]);

        // El codigo se puede volver a usar.
        $this->as()->post(route('services.store'), [
            'code' => 'SRV-P', 'name' => 'Porcionado nuevo', 'price' => 3000, 'tax_rate' => 0, 'pricing_mode' => 'bundled',
        ])->assertSessionHasNoErrors();
    }

    public function test_un_cajero_ve_servicios_pero_no_crea(): void
    {
        $cajero = $this->makeUser('cajero');
        $this->makeService('Porcionado', 3000);

        $this->as($cajero)->get(route('services.index'))->assertOk();
        $this->as($cajero)->get(route('services.create'))->assertForbidden();
        $this->assertFalse($cajero->can('services.index.create'));
        $this->assertFalse($cajero->can('pos.sale.service_add'));
        $this->assertTrue($cajero->can('pos.sale.service_remove'));
    }

    public function test_un_servicio_de_otra_empresa_no_se_abre(): void
    {
        $other = $this->makeOtherCompany();
        $ajeno = Service::query()->withoutGlobalScopes()->create([
            'company_id' => $other['company']->id, 'code' => 'SRV-AJENO', 'name' => 'Ajeno', 'price' => 1000, 'tax_rate' => 0,
            'pricing_mode' => 'bundled', 'is_active' => true,
        ]);

        $status = $this->as()->get(route('services.show', $ajeno->id))->getStatusCode();
        $this->assertContains($status, [403, 404]);
        $this->assertContains($this->as()->delete(route('services.destroy', $ajeno->id))->getStatusCode(), [403, 404]);
        $this->assertNotSoftDeleted('services', ['id' => $ajeno->id]);
    }

    public function test_exportar_da_las_columnas_de_la_importacion(): void
    {
        $leche = $this->makeProduct(['name' => 'Leche', 'sku' => 'LAC-001']);
        $this->makeService('Arreglo', 5000, [[$leche, 2, false]], Service::PRICING_ITEMIZED, ['code' => 'SRV-ARR']);

        $csv = $this->as()->get(route('services.export'))->streamedContent();

        $this->assertStringContainsString('codigo;nombre;categoria;precio', $csv);
        $this->assertStringContainsString('LAC-001:2:no', $csv);
        $this->assertStringContainsString('detallado', $csv);
    }

    protected function as(?User $user = null): static
    {
        return $this->actingAs($user ?? $this->admin)->withSession($this->withActiveStore());
    }
}

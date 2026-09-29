<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PlatformImportBatch;
use App\Models\Product;
use App\Models\Register;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Plataforma > Carga masiva: mismo lector, empresa destino propia, vista previa y bitacora.
 */
class PlatformImportTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $super;

    protected array $other;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedEnvironment();
        $this->super = $this->makeSuperAdmin();
        $this->other = $this->makeOtherCompany();
    }

    protected function csv(array $lines, string $name = 'carga.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, implode("\n", $lines)."\n");
    }

    protected function previewAs(string $type, array $lines, ?int $companyId = null)
    {
        return $this->actingAs($this->super)
            // Arriba esta elegida La Canasta: la carga no debe depender de eso.
            ->withSession([TenantContext::SESSION_KEY => $this->company->id])
            ->post(route('platform.imports.preview'), array_filter([
                'type' => $type,
                'company_id' => $companyId,
                'file' => $this->csv($lines),
            ], fn ($value) => $value !== null));
    }

    public function test_los_articulos_van_a_la_empresa_del_formulario_no_a_la_de_arriba(): void
    {
        $category = \App\Models\Category::query()->withoutGlobalScopes()->create([
            'company_id' => $this->other['company']->id, 'name' => 'Granos', 'slug' => 'granos', 'is_active' => true,
        ]);

        $lines = [
            'sku;nombre;categoria;precio;costo;iva;unidad;codigo_barras;minimo;activo',
            'VEC-ARROZ;Arroz Vecino 1 kg;Granos;5200;3900;0;unit;;10;si',
            'VEC-FRIJOL;Fríjol Vecino 500 g;Granos;6800;5100;0;unit;;10;si',
        ];

        $this->previewAs('products', $lines, $this->other['company']->id)->assertRedirect(route('platform.imports.index'));

        // La vista previa no guarda nada.
        $this->assertSame(0, Product::query()->withoutGlobalScopes()->count());
        $batch = PlatformImportBatch::query()->sole();
        $this->assertSame(PlatformImportBatch::STATUS_PENDING, $batch->status);
        $this->assertSame(2, session('platform_import_preview')['created']);

        $this->actingAs($this->super)
            ->withSession([TenantContext::SESSION_KEY => $this->company->id])
            ->post(route('platform.imports.process', $batch))
            ->assertSessionHas('success');

        $this->assertSame(2, Product::query()->withoutGlobalScopes()->where('company_id', $this->other['company']->id)->count());
        $this->assertSame(0, Product::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame($category->id, Product::query()->withoutGlobalScopes()->where('sku', 'VEC-ARROZ')->value('category_id'));

        $batch->refresh();
        $this->assertSame(PlatformImportBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame(2, $batch->rows_success);

        // Una corrida procesada no se vuelve a procesar.
        $this->actingAs($this->super)->post(route('platform.imports.process', $batch))->assertSessionHas('error');
        $this->assertSame(2, Product::query()->withoutGlobalScopes()->count());
    }

    public function test_tres_empresas_quedan_aprovisionadas_completas(): void
    {
        Notification::fake();
        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan(['name' => 'Pro', 'slug' => 'pro', 'max_stores' => 3], ['online_store']);

        $this->previewAs('companies', [
            'nombre;nit;razon_social;correo;telefono;ciudad;plan;ciclo;sede;codigo_sede;direccion_sede;latitud;longitud;admin_nombre;admin_apellido;admin_correo',
            'Mercado Uno;901000001-1;;uno@mercado.co;;Medellín;pro;monthly;Centro;CEN;;6.2442;-75.5812;Ana;Uno;ana@uno.co',
            'Mercado Dos;901000002-2;;dos@mercado.co;;Bello;Pro;Mensual;;;;;;Beto;Dos;beto@dos.co',
            'Mercado Tres;901000003-3;;tres@mercado.co;;Envigado;pro;monthly;Sur;SUR;;;;Cata;Tres;cata@tres.co',
            'Repetida;900111222-3;;rep@mercado.co;;;pro;monthly;;;;;;Dan;Rep;dan@rep.co',
            'Plan raro;901000005-5;;raro@mercado.co;;;oro;monthly;;;;;;Eva;Raro;eva@raro.co',
        ]);

        // En la vista previa no sale ningun correo.
        Notification::assertNothingSent();
        $this->assertSame(2, Company::query()->count());

        $batch = PlatformImportBatch::query()->sole();
        $this->actingAs($this->super)->post(route('platform.imports.process', $batch))->assertSessionHas('warning');

        foreach (['901000001-1', '901000002-2', '901000003-3'] as $taxId) {
            $company = Company::query()->where('tax_id', $taxId)->sole();
            $this->assertSame($plan->id, $company->membership_plan_id);
            $this->assertSame($cycle->id, $company->billing_cycle_id);

            $store = Store::query()->withoutGlobalScopes()->where('company_id', $company->id)->sole();
            $this->assertSame(1, Warehouse::query()->withoutGlobalScopes()->where('store_id', $store->id)->count());
            $this->assertSame(1, Register::query()->withoutGlobalScopes()->where('company_id', $company->id)->count());

            $admin = User::query()->where('company_id', $company->id)->sole();
            $this->assertTrue($admin->hasRole('admin'));
            Notification::assertSentTo($admin, ResetPassword::class);
        }

        $this->assertEqualsWithDelta(6.2442, (float) Store::query()->withoutGlobalScopes()->where('code', 'CEN')->whereHas('company', fn ($q) => $q->where('tax_id', '901000001-1'))->value('latitude'), 0.0001);

        $batch->refresh();
        $this->assertSame(PlatformImportBatch::STATUS_PARTIAL, $batch->status);
        $this->assertSame(3, $batch->rows_success);
        $this->assertSame(2, $batch->rows_failed);

        // El reporte de errores trae las filas tal cual, con el motivo.
        $report = Storage::disk('local')->get($batch->error_report_path);
        $this->assertStringContainsString('Repetida', $report);
        $this->assertStringContainsString('ya existe', $report);
        $this->assertStringContainsString('el plan «oro» no existe', $report);
        $this->assertStringNotContainsString('Mercado Uno', $report);

        $this->actingAs($this->super)->get(route('platform.imports.errors', $batch))->assertOk();
        $this->actingAs($this->super)->get(route('platform.imports.original', $batch))->assertOk();
    }

    public function test_los_topes_del_plan_se_respetan_en_la_carga(): void
    {
        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan(['max_stores' => 1]);
        $this->other['company']->forceFill(['membership_plan_id' => $plan->id, 'billing_cycle_id' => $cycle->id])->save();

        $this->previewAs('stores', [
            'codigo;nombre;direccion;ciudad;latitud;longitud;telefono;abre;cierra;base_caja;activa',
            'BEL;Bello;;Bello;;;;07:00;21:00;150000;si',
        ], $this->other['company']->id);

        $preview = session('platform_import_preview');
        $this->assertSame(0, $preview['created']);
        $this->assertSame(1, $preview['skipped']);
        $this->assertStringContainsString('Tu plan permite 1 sede', $preview['sample'][0]['reason']);
    }

    public function test_sin_empresa_destino_no_se_carga_una_maestra(): void
    {
        $this->previewAs('products', ['sku;nombre', 'A;B'])->assertSessionHasErrors('company_id');
        $this->assertSame(0, PlatformImportBatch::query()->count());
    }

    public function test_descartar_borra_el_archivo(): void
    {
        $this->previewAs('categories', ['nombre;descripcion;activa', 'Aseo;;si'], $this->other['company']->id);
        $batch = PlatformImportBatch::query()->sole();
        Storage::disk('local')->assertExists($batch->stored_path);

        $this->actingAs($this->super)->delete(route('platform.imports.discard', $batch))->assertSessionHas('success');

        Storage::disk('local')->assertMissing($batch->stored_path);
        $this->assertSame(0, PlatformImportBatch::query()->count());
    }

    public function test_las_plantillas_se_descargan_sueltas_y_en_zip(): void
    {
        $csv = $this->actingAs($this->super)->get(route('platform.imports.template', 'companies'))->assertOk();
        $this->assertStringContainsString('nombre;nit;razon_social', $csv->getContent());

        $zip = $this->actingAs($this->super)->get(route('platform.imports.templates'))->assertOk();
        $this->assertSame('application/zip', $zip->headers->get('content-type'));
    }

    public function test_la_pantalla_abre_y_solo_para_el_super_usuario(): void
    {
        $this->actingAs($this->super)
            ->get(route('platform.imports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Imports/Index')
                ->where('types.0.value', 'companies')
                ->where('types.0.needs_company', false)
                ->count('companies', 2));

        $this->actingAs($this->makeUser('admin'))->get(route('platform.imports.index'))->assertForbidden();
    }
}

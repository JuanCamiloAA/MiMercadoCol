<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * El super usuario en «Todas las empresas»: ve la plataforma, no mezcla datos de empresas.
 */
class ConsolidatedModeTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->super = $this->makeSuperAdmin();
        $this->makeOtherCompany();
    }

    public function test_el_inicio_en_consolidado_es_el_resumen_de_la_plataforma(): void
    {
        $this->actingAs($this->super)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/PlatformOverview')
                ->where('overview.companies.total', 2)
                ->has('overview.expiring')
                ->has('overview.charges')
                ->where('isConsolidatedView', true)
                ->where('activeCompany', null)
                ->count('companiesForSelector', 2));
    }

    public function test_las_ventas_de_hoy_se_cuentan_por_empresa(): void
    {
        $admin = $this->makeUser('admin');
        $product = $this->makeProduct(['price' => 10000]);
        $session = app(RegisterSessionService::class)->open($this->register, $admin, 100000);

        app(SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payments' => [['method' => SalePayment::METHOD_CARD, 'amount' => 20000]],
        ], $admin, $this->store, $session);

        $this->actingAs($this->super)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/PlatformOverview')
                ->where('overview.sales_today.rows.0.name', 'La Canasta')
                ->where('overview.sales_today.rows.0.total', 20000)
                ->where('overview.sales_today.total', 20000));

        $this->assertSame(1, Sale::query()->withoutGlobalScopes()->count());
    }

    public function test_un_modulo_de_empresa_pide_elegir_una(): void
    {
        $this->actingAs($this->super)
            ->get('/productos?q=arroz')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/ChooseCompany')
                ->where('destination', '/productos?q=arroz')
                ->count('companies', 2));
    }

    public function test_una_escritura_en_consolidado_no_crea_nada(): void
    {
        $before = Category::query()->withoutGlobalScopes()->count();

        $this->actingAs($this->super)
            ->from('/categorias')
            ->post(route('categories.store'), ['name' => 'Aseo'])
            ->assertRedirect('/categorias')
            ->assertSessionHas('error', 'Elige una empresa en la parte superior para continuar.');

        $this->assertSame($before, Category::query()->withoutGlobalScopes()->count());
    }

    public function test_una_consulta_json_en_consolidado_responde_409(): void
    {
        $this->actingAs($this->super)
            ->getJson(route('pos.products'))
            ->assertStatus(409)
            ->assertJson(['message' => 'Elige una empresa en la parte superior para continuar.']);
    }

    public function test_en_consolidado_no_hay_sede_ni_canales(): void
    {
        $this->actingAs($this->super)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeStore', null)
                ->count('storesForSelector', 0)
                ->where('realtime.enabled', false));
    }

    public function test_plataforma_funciona_sin_empresa_elegida(): void
    {
        $this->actingAs($this->super)
            ->get(route('companies.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Platform/Companies/Index'));
    }

    public function test_un_usuario_de_empresa_no_recibe_el_selector(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->withSession($this->withActiveStore())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('companiesForSelector', [])
                ->where('isConsolidatedView', false)
                ->where('activeCompany.id', $this->company->id));
    }

    public function test_un_usuario_de_empresa_ignora_la_empresa_en_sesion(): void
    {
        $admin = $this->makeUser('admin');
        $other = \App\Models\Company::query()->where('id', '!=', $this->company->id)->firstOrFail();

        // Aunque alguien plante la llave del super usuario en su sesion, sigue en la suya.
        $this->actingAs($admin)
            ->withSession([TenantContext::SESSION_KEY => $other->id] + $this->withActiveStore())
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('activeCompany.id', $this->company->id));
    }

    public function test_plataforma_no_se_abre_para_un_administrador_de_empresa(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->get(route('companies.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('companies.create'))->assertForbidden();
    }
}

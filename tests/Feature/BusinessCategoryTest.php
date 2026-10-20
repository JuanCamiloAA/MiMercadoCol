<?php

namespace Tests\Feature;

use App\Imports\CompanyImport;
use App\Models\BusinessCategory;
use App\Models\Company;
use App\Models\CompanySignup;
use App\Models\PlatformLead;
use App\Models\User;
use App\Services\Landing\LandingManager;
use App\Services\MasterImportService;
use App\Services\Platform\BusinessCategoryCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ConfiguresGateway;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Cada empresa tiene una clasificacion (alimentos, textiles, belleza…): se administra en
 * Plataforma, se elige al crearla, al contratar y en la carga masiva, y la landing agrupa por ella.
 */
class BusinessCategoryTest extends TestCase
{
    use ConfiguresGateway, CreatesCompanyEnvironment, RefreshDatabase;

    protected User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->super = $this->makeSuperAdmin();
    }

    protected function category(string $name): BusinessCategory
    {
        return BusinessCategory::query()->where('name', $name)->firstOrFail();
    }

    public function test_el_catalogo_trae_lo_de_fabrica_y_se_administra_en_plataforma(): void
    {
        $this->assertSame(count(BusinessCategoryCatalog::DEFAULTS), BusinessCategory::query()->count());

        $this->actingAs($this->super)->get(route('platform.business_categories.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Platform/BusinessCategories/Index')->where('categories.0.name', 'Alimentos y supermercados'));

        $this->actingAs($this->super)->post(route('platform.business_categories.store'), [
            'name' => 'Joyería y accesorios', 'icon' => 'Sparkle', 'is_active' => true, 'sort_order' => 20,
        ])->assertSessionHasNoErrors();

        $jewelry = $this->category('Joyería y accesorios');
        $this->assertSame('joyeria-y-accesorios', $jewelry->slug);

        // El nombre no se repite y el icono es de la lista que la landing sabe pintar.
        $this->actingAs($this->super)->post(route('platform.business_categories.store'), ['name' => 'joyería y accesorios', 'icon' => 'Sparkle', 'is_active' => true, 'sort_order' => 0])
            ->assertSessionHasErrors('slug');
        $this->actingAs($this->super)->post(route('platform.business_categories.store'), ['name' => 'Otra', 'icon' => 'Bomb', 'is_active' => true, 'sort_order' => 0])
            ->assertSessionHasErrors('icon');

        $this->actingAs($this->super)->put(route('platform.business_categories.update', $jewelry), ['name' => 'Joyería', 'icon' => 'Star', 'is_active' => true, 'sort_order' => 3])
            ->assertSessionHasNoErrors();
        $this->assertSame(['Joyería', 'Star', 3], [$jewelry->fresh()->name, $jewelry->fresh()->icon, $jewelry->fresh()->sort_order]);
    }

    public function test_siempre_queda_una_activa_y_desactivar_no_le_quita_la_suya_a_nadie(): void
    {
        $food = $this->category('Alimentos y supermercados');
        $this->company->update(['business_category_id' => $food->id]);
        BusinessCategory::query()->whereKeyNot($food->id)->update(['is_active' => false]);

        $this->actingAs($this->super)->post(route('platform.business_categories.toggle', $food))->assertSessionHas('error');
        $this->actingAs($this->super)->put(route('platform.business_categories.update', $food), ['name' => $food->name, 'icon' => $food->icon, 'is_active' => false, 'sort_order' => 0])
            ->assertSessionHasErrors('is_active');
        $this->assertTrue($food->fresh()->is_active);

        $beauty = $this->category('Belleza y cuidado personal');
        $this->actingAs($this->super)->post(route('platform.business_categories.toggle', $beauty))->assertSessionHas('success');
        $this->actingAs($this->super)->post(route('platform.business_categories.toggle', $food))->assertSessionHas('success');

        $this->assertFalse($food->fresh()->is_active);
        $this->assertSame($food->id, $this->company->fresh()->business_category_id);
    }

    public function test_solo_el_super_usuario_entra(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->get(route('platform.business_categories.index'))->assertForbidden();
        $this->actingAs($admin)->post(route('platform.business_categories.store'), ['name' => 'X', 'icon' => 'Star', 'is_active' => true, 'sort_order' => 0])->assertForbidden();
    }

    public function test_al_editar_una_empresa_se_conserva_una_desactivada_pero_no_se_elige_otra(): void
    {
        $textiles = $this->category('Textiles y confección');
        $beauty = $this->category('Belleza y cuidado personal');
        $this->company->update(['business_category_id' => $textiles->id]);
        BusinessCategory::query()->whereKey([$textiles->id, $beauty->id])->update(['is_active' => false]);

        $base = ['name' => 'La Canasta', 'legal_name' => 'La Canasta S.A.S.', 'tax_id' => '900111222-3', 'show_on_landing' => true, 'membership_status' => Company::MEMBERSHIP_ACTIVE];

        $this->actingAs($this->super)->get(route('companies.edit', $this->company))
            ->assertInertia(fn ($page) => $page->where('company.business_category_id', $textiles->id)->where('categories', fn ($options) => collect($options)->contains('value', $textiles->id) && ! collect($options)->contains('value', $beauty->id)));

        $this->actingAs($this->super)->put(route('companies.update', $this->company), $base + ['business_category_id' => $textiles->id])->assertSessionHasNoErrors();
        $this->actingAs($this->super)->put(route('companies.update', $this->company), $base + ['business_category_id' => $beauty->id])->assertSessionHasErrors('business_category_id');
        $this->actingAs($this->super)->put(route('companies.update', $this->company), $base)->assertSessionHasErrors('business_category_id');

        // El listado filtra por clasificacion.
        $this->actingAs($this->super)->get(route('companies.index', ['clasificacion' => $textiles->id]))
            ->assertInertia(fn ($page) => $page->where('companies.total', 1)->where('companies.data.0.category', 'Textiles y confección'));
        $this->actingAs($this->super)->get(route('companies.index', ['clasificacion' => $beauty->id]))
            ->assertInertia(fn ($page) => $page->where('companies.total', 0));
    }

    public function test_contratar_con_prueba_gratis_crea_la_empresa_clasificada(): void
    {
        Http::fake();
        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan(['name' => 'Prueba', 'slug' => 'prueba', 'trial_days' => 14, 'is_public' => true]);
        $beauty = $this->category('Belleza y cuidado personal');

        $this->get(route('signup.create'))->assertInertia(fn ($page) => $page->where('categories', fn ($options) => collect($options)->contains('name', 'Belleza y cuidado personal')));

        $this->post(route('signup.store'), $this->signup($plan->id, $cycle->id, ['business_category_id' => null]))->assertSessionHasErrors('business_category_id');
        $this->post(route('signup.store'), $this->signup($plan->id, $cycle->id, ['business_category_id' => $beauty->id]))->assertRedirect(route('dashboard'));

        $this->assertSame($beauty->id, Company::query()->where('tax_id', '901000111-2')->sole()->business_category_id);
    }

    public function test_contratar_pagando_guarda_la_clasificacion_hasta_que_la_empresa_nace(): void
    {
        $this->enableGateway();
        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan(['name' => 'Pro', 'slug' => 'pro', 'monthly_price' => 189000, 'is_public' => true]);
        $textiles = $this->category('Textiles y confección');

        $this->post(route('signup.store'), $this->signup($plan->id, $cycle->id, ['business_category_id' => $textiles->id]), ['X-Inertia' => 'true'])->assertStatus(409);

        $signup = CompanySignup::query()->sole();
        $this->assertSame($textiles->id, $signup->business_category_id);

        Http::fake([self::WOMPI.'/transactions/*' => Http::response(['data' => $this->transaction($signup->reference, $signup->amount * 100, 'APPROVED', 'tx-cat-1')])]);
        $this->get(route('signup.status', ['reference' => $signup->reference, 'id' => 'tx-cat-1']))->assertOk();

        $this->assertSame($textiles->id, Company::query()->where('tax_id', '901000111-2')->sole()->business_category_id);
    }

    public function test_que_me_contacten_guarda_la_clasificacion_y_crear_desde_la_solicitud_la_trae(): void
    {
        Notification::fake();
        $hardware = $this->category('Ferretería y construcción');

        $this->post(route('signup.contact'), ['contact_name' => 'Jorge', 'email' => 'jorge@ferre.co', 'company_name' => 'Ferretería El Tornillo', 'business_category_id' => $hardware->id])
            ->assertSessionHasNoErrors();

        $lead = PlatformLead::query()->sole();
        $this->assertSame($hardware->id, $lead->business_category_id);

        $this->actingAs($this->super)->get(route('companies.create', ['solicitud' => $lead->id]))
            ->assertInertia(fn ($page) => $page->where('lead.business_category_id', $hardware->id));
    }

    public function test_crear_una_empresa_exige_la_clasificacion(): void
    {
        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan(['name' => 'Pro', 'max_stores' => 3, 'max_users' => 10, 'max_registers' => 5]);
        $pets = $this->category('Mascotas');
        $payload = [
            'name' => 'Mundo Mascota', 'tax_id' => '900999888-1', 'store_name' => 'Principal', 'store_code' => 'PRI',
            'admin_name' => 'Ana', 'admin_email' => 'ana@mundomascota.co', 'membership_plan_id' => $plan->id, 'billing_cycle_id' => $cycle->id,
        ];

        $this->actingAs($this->super)->post(route('companies.store'), $payload)->assertSessionHasErrors('business_category_id');
        $this->actingAs($this->super)->post(route('companies.store'), $payload + ['business_category_id' => $pets->id])->assertSessionHasNoErrors();

        $this->assertSame($pets->id, Company::query()->where('tax_id', '900999888-1')->sole()->business_category_id);
    }

    public function test_la_carga_masiva_lee_la_columna_clasificacion(): void
    {
        $this->makePlan(['name' => 'Pro', 'slug' => 'pro', 'max_stores' => 3, 'max_users' => 10, 'max_registers' => 5]);
        $import = new CompanyImport;
        $header = array_keys($import->columns());
        $row = fn (string $name, string $nit, string $category, string $email) => array_combine($header, array_replace(
            array_fill(0, count($header), ''),
            [0 => $name, 1 => $nit, array_search('clasificacion', $header, true) => $category, array_search('plan', $header, true) => 'pro', array_search('ciclo', $header, true) => 'monthly', array_search('admin_nombre', $header, true) => 'Admin', array_search('admin_correo', $header, true) => $email],
        ));

        $path = tempnam(sys_get_temp_dir(), 'cmp');
        $handle = fopen($path, 'w');
        fputcsv($handle, $header, ';');
        fputcsv($handle, array_values($row('Moda Bella', '900100200-1', 'textiles y confeccion', 'a@moda.co')), ';');
        fputcsv($handle, array_values($row('Sin clasificar', '900100200-2', '', 'b@sin.co')), ';');
        fputcsv($handle, array_values($row('Inventada', '900100200-3', 'Naves espaciales', 'c@naves.co')), ';');
        fclose($handle);

        $result = app(MasterImportService::class)->run($import, $path, 0);

        $this->assertSame(2, $result['created'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('la clasificación «Naves espaciales» no existe', $result['failures'][0]['reason']);
        $this->assertSame($this->category('Textiles y confección')->id, Company::query()->where('tax_id', '900100200-1')->value('business_category_id'));
        // Vacía, la de alimentos: como toda empresa que se cargaba antes.
        $this->assertSame($this->category('Alimentos y supermercados')->id, Company::query()->where('tax_id', '900100200-2')->value('business_category_id'));
    }

    public function test_la_landing_agrupa_tiendas_y_empresas_por_clasificacion(): void
    {
        app(LandingManager::class)->ensureSeeded($this->super);
        $food = $this->category('Alimentos y supermercados');
        $textiles = $this->category('Textiles y confección');

        $this->company->update(['business_category_id' => $food->id, 'show_on_landing' => true]);
        $this->publishStorefront();

        $other = $this->makeOtherCompany('Moda Bella', '900777888-1');
        $other['company']->update(['business_category_id' => $textiles->id, 'show_on_landing' => true]);
        $this->publishStorefront($other['store'], $this->super);

        $blocks = collect(json_decode(json_encode($this->get('/')->assertOk()->viewData('page')['props']['blocks']), true));
        $finder = $blocks->firstWhere('type', 'store_finder')['source'];
        $showcase = $blocks->firstWhere('type', 'companies_showcase')['source'];

        // Cada tienda con su clasificación, y las clasificaciones presentes en el orden del catálogo.
        $byCompany = collect($finder['stores'])->keyBy('company_name');
        $this->assertSame('textiles-y-confeccion', $byCompany['Moda Bella']['category']['slug']);
        $this->assertSame('Basket', $byCompany['La Canasta']['category']['icon']);
        $this->assertSame([['Alimentos y supermercados', 1], ['Textiles y confección', 1]], array_map(fn ($category) => [$category['name'], $category['count']], $finder['categories']));

        $this->assertSame(['Alimentos y supermercados', 'Textiles y confección'], array_column($showcase['categories'], 'name'));
        $this->assertSame($textiles->id, collect($showcase['companies'])->firstWhere('name', 'Moda Bella')['category']['id']);

        // Desactivada sigue agrupando a las empresas que la tienen.
        $textiles->update(['is_active' => false]);
        $blocks = collect(json_decode(json_encode($this->get('/')->viewData('page')['props']['blocks']), true));
        $this->assertCount(2, $blocks->firstWhere('type', 'store_finder')['source']['categories']);
    }

    /** @return array<string, mixed> */
    protected function signup(int $planId, int $cycleId, array $overrides = []): array
    {
        return array_merge([
            'membership_plan_id' => $planId,
            'billing_cycle_id' => $cycleId,
            'company_name' => 'Belleza Total',
            'tax_id' => '901000111-2',
            'city' => 'Medellín',
            'company_email' => 'hola@bellezatotal.co',
            'admin_name' => 'Paula',
            'admin_email' => 'paula@bellezatotal.co',
            'admin_password' => 'ClaveSegura#2026',
            'admin_password_confirmation' => 'ClaveSegura#2026',
            'accept_terms' => true,
        ], $overrides);
    }
}

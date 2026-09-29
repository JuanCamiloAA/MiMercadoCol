<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\StoreContext;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * El selector de empresa del super usuario.
 */
class ActiveCompanySwitchTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $super;

    protected array $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->super = $this->makeSuperAdmin();
        $this->other = $this->makeOtherCompany();
    }

    public function test_elegir_una_empresa_la_deja_en_sesion_y_olvida_la_sede(): void
    {
        $this->actingAs($this->super)
            ->withSession([StoreContext::SESSION_KEY => $this->store->id])
            ->post(route('platform.active-company'), ['company_id' => $this->other['company']->id, 'redirect' => '/productos'])
            ->assertRedirect('/productos')
            ->assertSessionHas(TenantContext::SESSION_KEY, $this->other['company']->id)
            ->assertSessionMissing(StoreContext::SESSION_KEY)
            ->assertSessionHas('success', 'Ahora trabajas en Mercado El Vecino.');
    }

    public function test_volver_a_todas_las_empresas_borra_la_eleccion(): void
    {
        $this->actingAs($this->super)
            ->withSession([TenantContext::SESSION_KEY => $this->company->id])
            ->post(route('platform.active-company'), ['company_id' => null, 'redirect' => '/dashboard'])
            ->assertRedirect('/dashboard')
            ->assertSessionMissing(TenantContext::SESSION_KEY);
    }

    public function test_desde_un_registro_de_la_otra_empresa_vuelve_al_listado(): void
    {
        $product = $this->makeProduct();

        $this->actingAs($this->super)
            ->withSession([TenantContext::SESSION_KEY => $this->company->id])
            ->post(route('platform.active-company'), [
                'company_id' => $this->other['company']->id,
                'redirect' => "/productos/{$product->id}/editar",
            ])
            ->assertRedirect(route('products.index'));
    }

    public function test_la_redireccion_nunca_sale_del_dominio(): void
    {
        $response = $this->actingAs($this->super)
            ->post(route('platform.active-company'), [
                'company_id' => $this->company->id,
                'redirect' => 'https://otro-sitio.com/productos',
            ]);

        $this->assertStringStartsWith(url('/'), $response->headers->get('Location'));
        $this->assertStringNotContainsString('otro-sitio', $response->headers->get('Location'));
    }

    public function test_una_empresa_que_no_existe_se_rechaza(): void
    {
        $this->actingAs($this->super)
            ->post(route('platform.active-company'), ['company_id' => 999999])
            ->assertSessionHasErrors('company_id');

        $this->assertNull(session(TenantContext::SESSION_KEY));
    }

    public function test_un_administrador_de_empresa_no_puede_cambiar_de_empresa(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->post(route('platform.active-company'), ['company_id' => $this->other['company']->id])
            ->assertForbidden();

        $this->assertSame($this->company->id, TenantContext::activeCompanyId($admin));
    }

    public function test_con_una_empresa_elegida_solo_se_ven_sus_datos(): void
    {
        $this->makeProduct(['name' => 'Arroz de La Canasta']);
        $category = Category::query()->withoutGlobalScopes()->create([
            'company_id' => $this->other['company']->id,
            'name' => 'Granos',
            'slug' => 'granos',
            'is_active' => true,
        ]);

        Product::query()->withoutGlobalScopes()->create([
            'company_id' => $this->other['company']->id,
            'category_id' => $category->id,
            'name' => 'Arroz del Vecino',
            'sku' => 'VEC-1',
            'unit' => 'unit',
            'cost' => 3000,
            'price' => 4500,
            'tax_rate' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($this->super)
            ->withSession([TenantContext::SESSION_KEY => $this->other['company']->id])
            ->get(route('products.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Products/Index')
                ->where('activeCompany.id', $this->other['company']->id)
                ->where('isConsolidatedView', false)
                ->where('products.data.0.name', 'Arroz del Vecino')
                ->count('products.data', 1));
    }

    public function test_la_sede_activa_es_de_la_empresa_elegida(): void
    {
        $this->actingAs($this->super)
            ->withSession([TenantContext::SESSION_KEY => $this->other['company']->id])
            ->get(route('products.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeStore.id', $this->other['store']->id)
                ->count('storesForSelector', 1));
    }

    public function test_una_sede_de_otra_empresa_en_sesion_no_se_usa(): void
    {
        // Sesion vieja: la sede quedo de la empresa anterior. No debe cruzarse.
        $this->actingAs($this->super)
            ->withSession([
                TenantContext::SESSION_KEY => $this->other['company']->id,
                StoreContext::SESSION_KEY => $this->store->id,
            ])
            ->get(route('products.index'))
            ->assertInertia(fn (Assert $page) => $page->where('activeStore.id', $this->other['store']->id));
    }
}

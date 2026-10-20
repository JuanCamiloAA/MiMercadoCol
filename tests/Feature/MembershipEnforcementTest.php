<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Promotion;
use App\Models\PromotionTarget;
use App\Models\Storefront;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Lo que la membresia y el plan dejan hacer.
 */
class MembershipEnforcementTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        // La tienda en linea atiende la sede que el cliente eligio.
        $this->shopAt();
    }

    protected function suspend(): void
    {
        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan([], ['online_store', 'promotions']);
        $this->putCompanyOnPlan($plan, $cycle, Company::MEMBERSHIP_SUSPENDED);
    }

    public function test_suspendida_el_administrador_solo_llega_a_membresia(): void
    {
        $this->suspend();
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->withSession($this->withActiveStore())
            ->get(route('products.index'))
            ->assertRedirect(route('settings.index', ['pestana' => 'membresia']))
            ->assertSessionHas('warning');

        $this->actingAs($admin)->withSession($this->withActiveStore())
            ->get(route('settings.index', ['pestana' => 'membresia']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Index')
                ->where('membership.status', 'suspended')
                ->where('membershipNotice.tone', 'danger')
                ->where('membershipNotice.can_pay', true));

        // Cambiar otros ajustes tampoco: solo pagar.
        $this->actingAs($admin)->withSession($this->withActiveStore())
            ->put(route('settings.credit.update'), ['credit' => ['block_on_overdue_days' => 10]])
            ->assertRedirect(route('settings.index', ['pestana' => 'membresia']));
    }

    public function test_suspendida_una_peticion_json_recibe_402(): void
    {
        $this->suspend();

        $this->actingAs($this->makeUser('admin'))->withSession($this->withActiveStore())
            ->getJson(route('pos.products'))
            ->assertStatus(402);
    }

    public function test_suspendida_quien_no_puede_pagar_ve_a_quien_acudir(): void
    {
        $this->suspend();

        $this->actingAs($this->makeUser('cajero'))->withSession($this->withActiveStore())
            ->get(route('pos.index'))
            ->assertStatus(402)
            ->assertInertia(fn (Assert $page) => $page->component('Errors/Suspended')->where('company', 'La Canasta'));
    }

    public function test_el_super_usuario_sigue_entrando_a_una_suspendida(): void
    {
        $this->suspend();

        $this->actingAs($this->makeSuperAdmin())
            ->withSession([TenantContext::SESSION_KEY => $this->company->id])
            ->get(route('products.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('membershipNotice.status', 'suspended'));
    }

    public function test_en_gracia_todo_funciona_con_aviso(): void
    {
        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan();
        $this->putCompanyOnPlan($plan, $cycle, Company::MEMBERSHIP_GRACE);
        $this->company->forceFill(['grace_ends_at' => now()->addDays(3)])->save();

        $this->actingAs($this->makeUser('admin'))->withSession($this->withActiveStore())
            ->get(route('products.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('membershipNotice.status', 'grace')
                ->where('membershipNotice.tone', 'warn'));
    }

    public function test_una_funcion_fuera_del_plan_se_niega_en_el_servidor(): void
    {
        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan([], []);
        $this->putCompanyOnPlan($plan, $cycle);
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->withSession($this->withActiveStore())
            ->get(route('promotions.index'))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->where('message', 'Tu plan no incluye «Ver las promociones». Cámbialo en Ajustes > Membresía.'));

        $this->actingAs($admin)->withSession($this->withActiveStore())
            ->get(route('storefront.editor.index'))
            ->assertForbidden();

        $this->actingAs($admin)->withSession($this->withActiveStore())
            ->get(route('products.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('activeCompany.features', []));
    }

    public function test_sin_promociones_en_el_plan_la_caja_no_las_aplica(): void
    {
        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan([], []);
        $this->putCompanyOnPlan($plan, $cycle);
        $product = $this->makeProduct(['price' => 10000]);

        Promotion::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Arroz a mitad',
            'type' => Promotion::TYPE_PERCENT_OFF,
            'applies_online' => true,
            'applies_pos' => true,
            'is_active' => true,
            'config' => ['percent' => 50],
        ])->targets()->create(['target_type' => PromotionTarget::TYPE_PRODUCT, 'target_id' => $product->id]);

        $this->actingAs($this->makeUser('admin'))->withSession($this->withActiveStore())
            ->postJson(route('pos.quote'), ['items' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->assertOk()
            ->assertJsonPath('lines.0.unit_price', 10000)
            ->assertJsonPath('lines.0.promo_discount', 0)
            ->assertJsonPath('applied', []);
    }

    public function test_la_tienda_de_una_empresa_suspendida_no_vende(): void
    {
        $this->suspend();

        $this->get('/tienda')
            ->assertStatus(503)
            ->assertInertia(fn (Assert $page) => $page->component('Storefront/Unavailable'));

        $this->postJson(route('storefront.cart.store'), ['product_id' => 1, 'quantity' => 1])->assertStatus(503);
    }

    public function test_en_gracia_la_tienda_sigue_vendiendo(): void
    {
        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan([], ['online_store']);
        $this->putCompanyOnPlan($plan, $cycle, Company::MEMBERSHIP_GRACE);

        $this->get('/tienda')->assertOk();
    }

    public function test_sin_tienda_en_el_plan_la_tienda_no_atiende(): void
    {
        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan([], ['promotions']);
        $this->putCompanyOnPlan($plan, $cycle);

        $this->get('/tienda')->assertStatus(503);
    }

    public function test_una_tienda_apagada_no_atiende(): void
    {
        Storefront::query()->withoutGlobalScopes()->updateOrCreate(
            ['store_id' => $this->store->id],
            ['company_id' => $this->company->id, 'slug' => 'centro', 'is_online' => false],
        );

        $this->get('/tienda')->assertStatus(503);
    }
}

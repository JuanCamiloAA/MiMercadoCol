<?php

namespace Tests\Feature;

use App\Models\BillingCycle;
use App\Models\MembershipPlan;
use App\Models\PaymentGatewayCredential;
use App\Models\PaymentGatewaySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ConfiguresGateway;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Plataforma > Pasarela, Planes y Ciclos.
 */
class GatewaySettingsTest extends TestCase
{
    use ConfiguresGateway, CreatesCompanyEnvironment, RefreshDatabase;

    protected User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->super = $this->makeSuperAdmin();
    }

    public function test_los_secretos_nunca_vuelven_a_la_pantalla(): void
    {
        $this->enableGateway();

        $response = $this->actingAs($this->super)->get(route('platform.gateway.index'))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Platform/Gateway/Index')
            ->where('credentials.sandbox.public_key', 'pub_test_Llave123')
            ->where('credentials.sandbox.secrets.private_key', '••••a123')
            ->where('credentials.sandbox.secrets.events_secret', '••••o123')
            ->where('settings.is_usable', true)
            ->where('webhookUrl', route('webhooks.wompi')));

        $response->assertDontSee('prv_test_Privada123')->assertDontSee(self::EVENTS_SECRET)->assertDontSee(self::INTEGRITY_SECRET);
    }

    public function test_los_secretos_se_guardan_cifrados_y_un_campo_vacio_los_conserva(): void
    {
        $this->actingAs($this->super)
            ->put(route('platform.gateway.credentials', 'sandbox'), [
                'public_key' => 'pub_test_Abc',
                'private_key' => 'prv_test_Uno',
                'events_secret' => 'test_events_Dos',
                'integrity_secret' => 'test_integrity_Tres',
            ])
            ->assertSessionHasNoErrors();

        $raw = DB::table('payment_gateway_credentials')->where('environment', 'sandbox')->first();
        $this->assertNotSame('prv_test_Uno', $raw->private_key);
        $this->assertStringNotContainsString('prv_test_Uno', (string) $raw->private_key);

        $this->actingAs($this->super)
            ->put(route('platform.gateway.credentials', 'sandbox'), ['public_key' => 'pub_test_Abc', 'private_key' => '', 'events_secret' => '', 'integrity_secret' => ''])
            ->assertSessionHasNoErrors();

        $credential = PaymentGatewayCredential::query()->where('environment', 'sandbox')->sole();
        $this->assertSame('prv_test_Uno', $credential->private_key);
        $this->assertTrue($credential->isComplete());
    }

    public function test_una_llave_del_otro_entorno_se_rechaza(): void
    {
        $this->actingAs($this->super)
            ->put(route('platform.gateway.credentials', 'sandbox'), ['public_key' => 'pub_prod_Real', 'private_key' => 'prv_prod_Real'])
            ->assertSessionHasErrors(['public_key', 'private_key']);

        $this->assertNull(PaymentGatewayCredential::query()->where('environment', 'sandbox')->value('public_key'));
    }

    public function test_no_se_enciende_sin_las_cuatro_llaves(): void
    {
        $this->actingAs($this->super)
            ->put(route('platform.gateway.credentials', 'production'), ['public_key' => 'pub_prod_Solo'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->super)
            ->put(route('platform.gateway.update'), ['environment' => 'production', 'is_enabled' => true])
            ->assertSessionHasErrors('is_enabled');

        $this->assertFalse((bool) PaymentGatewaySetting::query()->value('is_enabled'));
    }

    public function test_probar_conexion_consulta_el_comercio_con_la_llave_publica(): void
    {
        $this->enableGateway();
        Http::fake([self::WOMPI.'/merchants/info' => Http::response(['data' => ['name' => 'Plataforma MiMercado']])]);

        $this->actingAs($this->super)
            ->post(route('platform.gateway.test', 'sandbox'))
            ->assertSessionHas('success', 'Conexión correcta con el comercio «Plataforma MiMercado».');

        Http::assertSent(fn ($request) => $request->hasHeader('x-merchant-public-key', 'pub_test_Llave123'));
    }

    public function test_un_administrador_de_empresa_no_llega_a_la_pasarela(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->get(route('platform.gateway.index'))->assertForbidden();
        $this->actingAs($admin)->put(route('platform.gateway.update'), ['environment' => 'sandbox', 'is_enabled' => false])->assertForbidden();
        $this->actingAs($admin)->get(route('platform.plans.index'))->assertForbidden();
    }

    public function test_el_listado_de_planes_trae_el_precio_de_cada_ciclo(): void
    {
        $monthly = BillingCycle::query()->create(['name' => 'Mensual', 'code' => 'monthly', 'months' => 1, 'discount_percent' => 0, 'is_active' => true, 'sort_order' => 1]);
        $annual = BillingCycle::query()->create(['name' => 'Anual', 'code' => 'annual', 'months' => 12, 'discount_percent' => 15, 'is_active' => true, 'sort_order' => 4]);

        $this->actingAs($this->super)
            ->post(route('platform.plans.store'), [
                'name' => 'Pro', 'description' => 'Para crecer', 'monthly_price' => 189000, 'trial_days' => 0,
                'max_stores' => 3, 'max_users' => 20, 'max_registers' => 8, 'features' => ['online_store', 'promotions', 'inventado'],
                'is_public' => true, 'is_featured' => true, 'is_active' => true, 'sort_order' => 2,
            ])
            ->assertSessionHasErrors('features.2');

        $this->actingAs($this->super)
            ->post(route('platform.plans.store'), [
                'name' => 'Pro', 'description' => 'Para crecer', 'monthly_price' => 189000, 'trial_days' => 0,
                'max_stores' => 3, 'max_users' => 20, 'max_registers' => 8, 'features' => ['online_store', 'promotions'],
                'is_public' => true, 'is_featured' => true, 'is_active' => true, 'sort_order' => 2,
            ])
            ->assertRedirect(route('platform.plans.index'));

        $plan = MembershipPlan::query()->where('slug', 'pro')->sole();

        // 189.000 × 12 × 85 % = 1.927.800, hacia arriba al mil.
        $this->actingAs($this->super)
            ->get(route('platform.plans.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('plans.0.prices.'.$monthly->id, 189000)
                ->where('plans.0.prices.'.$annual->id, 1928000)
                ->where('plans.0.features', ['Tienda en línea', 'Promociones y cupones']));

        $this->assertSame(['online_store', 'promotions'], $plan->features);
    }

    public function test_un_plan_en_uso_no_se_borra(): void
    {
        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan();
        $this->putCompanyOnPlan($plan, $cycle);

        $this->actingAs($this->super)
            ->delete(route('platform.plans.destroy', $plan))
            ->assertSessionHas('error');

        $this->assertNotNull($plan->fresh());

        $this->actingAs($this->super)->post(route('platform.plans.toggle', $plan));
        $this->assertFalse($plan->fresh()->is_active);
    }

    public function test_no_se_desactiva_el_ultimo_ciclo_activo(): void
    {
        $cycle = BillingCycle::query()->create(['name' => 'Mensual', 'code' => 'monthly', 'months' => 1, 'discount_percent' => 0, 'is_active' => true, 'sort_order' => 1]);

        $this->actingAs($this->super)
            ->post(route('platform.billing_cycles.toggle', $cycle))
            ->assertSessionHas('error');

        $this->actingAs($this->super)
            ->put(route('platform.billing_cycles.update', $cycle), ['name' => 'Mensual', 'code' => 'monthly', 'months' => 1, 'discount_percent' => 0, 'is_active' => false, 'sort_order' => 1])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($cycle->fresh()->is_active);

        $this->actingAs($this->super)
            ->post(route('platform.billing_cycles.store'), ['name' => 'Trimestral', 'months' => 3, 'discount_percent' => 5, 'is_active' => true, 'sort_order' => 2])
            ->assertSessionHasNoErrors();

        $this->assertSame('trimestral', BillingCycle::query()->where('months', 3)->value('code'));

        $this->actingAs($this->super)->post(route('platform.billing_cycles.toggle', $cycle))->assertSessionHas('success');
        $this->assertFalse($cycle->fresh()->is_active);
    }
}

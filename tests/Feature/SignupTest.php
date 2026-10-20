<?php

namespace Tests\Feature;

use App\Models\BillingCycle;
use App\Models\Company;
use App\Models\CompanySignup;
use App\Models\MembershipPlan;
use App\Models\PlatformLead;
use App\Models\User;
use App\Notifications\Platform\NewPlatformLead;
use App\Services\PlatformSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ConfiguresGateway;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Contratar en linea: pagar ahora, prueba gratis o que me contacten.
 */
class SignupTest extends TestCase
{
    use ConfiguresGateway, CreatesCompanyEnvironment, RefreshDatabase;

    protected MembershipPlan $pro;

    protected MembershipPlan $chain;

    protected BillingCycle $monthly;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        ['plan' => $this->pro, 'cycle' => $this->monthly] = $this->makePlan(['name' => 'Pro', 'slug' => 'pro', 'monthly_price' => 189000], ['online_store']);
        ['plan' => $this->chain] = $this->makePlan(['name' => 'Cadena', 'slug' => 'cadena', 'monthly_price' => 390000, 'trial_days' => 14]);
    }

    /** @return array<string, mixed> */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'membership_plan_id' => $this->pro->id,
            'billing_cycle_id' => $this->monthly->id,
            'company_name' => 'Autoservicio La 80',
            'tax_id' => '901000111-2',
            'city' => 'Medellín',
            'business_category_id' => app(\App\Services\Platform\BusinessCategoryCatalog::class)->defaultId(),
            'company_phone' => '6045557788',
            'company_email' => 'Hola@La80.co',
            'admin_name' => 'Paula',
            'admin_last_name' => 'Restrepo',
            'admin_email' => 'paula@la80.co',
            'admin_password' => 'ClaveSegura#2026',
            'admin_password_confirmation' => 'ClaveSegura#2026',
            'accept_terms' => true,
        ], $overrides);
    }

    public function test_pagar_ahora_crea_la_contratacion_pendiente_y_va_a_la_pasarela(): void
    {
        $this->enableGateway();

        $response = $this->post(route('signup.store'), $this->payload(), ['X-Inertia' => 'true'])->assertStatus(409);
        $url = (string) $response->headers->get('X-Inertia-Location');

        $signup = CompanySignup::query()->sole();
        $this->assertStringStartsWith('SIG-', $signup->reference);
        $this->assertSame(CompanySignup::STATUS_PENDING, $signup->status);
        $this->assertSame(189000, $signup->amount);
        $this->assertSame('hola@la80.co', $signup->company_email);
        $this->assertEqualsWithDelta(120, now()->diffInMinutes($signup->expires_at), 2);

        // La contraseña nunca queda en claro.
        $raw = (array) DB::table('company_signups')->first();
        $this->assertStringNotContainsString('ClaveSegura#2026', json_encode($raw));
        $this->assertTrue(Hash::check('ClaveSegura#2026', $signup->admin_password_hash));

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('https://checkout.wompi.co/p/?', $url);
        $this->assertSame($signup->reference, $query['reference']);
        $this->assertSame('18900000', $query['amount-in-cents']);
        $this->assertSame(route('signup.status', $signup->reference), $query['redirect-url']);

        // Nada existe hasta que el pago se confirme.
        $this->assertFalse(Company::query()->where('tax_id', '901000111-2')->exists());
    }

    public function test_al_volver_pagada_la_cuenta_queda_lista_y_entra_solo_quien_contrato(): void
    {
        $this->enableGateway();
        $this->post(route('signup.store'), $this->payload(), ['X-Inertia' => 'true']);
        $signup = CompanySignup::query()->sole();

        Http::fake([self::WOMPI.'/transactions/*' => Http::response(['data' => $this->transaction($signup->reference, 18900000, 'APPROVED', 'tx-sig-1')])]);

        $this->get(route('signup.status', ['reference' => $signup->reference, 'id' => 'tx-sig-1']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Signup/Status')->where('signup.ready', true)->where('signup.can_enter', true));

        $company = Company::query()->where('tax_id', '901000111-2')->sole();
        $this->assertSame(Company::MEMBERSHIP_ACTIVE, $company->membership_status);

        // Otro navegador con la referencia no entra.
        $this->flushSession();
        $this->get(route('signup.status', ['reference' => $signup->reference, 'entrar' => 1]))->assertOk();
        $this->assertGuest('web');

        // El que contrato, si.
        $this->withSession([\App\Http\Controllers\Landing\SignupController::SESSION_KEY => $signup->reference])
            ->get(route('signup.status', ['reference' => $signup->reference, 'entrar' => 1]))
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs(User::query()->where('email', 'paula@la80.co')->sole(), 'web');
    }

    public function test_un_plan_con_prueba_gratis_crea_la_cuenta_sin_pasar_por_la_pasarela(): void
    {
        Http::fake();

        $this->post(route('signup.store'), $this->payload(['membership_plan_id' => $this->chain->id]))->assertRedirect(route('dashboard'));

        Http::assertNothingSent();
        $company = Company::query()->where('tax_id', '901000111-2')->sole();
        $this->assertSame(Company::MEMBERSHIP_TRIAL, $company->membership_status);
        $this->assertSame($this->chain->id, $company->membership_plan_id);
        $this->assertSame(0, CompanySignup::query()->count());

        $admin = User::query()->where('email', 'paula@la80.co')->sole();
        $this->assertAuthenticatedAs($admin, 'web');
        $this->assertFalse((bool) $admin->password_change_required);
    }

    public function test_sin_pasarela_no_se_ofrece_pagar(): void
    {
        $this->get(route('signup.create', ['plan' => 'pro']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Signup/Create')->where('gatewayEnabled', false)->where('selectedPlanId', $this->pro->id));

        $this->post(route('signup.store'), $this->payload())->assertSessionHasErrors('membership_plan_id');
        $this->assertSame(0, CompanySignup::query()->count());
    }

    public function test_un_nit_o_un_correo_ya_usados_se_rechazan(): void
    {
        $this->enableGateway();

        $this->post(route('signup.store'), $this->payload(['tax_id' => '900111222-3']))->assertSessionHasErrors('tax_id');

        $user = $this->makeUser('admin');
        $this->post(route('signup.store'), $this->payload(['admin_email' => $user->email]))->assertSessionHasErrors('admin_email');

        // Un NIT esperando pago tampoco se puede volver a contratar.
        $this->post(route('signup.store'), $this->payload(), ['X-Inertia' => 'true'])->assertStatus(409);
        $this->post(route('signup.store'), $this->payload(['admin_email' => 'otra@la80.co']))->assertSessionHasErrors('tax_id');
    }

    public function test_que_me_contacten_crea_la_solicitud_y_avisa(): void
    {
        Notification::fake();
        app(PlatformSettingsService::class)->set('platform.leads_notify_email', 'ventas@plataforma.co');

        $this->post(route('signup.contact'), [
            'source' => 'signup',
            'company_name' => 'Autoservicio La 80',
            'contact_name' => 'Paula Restrepo',
            'email' => 'paula@la80.co',
            'phone' => '3001234567',
            'estimated_stores' => 2,
            'membership_plan_id' => $this->pro->id,
            'message' => 'Queremos ver la caja.',
        ])->assertSessionHas('success');

        $lead = PlatformLead::query()->sole();
        $this->assertSame(PlatformLead::STATUS_NEW, $lead->status);
        $this->assertSame(PlatformLead::SOURCE_SIGNUP, $lead->source);

        Notification::assertSentTo(new AnonymousNotifiable, NewPlatformLead::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'ventas@plataforma.co');
    }

    public function test_el_campo_trampa_no_deja_solicitud(): void
    {
        $this->post(route('signup.contact'), ['contact_name' => 'Robot', 'email' => 'bot@spam.co', 'website' => 'http://spam.co'])
            ->assertSessionHas('success');

        $this->assertSame(0, PlatformLead::query()->count());
    }

    public function test_la_solicitud_se_gestiona_en_plataforma(): void
    {
        $lead = PlatformLead::query()->create(['source' => 'contact', 'contact_name' => 'Paula', 'email' => 'paula@la80.co', 'status' => 'new']);
        $super = $this->makeSuperAdmin();

        $this->actingAs($super)->get(route('platform.leads.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Platform/Leads/Index')->where('leads.data.0.id', $lead->id));

        $this->actingAs($super)->put(route('platform.leads.update', $lead), ['status' => 'contacted', 'notes' => 'Llamé el lunes'])->assertSessionHasNoErrors();
        $this->assertSame('contacted', $lead->fresh()->status);
        $this->assertSame($super->id, $lead->fresh()->handled_by_user_id);

        $this->actingAs($this->makeUser('admin'))->get(route('platform.leads.index'))->assertForbidden();
    }
}

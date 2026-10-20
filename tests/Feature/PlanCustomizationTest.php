<?php

namespace Tests\Feature;

use App\Models\BillingCycle;
use App\Models\CompanyEntitlement;
use App\Models\CompanyMembershipEvent;
use App\Models\CompanyPlanPrice;
use App\Models\MembershipCharge;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Notifications\Platform\PlanExtraExpiring;
use App\Services\Landing\LandingRenderer;
use App\Services\Membership\Entitlements;
use App\Services\Membership\EntitlementService;
use App\Services\Membership\MembershipBillingService;
use App\Services\Membership\MembershipPricing;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Lo que la plataforma le ajusta a cada empresa: excepciones al plan con vencimiento, precio
 * especial, el editor del plan con su arbol y los avisos del comando diario.
 */
class PlanCustomizationTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $super;

    protected MembershipPlan $plan;

    protected BillingCycle $cycle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->super = $this->makeSuperAdmin();

        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan(['name' => 'Pro', 'slug' => 'pro', 'monthly_price' => 189000], ['online_store']);
        $this->plan = $plan;
        $this->cycle = $cycle;
        $this->putCompanyOnPlan($plan, $cycle);
    }

    protected function priceRow(array $attributes): CompanyPlanPrice
    {
        return CompanyPlanPrice::query()->create(array_merge([
            'company_id' => $this->company->id,
            'membership_plan_id' => $this->plan->id,
            'starts_at' => now()->subDay(),
            'ends_at' => null,
            'reason' => 'Cliente fundador',
        ], $attributes));
    }

    public function test_el_precio_especial_solo_lo_ve_la_empresa_y_solo_en_su_plan(): void
    {
        $annual = BillingCycle::query()->create(['name' => 'Anual', 'code' => 'annual', 'months' => 12, 'discount_percent' => 15, 'is_active' => true, 'sort_order' => 4]);
        $this->priceRow(['discount_percent' => 20]);
        $company = $this->company->fresh();

        // Sin empresa: el de lista.
        $this->assertSame(189000, MembershipPricing::priceFor($this->plan, $this->cycle));
        // 189.000 − 20 % = 151.200, al mil de arriba.
        $this->assertSame(152000, MembershipPricing::priceFor($this->plan, $this->cycle, $company));
        // 189.000 × 0,8 × 12 × 0,85 = 1.542.240 → 1.543.000: el descuento del ciclo va despues.
        $this->assertSame(1543000, MembershipPricing::priceFor($this->plan, $annual, $company));

        // En otro plan no hay precio especial: no se arrastra.
        ['plan' => $cadena] = $this->makePlan(['name' => 'Cadena', 'slug' => 'cadena', 'monthly_price' => 390000]);
        $this->assertSame(390000, MembershipPricing::priceFor($cadena, $this->cycle, $company));
    }

    public function test_el_precio_mensual_fijo_reemplaza_la_base(): void
    {
        $this->priceRow(['monthly_price' => 99500]);

        $this->assertSame(100000, MembershipPricing::priceFor($this->plan, $this->cycle, $this->company->fresh()));
    }

    public function test_un_precio_vencido_no_cuenta(): void
    {
        $this->priceRow(['discount_percent' => 50, 'starts_at' => now()->subMonth(), 'ends_at' => now()->subMinute()]);

        $this->assertSame(189000, MembershipPricing::priceFor($this->plan, $this->cycle, $this->company->fresh()));
    }

    public function test_el_cobro_usa_el_precio_especial_y_el_ya_generado_conserva_su_monto(): void
    {
        $billing = app(MembershipBillingService::class);
        $first = $billing->recordManual($this->company->fresh(), $this->super, 'Transferencia');
        $this->assertSame(189000, $first->amount);

        $this->priceRow(['discount_percent' => 50]);

        $this->assertSame(189000, $first->fresh()->amount);
        $this->assertSame(95000, $billing->quote($this->company->fresh())['amount']);

        $second = $billing->recordManual($this->company->fresh(), $this->super, 'Transferencia');
        $this->assertSame(95000, $second->amount);
        $this->assertSame(2, MembershipCharge::query()->where('company_id', $this->company->id)->count());
    }

    public function test_la_landing_nunca_muestra_el_precio_especial(): void
    {
        $this->plan->update(['is_public' => true, 'highlights' => ['Tienda en línea', 'Domicilios']]);
        $this->priceRow(['discount_percent' => 50]);

        $catalogue = app(LandingRenderer::class)->plans();
        $row = collect($catalogue['plans'])->firstWhere('id', $this->plan->id);

        $this->assertSame(189000, $row['prices']->{$this->cycle->id});
        $this->assertSame(['Tienda en línea', 'Domicilios'], $row['highlights']);
        $this->assertNotEmpty($catalogue['comparison']);
        $this->assertSame('all', collect($catalogue['comparison'])->firstWhere('key', 'storefront')['plans'][(string) $this->plan->id]);
    }

    public function test_sin_destacados_la_landing_muestra_los_modulos_completos(): void
    {
        $this->plan->update(['is_public' => true, 'highlights' => []]);
        $this->makePlan(['name' => 'Básico', 'slug' => 'basico', 'monthly_price' => 89000]);

        $row = collect(app(LandingRenderer::class)->plans()['plans'])->firstWhere('id', $this->plan->id);

        // Primero lo que lo distingue del básico.
        $this->assertSame('Tienda en linea', $row['highlights'][0]);
        $this->assertNotContains('Ajustes', $row['highlights']);
        $this->assertLessThanOrEqual(MembershipPlan::MAX_HIGHLIGHTS, count($row['highlights']));
    }

    public function test_el_super_usuario_suma_una_funcion_con_vencimiento_y_queda_en_la_bitacora(): void
    {
        $this->actingAs($this->super)
            ->post(route('companies.entitlements.store', $this->company), [
                'node' => 'promotions.*',
                'effect' => 'grant',
                'ends_at' => now()->addDays(10)->toDateString(),
                'reason' => 'Prueba de promociones',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $exception = CompanyEntitlement::query()->sole();
        $this->assertSame('promotions.*', $exception->node);
        $this->assertSame('23:59:59', $exception->ends_at->timezone('America/Bogota')->format('H:i:s'));
        $this->assertTrue($this->company->fresh()->entitlement()->allows('promotions.index.view'));

        $event = CompanyMembershipEvent::query()->where('company_id', $this->company->id)->where('type', CompanyMembershipEvent::ENTITLEMENT_ADDED)->sole();
        $this->assertSame('Prueba de promociones', $event->data['reason']);
        $this->assertSame($this->super->id, $event->user_id);

        // La pestaña de la empresa lo muestra con su origen.
        $this->actingAs($this->super)
            ->get(route('companies.edit', $this->company))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('planAccess.exceptions.0.status', 'active')
                ->where('planAccess.tree', fn ($tree) => collect($tree)->firstWhere('key', 'promotions')['pages'][0]['actions'][0]['source'] === 'grant'));
    }

    public function test_terminar_una_excepcion_no_la_borra(): void
    {
        $exception = CompanyEntitlement::query()->create([
            'company_id' => $this->company->id, 'node' => 'promotions.*', 'effect' => 'grant',
            'starts_at' => now()->subDay(), 'reason' => 'Prueba',
        ]);

        $this->actingAs($this->super)
            ->post(route('companies.entitlements.end', [$this->company, $exception]), ['reason' => 'Ya no la usan'])
            ->assertSessionHasNoErrors();

        $exception->refresh();
        $this->assertNotNull($exception->ends_at);
        $this->assertSame($this->super->id, $exception->ended_by_user_id);
        $this->assertFalse(app(EntitlementService::class)->for($this->company->fresh())->allows('promotions.index.view'));
        $this->assertTrue(CompanyMembershipEvent::query()->where('type', CompanyMembershipEvent::ENTITLEMENT_ENDED)->exists());
    }

    public function test_no_se_quita_lo_que_siempre_va_ni_se_toca_la_plataforma(): void
    {
        $this->actingAs($this->super)
            ->post(route('companies.entitlements.store', $this->company), ['node' => 'settings.membership.*', 'effect' => 'revoke', 'reason' => 'Intento'])
            ->assertSessionHasErrors('node');

        $this->actingAs($this->super)
            ->post(route('companies.entitlements.store', $this->company), ['node' => 'platform.*', 'effect' => 'grant', 'reason' => 'Intento'])
            ->assertSessionHasErrors('node');

        $this->actingAs($this->super)
            ->post(route('companies.entitlements.store', $this->company), ['node' => 'inventario.*', 'effect' => 'grant', 'reason' => 'Intento'])
            ->assertSessionHasErrors('node');

        $this->assertSame(0, CompanyEntitlement::query()->count());
    }

    public function test_solo_el_super_usuario_ajusta_el_plan_de_una_empresa(): void
    {
        $this->actingAs($this->makeUser('admin'))
            ->post(route('companies.entitlements.store', $this->company), ['node' => 'promotions.*', 'effect' => 'grant', 'reason' => 'Me lo regalo'])
            ->assertForbidden();

        $this->assertSame(0, CompanyEntitlement::query()->count());
    }

    public function test_el_precio_especial_se_guarda_con_uno_de_los_dos_y_reemplaza_al_anterior(): void
    {
        $this->actingAs($this->super)
            ->post(route('companies.prices.store', $this->company), [
                'membership_plan_id' => $this->plan->id, 'monthly_price' => 100000, 'discount_percent' => 10, 'reason' => 'Las dos cosas',
            ])
            ->assertSessionHasErrors('monthly_price');

        $this->actingAs($this->super)
            ->post(route('companies.prices.store', $this->company), [
                'membership_plan_id' => $this->plan->id, 'discount_percent' => 95, 'reason' => 'Demasiado',
            ])
            ->assertSessionHasErrors('discount_percent');

        $this->actingAs($this->super)
            ->post(route('companies.prices.store', $this->company), ['membership_plan_id' => $this->plan->id, 'discount_percent' => 10, 'reason' => 'Cliente fundador'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->super)
            ->post(route('companies.prices.store', $this->company), ['membership_plan_id' => $this->plan->id, 'monthly_price' => 120000, 'reason' => 'Renegociado'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, CompanyPlanPrice::query()->count());
        $this->assertSame(1, CompanyPlanPrice::query()->activeAt(now())->count());
        $this->assertSame(120000, MembershipPricing::priceFor($this->plan, $this->cycle, $this->company->fresh()));
        $this->assertSame(2, CompanyMembershipEvent::query()->where('type', CompanyMembershipEvent::SPECIAL_PRICE_SET)->count());
    }

    public function test_quitarle_algo_a_un_plan_en_uso_se_confirma_y_queda_en_cada_bitacora(): void
    {
        $payload = fn (array $nodes, bool $confirm) => [
            'name' => 'Pro', 'slug' => 'pro', 'monthly_price' => 189000, 'trial_days' => 0,
            'entitlements' => $nodes, 'highlights' => ['Tienda'], 'confirm_removals' => $confirm,
            'is_public' => true, 'is_featured' => false, 'is_active' => true, 'sort_order' => 1,
        ];
        $without = array_values(array_diff(Entitlements::expand($this->plan->entitlements), Entitlements::expand(['storefront.editor.*'])));

        $this->actingAs($this->super)
            ->put(route('platform.plans.update', $this->plan), $payload($without, false))
            ->assertSessionHasErrors('entitlements');

        $this->assertContains('storefront.*', $this->plan->fresh()->entitlements);

        $this->actingAs($this->super)
            ->put(route('platform.plans.update', $this->plan), $payload($without, true))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('platform.plans.index'));

        $plan = $this->plan->fresh();
        $this->assertContains('storefront.catalog.*', $plan->entitlements);
        $this->assertNotContains('storefront.*', $plan->entitlements);
        $this->assertFalse($this->company->fresh()->entitlement()->allows('storefront.editor.view'));

        $event = CompanyMembershipEvent::query()->where('company_id', $this->company->id)->where('type', CompanyMembershipEvent::PLAN_ENTITLEMENTS_CHANGED)->sole();
        $this->assertSame(7, collect($event->data['losses'])->sum(fn ($group) => count($group['items'])));
    }

    public function test_el_editor_trae_el_arbol_sin_la_plataforma_y_genera_destacados(): void
    {
        $this->actingAs($this->super)
            ->get(route('platform.plans.edit', $this->plan))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Plans/Form')
                ->where('catalogue', fn ($modules) => ! collect($modules)->pluck('key')->intersect(['platform', 'companies'])->count()
                    && collect($modules)->pluck('key')->contains('inventory'))
                ->where('always', fn ($always) => collect($always)->contains('settings.membership.manage_payment'))
                ->where('plan.permissions', fn ($permissions) => collect($permissions)->contains('storefront.editor.view')));

        $this->actingAs($this->super)
            ->postJson(route('platform.plans.highlights'), ['entitlements' => ['inventory.*', 'promotions.index.view']])
            ->assertOk()
            ->assertJsonPath('highlights', ['Inventario']);
    }

    public function test_ajustes_membresia_muestra_el_arbol_el_precio_especial_y_lo_que_cambia_de_plan(): void
    {
        $this->priceRow(['discount_percent' => 20, 'ends_at' => now()->addDays(30)]);
        ['plan' => $basic] = $this->makePlan(['name' => 'Básico', 'slug' => 'basico', 'monthly_price' => 89000]);

        $this->actingAs($this->makeUser('admin'))->withSession($this->withActiveStore())
            ->get(route('settings.index', ['pestana' => 'membresia']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('membership.price', 152000)
                ->where('membership.special_price.description', '20 % menos')
                ->where('membership.entitlements.tree', fn ($tree) => collect($tree)->pluck('key')->contains('storefront'))
                ->where('membership.plans', function ($plans) use ($basic) {
                    $row = collect($plans)->firstWhere('id', $basic->id);

                    // Bajar al básico le quita la tienda; el precio del básico no lleva el especial.
                    return $row['diff']['lost'] > 0
                        && collect($row['diff']['losses'])->pluck('module')->contains('Tienda en linea')
                        && $row['prices'][(string) $this->cycle->id] === 89000;
                }));
    }

    public function test_el_comando_avisa_tres_dias_antes_y_anota_lo_vencido(): void
    {
        Notification::fake();
        $admin = $this->makeUser('admin');

        Carbon::setTestNow(Carbon::parse('2026-10-20 03:00:00', 'America/Bogota'));

        try {
            $grant = CompanyEntitlement::query()->create([
                'company_id' => $this->company->id, 'node' => 'promotions.*', 'effect' => 'grant',
                'starts_at' => now()->subDays(10), 'ends_at' => now()->addDays(2), 'reason' => 'Prueba',
            ]);
            $price = $this->priceRow(['discount_percent' => 10, 'ends_at' => now()->addDays(3)]);
            $expired = CompanyEntitlement::query()->create([
                'company_id' => $this->company->id, 'node' => 'inventory.transfers.direct', 'effect' => 'revoke',
                'starts_at' => now()->subDays(10), 'ends_at' => now()->subHour(), 'reason' => 'Vencida',
            ]);

            $this->artisan('membership:process')->assertSuccessful();

            Notification::assertSentTo($admin, PlanExtraExpiring::class, fn ($notification) => $notification->kind === PlanExtraExpiring::KIND_ENTITLEMENT);
            Notification::assertSentTo($admin, PlanExtraExpiring::class, fn ($notification) => $notification->kind === PlanExtraExpiring::KIND_PRICE);
            $this->assertNotNull($grant->fresh()->expiry_notified_at);
            $this->assertNotNull($price->fresh()->expiry_notified_at);
            $this->assertNotNull($expired->fresh()->expiry_recorded_at);
            $this->assertSame(1, CompanyMembershipEvent::query()->where('type', CompanyMembershipEvent::ENTITLEMENT_EXPIRED)->count());

            // Una segunda ronda no repite nada.
            $this->artisan('membership:process')->assertSuccessful();
            $this->assertSame(1, CompanyMembershipEvent::query()->where('type', CompanyMembershipEvent::ENTITLEMENT_EXPIRING)->count());
            $this->assertSame(1, CompanyMembershipEvent::query()->where('type', CompanyMembershipEvent::ENTITLEMENT_EXPIRED)->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_el_super_usuario_operando_la_empresa_no_se_limita(): void
    {
        $this->plan->update(['entitlements' => Entitlements::LEGACY_BASE]);

        $this->actingAs($this->super)
            ->withSession([TenantContext::SESSION_KEY => $this->company->id])
            ->get(route('promotions.index'))
            ->assertOk();
    }
}

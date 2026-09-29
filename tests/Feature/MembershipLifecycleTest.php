<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembershipEvent;
use App\Models\CompanyPaymentMethod;
use App\Models\CompanySignup;
use App\Models\MembershipCharge;
use App\Notifications\Platform\MembershipReminder;
use App\Services\Membership\MembershipBillingService;
use App\Services\Membership\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ConfiguresGateway;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * El calendario de la membresia: activa → gracia → suspendida, renovaciones y avisos.
 */
class MembershipLifecycleTest extends TestCase
{
    use ConfiguresGateway, CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00', 'America/Bogota'));

        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan(['name' => 'Pro', 'monthly_price' => 189000]);
        $this->putCompanyOnPlan($plan, $cycle);
        $this->company->forceFill(['membership_ends_at' => now()->addDays(3)])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pasa_de_activa_a_gracia_y_a_suspendida(): void
    {
        Carbon::setTestNow(now()->addDays(4));
        $this->artisan('membership:process')->assertSuccessful();

        $company = $this->company->fresh();
        $this->assertSame(Company::MEMBERSHIP_GRACE, $company->membership_status);
        // Cinco dias de gracia desde el vencimiento.
        $this->assertTrue($company->grace_ends_at->equalTo($company->membership_ends_at->copy()->addDays(5)));

        Carbon::setTestNow(now()->addDays(3));
        $this->artisan('membership:process')->assertSuccessful();
        $this->assertSame(Company::MEMBERSHIP_GRACE, $this->company->fresh()->membership_status);

        Carbon::setTestNow(now()->addDays(3));
        $this->artisan('membership:process')->assertSuccessful();
        $this->assertSame(Company::MEMBERSHIP_SUSPENDED, $this->company->fresh()->membership_status);

        $this->assertDatabaseHas('company_membership_events', ['company_id' => $this->company->id, 'type' => CompanyMembershipEvent::GRACE_STARTED]);
        $this->assertDatabaseHas('company_membership_events', ['company_id' => $this->company->id, 'type' => CompanyMembershipEvent::SUSPENDED]);
    }

    public function test_la_simulacion_no_cambia_nada(): void
    {
        Carbon::setTestNow(now()->addDays(20));
        $this->artisan('membership:process', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(Company::MEMBERSHIP_ACTIVE, $this->company->fresh()->membership_status);
        $this->assertSame(0, CompanyMembershipEvent::query()->count());
    }

    public function test_la_prueba_vencida_entra_en_gracia(): void
    {
        $this->company->forceFill(['membership_status' => Company::MEMBERSHIP_TRIAL, 'membership_ends_at' => now()->subHour()])->save();

        $this->artisan('membership:process')->assertSuccessful();

        $this->assertSame(Company::MEMBERSHIP_GRACE, $this->company->fresh()->membership_status);
    }

    public function test_pagar_al_dia_renueva_desde_el_vencimiento(): void
    {
        $endsAt = $this->company->membership_ends_at->copy();
        $charge = app(MembershipBillingService::class)->recordManual($this->company, $this->makeUser('admin'), 'Transferencia');

        $company = $this->company->fresh();
        $this->assertSame(MembershipCharge::STATUS_APPROVED, $charge->status);
        $this->assertSame(189000, $charge->amount);
        $this->assertTrue($company->membership_ends_at->equalTo($endsAt->copy()->addMonthNoOverflow()));
    }

    public function test_pagar_en_gracia_renueva_desde_hoy_y_reactiva(): void
    {
        Carbon::setTestNow(now()->addDays(5));
        $this->artisan('membership:process');
        $this->assertSame(Company::MEMBERSHIP_GRACE, $this->company->fresh()->membership_status);

        app(MembershipBillingService::class)->recordManual($this->company->fresh(), $this->makeUser('admin'), 'Consignación');

        $company = $this->company->fresh();
        $this->assertSame(Company::MEMBERSHIP_ACTIVE, $company->membership_status);
        $this->assertNull($company->grace_ends_at);
        $this->assertSame(now()->addMonthNoOverflow()->toDateString(), $company->membership_ends_at->toDateString());
        $this->assertDatabaseHas('company_membership_events', ['company_id' => $this->company->id, 'type' => CompanyMembershipEvent::REACTIVATED]);
    }

    public function test_aprobar_dos_veces_el_mismo_cobro_no_renueva_dos_veces(): void
    {
        $charge = MembershipCharge::query()->create([
            'company_id' => $this->company->id,
            'membership_plan_id' => $this->company->membership_plan_id,
            'billing_cycle_id' => $this->company->billing_cycle_id,
            'amount' => 189000,
            'currency' => 'COP',
            'concept' => 'Membresía',
            'status' => MembershipCharge::STATUS_PENDING,
            'method' => MembershipCharge::METHOD_CHECKOUT,
            'reference' => 'MEM-1-PRUEBA',
        ]);

        $service = app(MembershipService::class);
        $this->assertTrue($service->approveCharge($charge, 'tx-1'));
        $endsAt = $this->company->fresh()->membership_ends_at;

        $this->assertFalse($service->approveCharge($charge->fresh(), 'tx-1'));
        $this->assertTrue($endsAt->equalTo($this->company->fresh()->membership_ends_at));
        $this->assertSame(1, CompanyMembershipEvent::query()->where('type', CompanyMembershipEvent::RENEWED)->count());
    }

    public function test_el_cambio_programado_entra_con_la_renovacion(): void
    {
        ['plan' => $cadena, 'cycle' => $cycle] = $this->makePlan(['name' => 'Cadena', 'monthly_price' => 390000]);
        app(MembershipService::class)->scheduleChange($this->company, $cadena, $cycle, $this->makeUser('admin'));

        $charge = app(MembershipBillingService::class)->recordManual($this->company->fresh(), $this->makeUser('admin'), 'Transferencia');

        $company = $this->company->fresh();
        $this->assertSame(390000, $charge->amount);
        $this->assertSame($cadena->id, $company->membership_plan_id);
        $this->assertNull($company->next_membership_plan_id);
    }

    public function test_la_renovacion_automatica_cobra_primero_pendiente_y_luego_liquida(): void
    {
        $this->enableGateway();
        $this->company->forceFill(['auto_renew' => true, 'next_charge_at' => $this->company->membership_ends_at])->save();
        CompanyPaymentMethod::query()->create([
            'company_id' => $this->company->id,
            'environment' => 'sandbox',
            'payment_source_id' => '3891',
            'brand' => 'VISA',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'customer_email' => 'pagos@lacanasta.co',
            'status' => CompanyPaymentMethod::STATUS_ACTIVE,
        ]);

        Http::fake(function ($request) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/transactions')) {
                // Cuando la pasarela recibe el cobro, la fila pendiente ya existe.
                $this->assertSame(1, MembershipCharge::query()->where('status', MembershipCharge::STATUS_PENDING)->count());

                return Http::response(['data' => $this->transaction($request['reference'], $request['amount_in_cents'], 'PENDING', 'tx-99')], 201);
            }

            $reference = MembershipCharge::query()->value('reference');

            return Http::response(['data' => $this->transaction($reference, 18900000, 'APPROVED', 'tx-99')]);
        });

        Carbon::setTestNow($this->company->membership_ends_at->copy()->addHour());
        $this->artisan('membership:process')->assertSuccessful();

        $charge = MembershipCharge::query()->sole();
        $this->assertSame(MembershipCharge::STATUS_APPROVED, $charge->status);
        $this->assertSame('tx-99', $charge->gateway_transaction_id);
        $this->assertSame(Company::MEMBERSHIP_ACTIVE, $this->company->fresh()->membership_status);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request['payment_source_id'] === 3891
            && $request['recurrent'] === true
            && $request['amount_in_cents'] === 18900000
            && $request->hasHeader('Authorization', 'Bearer prv_test_Privada123'));
    }

    public function test_un_cobro_automatico_rechazado_no_renueva_y_se_reintenta_despues(): void
    {
        $this->enableGateway();
        $this->company->forceFill(['auto_renew' => true, 'next_charge_at' => $this->company->membership_ends_at])->save();
        CompanyPaymentMethod::query()->create([
            'company_id' => $this->company->id,
            'environment' => 'sandbox',
            'payment_source_id' => '3891',
            'status' => CompanyPaymentMethod::STATUS_ACTIVE,
        ]);

        Http::fake(function ($request) {
            $reference = MembershipCharge::query()->latest('id')->value('reference');

            return Http::response(['data' => $this->transaction($reference, 18900000, 'DECLINED', 'tx-'.MembershipCharge::query()->count())]);
        });

        Carbon::setTestNow($this->company->membership_ends_at->copy()->addHour());
        $this->artisan('membership:process');

        $this->assertSame(MembershipCharge::STATUS_DECLINED, MembershipCharge::query()->sole()->status);
        $this->assertSame('Fondos insuficientes', MembershipCharge::query()->sole()->failure_reason);
        $this->assertSame(Company::MEMBERSHIP_GRACE, $this->company->fresh()->membership_status);
        $this->assertTrue($this->company->fresh()->next_charge_at->isFuture());

        Carbon::setTestNow(now()->addDay()->addHour());
        $this->artisan('membership:process');
        $this->assertSame(2, MembershipCharge::query()->count());
    }

    public function test_los_recordatorios_salen_una_vez_por_dia_configurado(): void
    {
        Notification::fake();
        $admin = $this->makeUser('admin');
        $this->company->forceFill(['membership_ends_at' => now()->addDays(7)->addHours(2)])->save();

        $this->artisan('membership:process');
        $this->artisan('membership:process');

        Notification::assertSentToTimes($admin, MembershipReminder::class, 1);
        $this->assertSame(1, CompanyMembershipEvent::query()->where('type', CompanyMembershipEvent::REMINDER_SENT)->count());

        Carbon::setTestNow(now()->addDays(4));
        $this->artisan('membership:process');
        Notification::assertSentToTimes($admin, MembershipReminder::class, 2);
    }

    public function test_avisa_si_la_tarjeta_vence_antes_de_renovar(): void
    {
        Notification::fake();
        $this->enableGateway();
        $admin = $this->makeUser('admin');
        $this->company->forceFill(['auto_renew' => true, 'membership_ends_at' => now()->addDays(40)])->save();
        CompanyPaymentMethod::query()->create([
            'company_id' => $this->company->id,
            'environment' => 'sandbox',
            'payment_source_id' => '3891',
            'exp_month' => 10,
            'exp_year' => 2026,
            'status' => CompanyPaymentMethod::STATUS_ACTIVE,
        ]);

        $this->artisan('membership:process');
        $this->artisan('membership:process');

        Notification::assertSentToTimes($admin, MembershipReminder::class, 1);
        Notification::assertSentTo($admin, MembershipReminder::class, fn (MembershipReminder $notification) => $notification->kind === MembershipReminder::KIND_CARD);
    }

    public function test_las_contrataciones_sin_pagar_vencen(): void
    {
        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan();
        CompanySignup::query()->create([
            'reference' => 'SIG-VIEJA',
            'membership_plan_id' => $plan->id,
            'billing_cycle_id' => $cycle->id,
            'amount' => 100000,
            'company_name' => 'Autoservicio Viejo',
            'tax_id' => '901222333-4',
            'company_email' => 'hola@viejo.co',
            'admin_name' => 'Ana',
            'admin_email' => 'ana@viejo.co',
            'admin_password_hash' => bcrypt('x'),
            'accepted_terms_at' => now()->subHours(3),
            'status' => CompanySignup::STATUS_PENDING,
            'expires_at' => now()->subHour(),
        ]);

        $this->artisan('membership:process');

        $this->assertSame(CompanySignup::STATUS_EXPIRED, CompanySignup::query()->sole()->status);
    }
}

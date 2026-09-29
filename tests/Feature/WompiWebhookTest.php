<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembershipEvent;
use App\Models\CompanySignup;
use App\Models\MembershipCharge;
use App\Models\User;
use App\Services\Payments\PaymentGatewayResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresGateway;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Los eventos de la pasarela: firma, relectura en la API e idempotencia.
 */
class WompiWebhookTest extends TestCase
{
    use ConfiguresGateway, CreatesCompanyEnvironment, RefreshDatabase;

    protected MembershipCharge $charge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->enableGateway();
        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan(['monthly_price' => 189000]);
        $this->putCompanyOnPlan($plan, $cycle);

        $this->charge = MembershipCharge::query()->create([
            'company_id' => $this->company->id,
            'membership_plan_id' => $plan->id,
            'billing_cycle_id' => $cycle->id,
            'amount' => 189000,
            'currency' => 'COP',
            'concept' => 'Membresía Pro · Mensual',
            'status' => MembershipCharge::STATUS_PENDING,
            'method' => MembershipCharge::METHOD_CHECKOUT,
            'reference' => 'MEM-'.$this->company->id.'-ABC123',
        ]);
    }

    protected function fakeApi(array $transaction): void
    {
        Http::fake([self::WOMPI.'/transactions/*' => Http::response(['data' => $transaction])]);
    }

    public function test_la_firma_de_la_documentacion_se_verifica(): void
    {
        // La cadena del ejemplo de la documentacion de Wompi, en su orden: propiedades, timestamp y
        // secreto. El checksum que publica ese ejemplo no corresponde a su propia cadena (el SHA-256
        // real es este); lo que se prueba es el orden y que llegue en mayusculas.
        $payload = [
            'event' => 'transaction.updated',
            'data' => ['transaction' => ['id' => '1234-1610641025-49201', 'status' => 'APPROVED', 'amount_in_cents' => 4490000]],
            'signature' => [
                'properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'],
                'checksum' => '5A18EC5E8FDB7DF463E9F94774CBA8F583BA21BD04A09CEFF2EA68A4BC0AEFBE',
            ],
            'timestamp' => 1530291411,
        ];

        $wompi = app(PaymentGatewayResolver::class)->wompi();
        $this->assertFalse($wompi->verifyEventSignature($payload));

        app(PaymentGatewayResolver::class)->credential('sandbox')->forceFill(['events_secret' => 'prod_events_OcHnIzeBl5socpwByQ4hA52Em3USQ93Z'])->save();
        $this->assertTrue(app(PaymentGatewayResolver::class)->wompi()->verifyEventSignature($payload));
    }

    public function test_la_firma_de_integridad_coincide_con_la_documentacion(): void
    {
        app(PaymentGatewayResolver::class)->credential('sandbox')->forceFill(['integrity_secret' => 'prod_integrity_Z5mMke9x0k8gpErbDqwrJXMqsI6SFli6'])->save();

        $this->assertSame(
            '37c8407747e595535433ef8f6a811d853cd943046624a0ec04662b17bbf33bf5',
            app(PaymentGatewayResolver::class)->wompi()->integritySignature('sk8-438k4-xmxm392-sn2m', 2490000, 'COP'),
        );
    }

    public function test_una_firma_invalida_responde_401_y_no_toca_nada(): void
    {
        Http::fake();
        $event = $this->signedEvent($this->transaction($this->charge->reference, 18900000), 'test_events_otro');

        $this->postJson(route('webhooks.wompi'), $event)->assertStatus(401);

        Http::assertNothingSent();
        $this->assertSame(MembershipCharge::STATUS_PENDING, $this->charge->fresh()->status);
    }

    public function test_no_pide_token_csrf(): void
    {
        $this->fakeApi($this->transaction($this->charge->reference, 18900000));

        // Sin `postJson` ni cabeceras de prueba: como llega de afuera.
        $this->withMiddleware()
            ->call('POST', '/webhooks/wompi', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($this->signedEvent($this->transaction($this->charge->reference, 18900000))))
            ->assertOk();
    }

    public function test_un_pago_aprobado_renueva_una_sola_vez(): void
    {
        $transaction = $this->transaction($this->charge->reference, 18900000);
        $this->fakeApi($transaction);
        $endsAt = $this->company->membership_ends_at->copy();

        $this->postJson(route('webhooks.wompi'), $this->signedEvent($transaction))->assertOk();
        $this->postJson(route('webhooks.wompi'), $this->signedEvent($transaction))->assertOk();

        $this->assertSame(MembershipCharge::STATUS_APPROVED, $this->charge->fresh()->status);
        $this->assertTrue($this->company->fresh()->membership_ends_at->equalTo($endsAt->addMonthNoOverflow()));
        $this->assertSame(1, CompanyMembershipEvent::query()->where('type', CompanyMembershipEvent::RENEWED)->count());
    }

    public function test_manda_lo_que_dice_la_api_no_el_cuerpo_del_evento(): void
    {
        // El evento dice aprobado, la API dice rechazado.
        $this->fakeApi($this->transaction($this->charge->reference, 18900000, 'DECLINED'));

        $this->postJson(route('webhooks.wompi'), $this->signedEvent($this->transaction($this->charge->reference, 18900000)))->assertOk();

        $this->assertSame(MembershipCharge::STATUS_DECLINED, $this->charge->fresh()->status);
        $this->assertSame(0, CompanyMembershipEvent::query()->where('type', CompanyMembershipEvent::RENEWED)->count());
    }

    public function test_un_monto_distinto_queda_con_error_y_no_renueva(): void
    {
        $transaction = $this->transaction($this->charge->reference, 100);
        $this->fakeApi($transaction);

        $this->postJson(route('webhooks.wompi'), $this->signedEvent($transaction))->assertOk();

        $this->assertSame(MembershipCharge::STATUS_ERROR, $this->charge->fresh()->status);
        $this->assertSame(0, CompanyMembershipEvent::query()->where('type', CompanyMembershipEvent::RENEWED)->count());
    }

    public function test_si_la_api_no_responde_pide_reintento(): void
    {
        Http::fake([self::WOMPI.'/transactions/*' => Http::response([], 500)]);

        $this->postJson(route('webhooks.wompi'), $this->signedEvent($this->transaction($this->charge->reference, 18900000)))->assertStatus(503);

        $this->assertSame(MembershipCharge::STATUS_PENDING, $this->charge->fresh()->status);
    }

    public function test_otros_eventos_se_ignoran(): void
    {
        Http::fake();
        $event = $this->signedEvent($this->transaction($this->charge->reference, 18900000));
        $event['event'] = 'nequi_token.updated';

        $this->postJson(route('webhooks.wompi'), $event)->assertOk()->assertJson(['message' => 'Evento ignorado.']);
        Http::assertNothingSent();
    }

    public function test_una_contratacion_pagada_crea_la_empresa_una_sola_vez(): void
    {
        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan(['name' => 'Básico', 'monthly_price' => 89000]);
        $signup = CompanySignup::query()->create([
            'reference' => 'SIG-PRUEBA123',
            'membership_plan_id' => $plan->id,
            'billing_cycle_id' => $cycle->id,
            'amount' => 89000,
            'currency' => 'COP',
            'company_name' => 'Autoservicio La 80',
            'tax_id' => '901000111-2',
            'city' => 'Medellín',
            'company_email' => 'hola@la80.co',
            'admin_name' => 'Paula',
            'admin_last_name' => 'Restrepo',
            'admin_email' => 'paula@la80.co',
            'admin_password_hash' => Hash::make('ClaveSegura#2026'),
            'accepted_terms_at' => now(),
            'status' => CompanySignup::STATUS_PENDING,
            'expires_at' => now()->addHours(2),
        ]);
        $transaction = $this->transaction('SIG-PRUEBA123', 8900000, 'APPROVED', 'tx-sig');
        $this->fakeApi($transaction);

        $this->postJson(route('webhooks.wompi'), $this->signedEvent($transaction))->assertOk();
        $this->postJson(route('webhooks.wompi'), $this->signedEvent($transaction))->assertOk();

        $company = Company::query()->where('tax_id', '901000111-2')->sole();
        $this->assertSame(Company::MEMBERSHIP_ACTIVE, $company->membership_status);
        $this->assertSame($company->id, $signup->fresh()->company_id);
        $this->assertSame(CompanySignup::STATUS_PAID, $signup->fresh()->status);

        $admin = User::query()->where('email', 'paula@la80.co')->sole();
        $this->assertTrue(Hash::check('ClaveSegura#2026', $admin->password));
        $this->assertFalse((bool) $admin->password_change_required);

        $this->assertSame(1, MembershipCharge::query()->where('company_id', $company->id)->where('status', MembershipCharge::STATUS_APPROVED)->count());
    }
}

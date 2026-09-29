<?php

namespace Tests\Feature;

use App\Models\CompanyMembershipEvent;
use App\Models\CompanyPaymentMethod;
use App\Models\MembershipCharge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ConfiguresGateway;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * La tarjeta de la membresia: el numero y el CVC nunca llegan a este servidor.
 */
class PaymentMethodTest extends TestCase
{
    use ConfiguresGateway, CreatesCompanyEnvironment, RefreshDatabase;

    protected User $admin;

    protected const TOKEN = 'tok_test_1_AbCdEf123456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan(['monthly_price' => 189000], ['online_store']);
        $this->putCompanyOnPlan($plan, $cycle);
        $this->admin = $this->makeUser('admin');
        $this->enableGateway();
    }

    protected function cardPayload(array $extra = []): array
    {
        return array_merge([
            'token' => self::TOKEN,
            'acceptance_token' => 'eyJhbGciOiJIUzI1NiJ9.aceptacion',
            'personal_auth_token' => 'eyJhbGciOiJIUzI1NiJ9.datos',
            'accepted' => true,
            'email' => 'pagos@lacanasta.co',
        ], $extra);
    }

    protected function fakePaymentSource(): void
    {
        Http::fake([
            self::WOMPI.'/payment_sources' => Http::response(['data' => [
                'id' => 3891,
                'public_data' => ['bin' => '424242', 'last_four' => '4242', 'exp_month' => '08', 'exp_year' => '30', 'card_holder' => 'Marcela Ospina', 'type' => 'CARD'],
                'type' => 'CARD',
                'status' => 'AVAILABLE',
            ]], 201),
        ]);
    }

    public function test_si_la_peticion_trae_el_numero_o_el_cvc_se_rechaza_entera(): void
    {
        Http::fake();
        Log::spy();

        foreach ([['card_number' => '4242424242424242'], ['number' => '4242424242424242'], ['cvc' => '123']] as $forbidden) {
            $this->actingAs($this->admin)->withSession($this->withActiveStore())
                ->put(route('settings.membership.card'), $this->cardPayload($forbidden))
                ->assertSessionHasErrors('token');

            // Tampoco queda como «old input» en la sesion.
            $this->assertNull(session()->getOldInput('number'));
            $this->assertNull(session()->getOldInput('card_number'));
            $this->assertNull(session()->getOldInput('cvc'));
        }

        Http::assertNothingSent();
        $this->assertSame(0, CompanyPaymentMethod::query()->count());
        Log::shouldNotHaveReceived('warning', [\Mockery::on(fn ($message) => str_contains((string) $message, '4242424242424242'))]);
    }

    public function test_guarda_solo_lo_que_devuelve_la_pasarela(): void
    {
        $this->fakePaymentSource();

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->put(route('settings.membership.card'), $this->cardPayload(['brand' => 'AMEX', 'last4' => '0000']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Tarjeta guardada (•••• 4242).');

        $card = CompanyPaymentMethod::query()->sole();
        $this->assertSame('3891', $card->payment_source_id);
        // La marca sale del BIN de la pasarela, no de lo que mando el navegador.
        $this->assertSame('VISA', $card->brand);
        $this->assertSame('4242', $card->last4);
        $this->assertSame(8, $card->exp_month);
        $this->assertSame(2030, $card->exp_year);
        $this->assertSame('Marcela Ospina', $card->holder_name);
        $this->assertSame('sandbox', $card->environment);

        Http::assertSent(fn ($request) => $request->url() === self::WOMPI.'/payment_sources'
            && $request['type'] === 'CARD'
            && $request['token'] === self::TOKEN
            && $request['acceptance_token'] === 'eyJhbGciOiJIUzI1NiJ9.aceptacion'
            && $request['accept_personal_auth'] === 'eyJhbGciOiJIUzI1NiJ9.datos'
            && $request->hasHeader('Authorization', 'Bearer prv_test_Privada123'));

        // El token de un solo uso no queda en ninguna parte.
        foreach (['company_payment_methods', 'company_membership_events', 'access_logs'] as $table) {
            $dump = json_encode(DB::table($table)->get());
            $this->assertStringNotContainsString(self::TOKEN, (string) $dump, "El token quedo en {$table}.");
        }

        $this->assertDatabaseHas('company_membership_events', ['company_id' => $this->company->id, 'type' => CompanyMembershipEvent::CARD_UPDATED]);
    }

    public function test_la_pantalla_nunca_recibe_el_id_de_la_fuente(): void
    {
        $this->fakePaymentSource();
        $this->actingAs($this->admin)->withSession($this->withActiveStore())->put(route('settings.membership.card'), $this->cardPayload());

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->get(route('settings.index', ['pestana' => 'membresia']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('membership.card.last4', '4242')
                ->where('membership.card.usable', true)
                ->missing('membership.card.payment_source_id'))
            ->assertDontSee('3891');
    }

    public function test_sin_tarjeta_no_se_enciende_la_renovacion_automatica(): void
    {
        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->put(route('settings.membership.auto_renew'), ['auto_renew' => true])
            ->assertSessionHasErrors(['auto_renew' => 'Agrega una tarjeta antes de encender la renovación automática.']);

        $this->assertFalse((bool) $this->company->fresh()->auto_renew);
    }

    public function test_con_tarjeta_la_renovacion_automatica_programa_el_cobro(): void
    {
        $this->fakePaymentSource();
        $this->actingAs($this->admin)->withSession($this->withActiveStore())->put(route('settings.membership.card'), $this->cardPayload());

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->put(route('settings.membership.auto_renew'), ['auto_renew' => true])
            ->assertSessionHasNoErrors();

        $company = $this->company->fresh();
        $this->assertTrue((bool) $company->auto_renew);
        $this->assertTrue($company->next_charge_at->equalTo($company->membership_ends_at));
    }

    public function test_pagar_ahora_con_la_tarjeta_guardada_renueva_y_deja_comprobante(): void
    {
        CompanyPaymentMethod::query()->create([
            'company_id' => $this->company->id,
            'environment' => 'sandbox',
            'payment_source_id' => '3891',
            'status' => CompanyPaymentMethod::STATUS_ACTIVE,
        ]);
        $endsAt = $this->company->membership_ends_at->copy();

        Http::fake(function ($request) {
            $reference = MembershipCharge::query()->value('reference');

            return $request->method() === 'POST'
                ? Http::response(['data' => $this->transaction($reference, 18900000, 'PENDING', 'tx-7')], 201)
                : Http::response(['data' => $this->transaction($reference, 18900000, 'APPROVED', 'tx-7')]);
        });

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('settings.membership.pay'), ['method' => 'saved_card'])
            ->assertSessionHas('success', 'Pago aprobado. La membresía quedó renovada.');

        $charge = MembershipCharge::query()->sole();
        $this->assertSame(MembershipCharge::STATUS_APPROVED, $charge->status);
        $this->assertNull(Http::recorded(fn ($request) => $request->method() === 'POST')->first()[0]['recurrent'] ?? null);
        $this->assertTrue($this->company->fresh()->membership_ends_at->equalTo($endsAt->addMonthNoOverflow()));

        $pdf = $this->actingAs($this->admin)->withSession($this->withActiveStore())->get(route('settings.membership.receipt', $charge));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
    }

    public function test_pagar_con_otro_medio_abre_el_checkout_firmado(): void
    {
        $response = $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('settings.membership.pay'), ['method' => 'checkout'], ['X-Inertia' => 'true']);

        $response->assertStatus(409);
        $url = $response->headers->get('X-Inertia-Location');
        $this->assertStringStartsWith('https://checkout.wompi.co/p/?', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $charge = MembershipCharge::query()->sole();

        $this->assertSame('pub_test_Llave123', $query['public-key']);
        $this->assertSame('18900000', $query['amount-in-cents']);
        $this->assertSame($charge->reference, $query['reference']);
        $this->assertSame(
            hash('sha256', $charge->reference.'18900000COP'.$query['expiration-time'].self::INTEGRITY_SECRET),
            $query['signature:integrity'],
        );
        $this->assertSame(route('settings.membership.return'), $query['redirect-url']);
    }

    public function test_el_comprobante_de_otra_empresa_no_se_descarga(): void
    {
        $other = $this->makeOtherCompany();
        $charge = MembershipCharge::query()->create([
            'company_id' => $other['company']->id,
            'amount' => 100000,
            'concept' => 'Membresía',
            'status' => MembershipCharge::STATUS_APPROVED,
            'method' => MembershipCharge::METHOD_MANUAL,
            'reference' => 'MEM-OTRA-1',
            'paid_at' => now(),
        ]);

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->get(route('settings.membership.receipt', $charge))
            ->assertNotFound();
    }

    public function test_sin_pasarela_no_hay_botones_de_pago(): void
    {
        $this->enableGateway(false);

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->get(route('settings.index', ['pestana' => 'membresia']))
            ->assertInertia(fn (Assert $page) => $page->where('membership.gateway.enabled', false));

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->post(route('settings.membership.pay'), ['method' => 'checkout'])
            ->assertSessionHasErrors('method');

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->getJson(route('settings.membership.acceptance'))
            ->assertStatus(409);
    }

    public function test_los_tokens_de_aceptacion_se_piden_con_el_encabezado_vigente(): void
    {
        Http::fake([
            self::WOMPI.'/merchants/info' => Http::response(['data' => [
                'presigned_acceptance' => ['acceptance_token' => 'eyJ.acepta', 'permalink' => 'https://wompi.co/reglamento.pdf', 'type' => 'END_USER_POLICY'],
                'presigned_personal_data_auth' => ['acceptance_token' => 'eyJ.datos', 'permalink' => 'https://wompi.com/datos.pdf', 'type' => 'PERSONAL_DATA_AUTH'],
            ]]),
        ]);

        $this->actingAs($this->admin)->withSession($this->withActiveStore())
            ->getJson(route('settings.membership.acceptance'))
            ->assertOk()
            ->assertJson([
                'public_key' => 'pub_test_Llave123',
                'api_base' => self::WOMPI,
                'acceptance_token' => 'eyJ.acepta',
                'personal_auth_token' => 'eyJ.datos',
            ])
            ->assertJsonMissing(['private_key' => 'prv_test_Privada123']);

        Http::assertSent(fn ($request) => $request->hasHeader('x-merchant-public-key', 'pub_test_Llave123'));
    }

    public function test_quien_no_administra_pagos_no_toca_la_tarjeta(): void
    {
        $supervisor = $this->makeUser('supervisor');

        $this->actingAs($supervisor)->withSession($this->withActiveStore())
            ->put(route('settings.membership.card'), $this->cardPayload())
            ->assertForbidden();
    }
}

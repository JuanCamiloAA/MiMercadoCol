<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayAccount;
use App\Models\PaymentMethod;
use App\Models\PaymentTerminal;
use App\Models\User;
use App\Services\Membership\Entitlements;
use App\Services\Payments\PaymentContext;
use App\Services\Payments\PaymentMethodAvailability;
use App\Services\PlatformSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\CreatesPaymentGateways;
use Tests\TestCase;

/**
 * Medios de pago > Medios, Pasarelas, Datáfonos y Transacciones: lo que la empresa configura, con
 * las llaves secretas que nunca vuelven al navegador y el plan que decide si hay pasarelas.
 */
class PaymentMethodsAdminTest extends TestCase
{
    use CreatesCompanyEnvironment, CreatesPaymentGateways, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->admin = $this->makeUser('admin');
    }

    protected function asAdmin(?User $user = null): static
    {
        return $this->actingAs($user ?? $this->admin)->withSession($this->withActiveStore());
    }

    /** @return array<string, mixed> */
    protected function nequi(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'Nequi',
            'kind' => PaymentMethod::KIND_TRANSFER,
            'gateway_account_id' => '',
            'icon' => 'device-mobile',
            'display' => ['description_pos' => 'Pide el pantallazo', 'description_online' => '', 'instructions' => "Nequi 300 123 4567\nA nombre de La Canasta", 'highlight' => true],
            'requires_reference' => true,
            'reference_label' => 'Número de la transacción',
            'fee_percent' => 0,
            'fee_fixed' => '',
            'is_active' => true,
            'availability' => [
                'channels' => ['pos'],
                'stores' => [],
                'channel_stores' => ['online' => []],
                'registers' => [],
                'schedule' => [['days' => [1, 2, 3, 4, 5], 'from' => '07:00', 'to' => '21:00']],
                'valid_from' => '',
                'valid_until' => '',
                'min_amount' => '',
                'max_amount' => 2000000,
                'delivery_modes' => [],
                'customer' => ['registered' => false, 'credit_line' => false, 'store_credit' => false],
                'combinable' => true,
            ],
        ], $overrides);
    }

    public function test_la_pantalla_lista_los_medios_sin_el_cambio_por_devolucion(): void
    {
        $this->asAdmin()->get(route('payment_methods.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('PaymentMethods/Index')
                ->where('methods', fn ($methods) => collect($methods)->pluck('system_key')->contains('cash')
                    && ! collect($methods)->pluck('kind')->contains('exchange'))
                ->has('kinds')
                ->has('icons'));
    }

    public function test_crear_un_medio_guarda_solo_las_reglas_que_limitan(): void
    {
        $this->asAdmin()->post(route('payment_methods.store'), $this->nequi())->assertSessionHasNoErrors();

        $method = PaymentMethod::query()->withoutGlobalScopes()->where('name', 'Nequi')->firstOrFail();

        $this->assertSame(PaymentMethod::KIND_TRANSFER, $method->kind);
        $this->assertFalse($method->is_system);
        // Lo vacio no se guarda: vacio es «sin restriccion».
        $this->assertSame(['channels', 'schedule', 'max_amount'], array_keys($method->availability));
        $this->assertSame(2000000, $method->availability['max_amount']);
        $this->assertTrue($method->display['highlight']);
        $this->assertNull($method->gateway_account_id);
    }

    public function test_las_validaciones_del_formulario(): void
    {
        // Pide referencia sin decir cual.
        $this->asAdmin()->post(route('payment_methods.store'), $this->nequi(['reference_label' => '']))->assertSessionHasErrors('reference_label');

        // Una franja que no dura nada.
        $this->asAdmin()->post(route('payment_methods.store'), $this->nequi(['availability' => ['schedule' => [['days' => [1], 'from' => '09:00', 'to' => '09:00']]]]))
            ->assertSessionHasErrors('availability.schedule.0.to');

        // Maximo menor que el minimo.
        $this->asAdmin()->post(route('payment_methods.store'), $this->nequi(['availability' => ['min_amount' => 5000, 'max_amount' => 1000]]))
            ->assertSessionHasErrors('availability.max_amount');

        // Un QR de caja no se ofrece en la tienda.
        $account = $this->wompiAccount();
        $this->asAdmin()->post(route('payment_methods.store'), $this->nequi(['kind' => PaymentMethod::KIND_GATEWAY_LINK, 'gateway_account_id' => $account->id, 'requires_reference' => false, 'availability' => ['channels' => ['online']]]))
            ->assertSessionHasErrors('availability.channels');

        // Un medio de pasarela sin cuenta.
        $this->asAdmin()->post(route('payment_methods.store'), $this->nequi(['kind' => PaymentMethod::KIND_GATEWAY_CHECKOUT, 'gateway_account_id' => '', 'requires_reference' => false, 'availability' => ['channels' => []]]))
            ->assertSessionHasErrors('gateway_account_id');

        $this->assertSame(0, PaymentMethod::query()->withoutGlobalScopes()->where('name', 'Nequi')->count());
    }

    public function test_un_medio_del_sistema_no_cambia_de_tipo_ni_se_borra(): void
    {
        $cash = $this->systemMethod('cash');

        $this->asAdmin()->put(route('payment_methods.update', $cash), $this->nequi(['name' => 'Efectivo', 'kind' => PaymentMethod::KIND_TRANSFER, 'requires_reference' => false]))
            ->assertSessionHasErrors('kind');

        $this->asAdmin()->delete(route('payment_methods.destroy', $cash))->assertSessionHas('error');
        $this->assertNotNull($cash->fresh());
    }

    public function test_apagar_ordenar_y_borrar(): void
    {
        $this->asAdmin()->post(route('payment_methods.store'), $this->nequi())->assertSessionHasNoErrors();
        $nequi = PaymentMethod::query()->withoutGlobalScopes()->where('name', 'Nequi')->firstOrFail();

        $this->asAdmin()->patch(route('payment_methods.toggle', $nequi))->assertSessionHasNoErrors();
        $this->assertFalse($nequi->fresh()->is_active);

        $ids = PaymentMethod::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->orderByDesc('id')->pluck('id')->all();
        $this->asAdmin()->post(route('payment_methods.reorder'), ['ids' => $ids])->assertSessionHasNoErrors();
        $this->assertSame(10, (int) $nequi->fresh()->sort_order);

        // Nunca se uso: se puede borrar.
        $this->asAdmin()->delete(route('payment_methods.destroy', $nequi))->assertSessionHas('success');
        $this->assertNull(PaymentMethod::query()->withoutGlobalScopes()->find($nequi->id));
    }

    public function test_un_medio_usado_no_se_borra(): void
    {
        $nequi = $this->manualMethod('Nequi', PaymentMethod::KIND_TRANSFER);
        $product = $this->makeProduct(['price' => 1000, 'tax_rate' => 0]);
        $session = app(\App\Services\RegisterSessionService::class)->open($this->register, $this->admin, 0);
        app(\App\Services\SaleService::class)->create([
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['payment_method_id' => $nequi->id, 'amount' => 1000]],
        ], $this->admin, $this->store, $session);

        $this->asAdmin()->delete(route('payment_methods.destroy', $nequi))->assertSessionHas('error');
        $this->assertNotNull($nequi->fresh());
    }

    public function test_las_llaves_secretas_nunca_llegan_al_navegador(): void
    {
        $this->asAdmin()->post(route('payment_methods.gateways.store'), [
            'provider' => 'wompi',
            'environment' => PaymentGatewayAccount::ENV_SANDBOX,
            'label' => 'Cuenta principal',
            'fields' => [
                'public_key' => 'pub_test_visible',
                'private_key' => 'prv_test_SECRETA_1234',
                'events_secret' => 'test_events_SECRETO_9999',
                'integrity_secret' => 'test_integrity_SECRETO_7777',
            ],
        ])->assertSessionHasNoErrors();

        $account = PaymentGatewayAccount::query()->withoutGlobalScopes()->firstOrFail();

        // Cifradas en la base, apagada hasta probarla.
        $raw = (string) DB::table('payment_gateway_accounts')->where('id', $account->id)->value('secret_fields');
        $this->assertStringNotContainsString('SECRETA', $raw);
        $this->assertFalse($account->is_enabled);
        $this->assertSame('prv_test_SECRETA_1234', $account->secretField('private_key'));

        $response = $this->asAdmin()->get(route('payment_methods.gateways.index'))->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString('prv_test_SECRETA_1234', $html);
        $this->assertStringNotContainsString('SECRETO_9999', $html);
        $this->assertStringNotContainsString('SECRETO_7777', $html);
        $this->assertStringContainsString('pub_test_visible', $html);
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('accounts.0.secret_fields.private_key', fn ($masked) => str_ends_with((string) $masked, '1234') && ! str_contains((string) $masked, 'SECRETA')));
    }

    public function test_una_llave_secreta_vacia_se_conserva_y_el_token_rota(): void
    {
        $account = $this->wompiAccount();
        $token = $account->webhook_token;

        $this->asAdmin()->put(route('payment_methods.gateways.update', $account), [
            'label' => 'Renombrada',
            'fields' => ['public_key' => 'pub_test_nueva', 'private_key' => '', 'events_secret' => '', 'integrity_secret' => 'nuevo_integrity'],
        ])->assertSessionHasNoErrors();

        $account->refresh();
        $this->assertSame('pub_test_nueva', $account->publicField('public_key'));
        $this->assertSame('prv_test_llave', $account->secretField('private_key'));
        $this->assertSame('nuevo_integrity', $account->secretField('integrity_secret'));
        $this->assertNull($account->verified_at);

        $this->asAdmin()->post(route('payment_methods.gateways.rotate', $account))->assertSessionHasNoErrors();
        $this->assertNotSame($token, $account->fresh()->webhook_token);
        $this->postJson(route('webhooks.payments', ['provider' => 'wompi', 'token' => $token]), [])->assertNotFound();
    }

    public function test_no_se_enciende_una_cuenta_incompleta(): void
    {
        $incomplete = $this->gatewayAccount('wompi', ['public_key' => 'pub_prod_x'], ['private_key' => 'prv_prod_x'], PaymentGatewayAccount::ENV_PRODUCTION, enabled: false);
        $this->asAdmin()->patch(route('payment_methods.gateways.toggle', $incomplete))->assertSessionHas('error');
        $this->assertFalse($incomplete->fresh()->is_enabled);
    }

    public function test_una_pasarela_apagada_por_la_plataforma_sale_de_todas_partes(): void
    {
        $account = $this->wompiAccount();
        $qr = $this->gatewayMethod(PaymentMethod::KIND_GATEWAY_LINK, $account);
        app(PlatformSettingsService::class)->set('payments.company_providers', ['bold']);

        // La plataforma apago Wompi: sus medios dejan de aparecer sin tocar la cuenta de nadie.
        $reason = app(PaymentMethodAvailability::class)->reason($qr->fresh(), PaymentContext::pos($this->store->id, $this->register->id));
        $this->assertStringContainsString('no está disponible por ahora', (string) $reason);
        $this->assertTrue($account->fresh()->is_enabled);

        $account->forceFill(['is_enabled' => false])->save();
        $this->asAdmin()->patch(route('payment_methods.gateways.toggle', $account))->assertSessionHas('error');
    }

    public function test_un_datafono_se_registra_y_la_prueba_solo_en_sandbox(): void
    {
        $bold = $this->gatewayAccount('bold', ['identity_key' => 'idk', 'user_email' => 'caja@lacanasta.co'], [], PaymentGatewayAccount::ENV_PRODUCTION);

        $this->asAdmin()->post(route('payment_methods.terminals.store'), [
            'name' => 'Datáfono caja 1',
            'gateway_account_id' => $bold->id,
            'store_id' => $this->store->id,
            'register_id' => $this->register->id,
            'device_id' => 'SN-0001',
            'model' => 'SmartPro',
        ])->assertSessionHasNoErrors();

        $terminal = PaymentTerminal::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->register->id, (int) $terminal->register_id);

        // Con una cuenta de produccion no se hace el cobro de prueba.
        $this->asAdmin()->post(route('payment_methods.terminals.test', $terminal))->assertSessionHas('error');

        $this->asAdmin()->get(route('payment_methods.terminals.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('PaymentMethods/Terminals')->has('terminals', 1));
    }

    public function test_sin_permiso_no_se_entra(): void
    {
        $cashier = $this->makeUser('cajero');

        $this->asAdmin($cashier)->get(route('payment_methods.index'))->assertForbidden();
        $this->asAdmin($cashier)->post(route('payment_methods.store'), $this->nequi())->assertForbidden();
        $this->asAdmin($cashier)->get(route('payment_methods.transactions.index'))->assertForbidden();
    }

    public function test_un_plan_sin_pasarelas_solo_deja_los_medios_manuales(): void
    {
        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan(['entitlements' => Entitlements::preset('basico')]);
        $this->putCompanyOnPlan($plan, $cycle);
        $admin = $this->makeUser('admin');

        $this->asAdmin($admin)->get(route('payment_methods.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('kinds', fn ($kinds) => collect($kinds)->firstWhere('value', 'gateway_link')['available'] === false
                && collect($kinds)->firstWhere('value', 'transfer')['available'] === true));

        $this->asAdmin($admin)->get(route('payment_methods.gateways.index'))->assertForbidden();

        $account = $this->wompiAccount();
        $this->asAdmin($admin)->post(route('payment_methods.store'), $this->nequi([
            'name' => 'QR', 'kind' => PaymentMethod::KIND_GATEWAY_LINK, 'gateway_account_id' => $account->id, 'requires_reference' => false,
        ]))->assertSessionHasErrors('kind');

        // Y uno que ya existiera deja de ofrecerse.
        $qr = $this->gatewayMethod(PaymentMethod::KIND_GATEWAY_LINK, $account);
        $this->assertStringContainsString('Tu plan no incluye', (string) app(PaymentMethodAvailability::class)->reason($qr->fresh(), PaymentContext::pos($this->store->id, $this->register->id)));
    }

    public function test_la_plataforma_elige_que_pasarelas_pueden_conectar_las_empresas(): void
    {
        $root = $this->makeSuperAdmin();

        $this->actingAs($root)->get(route('platform.company_gateways.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Platform/CompanyGateways/Index')->has('providers', 5));

        $this->actingAs($root)->put(route('platform.company_gateways.update'), ['providers' => ['wompi', 'bold']])->assertSessionHasNoErrors();
        $this->assertSame(['wompi', 'bold'], app(PlatformSettingsService::class)->get('payments.company_providers'));

        // Una empresa ya no puede conectar Mercado Pago.
        $this->asAdmin()->post(route('payment_methods.gateways.store'), [
            'provider' => 'mercadopago',
            'environment' => PaymentGatewayAccount::ENV_SANDBOX,
            'fields' => ['access_token' => 'x', 'webhook_secret' => 'y'],
        ])->assertSessionHasErrors('provider');
    }

    public function test_transacciones_y_su_detalle(): void
    {
        $account = $this->wompiAccount();
        $method = $this->gatewayMethod(PaymentMethod::KIND_GATEWAY_LINK, $account);
        $transaction = \App\Models\PaymentTransaction::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'payment_method_id' => $method->id,
            'gateway_account_id' => $account->id,
            'provider' => 'wompi',
            'environment' => 'sandbox',
            'reference' => \App\Models\PaymentTransaction::newReference(),
            'amount' => 15000,
            'currency' => 'COP',
            'channel' => 'pos',
            'mode' => 'qr',
            'status' => 'approved',
            'approved_at' => now(),
            'payload' => ['id' => 'tx-1', 'status' => 'APPROVED'],
        ]);

        $this->asAdmin()->get(route('payment_methods.transactions.index', ['estado' => 'conciliar']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('PaymentMethods/Transactions')->where('toReconcile', 1)->has('transactions.data', 1));

        $this->asAdmin()->get(route('payment_methods.transactions.show', $transaction))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('PaymentMethods/TransactionShow')->where('reconcilable', true));

        // Dejar constancia de que se devolvio por fuera la saca de la bandeja.
        $this->asAdmin()->post(route('payment_methods.transactions.reconcile', $transaction), [
            'action' => 'refunded_outside',
            'note' => 'Se devolvió desde el panel de Wompi.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(\App\Models\PaymentTransaction::RESOLUTION_REFUNDED_OUTSIDE, $transaction->fresh()->resolution);
        $this->assertSame(0, \App\Models\PaymentTransaction::query()->withoutGlobalScopes()->toReconcile()->count());
    }
}

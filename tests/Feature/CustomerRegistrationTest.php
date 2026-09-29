<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerActivation;
use App\Notifications\CustomerActivationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Registro de clientes de la tienda en linea, en la empresa de la sede elegida.
 */
class CustomerRegistrationTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->publishStorefront();
        $this->shopAt();
    }

    /** @return array<string, mixed> */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'document_type' => 'CC',
            'document_number' => '1036123456',
            'name' => 'Laura Gómez',
            'email' => 'Laura.Gomez@Correo.co',
            'phone' => '3001234567',
            'address' => 'Calle 10 # 43-20',
            'neighborhood' => 'El Poblado',
            'password' => 'ClaveSegura#2026',
            'password_confirmation' => 'ClaveSegura#2026',
            'accept_terms' => true,
        ], $overrides);
    }

    public function test_un_cliente_nuevo_queda_en_la_empresa_de_la_sede_con_sesion(): void
    {
        $this->get(route('storefront.register'))->assertOk();

        $this->post(route('storefront.register.store'), $this->payload())->assertRedirect(route('storefront.home'));

        $customer = Customer::query()->withoutGlobalScopes()->where('document_number', '1036123456')->sole();
        $this->assertSame($this->company->id, $customer->company_id);
        $this->assertSame($this->store->id, $customer->preferred_store_id);
        $this->assertTrue((bool) $customer->is_online_user);
        $this->assertSame('laura.gomez@correo.co', $customer->email);
        $this->assertNotNull($customer->accepted_terms_at);
        $this->assertTrue(Hash::check('ClaveSegura#2026', $customer->password));
        $this->assertAuthenticatedAs($customer, 'customer');
    }

    public function test_sin_aceptar_los_terminos_no_se_registra(): void
    {
        $this->post(route('storefront.register.store'), $this->payload(['accept_terms' => false]))->assertSessionHasErrors('accept_terms');
        $this->assertSame(0, Customer::query()->withoutGlobalScopes()->count());
    }

    public function test_vuelve_a_donde_iba(): void
    {
        $this->get(route('storefront.checkout.create'))->assertRedirect(route('storefront.login'));

        $this->post(route('storefront.register.store'), $this->payload())->assertRedirect(route('storefront.checkout.create'));
    }

    public function test_un_documento_con_cuenta_en_linea_debe_ingresar(): void
    {
        $this->makeCustomer(['document_number' => '1036123456', 'is_online_user' => true, 'password' => Hash::make('Otra#Clave1')]);

        $this->post(route('storefront.register.store'), $this->payload())
            ->assertSessionHasErrors(['document_number' => 'Ya tienes cuenta en esta tienda. Ingresa con tu documento.']);
        $this->assertGuest('customer');
    }

    public function test_un_cliente_de_la_caja_se_activa_solo_con_el_codigo_de_su_correo(): void
    {
        Notification::fake();
        $existing = $this->makeCustomer(['document_number' => '1036123456', 'email' => 'real@correo.co', 'phone' => '3110000000', 'credit_enabled' => true, 'credit_limit' => 300000]);

        // Quien se registra escribe otro correo: el codigo va al de la ficha, no al del formulario.
        $this->post(route('storefront.register.store'), $this->payload(['email' => 'intruso@correo.co']))
            ->assertRedirect(route('storefront.register.activation'));

        $this->assertGuest('customer');
        $existing->refresh();
        $this->assertFalse((bool) $existing->is_online_user);
        $this->assertNull($existing->password);

        $code = null;
        Notification::assertSentTo(new AnonymousNotifiable, CustomerActivationCode::class, function (CustomerActivationCode $notification, $channels, AnonymousNotifiable $notifiable) use (&$code) {
            $code = $notification->code;

            return $notifiable->routes['mail'] === 'real@correo.co';
        });

        // Un codigo equivocado no activa y cuenta el intento.
        $this->post(route('storefront.register.activate'), ['code' => $code === '000000' ? '111111' : '000000'])->assertSessionHasErrors('code');
        $this->assertSame(1, CustomerActivation::query()->sole()->attempts);

        $this->post(route('storefront.register.activate'), ['code' => $code])->assertRedirect(route('storefront.home'));

        $existing->refresh();
        $this->assertTrue((bool) $existing->is_online_user);
        $this->assertTrue(Hash::check('ClaveSegura#2026', $existing->password));
        // Lo que ya estaba en la ficha se respeta; el correo de la ficha no cambia.
        $this->assertSame('real@correo.co', $existing->email);
        $this->assertSame('3110000000', $existing->phone);
        $this->assertAuthenticatedAs($existing, 'customer');

        // El codigo no se reusa.
        $this->assertNotNull(CustomerActivation::query()->sole()->consumed_at);
    }

    public function test_demasiados_intentos_bloquean_el_codigo(): void
    {
        Notification::fake();
        $this->makeCustomer(['document_number' => '1036123456', 'email' => 'real@correo.co']);
        $this->post(route('storefront.register.store'), $this->payload());

        $activation = CustomerActivation::query()->sole();
        $activation->forceFill(['attempts' => CustomerActivation::MAX_ATTEMPTS])->save();

        $this->post(route('storefront.register.activate'), ['code' => '123456'])->assertSessionHasErrors(['code' => 'El código venció o ya se usó. Pide uno nuevo.']);
        $this->assertGuest('customer');
    }

    public function test_sin_correo_en_la_ficha_debe_ir_a_la_sede(): void
    {
        $this->makeCustomer(['document_number' => '1036123456', 'email' => null]);

        $this->post(route('storefront.register.store'), $this->payload())
            ->assertSessionHasErrors('document_number');

        $this->assertStringContainsString('Acércate a la sede', session('errors')->first('document_number'));
        $this->assertSame(0, CustomerActivation::query()->count());
    }

    public function test_el_mismo_documento_puede_ser_cliente_de_otra_empresa(): void
    {
        $other = $this->makeOtherCompany();
        Customer::query()->withoutGlobalScopes()->create([
            'company_id' => $other['company']->id, 'document_type' => 'CC', 'document_number' => '1036123456', 'name' => 'Laura en otra',
            'is_online_user' => true, 'password' => Hash::make('X#1234567'), 'credit_limit' => 0, 'credit_term_days' => 0, 'is_active' => true,
        ]);

        $this->post(route('storefront.register.store'), $this->payload())->assertRedirect(route('storefront.home'));

        $this->assertSame(2, Customer::query()->withoutGlobalScopes()->where('document_number', '1036123456')->count());
    }
}

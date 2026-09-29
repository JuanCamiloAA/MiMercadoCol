<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * El ingreso desde el diálogo: el cliente vuelve a la página donde estaba, y `return_to` no
 * sirve para mandarlo fuera de la tienda.
 */
class StorefrontLoginDialogTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->publishStorefront();
        $this->shopAt();

        $this->customer = $this->makeCustomer([
            'name' => 'Camila Rojas',
            'document_number' => '1036555444',
            'is_online_user' => true,
            'password' => Hash::make('Clave#Segura1'),
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    protected function login(array $extra = []): TestResponse
    {
        return $this->post(route('storefront.login.store'), ['document_number' => '1036555444', 'password' => 'Clave#Segura1', ...$extra]);
    }

    public function test_vuelve_a_la_pagina_donde_estaba(): void
    {
        $this->login(['return_to' => '/tienda/ofertas?orden=precio'])
            ->assertRedirect(url('/tienda/ofertas?orden=precio'))
            ->assertSessionHas('success', 'Hola, Camila. Ya entraste a tu cuenta.');

        $this->assertAuthenticatedAs($this->customer, 'customer');
    }

    public function test_del_dominio_que_mande_el_navegador_solo_se_toma_la_ruta(): void
    {
        $this->login(['return_to' => route('storefront.checkout.create')])->assertRedirect(route('storefront.checkout.create'));
        $this->post(route('storefront.logout'));

        $this->login(['return_to' => 'https://otro-sitio.example/tienda/carrito'])->assertRedirect(url('/tienda/carrito'));
        $this->post(route('storefront.logout'));

        $this->login(['return_to' => '//otro-sitio.example/tienda'])->assertRedirect(url('/tienda'));
    }

    public function test_fuera_de_la_tienda_no_se_vuelve(): void
    {
        foreach (['/admin', '/tienda/../admin', '/tienda/%2e%2e/admin', '/tiendaX', 'javascript:alert(1)'] as $target) {
            $this->login(['return_to' => $target])->assertRedirect(route('storefront.checkout.create'));
            $this->post(route('storefront.logout'));
        }
    }

    public function test_desde_ingreso_o_registro_va_a_la_portada(): void
    {
        $this->login(['return_to' => '/tienda/registro'])->assertRedirect(route('storefront.home'));
        $this->post(route('storefront.logout'));

        $this->login(['return_to' => '/tienda/ingresar'])->assertRedirect(route('storefront.home'));
    }

    public function test_la_pagina_de_ingreso_sigue_llevando_a_donde_iba(): void
    {
        $this->get(route('storefront.orders.index'))->assertRedirect(route('storefront.login'));

        $this->login()->assertRedirect(route('storefront.orders.index'));
    }

    public function test_el_dialogo_manda_sobre_el_destino_guardado(): void
    {
        $this->get(route('storefront.orders.index'))->assertRedirect(route('storefront.login'));

        $this->login(['return_to' => '/tienda/carrito'])->assertRedirect(url('/tienda/carrito'));
        $this->assertNull(session('url.intended'));
    }

    public function test_datos_equivocados_no_entran_ni_redirigen(): void
    {
        $this->from('/tienda/carrito')
            ->post(route('storefront.login.store'), ['document_number' => '1036555444', 'password' => 'otra', 'return_to' => '/tienda/carrito'])
            ->assertRedirect('/tienda/carrito')
            ->assertSessionHasErrors('document_number');

        $this->assertGuest('customer');
    }
}

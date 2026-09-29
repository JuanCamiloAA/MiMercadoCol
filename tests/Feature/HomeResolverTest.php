<?php

namespace Tests\Feature;

use App\Services\SettingsService;
use App\Support\HomeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

class HomeResolverTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        RateLimiter::clear('');
    }

    protected function login(string $email)
    {
        return $this->post('/login', ['login_mode' => 'email', 'email' => $email, 'password' => 'Password123*']);
    }

    public function test_sin_configurar_todos_entran_al_panel(): void
    {
        $this->makeUser('cajero', ['email' => 'cajera@lacanasta.co']);

        $this->login('cajera@lacanasta.co')->assertRedirect(route('dashboard'));
    }

    public function test_un_cajero_configurado_entra_directo_a_la_caja(): void
    {
        app(SettingsService::class)->set($this->company->id, 'panel.home_by_role', ['cajero' => 'pos.index']);
        $this->makeUser('cajero', ['email' => 'cajera@lacanasta.co']);

        $this->login('cajera@lacanasta.co')->assertRedirect(route('pos.index'));
    }

    public function test_sin_permiso_de_caja_cae_en_la_primera_accesible(): void
    {
        app(SettingsService::class)->set($this->company->id, 'panel.home_by_role', ['domiciliario' => 'pos.index']);
        $user = $this->makeUser('domiciliario', ['email' => 'domicilios@lacanasta.co']);

        $this->assertFalse($user->can('pos.sale.view'));
        $this->assertFalse($user->can('dashboard.index.view'));

        // El domiciliario no ve la caja ni el inicio: entra a los pedidos, que si puede abrir.
        $this->assertSame('orders.index', HomeResolver::routeFor($user));
        $this->login('domicilios@lacanasta.co')->assertRedirect(route('orders.index'));
    }

    public function test_intended_sigue_mandando(): void
    {
        app(SettingsService::class)->set($this->company->id, 'panel.home_by_role', ['cajero' => 'pos.index']);
        $this->makeUser('cajero', ['email' => 'cajera@lacanasta.co']);

        // Venia de un enlace: vuelve ahi, no a su inicio.
        $this->get(route('customers.index'))->assertRedirect(route('login'));

        $this->login('cajera@lacanasta.co')->assertRedirect(route('customers.index'));
    }

    public function test_la_landing_lleva_a_cada_quien_a_su_inicio(): void
    {
        app(SettingsService::class)->set($this->company->id, 'panel.home_by_role', ['cajero' => 'pos.index']);
        $user = $this->makeUser('cajero');

        // La raiz es la landing de la plataforma; con sesion de personal, «Ir al panel» lleva
        // al inicio de cada quien.
        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('Landing/Public')
                ->where('panel.url', route('pos.index')));
    }
}

<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        RateLimiter::clear('');
    }

    public function test_entra_con_correo(): void
    {
        $user = $this->makeUser('admin', ['email' => 'admin@lacanasta.co']);

        $response = $this->post('/login', [
            'login_mode' => 'email',
            'email' => 'admin@lacanasta.co',
            'password' => 'Password123*',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('access_logs', ['user_id' => $user->id, 'action' => AccessLog::ACTION_LOGIN]);
    }

    public function test_entra_con_documento(): void
    {
        $user = $this->makeUser('cajero', ['email' => 'cajera@lacanasta.co', 'document' => '1017234567']);

        $response = $this->post('/login', [
            'login_mode' => 'document',
            'document' => '1017234567',
            'password' => 'Password123*',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_rechaza_una_cuenta_desactivada(): void
    {
        $this->makeUser('admin', ['email' => 'inactivo@lacanasta.co', 'is_active' => false]);

        $response = $this->from('/login')->post('/login', [
            'login_mode' => 'email',
            'email' => 'inactivo@lacanasta.co',
            'password' => 'Password123*',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertStringContainsString('desactivada', session('errors')->first('email'));
    }

    public function test_bloquea_tras_cinco_intentos_fallidos(): void
    {
        $this->makeUser('admin', ['email' => 'admin@lacanasta.co']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->from('/login')->post('/login', [
                'login_mode' => 'email',
                'email' => 'admin@lacanasta.co',
                'password' => 'clave-incorrecta',
            ]);
        }

        $response = $this->from('/login')->post('/login', [
            'login_mode' => 'email',
            'email' => 'admin@lacanasta.co',
            'password' => 'Password123*',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Demasiados intentos', session('errors')->first('email'));
        $this->assertGuest();
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
    }

    public function test_envia_el_enlace(): void
    {
        Notification::fake();
        $user = $this->makeUser('admin', ['email' => 'admin@lacanasta.co']);

        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'admin@lacanasta.co'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_si_el_servidor_de_correo_falla_avisa_en_vez_de_reventar(): void
    {
        $this->makeUser('admin', ['email' => 'admin@lacanasta.co']);

        // Un SMTP que no responde: lo mismo que un Brevo caido o con la clave mala.
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 2,
        ]);

        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'admin@lacanasta.co'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHasErrors('email');
    }
}

<?php

namespace App\Notifications\Platform;

use App\Models\Company;
use App\Services\PlatformSettingsService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Bienvenida al administrador de una empresa que acaba de contratar en linea.
 *
 * Nunca lleva la contraseña: la eligio la misma persona al contratar.
 */
class CompanyWelcome extends Notification
{
    public function __construct(public Company $company) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return filter_var($notifiable->email ?? null, FILTER_VALIDATE_EMAIL) ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $platform = app(PlatformSettingsService::class)->name();

        return (new MailMessage)
            ->subject("Bienvenido a {$platform}: {$this->company->name} ya está lista")
            ->greeting("Hola {$notifiable->name},")
            ->line("La cuenta de {$this->company->name} quedó creada con su primera sede, una caja y la tienda en línea lista para configurar.")
            ->line('Entra con este correo y la contraseña que elegiste al contratar.')
            ->action('Ir al panel', route('login'))
            ->line('Si no fuiste tú quien contrató, responde este correo y lo revisamos.');
    }
}

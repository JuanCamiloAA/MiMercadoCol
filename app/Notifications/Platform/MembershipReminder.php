<?php

namespace App\Notifications\Platform;

use App\Models\Company;
use App\Services\PlatformSettingsService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso de membresia por vencer, o de tarjeta que vence antes del proximo cobro.
 */
class MembershipReminder extends Notification
{
    public const KIND_EXPIRING = 'expiring';

    public const KIND_CARD = 'card';

    public function __construct(public Company $company, public string $kind, public int $days, public bool $autoRenew) {}

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
        $date = $this->company->membership_ends_at?->timezone('America/Bogota')->format('d/m/Y');
        $message = (new MailMessage)->greeting('Hola '.($notifiable->name ?? '').',');

        if ($this->kind === self::KIND_CARD) {
            return $message
                ->subject("La tarjeta de {$this->company->name} vence antes de la renovación")
                ->line("La tarjeta guardada vence antes del próximo cobro de la membresía ({$date}).")
                ->line('Cámbiala para que la renovación automática no falle.')
                ->action('Cambiar la tarjeta', route('settings.index', ['pestana' => 'membresia']))
                ->salutation("Equipo de {$platform}");
        }

        $when = $this->days === 1 ? 'mañana' : "en {$this->days} días";

        $message->subject("La membresía de {$this->company->name} vence {$when}")
            ->line("La membresía vence el {$date}.");

        $this->autoRenew
            ? $message->line('La renovación automática está encendida: ese día cobraremos a la tarjeta guardada.')
            : $message->line('Para no perder el acceso, paga la renovación antes de esa fecha.');

        return $message
            ->action('Ver mi membresía', route('settings.index', ['pestana' => 'membresia']))
            ->salutation("Equipo de {$platform}");
    }
}

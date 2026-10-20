<?php

namespace App\Notifications\Platform;

use App\Models\Company;
use App\Services\PlatformSettingsService;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso de que en pocos dias vence algo que la plataforma le dio de mas a la empresa: una
 * funcion agregada a su plan o un precio especial.
 */
class PlanExtraExpiring extends Notification
{
    public const KIND_ENTITLEMENT = 'entitlement';

    public const KIND_PRICE = 'price';

    public function __construct(
        public Company $company,
        public string $kind,
        public string $label,
        public CarbonInterface $endsAt,
    ) {}

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
        $date = $this->endsAt->copy()->timezone('America/Bogota')->format('d/m/Y');
        $message = (new MailMessage)->greeting('Hola '.($notifiable->name ?? '').',');

        if ($this->kind === self::KIND_PRICE) {
            $message->subject("El precio especial de {$this->company->name} termina el {$date}")
                ->line("El precio especial de la membresía ({$this->label}) termina el {$date}.")
                ->line('Desde el siguiente cobro se cobra el precio del plan.');
        } else {
            $message->subject("{$this->label} deja de estar incluido el {$date}")
                ->line("La función «{$this->label}», que se agregó al plan de {$this->company->name}, deja de estar incluida el {$date}.")
                ->line('Los datos se conservan; lo que se corta es el acceso. Para seguir usándola, cambia de plan.');
        }

        return $message
            ->action('Ver mi membresía', route('settings.index', ['pestana' => 'membresia']))
            ->salutation("Equipo de {$platform}");
    }
}

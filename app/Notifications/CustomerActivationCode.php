<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * El codigo para activar la cuenta en linea de un cliente que ya existia en la caja. Va al correo
 * de su ficha, no al que escribio en el formulario: asi nadie activa la cuenta (y el credito) de
 * otra persona sabiendo solo su cedula.
 */
class CustomerActivationCode extends Notification
{
    public function __construct(public string $code, public string $storeName, public int $minutes) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Tu código para activar la cuenta en {$this->storeName}")
            ->greeting('Hola,')
            ->line("Alguien pidió activar tu cuenta para comprar en línea en {$this->storeName}.")
            ->line("Tu código es: **{$this->code}**")
            ->line("Vence en {$this->minutes} minutos.")
            ->line('Si no fuiste tú, ignora este correo: sin el código nadie puede entrar a tu cuenta.');
    }
}

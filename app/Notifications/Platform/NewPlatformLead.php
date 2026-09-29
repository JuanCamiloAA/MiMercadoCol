<?php

namespace App\Notifications\Platform;

use App\Models\PlatformLead;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso de una solicitud nueva («que me contacten») al correo de la plataforma.
 */
class NewPlatformLead extends Notification
{
    public function __construct(public PlatformLead $lead) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lead = $this->lead;

        return (new MailMessage)
            ->subject('Solicitud nueva: '.($lead->company_name ?: $lead->contact_name))
            ->line('Empresa: '.($lead->company_name ?: '—').($lead->city ? " ({$lead->city})" : ''))
            ->line("Contacto: {$lead->contact_name} · {$lead->email}".($lead->phone ? " · {$lead->phone}" : ''))
            ->line('Sedes estimadas: '.($lead->estimated_stores ?? '—'))
            ->line('Mensaje: '.($lead->message ?: '—'))
            ->action('Ver la solicitud', route('platform.leads.index', ['solicitud' => $lead->id]));
    }
}

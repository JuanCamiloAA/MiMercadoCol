<?php

namespace App\Notifications;

use App\Models\CustomerReturn;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso al cliente de la tienda en linea en cada paso de su devolucion: la recibimos, la
 * aprobamos, no procede o ya esta resuelta. Va en cola, como el aviso del pedido.
 */
class CustomerReturnUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public CustomerReturn $return) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return filter_var($notifiable->email, FILTER_VALIDATE_EMAIL) ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->subject())
            ->greeting("Hola {$notifiable->name},")
            ->line($this->body());

        if ($this->return->order_id) {
            $message->action('Ver mi pedido', route('storefront.orders.show', $this->return->order_id));
        }

        return $message->line('Gracias por comprar en '.($this->return->company?->name ?? 'nuestra tienda').'.');
    }

    protected function subject(): string
    {
        return match ($this->return->status) {
            CustomerReturn::STATUS_REQUESTED => "Recibimos tu solicitud de devolución {$this->return->number}",
            CustomerReturn::STATUS_APPROVED => "Aprobamos tu devolución {$this->return->number}",
            CustomerReturn::STATUS_REJECTED => "Tu devolución {$this->return->number} no procede",
            CustomerReturn::STATUS_RESOLVED => "Tu devolución {$this->return->number} quedó resuelta",
            default => "Novedad en tu devolución {$this->return->number}",
        };
    }

    protected function body(): string
    {
        $money = fn (int $value) => '$ '.number_format($value, 0, ',', '.');

        return match ($this->return->status) {
            CustomerReturn::STATUS_REQUESTED => 'La vamos a revisar y te avisamos por este medio.',
            CustomerReturn::STATUS_APPROVED => 'Te contactaremos para recoger los artículos, o puedes llevarlos a la sede '.($this->return->store?->name ?? '').'.',
            CustomerReturn::STATUS_REJECTED => 'No procede: '.($this->return->reject_reason ?? 'no cumple las condiciones de devolución.'),
            CustomerReturn::STATUS_RESOLVED => match ($this->return->resolution) {
                CustomerReturn::RESOLUTION_STORE_CREDIT => 'Te abonamos '.$money($this->return->credited_total).' de saldo a favor para tu próxima compra.',
                default => 'Te devolvimos '.$money($this->return->refunded_total).
                    ($this->return->debt_relief_total > 0 ? ' y descontamos '.$money($this->return->debt_relief_total).' de tu cuenta por pagar' : '').'.',
            },
            default => 'Tu devolución cambió de estado.',
        };
    }
}

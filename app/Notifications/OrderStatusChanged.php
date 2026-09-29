<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso al cliente cuando su pedido cambia de estado.
 *
 * Va en cola: si el correo tarda o el servidor no responde, el cambio de estado no se puede
 * quedar esperando con el domiciliario en la puerta.
 */
class OrderStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    /** Estados que de verdad le importan al cliente; los demas son ruido. */
    public const NOTIFIABLE = [
        Order::STATUS_PICKING,
        Order::STATUS_ON_ROUTE,
        Order::STATUS_DELIVERED,
        Order::STATUS_CANCELLED,
    ];

    public function __construct(public Order $order) {}

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

        if ($this->order->status !== Order::STATUS_CANCELLED) {
            $message->action('Ver mi pedido', route('storefront.orders.show', $this->order));
        }

        return $message->line('Gracias por comprar en La Canasta.');
    }

    protected function subject(): string
    {
        return match ($this->order->status) {
            Order::STATUS_PICKING => "Estamos alistando tu pedido #{$this->order->number}",
            Order::STATUS_ON_ROUTE => "Tu pedido #{$this->order->number} va en camino",
            Order::STATUS_DELIVERED => "Entregamos tu pedido #{$this->order->number}",
            Order::STATUS_CANCELLED => "Cancelamos tu pedido #{$this->order->number}",
            default => "Novedad en tu pedido #{$this->order->number}",
        };
    }

    protected function body(): string
    {
        return match ($this->order->status) {
            Order::STATUS_PICKING => 'Ya estamos recogiendo tus productos en la sede '.($this->order->store?->name ?? '').'.',
            Order::STATUS_ON_ROUTE => 'Tu pedido salio para '.$this->order->delivery_address.
                ($this->order->courier ? ' con '.$this->order->courier->full_name.'.' : '.'),
            Order::STATUS_DELIVERED => 'Tu pedido quedo entregado. Si algo no llego bien, escribenos y lo resolvemos.',
            Order::STATUS_CANCELLED => 'Tu pedido quedo cancelado'.
                ($this->order->cancel_reason ? ': '.$this->order->cancel_reason : '.'),
            default => 'Tu pedido cambio de estado.',
        };
    }
}

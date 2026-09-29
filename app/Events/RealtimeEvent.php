<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Base de todo lo que se transmite en vivo.
 *
 * **`ShouldBroadcastNow`, no `ShouldBroadcast`.** Lo segundo encola la transmision, y en una
 * instalacion de mostrador no siempre hay un `queue:work` corriendo: el evento se quedaria en
 * la tabla `jobs` y nadie se enteraria de nada. Transmitir es una peticion HTTP corta al
 * servidor de sockets; sale en la misma peticion y se acabo.
 *
 * Si el servidor de sockets esta caido, `BroadcastServiceProvider` se lo traga y lo registra:
 * que no haya tiempo real no puede tumbar una venta.
 *
 * Lo que viaja es **un aviso, no un expediente**. El navegador recibe «algo cambio en pedidos»
 * y le pide al servidor los datos por los canales de siempre, donde la autorizacion ya esta
 * resuelta. Mandar el pedido entero por el socket obligaria a repetir aqui cada regla de
 * visibilidad, y tarde o temprano una se olvidaria.
 */
abstract class RealtimeEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $companyId) {}

    /** El canal donde vive el evento. */
    abstract public function channelName(): string;

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->channelName())];
    }

    /**
     * El nombre corto con el que el navegador lo escucha.
     *
     * Sin esto llegaria el nombre completo de la clase con su espacio de nombres, y renombrar
     * una clase romperia el front sin que nada lo avise.
     */
    public function broadcastAs(): string
    {
        return class_basename(static::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return array_merge(['at' => now()->toIso8601String()], $this->payload());
    }

    /**
     * Lo minimo para que la pantalla decida si le interesa y que refrescar.
     *
     * @return array<string, mixed>
     */
    abstract protected function payload(): array;
}

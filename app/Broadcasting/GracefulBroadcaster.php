<?php

namespace App\Broadcasting;

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Transmite, y si el servidor de sockets no esta, sigue de largo.
 *
 * El tiempo real es una comodidad, no parte del negocio. Si Reverb esta caido —o nadie lo
 * arranco, que en una instalacion de mostrador pasa— la venta tiene que cerrarse igual, el
 * pedido tiene que grabarse igual y la caja tiene que cuadrar igual. Sin esto, un servidor de
 * sockets apagado lanzaria una excepcion en mitad de la transaccion y tumbaria el cobro.
 *
 * Queda en el registro para que se note, pero nunca sube.
 *
 * Solo se traga los fallos de **transmitir**. Autorizar un canal sigue fallando fuerte: si la
 * autorizacion se cayera en silencio, la pantalla se quedaria sin datos sin decir por que.
 */
class GracefulBroadcaster implements Broadcaster
{
    public function __construct(protected Broadcaster $inner) {}

    public function broadcast(array $channels, $event, array $payload = []): void
    {
        try {
            $this->inner->broadcast($channels, $event, $payload);
        } catch (Throwable $exception) {
            Log::warning('No se pudo transmitir en vivo; la aplicacion sigue normal.', [
                'event' => $event,
                'channels' => array_map('strval', $channels),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function auth($request)
    {
        return $this->inner->auth($request);
    }

    public function validAuthenticationResponse($request, $result)
    {
        return $this->inner->validAuthenticationResponse($request, $result);
    }

    /** Lo demas —`channel()`, `resolveAuthenticatedUser()`— va tal cual al de adentro. */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->inner->{$method}(...$parameters);
    }
}

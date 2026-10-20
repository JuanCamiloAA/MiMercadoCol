<?php

namespace App\Events;

use App\Support\Channels;

/**
 * «Cambio esto»: el aviso que mantiene al dia las pestañas abiertas de una empresa.
 *
 * Viaja solo el tema y la sede (`inventory` en la sede 3), nunca los datos: la pestaña que lo
 * recibe vuelve a pedir su pagina por el camino de siempre, con su autorizacion y su filtro por
 * empresa. Lo arma `ChangeCollector`, uno por peticion.
 */
class ResourcesChanged extends RealtimeEvent
{
    /**
     * @param  list<array{topic: string, stores: list<int|null>}>  $changes
     */
    public function __construct(int $companyId, public array $changes, public ?int $userId = null)
    {
        parent::__construct($companyId);
    }

    public function channelName(): string
    {
        return Channels::changes($this->companyId);
    }

    protected function payload(): array
    {
        return ['changes' => $this->changes, 'by' => $this->userId];
    }
}

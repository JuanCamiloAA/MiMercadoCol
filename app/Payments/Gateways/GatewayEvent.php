<?php

namespace App\Payments\Gateways;

/**
 * Un evento de webhook con firma valida: a que transaccion se refiere. No dice el estado: ese se
 * relee en la pasarela (`PaymentGateway::fetch`), porque la firma prueba quien envia, no que el
 * cuerpo siga siendo la ultima verdad.
 */
final class GatewayEvent
{
    public function __construct(
        public readonly ?string $reference = null,
        public readonly ?string $gatewayTransactionId = null,
        public readonly ?string $gatewayLinkId = null,
        /** Eventos que no son de cobro (un link creado, una prueba): se responden 200 y nada mas. */
        public readonly bool $ignorable = false,
    ) {}
}

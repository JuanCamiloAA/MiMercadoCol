<?php

namespace App\Payments\Gateways;

/**
 * Un link de pago (o el QR que lo contiene) para cobrar en la caja: el cliente lo abre en su
 * celular y paga ahi.
 */
final class LinkResult
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $gatewayLinkId = null,
        /** Lo que se codifica en el QR; por defecto, el mismo link. */
        public readonly ?string $qrPayload = null,
    ) {}
}

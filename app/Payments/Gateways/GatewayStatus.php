<?php

namespace App\Payments\Gateways;

use App\Models\PaymentTransaction;

/**
 * El estado de un cobro tal como lo dice la pasarela, ya traducido: estado nuestro, monto en COP
 * entero, moneda y el id de la pasarela. `raw` va saneado y es lo que se guarda en `payload`.
 */
final class GatewayStatus
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $status,
        public readonly ?int $amount = null,
        public readonly ?string $currency = null,
        public readonly ?string $gatewayTransactionId = null,
        public readonly ?string $detail = null,
        public readonly array $raw = [],
        public readonly ?string $reference = null,
    ) {}

    public function isApproved(): bool
    {
        return $this->status === PaymentTransaction::STATUS_APPROVED;
    }

    public function isPending(): bool
    {
        return $this->status === PaymentTransaction::STATUS_PENDING;
    }
}

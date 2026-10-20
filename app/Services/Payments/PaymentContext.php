<?php

namespace App\Services\Payments;

use App\Models\Customer;
use App\Models\PaymentMethod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Donde y cuando se esta cobrando: lo que `PaymentMethodAvailability` necesita para decidir que
 * medios valen. La hora va siempre en la de Colombia: «de 7:00 a 21:00» es de 7 a 21 en Bogota,
 * no en el reloj del servidor.
 */
final class PaymentContext
{
    public readonly CarbonImmutable $at;

    public function __construct(
        public readonly string $channel,
        public readonly ?int $storeId = null,
        public readonly ?int $registerId = null,
        ?CarbonInterface $at = null,
        /** Lo que se va a cobrar. En la tienda lo paga entero un solo medio; en la caja es solo una guia. */
        public readonly ?int $amount = null,
        public readonly ?Customer $customer = null,
        /** En la tienda: `delivery` o `pickup`. */
        public readonly ?string $deliveryMode = null,
    ) {
        $this->at = CarbonImmutable::instance($at ?? now())->setTimezone('America/Bogota');
    }

    public static function pos(?int $storeId, ?int $registerId, ?Customer $customer = null, ?int $amount = null, ?CarbonInterface $at = null): self
    {
        return new self(PaymentMethod::CHANNEL_POS, $storeId, $registerId, $at, $amount, $customer);
    }

    public static function online(?int $storeId, ?int $amount = null, ?Customer $customer = null, ?string $deliveryMode = 'delivery', ?CarbonInterface $at = null): self
    {
        return new self(PaymentMethod::CHANNEL_ONLINE, $storeId, null, $at, $amount, $customer, $deliveryMode);
    }

    public function withAmount(?int $amount): self
    {
        return new self($this->channel, $this->storeId, $this->registerId, $this->at, $amount, $this->customer, $this->deliveryMode);
    }

    public function withCustomer(?Customer $customer): self
    {
        return new self($this->channel, $this->storeId, $this->registerId, $this->at, $this->amount, $customer, $this->deliveryMode);
    }
}

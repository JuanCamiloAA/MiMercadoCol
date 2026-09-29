<?php

namespace App\Services\Catalog;

/**
 * Un articulo de la fuente externa, ya limpio.
 *
 * O trae datos utilizables, o trae el motivo por el que no sirve. Nunca las dos cosas, y nunca
 * ninguna: quien lo recibe no tiene que adivinar.
 */
final class NormalizedProduct
{
    /**
     * @param  array<string, mixed>  $payload  Lo que devolvio la fuente, tal cual.
     */
    private function __construct(
        public readonly ?string $ean,
        public readonly ?string $name,
        public readonly ?string $description,
        public readonly ?string $brand,
        public readonly ?string $packaging,
        public readonly ?float $contentAmount,
        public readonly ?string $contentUnit,
        public readonly ?string $externalCategory,
        public readonly ?string $imageUrl,
        public readonly ?string $reason,
        public readonly array $payload = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function usable(
        string $ean,
        string $name,
        ?string $description,
        ?string $brand,
        ?string $packaging,
        ?float $contentAmount,
        ?string $contentUnit,
        ?string $externalCategory,
        ?string $imageUrl,
        array $payload,
    ): self {
        return new self(
            $ean, $name, $description, $brand, $packaging,
            $contentAmount, $contentUnit, $externalCategory, $imageUrl,
            null, $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function rejected(string $reason, ?string $ean = null, ?string $name = null, array $payload = []): self
    {
        return new self($ean, $name, null, null, null, null, null, null, null, $reason, $payload);
    }

    public function isUsable(): bool
    {
        return $this->reason === null;
    }
}

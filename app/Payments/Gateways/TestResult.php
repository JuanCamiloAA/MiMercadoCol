<?php

namespace App\Payments\Gateways;

/**
 * El resultado de «Probar conexión»: si respondio y que se le dice a la persona.
 */
final class TestResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
    ) {}
}

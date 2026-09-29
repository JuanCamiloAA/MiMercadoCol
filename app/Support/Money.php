<?php

namespace App\Support;

/**
 * Pesos colombianos enteros: nada de centavos, nada de flotantes en la base.
 */
final class Money
{
    /** Base gravable de un valor que ya incluye IVA. */
    public static function baseFromGross(int $gross, float $taxRate): int
    {
        if ($taxRate <= 0) {
            return $gross;
        }

        return (int) round($gross / (1 + $taxRate / 100));
    }

    /** IVA contenido en un valor que ya lo incluye. */
    public static function taxFromGross(int $gross, float $taxRate): int
    {
        return $gross - self::baseFromGross($gross, $taxRate);
    }

    public static function format(int $amount): string
    {
        return '$ '.number_format($amount, 0, ',', '.');
    }
}

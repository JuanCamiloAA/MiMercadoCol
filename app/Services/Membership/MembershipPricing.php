<?php

namespace App\Services\Membership;

use App\Models\BillingCycle;
use App\Models\MembershipPlan;

/**
 * El precio de un plan en un ciclo. Una sola fuente: ni el `.tsx`, ni la landing, ni el cobro
 * calculan precios por su cuenta.
 *
 *     precio mensual × meses × (100 − descuento) / 100, redondeado hacia arriba a mil pesos.
 */
final class MembershipPricing
{
    public static function priceFor(MembershipPlan $plan, BillingCycle $cycle): int
    {
        $raw = (int) $plan->monthly_price * max(1, (int) $cycle->months) * (100 - min(90, max(0, (int) $cycle->discount_percent)));

        if ($raw <= 0) {
            return 0;
        }

        // `$raw` va en pesos × 100 (por el porcentaje): se divide y se sube al siguiente mil.
        return (int) (intdiv($raw + 99_999, 100_000) * 1000);
    }

    /**
     * Lo que ese ciclo vale al mes, para sumar el ingreso recurrente.
     */
    public static function monthlyEquivalent(MembershipPlan $plan, BillingCycle $cycle): int
    {
        return intdiv(self::priceFor($plan, $cycle), max(1, (int) $cycle->months));
    }

    /**
     * Precio del plan en cada ciclo dado, para pintarlo sin calcularlo en el navegador.
     *
     * @param  iterable<BillingCycle>  $cycles
     * @return array<int, int> id del ciclo => precio
     */
    public static function priceTable(MembershipPlan $plan, iterable $cycles): array
    {
        $prices = [];

        foreach ($cycles as $cycle) {
            $prices[$cycle->id] = self::priceFor($plan, $cycle);
        }

        return $prices;
    }
}

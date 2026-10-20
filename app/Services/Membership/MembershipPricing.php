<?php

namespace App\Services\Membership;

use App\Models\BillingCycle;
use App\Models\Company;
use App\Models\CompanyPlanPrice;
use App\Models\MembershipPlan;
use Carbon\CarbonInterface;

/**
 * El precio de un plan en un ciclo. Una sola fuente: ni el `.tsx`, ni la landing, ni el cobro
 * calculan precios por su cuenta.
 *
 *     base mensual × meses × (100 − descuento del ciclo) / 100, redondeado hacia arriba a mil.
 *
 * La base mensual es la del plan, salvo que la empresa tenga un precio especial vigente PARA ESE
 * plan: entonces es su precio fijo, o el del plan menos su descuento. Sin empresa (la landing,
 * «Contratar») nunca hay precio especial. Un cobro ya generado conserva su monto: esto solo
 * decide los cobros nuevos.
 */
final class MembershipPricing
{
    public static function priceFor(MembershipPlan $plan, BillingCycle $cycle, ?Company $company = null): int
    {
        $special = $company ? self::specialPrice($company, $plan) : null;

        // Todo en una sola cuenta entera: pesos × (100 − especial) × (100 − ciclo), para no
        // redondear dos veces.
        $raw = match (true) {
            $special?->monthly_price !== null => (int) $special->monthly_price * 100 * 100,
            $special !== null => (int) $plan->monthly_price * (100 - min(90, max(0, (int) $special->discount_percent))) * 100,
            default => (int) $plan->monthly_price * 100 * 100,
        };

        $raw *= max(1, (int) $cycle->months) * (100 - min(90, max(0, (int) $cycle->discount_percent)));

        if ($raw <= 0) {
            return 0;
        }

        // `$raw` va en pesos × 1.000.000 (tres porcentajes): se divide y se sube al siguiente mil.
        $divisor = 100 * 100 * 100 * 1000;

        return (int) (intdiv($raw + $divisor - 1, $divisor) * 1000);
    }

    /**
     * El precio especial vigente de la empresa en ese plan, si hay.
     */
    public static function specialPrice(Company $company, MembershipPlan $plan, ?CarbonInterface $at = null): ?CompanyPlanPrice
    {
        return CompanyPlanPrice::query()
            ->where('company_id', $company->id)
            ->where('membership_plan_id', $plan->id)
            ->activeAt($at ?? now())
            ->latest('id')
            ->first();
    }

    /**
     * Lo que ese ciclo vale al mes, para sumar el ingreso recurrente.
     */
    public static function monthlyEquivalent(MembershipPlan $plan, BillingCycle $cycle, ?Company $company = null): int
    {
        return intdiv(self::priceFor($plan, $cycle, $company), max(1, (int) $cycle->months));
    }

    /**
     * Precio del plan en cada ciclo dado, para pintarlo sin calcularlo en el navegador.
     *
     * @param  iterable<BillingCycle>  $cycles
     * @return array<int, int> id del ciclo => precio
     */
    public static function priceTable(MembershipPlan $plan, iterable $cycles, ?Company $company = null): array
    {
        $prices = [];

        foreach ($cycles as $cycle) {
            $prices[$cycle->id] = self::priceFor($plan, $cycle, $company);
        }

        return $prices;
    }
}

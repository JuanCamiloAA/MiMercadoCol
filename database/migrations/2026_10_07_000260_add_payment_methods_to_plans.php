<?php

use App\Services\Membership\Entitlements;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Paso 7: el modulo nuevo de medios de pago en los planes que ya existian.
 *
 * - Los medios manuales (`payment_methods.index.*`), en todos.
 * - Pasarelas, datafonos y transacciones, en Pro y Cadena (y en cualquier plan que ya traiga la
 *   tienda en linea, que es donde se cobra en linea).
 *
 * Es solo el punto de partida: el super usuario lo cambia en el editor del plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('membership_plans')->orderBy('id')->get(['id', 'slug', 'entitlements'])->each(function ($plan) {
            $nodes = json_decode((string) $plan->entitlements, true);
            $nodes = is_array($nodes) ? $nodes : [];
            $online = in_array($plan->slug, ['pro', 'cadena'], true) || in_array('storefront.*', $nodes, true);

            $nodes[] = 'payment_methods.index.*';

            if ($online) {
                $nodes[] = 'payment_methods.*';
            }

            DB::table('membership_plans')->where('id', $plan->id)->update([
                'entitlements' => json_encode(Entitlements::normalize($nodes)),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('membership_plans')->orderBy('id')->get(['id', 'entitlements'])->each(function ($plan) {
            $nodes = json_decode((string) $plan->entitlements, true);

            DB::table('membership_plans')->where('id', $plan->id)->update([
                'entitlements' => json_encode(array_values(array_filter(is_array($nodes) ? $nodes : [], fn ($node) => ! str_starts_with((string) $node, 'payment_methods.')))),
            ]);
        });
    }
};

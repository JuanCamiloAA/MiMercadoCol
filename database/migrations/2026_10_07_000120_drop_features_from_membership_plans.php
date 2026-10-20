<?php

use App\Services\Membership\Entitlements;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `features` ya no la lee nadie: lo que incluye un plan vive en `entitlements`.
 *
 * Al deshacer se reconstruye a partir de los nodos: una funcion vieja queda encendida si el
 * plan incluye todos sus nodos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            $table->dropColumn('features');
        });
    }

    public function down(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            $table->json('features')->nullable()->after('max_registers');
        });

        DB::table('membership_plans')->orderBy('id')->get(['id', 'entitlements'])->each(function ($plan) {
            $nodes = json_decode((string) $plan->entitlements, true);
            $permissions = array_flip(Entitlements::expand(is_array($nodes) ? $nodes : [], warn: false));
            $features = [];

            foreach (Entitlements::LEGACY_FEATURES as $feature => $featureNodes) {
                $required = Entitlements::expand($featureNodes, warn: false);

                if ($required !== [] && array_diff($required, array_keys($permissions)) === []) {
                    $features[] = $feature;
                }
            }

            DB::table('membership_plans')->where('id', $plan->id)->update(['features' => json_encode($features)]);
        });
    }
};

<?php

use App\Services\Membership\Entitlements;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que incluye un plan pasa de cuatro interruptores (`features`) a nodos del arbol de permisos
 * (`entitlements`), y el plan gana sus destacados para la landing.
 *
 * Conversion: lo que no estaba detras de ninguna funcion queda incluido en todos los planes
 * (`Entitlements::LEGACY_BASE`, congelado), y cada funcion suma sus nodos. Ninguna empresa gana
 * ni pierde acceso. `features` se borra en una migracion aparte, cuando ya nada la lee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            $table->json('entitlements')->nullable()->after('max_registers');
            $table->json('highlights')->nullable()->after('entitlements');
        });

        DB::table('membership_plans')->orderBy('id')->get(['id', 'features'])->each(function ($plan) {
            $features = json_decode((string) $plan->features, true);

            DB::table('membership_plans')->where('id', $plan->id)->update([
                'entitlements' => json_encode(Entitlements::fromLegacyFeatures(is_array($features) ? $features : [])),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            $table->dropColumn(['entitlements', 'highlights']);
        });
    }
};

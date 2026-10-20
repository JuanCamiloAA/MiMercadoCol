<?php

use App\Services\Payments\LegacyStorefrontPayments;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Paso 4: los interruptores de pago de la version publicada de cada sede pasan a reglas de los
 * medios de pago (canal en linea, esa sede). Ver `LegacyStorefrontPayments`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $legacy = app(LegacyStorefrontPayments::class);

        DB::table('companies')->orderBy('id')->pluck('id')->each(fn ($id) => $legacy->migrateCompany((int) $id));
    }

    public function down(): void
    {
        // Los interruptores siguen en `storefront_settings`: no hay nada que deshacer.
    }
};

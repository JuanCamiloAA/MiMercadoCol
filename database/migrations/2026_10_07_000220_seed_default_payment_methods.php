<?php

use App\Services\Payments\PaymentMethodCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Paso 1 de la migracion de medios de pago: a cada empresa que ya existia, sus medios de
 * siempre con el mismo `kind` de los `METHOD_*` de antes: Efectivo, Tarjeta (datáfono),
 * Transferencia, Crédito, Saldo a favor y Cambio por devolución (oculto), mas los dos de la
 * contraentrega. Las empresas nuevas los reciben al nacer (`Company::booted`).
 */
return new class extends Migration
{
    public function up(): void
    {
        $catalog = app(PaymentMethodCatalog::class);

        DB::table('companies')->orderBy('id')->pluck('id')->each(fn ($id) => $catalog->ensureDefaults((int) $id));
    }

    public function down(): void
    {
        DB::table('payment_methods')->whereNotNull('system_key')->delete();
    }
};

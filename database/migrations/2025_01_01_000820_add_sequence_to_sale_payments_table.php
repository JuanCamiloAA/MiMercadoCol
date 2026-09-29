<?php

use App\Models\SalePayment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Una venta pasa a admitir varios medios de pago combinados.
 *
 * Con el cobro partido cambia el significado de la linea de efectivo: `amount` es lo que el
 * cliente entrego, y el sobrante queda en `change_amount`. La invariante pasa a ser
 * `sum(amount) - change_amount == sales.total`, asi que las filas viejas se ajustan para que
 * el historial siga cuadrando: antes el cambio salia de `received_amount` y no estaba dentro
 * del monto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->unsignedTinyInteger('sequence')->default(1)->after('sale_id');
        });

        // El cambio entregado pasa a estar contenido en el monto de la linea de efectivo.
        DB::table('sale_payments')
            ->where('method', SalePayment::METHOD_CASH)
            ->where('change_amount', '>', 0)
            ->update(['amount' => DB::raw('amount + change_amount')]);

        // Orden en que se aplicaron los pagos: lo viejo trae uno solo por venta.
        DB::statement('UPDATE sale_payments SET sequence = 1');

        Schema::table('sale_payments', function (Blueprint $table) {
            // Un pago por medio: repartir el cobro no es repetir el mismo medio dos veces.
            $table->unique(['sale_id', 'method'], 'sale_payments_sale_method_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropUnique('sale_payments_sale_method_unique');
            $table->dropColumn('sequence');
        });

        DB::table('sale_payments')
            ->where('method', SalePayment::METHOD_CASH)
            ->where('change_amount', '>', 0)
            ->update(['amount' => DB::raw('amount - change_amount')]);
    }
};

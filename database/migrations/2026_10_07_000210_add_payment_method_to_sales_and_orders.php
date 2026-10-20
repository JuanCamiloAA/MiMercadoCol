<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada pago de una venta y cada pedido dicen con que medio CONFIGURADO se cobraron.
 *
 * `sale_payments.method` y `orders.payment_method` se quedan con el `kind`: las reglas de plata
 * (redondeo, cambio, `NET_AMOUNT_SQL`, arqueo) siguen leyendolo. Se ensanchan porque los `kind`
 * de pasarela no caben en los 15 de antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->string('method', 30)->change();
            $table->foreignId('payment_method_id')->nullable()->after('sequence')->constrained('payment_methods')->restrictOnDelete();
            $table->foreignId('payment_transaction_id')->nullable()->after('payment_method_id')->constrained('payment_transactions')->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method', 30)->default('cash_on_delivery')->change();
            $table->foreignId('payment_method_id')->nullable()->after('payment_method')->constrained('payment_methods')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_method_id');
        });

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_transaction_id');
            $table->dropConstrainedForeignId('payment_method_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paso 3, despues del relleno: un pago por medio CONFIGURADO y no por `kind`. Asi una venta puede
 * pagarse con Nequi y con Daviplata, dos transferencias. Solo el efectivo puede exceder el total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropUnique('sale_payments_sale_method_unique');
            $table->unique(['sale_id', 'payment_method_id'], 'sale_payments_sale_payment_method_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropUnique('sale_payments_sale_payment_method_unique');
            $table->unique(['sale_id', 'method'], 'sale_payments_sale_method_unique');
        });
    }
};

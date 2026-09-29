<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El precio de un articulo dentro de un servicio «servicio + articulos». Nulo es lo de siempre:
 * se cobra al precio vigente del articulo en cada canal. Con valor, ese precio fijo, con IVA
 * incluido, en la caja y en la tienda. En un servicio de precio cerrado no se guarda: ahi los
 * articulos valen $0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_components', function (Blueprint $table) {
            $table->bigInteger('unit_price')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('service_components', function (Blueprint $table) {
            $table->dropColumn('unit_price');
        });
    }
};

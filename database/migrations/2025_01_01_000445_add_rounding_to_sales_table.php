<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Ajuste al redondeo de efectivo: en Colombia casi nadie da monedas de 50.
            $table->bigInteger('rounding_total')->default(0)->after('discount_total');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('rounding_total');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Sin cascada a proposito: una sede con bodegas se desactiva, no se borra. Borrarla
            // arrastraria existencias y kardex de mercancia que si existio.
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code', 20);
            // Informativo: no decide nada. Lo que decide son las banderas.
            $table->string('type', 20)->default('sales_floor');
            $table->boolean('is_pos_source')->default(false);
            $table->boolean('is_online_source')->default(false);
            $table->boolean('is_receiving_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'code']);
            $table->index(['company_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};

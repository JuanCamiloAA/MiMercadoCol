<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Excepciones por sede de los ajustes que las admiten (hoy, los del tiquete).
 *
 * Tabla aparte y no un `store_id` nulo en `settings`: en MariaDB un indice unico trata los NULL
 * como distintos, asi que `(1, NULL, 'x')` se podria insertar dos veces y la empresa tendria
 * dos valores para la misma clave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_setting_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_setting_overrides');
    }
};

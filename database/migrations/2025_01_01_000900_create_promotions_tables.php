<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promociones de la tienda.
 *
 * Los cuatro tipos caben en una sola tabla porque comparten lo mismo: a quien aplican, desde
 * cuando, en que canal y con que etiqueta se le muestra al cliente. Lo que cambia entre un
 * precio especial y un 3x2 vive en `config`, que es lo unico irregular.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 20);
            /** Texto que se pinta sobre el articulo: «-20%», «3x2», «Combo». */
            $table->string('label', 40)->nullable();
            $table->string('color', 20)->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            /** El canal lo decide cada promocion: la de la tienda no tiene que valer en caja. */
            $table->boolean('applies_online')->default(true);
            $table->boolean('applies_pos')->default(false);
            /** Cuando dos promociones tocan el mismo articulo, manda la de mayor prioridad. */
            $table->unsignedSmallInteger('priority')->default(0);
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'is_active', 'starts_at']);
        });

        Schema::create('promotion_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            /** `product` o `category`: una promocion puede apuntar a una lista o a un estante. */
            $table->string('target_type', 12);
            $table->unsignedBigInteger('target_id');
            /** Precio especial de ese articulo, cuando el tipo lo usa. */
            $table->bigInteger('value')->nullable();
            /** Cuantas unidades entran al combo. */
            $table->decimal('quantity', 12, 3)->default(1);
            $table->timestamps();

            $table->unique(['promotion_id', 'target_type', 'target_id']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_targets');
        Schema::dropIfExists('promotions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editor de la tienda en linea.
 *
 * La portada deja de estar escrita en el codigo y pasa a ser una lista de bloques ordenables.
 * Cada bloque guarda dos versiones de sus datos: `data` es el borrador que se esta editando y
 * `published_data` lo que el cliente ve. Asi se puede armar una portada completa sin que nadie
 * vea el desorden, y publicarla de un golpe cuando quede.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->json('data');
            /** Null mientras el bloque no se haya publicado nunca. */
            $table->json('published_data')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'position']);
        });

        Schema::create('storefront_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            /** Retrato completo de la portada en el momento de publicar, para poder volver. */
            $table->json('snapshot');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 120)->nullable();
            $table->dateTime('published_at');
            $table->timestamps();

            $table->index(['company_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_versions');
        Schema::dropIfExists('storefront_blocks');
    }
};

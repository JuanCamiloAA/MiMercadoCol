<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La landing publica de la plataforma: sus bloques (borrador y publicado) y el historial de lo
 * que se publico, para poder volver atras.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_blocks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 40);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_visible')->default(true);
            // Lo que se esta editando. El publico ve `published_data`.
            $table->json('data')->nullable();
            $table->json('published_data')->nullable();
            $table->boolean('published_visible')->default(false);
            $table->unsignedInteger('published_position')->nullable();
            $table->timestamps();
        });

        Schema::create('landing_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number');
            $table->json('snapshot');
            $table->string('note', 160)->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('published_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_versions');
        Schema::dropIfExists('landing_blocks');
    }
};

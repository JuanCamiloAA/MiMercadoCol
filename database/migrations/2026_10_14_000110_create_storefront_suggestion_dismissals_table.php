<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las sugerencias de «Tienda en linea» que alguien descarto. Es por sede: lo que no conviene
 * publicar en una puede convenir en otra. Publicar el articulo borra sus descartes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_suggestion_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('group', 32);
            $table->foreignId('dismissed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('dismissed_at');
            $table->timestamps();

            $table->unique(['store_id', 'product_id', 'group'], 'sf_suggestion_dismissals_unique');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_suggestion_dismissals');
    }
};

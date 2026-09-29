<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El articulo principal de un articulo con subarticulos.
 *
 * No se vende, no tiene existencias, no se compra ni se traslada: agrupa. Cada subarticulo es una
 * fila normal de `products` y todo lo que hoy trabaja con `product_id` sigue igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_families', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40)->nullable();
            $table->string('name', 160);
            $table->string('search_name', 255)->nullable();
            $table->string('slug', 180);
            $table->text('description')->nullable();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('brand', 60)->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->foreignId('attribute_1_id')->constrained('product_attributes')->restrictOnDelete();
            $table->foreignId('attribute_2_id')->nullable()->constrained('product_attributes')->restrictOnDelete();
            // La llave foranea a `products` va en la siguiente migracion: esa tabla la referencia.
            $table->unsignedBigInteger('default_variant_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_online_visible')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_id', 'slug']);
            $table->index(['company_id', 'code']);
            $table->index(['company_id', 'search_name']);
        });

        Schema::create('product_family_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_family_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('thumb_path')->nullable();
            // La foto de un color: al elegirlo en la tienda, la galeria cambia.
            $table->foreignId('attribute_value_id')->nullable()->constrained('product_attribute_values')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_family_images');
        Schema::dropIfExists('product_families');
    }
};

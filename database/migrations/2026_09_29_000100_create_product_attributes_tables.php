<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El catalogo de caracteristicas de cada empresa: presentacion, peso, sabor, tamaño, talla, color…
 *
 * Una caracteristica puede ser una medida (`measure`: volumen, peso o unidades). Sus valores
 * guardan cantidad y unidad («500 ml», «1 kg») y se copian al contenido del subarticulo, que es
 * de donde sale el precio por litro o por kilo de la tienda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('slug', 40);
            // Como se escoge en la tienda: botones, muestras de color o lista.
            $table->string('display', 10)->default('buttons');
            // Nulo: un texto (sabor, talla). `volume`, `weight` o `units`: una medida.
            $table->string('measure', 10)->nullable();
            // «Talla 8» lleva el nombre; «Azul» y «900 ml» no.
            $table->boolean('name_in_label')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'slug']);
        });

        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_attribute_id')->constrained()->cascadeOnDelete();
            $table->string('value', 40);
            $table->string('slug', 40);
            // Solo en las medidas: la cantidad y su unidad (ml, l, g, kg, un).
            $table->decimal('amount', 12, 3)->nullable();
            $table->string('unit', 4)->nullable();
            $table->string('color_hex', 9)->nullable();
            $table->foreignId('media_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_attribute_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_values');
        Schema::dropIfExists('product_attributes');
    }
};

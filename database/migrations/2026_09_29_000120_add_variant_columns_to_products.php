<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un subarticulo es un articulo con familia. Todos los que ya existen quedan individuales
 * (`product_family_id` nulo) y se comportan igual que antes.
 *
 * El unico de combinaciones va por `variant_key` y no por las dos columnas de valor: MariaDB deja
 * repetir `NULL` en un indice unico, y un articulo de una sola caracteristica tiene la segunda nula.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('product_family_id')->nullable()->after('supplier_id')->constrained()->nullOnDelete();
            $table->foreignId('variant_value_1_id')->nullable()->after('product_family_id')->constrained('product_attribute_values')->restrictOnDelete();
            $table->foreignId('variant_value_2_id')->nullable()->after('variant_value_1_id')->constrained('product_attribute_values')->restrictOnDelete();
            $table->string('variant_key', 40)->nullable()->after('variant_value_2_id');
            $table->string('variant_label', 80)->nullable()->after('variant_key');
            $table->unsignedSmallInteger('variant_sort')->default(0)->after('variant_label');

            $table->unique(['product_family_id', 'variant_key']);
            $table->index(['company_id', 'product_family_id']);
        });

        Schema::table('product_families', function (Blueprint $table) {
            $table->foreign('default_variant_id')->references('id')->on('products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_families', function (Blueprint $table) {
            $table->dropForeign(['default_variant_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['product_family_id', 'variant_key']);
            $table->dropIndex(['company_id', 'product_family_id']);
            $table->dropConstrainedForeignId('variant_value_2_id');
            $table->dropConstrainedForeignId('variant_value_1_id');
            $table->dropConstrainedForeignId('product_family_id');
            $table->dropColumn(['variant_key', 'variant_label', 'variant_sort']);
        });
    }
};

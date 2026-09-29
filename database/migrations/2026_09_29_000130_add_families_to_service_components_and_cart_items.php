<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un servicio puede llevar un articulo con subarticulos completo («Aceite de girasol», en la
 * presentacion que se elija) y no solo uno puntual.
 *
 * - `service_components.product_family_id`: el componente es el articulo completo. `product_id`
 *   sigue siendo obligatorio y es el subarticulo por defecto: todo lo que hoy lee el componente
 *   sigue sirviendo.
 * - `cart_items.choices`: en la tienda, la presentacion que el cliente eligio para cada articulo
 *   completo del servicio (`{familia: subarticulo}`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_components', function (Blueprint $table) {
            $table->foreignId('product_family_id')->nullable()->after('product_id')->constrained()->restrictOnDelete();
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->json('choices')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropColumn('choices');
        });

        Schema::table('service_components', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_family_id');
        });
    }
};

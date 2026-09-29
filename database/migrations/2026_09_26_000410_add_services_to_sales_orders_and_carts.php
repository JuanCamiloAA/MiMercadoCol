<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Donde viajan los servicios: la venta, el carrito y el pedido.
 *
 * En la venta, el servicio es una linea y lo que consumio son lineas hijas
 * (`parent_sale_item_id`) con su articulo, que son las que mueven inventario. La linea del
 * servicio guarda una foto del maestro (`service_snapshot`): la venta no depende de como quede
 * el servicio despues. Y cada linea de una devolucion dice de que linea original sale
 * (`refunded_from_sale_item_id`), porque agrupar por articulo confunde un articulo vendido
 * suelto con el mismo dentro de un servicio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            $table->foreignId('parent_sale_item_id')->nullable()->after('service_id')->constrained('sale_items')->cascadeOnDelete();
            $table->decimal('master_quantity', 12, 3)->nullable()->after('quantity');
            $table->string('component_change', 10)->nullable()->after('master_quantity');
            $table->boolean('returnable_by_default')->nullable()->after('component_change');
            $table->json('service_snapshot')->nullable()->after('returnable_by_default');
            $table->foreignId('refunded_from_sale_item_id')->nullable()->after('service_snapshot')->constrained('sale_items')->nullOnDelete();
            $table->boolean('restocked')->nullable()->after('refunded_from_sale_item_id');

            $table->index(['sale_id', 'parent_sale_item_id']);
        });

        Schema::table('cart_items', function (Blueprint $table) {
            // Un renglon es un articulo o un servicio, nunca los dos: lo cuida `CartService`.
            $table->unsignedBigInteger('product_id')->nullable()->change();
            $table->foreignId('service_id')->nullable()->after('product_id')->constrained()->cascadeOnDelete();

            $table->unique(['cart_id', 'service_id']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable()->change();
            $table->foreignId('service_id')->nullable()->after('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_order_item_id')->nullable()->after('service_id')->constrained('order_items')->cascadeOnDelete();
            $table->json('service_snapshot')->nullable()->after('pick_note');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_order_item_id');
            $table->dropConstrainedForeignId('service_id');
            $table->dropColumn('service_snapshot');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique(['cart_id', 'service_id']);
            $table->dropConstrainedForeignId('service_id');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropIndex(['sale_id', 'parent_sale_item_id']);
            $table->dropConstrainedForeignId('refunded_from_sale_item_id');
            $table->dropConstrainedForeignId('parent_sale_item_id');
            $table->dropConstrainedForeignId('service_id');
            $table->dropColumn(['master_quantity', 'component_change', 'returnable_by_default', 'service_snapshot', 'restocked']);
        });
    }
};

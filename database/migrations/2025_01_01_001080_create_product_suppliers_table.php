<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un articulo lo puede surtir mas de un proveedor.
 *
 * Hasta ahora `products.supplier_id` decia «el proveedor», en singular, y por eso la pantalla
 * de compras solo dejaba pedirle a uno lo que ese uno surtia. En una tienda de barrio el mismo
 * arroz se le compra a dos o tres segun quien tenga y a como este.
 *
 * `products.supplier_id` se conserva como **principal**, sincronizado con la fila que tenga
 * `is_primary`. No se elimina: media aplicacion lo usa como atajo y quitarlo seria un cambio
 * mucho mas grande que el que este modulo necesita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            // La referencia con la que el proveedor lo llama en su catalogo.
            $table->string('supplier_sku', 60)->nullable();
            $table->bigInteger('last_cost')->default(0);
            $table->dateTime('last_purchased_at')->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['product_id', 'supplier_id']);
            $table->index(['company_id', 'supplier_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            // Unidades por empaque de compra: si el proveedor vende por cajas de 12, pedir 7 no
            // es una opcion y el sugerido se redondea hacia arriba.
            $table->unsignedInteger('purchase_pack_size')->default(1)->after('min_stock');
            // Cuanto se pide respecto al minimo. Dos veces el minimo deja margen para vender
            // mientras llega el siguiente pedido.
            $table->decimal('reorder_multiplier', 4, 2)->default(2.00)->after('purchase_pack_size');
        });

        // Lo que hay hoy se conserva: el proveedor de cada articulo pasa a ser su principal.
        DB::statement('
            INSERT INTO product_suppliers (company_id, product_id, supplier_id, last_cost, is_primary, created_at, updated_at)
            SELECT company_id, id, supplier_id, cost, 1, NOW(), NOW()
            FROM products
            WHERE supplier_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['purchase_pack_size', 'reorder_multiplier']);
        });

        Schema::dropIfExists('product_suppliers');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traslados de mercancia entre bodegas, con documento.
 *
 * Antes se movia mercancia con dos filas de kardex y nada mas: nadie podia decir quien lo
 * mando, quien lo recibio ni cuanto se perdio en el camino. El documento es lo que hace que
 * una diferencia entre sedes se pueda explicar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20);
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->string('note')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dispatched_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('dispatched_at')->nullable();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('received_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
            $table->index(['from_warehouse_id', 'status']);
            $table->index(['to_warehouse_id', 'status']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->decimal('received_quantity', 12, 3)->default(0);
            // El costo al despachar, para poder valorizar lo que va en camino.
            $table->bigInteger('unit_cost')->default(0);
            /*
             * Los lotes con que salio la mercancia.
             *
             * Viajan con ella: el lote y el vencimiento son del producto fisico, no del
             * estante. Sin esto, mover una caja de leche de bodega le borraria la fecha, y
             * la alerta de «por vencer» dejaria de verla.
             */
            $table->json('lots')->nullable();
            $table->timestamps();

            $table->unique(['stock_transfer_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};

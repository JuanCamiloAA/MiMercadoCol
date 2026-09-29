<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devoluciones a proveedor, con orden de compra o sueltas.
 *
 * El borrador no mueve inventario; despachar saca la mercancia y deja la devolucion esperando
 * lo que responda el proveedor (nota credito, reembolso o reposicion). Lo que el proveedor no
 * acepta y vuelve se registra como rechazo y entra otra vez a la misma bodega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('number', 20);
            $table->string('status', 20)->default('draft');
            // Lo que se le reclama al proveedor, con IVA. La base sale hacia atras por articulo.
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->bigInteger('total')->default(0);
            $table->bigInteger('rejected_total')->default(0);
            $table->bigInteger('settled_total')->default(0);
            $table->string('note')->nullable();
            $table->string('shipping_reference', 60)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // La ultima correccion del borrador: la ficha la pinta en el historial.
            $table->foreignId('edited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('edited_at')->nullable();
            $table->foreignId('dispatched_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('dispatched_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'supplier_id']);
        });

        Schema::create('supplier_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->decimal('rejected_quantity', 12, 3)->default(0);
            // Lo que se reclama por unidad, con IVA: el costo de la orden o el ultimo con el proveedor.
            $table->bigInteger('unit_cost')->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->bigInteger('line_total')->default(0);
            // El costo promedio al despachar: es el que va al kardex, no lo que se reclama.
            $table->bigInteger('inventory_unit_cost')->nullable();
            $table->string('reason', 20);
            $table->string('note')->nullable();
            $table->foreignId('lot_id')->nullable()->constrained('product_lots')->nullOnDelete();
            // Los lotes que de verdad salieron: un rechazo los vuelve a armar con su vencimiento.
            $table->json('lots')->nullable();
            $table->timestamps();

            $table->unique(['supplier_return_id', 'product_id']);
        });

        Schema::create('supplier_return_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_return_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('document_number', 40)->nullable();
            $table->bigInteger('amount');
            $table->dateTime('received_at');
            $table->string('payment_method', 20)->nullable();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('returned_quantity', 12, 3)->default(0)->after('received_quantity');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            // Solo en las ordenes de reposicion: la devolucion que las origino.
            $table->foreignId('supplier_return_id')->nullable()->after('parent_order_id')
                ->constrained()->nullOnDelete();
        });

        Schema::table('product_lots', function (Blueprint $table) {
            // De aqui en adelante cada lote recuerda con que renglon de compra entro, para que
            // una devolucion con orden saque justo ese.
            $table->foreignId('purchase_order_item_id')->nullable()->after('warehouse_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_lots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_item_id');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_return_id');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('returned_quantity');
        });

        Schema::dropIfExists('supplier_return_settlements');
        Schema::dropIfExists('supplier_return_items');
        Schema::dropIfExists('supplier_returns');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devoluciones de venta y saldo a favor.
 *
 * Una devolucion es un documento propio (DV-0001) que cuelga de la venta original: dice que
 * volvio, por que, a donde fue cada articulo (inventario o averia) y como se le respondio al
 * cliente. La plata sigue saliendo por una venta en negativo (`refund_sale_id`), como antes: asi
 * el arqueo y los informes del dia no cambian de regla.
 *
 * El saldo a favor es un libro de movimientos por cliente. `remaining` y `expires_at` solo los
 * tienen los abonos: se consumen del que vence primero. `customers.store_credit_balance` es la
 * suma, guardada para no recalcularla en cada pantalla; solo `StoreCreditService` la escribe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained();
            $table->string('number', 20);
            $table->foreignId('sale_id')->constrained('sales');
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('channel', 10);
            $table->string('origin', 20);
            $table->string('status', 20);
            $table->string('resolution', 20)->nullable();
            $table->string('refund_method', 20)->nullable();
            $table->bigInteger('total')->default(0);
            $table->bigInteger('refunded_total')->default(0);
            $table->bigInteger('credited_total')->default(0);
            $table->bigInteger('exchanged_total')->default(0);
            $table->bigInteger('debt_relief_total')->default(0);
            $table->foreignId('refund_sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->foreignId('exchange_sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->string('reason', 160)->nullable();
            $table->text('customer_note')->nullable();
            $table->string('reject_reason', 255)->nullable();
            $table->json('photos')->nullable();
            $table->boolean('out_of_window')->default(false);
            $table->foreignId('window_approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('requested_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('received_at')->nullable();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('rejected_at')->nullable();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('cancelled_by_customer')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
            $table->index('sale_id');
        });

        Schema::create('customer_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained('sale_items');
            $table->foreignId('parent_item_id')->nullable()->constrained('customer_return_items')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->string('description', 200);
            $table->decimal('quantity', 12, 3);
            $table->bigInteger('unit_price')->default(0);
            $table->bigInteger('line_total')->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->bigInteger('unit_cost')->default(0);
            $table->string('reason', 30);
            $table->string('disposition', 20)->nullable();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('store_credit_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->bigInteger('amount');
            $table->bigInteger('remaining')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->foreignId('customer_return_id')->nullable()->constrained('customer_returns')->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index(['customer_id', 'occurred_at']);
            $table->index(['customer_id', 'expires_at']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->bigInteger('store_credit_balance')->default(0);
        });

        Schema::table('orders', function (Blueprint $table) {
            // Saldo a favor apartado al confirmar el pedido; se cobra al entregar.
            $table->bigInteger('store_credit_amount')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('store_credit_amount');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('store_credit_balance');
        });

        Schema::dropIfExists('store_credit_movements');
        Schema::dropIfExists('customer_return_items');
        Schema::dropIfExists('customer_returns');
    }
};

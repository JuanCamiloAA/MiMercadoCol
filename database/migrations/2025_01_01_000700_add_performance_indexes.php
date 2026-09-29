<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indices que sostienen las consultas que mas crecen con el tiempo.
 *
 * El panel y los informes agregan sobre `sales` por dia y por sede, y el POS busca
 * articulos por nombre y codigo. Con unos miles de ventas da igual; con medio millon, no.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->index(['company_id', 'store_id', 'sold_at'], 'sales_company_store_sold_index');
            $table->index(['company_id', 'status', 'due_date'], 'sales_company_status_due_index');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->index(['sale_id', 'product_id'], 'sale_items_sale_product_index');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->index(['company_id', 'store_id', 'occurred_at'], 'stock_movements_company_store_date_index');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->index(['company_id', 'is_online_visible', 'is_active'], 'products_company_online_index');
            $table->index(['company_id', 'name'], 'products_company_name_index');
        });

        Schema::table('customer_payments', function (Blueprint $table) {
            $table->index(['customer_id', 'paid_at'], 'customer_payments_customer_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_company_store_sold_index');
            $table->dropIndex('sales_company_status_due_index');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropIndex('sale_items_sale_product_index');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_movements_company_store_date_index');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_company_online_index');
            $table->dropIndex('products_company_name_index');
        });

        Schema::table('customer_payments', function (Blueprint $table) {
            $table->dropIndex('customer_payments_customer_date_index');
        });
    }
};

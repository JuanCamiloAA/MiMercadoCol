<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('sku', 40);
            $table->string('barcode', 60)->nullable();
            $table->string('brand')->nullable();
            $table->string('unit', 10)->default('unit');
            $table->boolean('sold_by_weight')->default(false);
            $table->bigInteger('cost')->default(0);
            $table->bigInteger('price')->default(0);
            $table->bigInteger('wholesale_price')->nullable();
            $table->bigInteger('online_price')->nullable();
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->unsignedInteger('min_stock')->default(0);
            $table->boolean('track_lots')->default(false);
            $table->boolean('allow_negative_stock')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_online_visible')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_id', 'sku']);
            $table->unique(['company_id', 'barcode']);
            $table->index(['company_id', 'category_id']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('number', 20);
            $table->string('channel', 15)->default('app');
            $table->string('status', 15)->default('new');
            $table->string('payment_method', 20)->default('cash_on_delivery');
            $table->string('payment_status', 10)->default('pending');
            $table->string('delivery_address');
            $table->string('delivery_notes')->nullable();
            $table->dateTime('delivery_slot_start')->nullable();
            $table->dateTime('delivery_slot_end')->nullable();
            $table->foreignId('courier_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('delivery_fee')->default(0);
            $table->bigInteger('discount_total')->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->bigInteger('total')->default(0);
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('placed_at');
            $table->dateTime('delivered_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
            $table->index(['store_id', 'placed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

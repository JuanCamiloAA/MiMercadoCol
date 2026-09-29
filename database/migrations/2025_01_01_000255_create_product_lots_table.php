<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40)->nullable();
            $table->date('expires_at')->nullable();
            $table->decimal('quantity', 12, 3)->default(0);
            $table->bigInteger('unit_cost')->nullable();
            $table->dateTime('received_at');
            $table->timestamps();

            // Se consume primero lo que vence antes; el indice sostiene esa consulta.
            $table->index(['product_id', 'store_id', 'expires_at']);
            $table->index(['company_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_lots');
    }
};

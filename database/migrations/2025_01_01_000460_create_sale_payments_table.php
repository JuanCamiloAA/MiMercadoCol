<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('method', 15);
            $table->bigInteger('amount');
            $table->bigInteger('received_amount')->nullable();
            $table->bigInteger('change_amount')->nullable();
            $table->string('reference', 60)->nullable();
            $table->timestamps();

            $table->index(['sale_id', 'method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
    }
};

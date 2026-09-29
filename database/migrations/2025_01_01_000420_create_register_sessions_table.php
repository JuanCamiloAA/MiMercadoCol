<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('register_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('register_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->dateTime('opened_at');
            $table->bigInteger('opening_amount')->default(0);
            $table->json('opening_breakdown')->nullable();
            $table->string('opening_note')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->bigInteger('counted_amount')->nullable();
            $table->json('closing_breakdown')->nullable();
            $table->bigInteger('expected_amount')->nullable();
            $table->bigInteger('difference')->nullable();
            $table->string('closing_note')->nullable();
            $table->string('status', 10)->default('open');
            $table->timestamps();

            $table->index(['register_id', 'status']);
            $table->index(['store_id', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('register_sessions');
    }
};

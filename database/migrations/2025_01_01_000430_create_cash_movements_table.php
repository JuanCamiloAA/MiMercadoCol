<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('register_session_id')->constrained()->cascadeOnDelete();
            $table->string('type', 5);
            $table->bigInteger('amount');
            $table->string('reason');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};

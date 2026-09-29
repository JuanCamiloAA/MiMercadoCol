<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // 0 significa "contador de toda la empresa"; no lleva FK para que el unique funcione sin NULL.
            $table->unsignedBigInteger('store_id')->default(0);
            $table->string('key', 30);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'store_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_counters');
    }
};

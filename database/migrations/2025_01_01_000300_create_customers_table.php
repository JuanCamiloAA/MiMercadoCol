<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 5)->default('CC');
            $table->string('document_number', 40);
            $table->string('name');
            $table->string('business_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('neighborhood')->nullable();
            $table->string('delivery_notes')->nullable();
            $table->bigInteger('credit_limit')->default(0);
            $table->unsignedInteger('credit_term_days')->default(0);
            $table->boolean('credit_enabled')->default(false);
            $table->boolean('credit_blocked')->default(false);
            $table->string('blocked_reason')->nullable();
            $table->boolean('is_online_user')->default(false);
            $table->string('password')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();

            $table->unique(['company_id', 'document_number']);
            $table->index(['company_id', 'credit_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};

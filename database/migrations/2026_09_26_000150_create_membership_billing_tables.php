<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobro de la membresia: la tarjeta guardada de cada empresa y cada intento de cobro.
 *
 * De la tarjeta solo se guarda lo que devuelve la pasarela: la fuente de pago y los datos que
 * se muestran. Jamas el numero completo, el CVC ni el token de un solo uso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('wompi');
            $table->string('environment', 20)->default('sandbox');
            $table->string('payment_source_id', 60);
            $table->string('brand', 30)->nullable();
            $table->string('last4', 4)->nullable();
            $table->unsignedTinyInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();
            $table->string('holder_name', 120)->nullable();
            $table->string('customer_email', 160)->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('membership_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_plan_id')->nullable()->constrained('membership_plans')->nullOnDelete();
            $table->foreignId('billing_cycle_id')->nullable()->constrained('billing_cycles')->nullOnDelete();
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('COP');
            $table->string('concept', 160);
            $table->dateTime('period_starts_at')->nullable();
            $table->dateTime('period_ends_at')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('method', 20);
            $table->string('reference', 60)->unique();
            $table->string('gateway_transaction_id', 80)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('failure_reason')->nullable();
            $table->dateTime('charged_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            // null = lo hizo el sistema (renovacion automatica).
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'charged_at']);
            $table->index('gateway_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_charges');
        Schema::dropIfExists('company_payment_methods');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que cambia el plan para una empresa en particular:
 *
 * - `company_entitlements`: excepciones que suman o quitan un nodo, con vigencia. No se borran:
 *   terminar una antes de tiempo es ponerle `ends_at`.
 * - `company_plan_prices`: precio especial de una empresa en un plan, como precio mensual fijo
 *   o como descuento (exactamente uno de los dos). Es de ese plan: no se arrastra al cambiar.
 *
 * `expiry_notified_at` y `expiry_recorded_at` los llena `membership:process`: el aviso tres
 * dias antes y el renglon de la bitacora el dia que vence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('node', 120);
            $table->string('effect', 10);
            $table->dateTime('starts_at');
            // null = sin vencimiento.
            $table->dateTime('ends_at')->nullable();
            $table->string('reason', 250);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('expiry_notified_at')->nullable();
            $table->dateTime('expiry_recorded_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'ends_at']);
        });

        Schema::create('company_plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_plan_id')->constrained('membership_plans')->cascadeOnDelete();
            $table->bigInteger('monthly_price')->nullable();
            $table->unsignedTinyInteger('discount_percent')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('reason', 250);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('expiry_notified_at')->nullable();
            $table->dateTime('expiry_recorded_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'membership_plan_id', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_plan_prices');
        Schema::dropIfExists('company_entitlements');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planes de membresia de la plataforma. El precio va en COP entero y es mensual: el de cada
 * ciclo sale de `MembershipPricing::priceFor()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 80)->unique();
            $table->text('description')->nullable();
            $table->bigInteger('monthly_price')->default(0);
            $table->unsignedSmallInteger('trial_days')->default(0);
            // null = sin tope.
            $table->unsignedInteger('max_stores')->nullable();
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_registers')->nullable();
            $table->json('features')->nullable();
            $table->boolean('is_public')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_plans');
    }
};

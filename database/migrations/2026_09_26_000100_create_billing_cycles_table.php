<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclos de cobro de la membresia: catalogo de plataforma (mensual, trimestral...). El precio
 * de un plan en un ciclo lo calcula `MembershipPricing`, nadie mas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('code', 40)->unique();
            $table->unsignedTinyInteger('months');
            $table->unsignedTinyInteger('discount_percent')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_cycles');
    }
};

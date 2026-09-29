<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La pasarela de pagos de la plataforma (Wompi).
 *
 * `payment_gateway_settings` guarda lo global: que entorno esta en uso y si los cobros estan
 * encendidos, apagados por defecto. Las credenciales van por entorno en
 * `payment_gateway_credentials`, cifradas con la APP_KEY (cast `encrypted`): una llave privada
 * en texto plano es una llave regalada a quien lea un respaldo. Solo la publica queda legible,
 * porque de todos modos viaja al navegador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->unique();
            $table->string('environment', 20)->default('sandbox');
            $table->boolean('is_enabled')->default(false);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payment_gateway_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('environment', 20);
            $table->string('public_key')->nullable();
            // `text`: cifradas ocupan bastante mas que el valor original.
            $table->text('private_key')->nullable();
            $table->text('events_secret')->nullable();
            $table->text('integrity_secret')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['provider', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_credentials');
        Schema::dropIfExists('payment_gateway_settings');
    }
};

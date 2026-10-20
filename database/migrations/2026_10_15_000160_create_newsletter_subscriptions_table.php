<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los correos que dejan los clientes en el pie de la tienda para recibir novedades.
 *
 * Uno por empresa: el mismo correo en dos sedes de la misma empresa es una sola suscripcion
 * (`store_id` dice donde se suscribio primero). `consent_at` es la autorizacion de datos, que es
 * obligatoria para guardar el correo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email', 160);
            $table->dateTime('consent_at');
            $table->string('source', 40)->default('footer');
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'email']);
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscriptions');
    }
};

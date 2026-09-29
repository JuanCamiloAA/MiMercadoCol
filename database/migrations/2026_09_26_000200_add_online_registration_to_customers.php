<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de clientes en la tienda en linea.
 *
 * El cliente queda en la empresa de la sede donde se registro, con esa sede como preferida y la
 * fecha en que acepto terminos y tratamiento de datos (Ley 1581 de 2012).
 *
 * Quien ya existia en la caja sin cuenta en linea no puede ponerse contrasena con solo saber
 * su documento: se le manda un codigo al correo de su ficha. El codigo se guarda con hash y
 * vence; tampoco se puede probar indefinidamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('preferred_store_id')->nullable()->after('company_id')->constrained('stores')->nullOnDelete();
            $table->dateTime('accepted_terms_at')->nullable()->after('is_online_user');
        });

        Schema::create('customer_activations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code_hash');
            // La contrasena que eligio, ya con hash: se aplica solo al confirmar el codigo.
            $table->string('password_hash');
            $table->json('profile')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at');
            $table->dateTime('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_activations');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preferred_store_id');
            $table->dropColumn('accepted_terms_at');
        });
    }
};

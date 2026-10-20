<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Medios de pago por empresa:
 *
 * - `payment_gateway_accounts`: la cuenta de la EMPRESA en una pasarela. Distinta de
 *   `payment_gateway_credentials`, que es la de la plataforma para cobrar membresias. Unica por
 *   empresa + proveedor + entorno, ninguna nula (MariaDB deja repetir NULL en un indice unico).
 *   Las llaves secretas van en `secret_fields`, JSON cifrado en reposo.
 * - `payment_methods`: cada medio configurado («Nequi», «Datáfono Bancolombia»); el
 *   comportamiento sale de `kind`. `system_key` identifica los que la plataforma crea sola.
 * - `payment_terminals`: datafonos integrados, por sede o caja.
 * - `payment_transactions`: cada intento de cobro por pasarela, con `reference` unica como llave
 *   de idempotencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('environment', 12);
            $table->string('label', 80)->nullable();
            $table->json('public_fields')->nullable();
            $table->text('secret_fields')->nullable();
            $table->string('webhook_token', 64)->unique();
            $table->boolean('is_enabled')->default(false);
            $table->dateTime('verified_at')->nullable();
            $table->string('last_error', 250)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'provider', 'environment'], 'payment_gateway_accounts_company_provider_env_unique');
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('kind', 30);
            // Los que crea la plataforma (efectivo, saldo a favor...): para no repetirlos.
            $table->string('system_key', 30)->nullable();
            $table->foreignId('gateway_account_id')->nullable()->constrained('payment_gateway_accounts')->nullOnDelete();
            $table->boolean('is_system')->default(false);
            $table->string('icon', 30)->nullable();
            $table->json('display')->nullable();
            $table->boolean('requires_reference')->default(false);
            $table->string('reference_label', 60)->nullable();
            $table->decimal('fee_percent', 5, 2)->default(0);
            $table->bigInteger('fee_fixed')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('availability')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'system_key']);
            $table->index(['company_id', 'is_active', 'sort_order']);
        });

        Schema::create('payment_terminals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            // Nulo: cualquier caja de la sede.
            $table->foreignId('register_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('gateway_account_id')->constrained('payment_gateway_accounts')->cascadeOnDelete();
            $table->string('device_id', 120);
            $table->string('model', 60)->nullable();
            $table->string('name', 80);
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway_account_id', 'device_id']);
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('register_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('gateway_account_id')->nullable()->constrained('payment_gateway_accounts')->nullOnDelete();
            $table->foreignId('payment_terminal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 20);
            $table->string('environment', 12);
            $table->string('reference', 40)->unique();
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('COP');
            $table->string('channel', 10);
            $table->string('mode', 12);
            $table->nullableMorphs('payable');
            $table->string('status', 12)->default('pending');
            $table->string('status_detail', 250)->nullable();
            $table->string('gateway_transaction_id', 120)->nullable()->index();
            $table->string('gateway_link_id', 120)->nullable();
            $table->text('checkout_url')->nullable();
            $table->text('qr_payload')->nullable();
            $table->string('terminal_id', 120)->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('applied_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->json('payload')->nullable();
            // Lo que hace falta para convertirla en venta: el tiquete de la caja, el pedido...
            $table->json('context')->nullable();
            // Pagos por conciliar: que se hizo con una aprobada que no termino en nada.
            $table->string('resolution', 20)->nullable();
            $table->string('resolution_note', 250)->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->bigInteger('refunded_amount')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('payment_terminals');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('payment_gateway_accounts');
    }
};

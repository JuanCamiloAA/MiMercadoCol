<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contratar la plataforma desde la landing.
 *
 * `company_signups`: «Pagar ahora y activar». Se guarda antes de ir a la pasarela y la empresa
 * nace cuando el webhook confirma el pago. La contrasena del administrador se guarda ya con
 * hash: nunca en claro, ni un segundo.
 *
 * `platform_leads`: «Quiero que me contacten» y el formulario de contacto de la landing. Las
 * gestiona el super usuario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_signups', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 60)->unique();
            $table->foreignId('membership_plan_id')->nullable()->constrained('membership_plans')->nullOnDelete();
            $table->foreignId('billing_cycle_id')->nullable()->constrained('billing_cycles')->nullOnDelete();
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('COP');
            $table->string('company_name', 120);
            $table->string('tax_id', 40);
            $table->string('city', 80)->nullable();
            $table->string('company_phone', 40)->nullable();
            $table->string('company_email', 160);
            $table->string('admin_name', 80);
            $table->string('admin_last_name', 80)->nullable();
            $table->string('admin_email', 160);
            $table->string('admin_document', 40)->nullable();
            $table->string('admin_password_hash');
            $table->unsignedSmallInteger('estimated_stores')->nullable();
            $table->text('message')->nullable();
            $table->dateTime('accepted_terms_at');
            $table->string('status', 20)->default('pending');
            $table->string('transaction_id', 80)->nullable();
            $table->string('failure_reason')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('expires_at');
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index('tax_id');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->foreign('signup_id')->references('id')->on('company_signups')->nullOnDelete();
        });

        Schema::create('platform_leads', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20)->default('signup');
            $table->string('company_name', 120)->nullable();
            $table->string('tax_id', 40)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('contact_name', 120);
            $table->string('email', 160);
            $table->string('phone', 40)->nullable();
            $table->unsignedSmallInteger('estimated_stores')->nullable();
            $table->foreignId('membership_plan_id')->nullable()->constrained('membership_plans')->nullOnDelete();
            $table->foreignId('billing_cycle_id')->nullable()->constrained('billing_cycles')->nullOnDelete();
            $table->text('message')->nullable();
            $table->string('status', 20)->default('new');
            $table->text('notes')->nullable();
            $table->foreignId('handled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_leads');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['signup_id']);
        });

        Schema::dropIfExists('company_signups');
    }
};

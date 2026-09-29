<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plazos de pago de cada empresa (contado, 8, 15, 30 dias): el credito a clientes y lo que se
 * le queda debiendo a un proveedor.
 *
 * El cliente y el proveedor apuntan a un plazo. `customers.credit_term_days` se conserva,
 * igual a los dias del plazo, porque lo leen el calculo de mora y la tarea de bloqueo.
 * Las empresas que ya existen reciben sus cuatro plazos por defecto.
 */
return new class extends Migration
{
    /** Nombre, dias y si es el defecto de clientes / proveedores. */
    protected const DEFAULTS = [
        ['Contado', 0, true, false],
        ['8 días', 8, false, false],
        ['15 días', 15, false, false],
        ['30 días', 30, false, true],
    ];

    public function up(): void
    {
        Schema::create('payment_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->unsignedSmallInteger('days')->default(0);
            $table->boolean('is_default_customers')->default(false);
            $table->boolean('is_default_suppliers')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('payment_term_id')->nullable()->after('credit_term_days')->constrained('payment_terms')->nullOnDelete();
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->foreignId('payment_term_id')->nullable()->after('lead_time_days')->constrained('payment_terms')->nullOnDelete();
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('payment_term_id')->nullable()->after('supplier_id')->constrained('payment_terms')->nullOnDelete();
            // Fecha de recepcion total + dias del plazo: cuando hay que pagarle al proveedor.
            $table->dateTime('due_at')->nullable()->after('received_at');
        });

        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach (self::DEFAULTS as $index => [$name, $days, $customers, $suppliers]) {
                DB::table('payment_terms')->insert([
                    'company_id' => $companyId,
                    'name' => $name,
                    'days' => $days,
                    'is_default_customers' => $customers,
                    'is_default_suppliers' => $suppliers,
                    'is_active' => true,
                    'sort_order' => $index,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_term_id');
            $table->dropColumn('due_at');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_term_id');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_term_id');
        });

        Schema::dropIfExists('payment_terms');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lo que la plataforma necesita saber de cada empresa: su direccion web, su ciudad y su
 * membresia.
 *
 * Las empresas que ya existen quedan activas y sin fecha de fin: desplegar esto no puede
 * bloquear a nadie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('slug', 80)->nullable()->after('name');
            $table->string('city', 80)->nullable()->after('address');
            $table->foreignId('membership_plan_id')->nullable()->after('is_active')->constrained('membership_plans')->nullOnDelete();
            $table->foreignId('billing_cycle_id')->nullable()->after('membership_plan_id')->constrained('billing_cycles')->nullOnDelete();
            // El cambio que pidio la empresa: aplica en la proxima renovacion.
            $table->foreignId('next_membership_plan_id')->nullable()->after('billing_cycle_id')->constrained('membership_plans')->nullOnDelete();
            $table->foreignId('next_billing_cycle_id')->nullable()->after('next_membership_plan_id')->constrained('billing_cycles')->nullOnDelete();
            $table->string('membership_status', 20)->default('active')->after('next_billing_cycle_id');
            $table->dateTime('membership_started_at')->nullable()->after('membership_status');
            $table->dateTime('membership_ends_at')->nullable()->after('membership_started_at');
            $table->dateTime('grace_ends_at')->nullable()->after('membership_ends_at');
            $table->dateTime('next_charge_at')->nullable()->after('grace_ends_at');
            $table->boolean('auto_renew')->default(false)->after('next_charge_at');
            $table->boolean('show_on_landing')->default(true)->after('auto_renew');
            $table->dateTime('deactivated_at')->nullable()->after('show_on_landing');
            $table->string('deactivation_reason')->nullable()->after('deactivated_at');
            // Rastro de la contratacion en linea. La llave foranea llega con la tabla de contrataciones.
            $table->unsignedBigInteger('signup_id')->nullable()->after('deactivation_reason');
        });

        $used = [];

        foreach (DB::table('companies')->orderBy('id')->get(['id', 'name']) as $company) {
            $base = Str::slug($company->name) ?: 'empresa';
            $slug = $base;
            $suffix = 2;

            while (in_array($slug, $used, true)) {
                $slug = $base.'-'.$suffix++;
            }

            $used[] = $slug;

            DB::table('companies')->where('id', $company->id)->update([
                'slug' => $slug,
                'membership_status' => 'active',
                'membership_started_at' => now(),
            ]);
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropConstrainedForeignId('membership_plan_id');
            $table->dropConstrainedForeignId('billing_cycle_id');
            $table->dropConstrainedForeignId('next_membership_plan_id');
            $table->dropConstrainedForeignId('next_billing_cycle_id');
            $table->dropColumn([
                'slug', 'city', 'membership_status', 'membership_started_at', 'membership_ends_at',
                'grace_ends_at', 'next_charge_at', 'auto_renew', 'show_on_landing', 'deactivated_at',
                'deactivation_reason', 'signup_id',
            ]);
        });
    }
};

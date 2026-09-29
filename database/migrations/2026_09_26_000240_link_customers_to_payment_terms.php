<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los clientes que ya tenian credito quedan con el plazo de sus mismos dias.
 *
 * Antes el plazo era un numero suelto; ahora se elige de la lista de la empresa. Si un cliente
 * tenia 45 dias y la empresa no tiene ese plazo, se crea: cambiarle los dias sin que nadie lo
 * pida cambiaria cuando entra en mora.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $groups = DB::table('customers')
            ->whereNull('payment_term_id')
            ->where('credit_enabled', true)
            ->select('company_id', 'credit_term_days')
            ->distinct()
            ->get();

        foreach ($groups as $group) {
            $days = (int) $group->credit_term_days;

            $termId = DB::table('payment_terms')
                ->where('company_id', $group->company_id)
                ->where('days', $days)
                ->orderByDesc('is_active')
                ->orderBy('id')
                ->value('id');

            if (! $termId) {
                $termId = DB::table('payment_terms')->insertGetId([
                    'company_id' => $group->company_id,
                    'name' => $days === 0 ? 'Contado' : "{$days} días",
                    'days' => $days,
                    'is_default_customers' => false,
                    'is_default_suppliers' => false,
                    'is_active' => true,
                    'sort_order' => (int) DB::table('payment_terms')->where('company_id', $group->company_id)->max('sort_order') + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('customers')
                ->where('company_id', $group->company_id)
                ->whereNull('payment_term_id')
                ->where('credit_enabled', true)
                ->where('credit_term_days', $days)
                ->update(['payment_term_id' => $termId]);
        }
    }

    public function down(): void
    {
        // Los plazos creados aqui quedan: pueden estar ya en uso.
    }
};

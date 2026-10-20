<?php

use App\Services\NumberSequenceService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El consecutivo de venta pasa de «por sede» a «por empresa y prefijo» (`sale:C1`, `sale:MOS`).
 *
 * Con el de antes, dos sedes con su «Caja 1» sacaban las dos el C1-000001 y la segunda venta
 * reventaba contra el unico de `sales.number`. Cada contador nuevo arranca en el mayor numero ya
 * emitido con ese prefijo en la empresa, en cualquier sede: el siguiente no choca con nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        // El prefijo es todo lo que va antes del ultimo guion: un codigo de caja puede llevar guiones.
        $rows = DB::table('sales')
            ->selectRaw("company_id, LEFT(number, CHAR_LENGTH(number) - CHAR_LENGTH(SUBSTRING_INDEX(number, '-', -1)) - 1) AS prefix, MAX(CAST(SUBSTRING_INDEX(number, '-', -1) AS UNSIGNED)) AS last_number")
            ->where('number', 'like', '%-%')
            ->groupBy('company_id', 'prefix')
            ->get();

        foreach ($rows as $row) {
            if ($row->prefix === '' || $row->prefix === null) {
                continue;
            }

            $key = NumberSequenceService::saleKey((string) $row->prefix);
            $current = (int) DB::table('document_counters')->where('company_id', $row->company_id)->where('store_id', 0)->where('key', $key)->value('last_number');

            DB::table('document_counters')->updateOrInsert(
                ['company_id' => $row->company_id, 'store_id' => 0, 'key' => $key],
                ['last_number' => max($current, (int) $row->last_number), 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    public function down(): void
    {
        DB::table('document_counters')->where('key', 'like', 'sale:%')->delete();
    }
};

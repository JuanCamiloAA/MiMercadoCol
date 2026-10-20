<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Desde cuando una bodega no tiene disponible de un articulo (existencia menos apartado en cero o
 * menos). La pinta «Tienda en linea · Publicados sin stock». La mantiene solo `StockService`.
 *
 * Las filas que hoy ya estan en cero toman la fecha de su ultimo movimiento en esa bodega; sin
 * movimientos quedan en nulo (la pantalla dice «—»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dateTime('out_of_stock_since')->nullable()->after('reserved_quantity');
        });

        DB::table('product_stocks')
            ->whereRaw('quantity - reserved_quantity <= 0')
            ->orderBy('id')
            ->select(['id', 'product_id', 'warehouse_id'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $last = DB::table('stock_movements')
                        ->where('product_id', $row->product_id)
                        ->where('warehouse_id', $row->warehouse_id)
                        ->max('occurred_at');

                    if ($last !== null) {
                        DB::table('product_stocks')->where('id', $row->id)->update(['out_of_stock_since' => $last]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropColumn('out_of_stock_since');
        });
    }
};

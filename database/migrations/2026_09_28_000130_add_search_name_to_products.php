<?php

use App\Support\SearchText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La llave de busqueda de cada articulo (`SearchText::key` del nombre y la marca).
 *
 * El buscador de la tienda y sus sugerencias comparan contra ella, con indice, en vez de pasar
 * cada nombre por PHP en cada tecla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('search_name', 255)->nullable()->after('name');
            $table->index(['company_id', 'search_name']);
        });

        DB::table('products')->select(['id', 'name', 'brand'])->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('products')->where('id', $row->id)->update([
                    'search_name' => mb_substr(SearchText::key($row->name.' '.$row->brand), 0, 255),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'search_name']);
            $table->dropColumn('search_name');
        });
    }
};

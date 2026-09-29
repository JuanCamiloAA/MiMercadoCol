<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los bloques pasan a tener pagina: portada, categoria, producto, carrito y busqueda.
 *
 * Todo lo que ya existia era de la portada, asi que el valor por defecto lo deja donde estaba.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefront_blocks', function (Blueprint $table) {
            $table->string('page', 16)->default('home')->after('type');
            $table->index(['storefront_id', 'page', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('storefront_blocks', function (Blueprint $table) {
            $table->dropIndex(['storefront_id', 'page', 'sort_order']);
            $table->dropColumn('page');
        });
    }
};

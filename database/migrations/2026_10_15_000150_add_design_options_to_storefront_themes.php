<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mas forma para el tema de la tienda: la letra del nombre, el fondo, los titulos en mayusculas,
 * el ancho de la pagina y la forma de los botones. Todos con el defecto que pinta la tienda hoy,
 * asi ninguna sede cambia al migrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefront_themes', function (Blueprint $table) {
            $table->string('logo_font', 20)->default('none')->after('button_style');
            $table->string('surface', 20)->default('white')->after('logo_font');
            $table->string('heading_case', 20)->default('normal')->after('surface');
            $table->string('container', 20)->default('normal')->after('heading_case');
            $table->string('button_shape', 20)->default('default')->after('container');
        });
    }

    public function down(): void
    {
        Schema::table('storefront_themes', function (Blueprint $table) {
            $table->dropColumn(['logo_font', 'surface', 'heading_case', 'container', 'button_shape']);
        });
    }
};

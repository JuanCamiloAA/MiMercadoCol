<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * El catalogo de plantillas de tienda: cada una con su `slug` (por el que la siembra sabe si ya
 * existe), para quien es, su familia de comercio (el filtro de la galeria), los colores que
 * sugiere y su orden.
 *
 * «Supermercado» queda con `supermercado`. Las que alguien creo a mano reciben su nombre con el
 * id al final: asi nunca chocan con una del catalogo que se siembre despues.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefront_templates', function (Blueprint $table) {
            $table->string('slug', 100)->nullable()->after('name');
            $table->string('audience', 120)->nullable()->after('description');
            $table->string('audience_group', 40)->nullable()->after('audience');
            $table->json('palette_suggestion')->nullable()->after('audience_group');
            $table->unsignedInteger('sort_order')->default(0)->after('palette_suggestion');
        });

        $taken = false;

        foreach (DB::table('storefront_templates')->orderBy('id')->get(['id', 'name']) as $index => $row) {
            $base = $row->name === 'Supermercado' && ! $taken;

            DB::table('storefront_templates')->where('id', $row->id)->update($base
                ? ['slug' => 'supermercado', 'audience' => 'Supermercados y tiendas de barrio', 'audience_group' => 'Mercado', 'sort_order' => 0]
                : ['slug' => Str::limit(Str::slug($row->name) ?: 'plantilla', 80, '').'-'.$row->id, 'sort_order' => 100 + $index]);

            $taken = $taken || $base;
        }

        Schema::table('storefront_templates', function (Blueprint $table) {
            $table->string('slug', 100)->nullable(false)->change();
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('storefront_templates', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'audience', 'audience_group', 'palette_suggestion', 'sort_order']);
        });
    }
};

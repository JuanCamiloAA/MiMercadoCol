<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * De donde salio cada articulo.
 *
 * Saberlo importa cuando una importacion trae mil articulos: el administrador tiene que poder
 * distinguir lo que digito a mano de lo que llego de una fuente externa, y volver atras sin
 * arrastrar lo suyo.
 *
 * `image_url` guarda la foto remota de la fuente. No se descarga: bajar mil imagenes en la
 * importacion la volveria eterna y llenaria el disco de fotos de articulos que quiza el
 * comerciante ni venda. Si el administrador sube una propia, `image_path` manda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('source', 10)->default(Product::SOURCE_MANUAL)->after('is_featured');
            $table->string('source_ref', 50)->nullable()->after('source');
            $table->text('image_url')->nullable()->after('thumb_path');

            $table->index(['company_id', 'source']);
        });

        // Lo que ya estaba en la base se digito a mano o entro por el CSV de maestras; en
        // cualquier caso no vino de la API, y `manual` es el valor por defecto correcto.
        DB::table('products')->update(['source' => Product::SOURCE_MANUAL]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'source']);
            $table->dropColumn(['source', 'source_ref', 'image_url']);
        });
    }
};

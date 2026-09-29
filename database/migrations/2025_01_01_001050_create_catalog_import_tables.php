<?php

use App\Models\CatalogImport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitacora de las importaciones de catalogo.
 *
 * Una fila por corrida y una por articulo. El detalle por articulo es lo que convierte «se
 * omitieron 412» en algo accionable: sin el motivo de cada uno, el administrador no sabe si
 * le faltaron nombres, si los codigos venian malos o si la API se cayo a la mitad.
 *
 * Las fechas van en `dateTime` y no en `timestamp`: en MariaDB solo la primera columna
 * TIMESTAMP NOT NULL de una tabla recibe valor por defecto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('source', 24)->default(CatalogImport::SOURCE_API);
            $table->json('criteria')->nullable();
            $table->string('status', 12)->default(CatalogImport::STATUS_PENDING);
            $table->unsignedInteger('total_detected')->default(0);
            $table->unsignedInteger('total_imported')->default(0);
            $table->unsignedInteger('total_updated')->default(0);
            $table->unsignedInteger('total_skipped')->default(0);
            $table->unsignedInteger('total_failed')->default(0);
            $table->text('error_message')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('catalog_import_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_import_id')->constrained()->cascadeOnDelete();
            // Siempre string: pasarlo a entero en cualquier punto se come los ceros de la
            // izquierda y el escaner de caja ya no encuentra el articulo.
            $table->string('ean', 14)->nullable();
            $table->string('name', 200)->nullable();
            $table->json('payload')->nullable();
            $table->string('result', 12);
            $table->string('reason', 255)->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['catalog_import_id', 'result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_import_items');
        Schema::dropIfExists('catalog_imports');
    }
};

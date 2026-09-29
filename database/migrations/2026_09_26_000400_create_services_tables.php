<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Servicios: lo que la tienda vende sin tenerlo en un estante (armar una ancheta, porcionar al
 * vacio, una recarga). Tabla propia y no una bandera en `products`: un servicio no tiene
 * existencias, lotes, compras ni minimos, y meterlo alli obligaria a filtrarlo en cada informe
 * de inventario. Lo que consume sale del inventario como lineas hijas de la venta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 40);
            $table->string('barcode', 60)->nullable();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->string('thumb_path')->nullable();
            // Precio al publico con IVA. En «servicio + articulos» es solo el valor del servicio.
            $table->bigInteger('price')->default(0);
            $table->bigInteger('online_price')->nullable();
            // Mano de obra o insumos que no se llevan en inventario.
            $table->bigInteger('cost')->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->string('pricing_mode', 12)->default('bundled');
            $table->boolean('open_price')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_online_visible')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'category_id']);
            $table->unique(['company_id', 'barcode']);
        });

        Schema::create('service_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            // Por una unidad de servicio.
            $table->decimal('quantity', 12, 3);
            // Si al devolver el servicio este articulo vuelve al estante: una ancheta si, un corte no.
            $table->boolean('returnable_by_default')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['service_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_components');
        Schema::dropIfExists('services');
    }
};

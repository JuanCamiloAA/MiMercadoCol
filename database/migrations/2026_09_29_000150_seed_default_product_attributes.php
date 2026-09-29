<?php

use App\Services\ProductAttributeCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las empresas que ya existian reciben las caracteristicas de fabrica (volumen, peso, unidades,
 * sabor, tamaño, color, talla). Las nuevas las reciben en `CompanyProvisioner`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $catalog = app(ProductAttributeCatalog::class);

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $catalog->seedDefaults((int) $companyId);
        }
    }

    public function down(): void
    {
        // Lo sembrado se va con las tablas en `create_product_attributes_tables`.
    }
};

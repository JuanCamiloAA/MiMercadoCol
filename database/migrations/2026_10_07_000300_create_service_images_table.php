<?php

use App\Services\Catalog\CatalogImages;
use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los servicios pasan a tener varias fotos, como los articulos. La foto que ya tenian queda como
 * la primera (la portada sigue en `services.image_path`).
 *
 * Y el tope de fotos por ficha (`catalog.max_images`, 1 por defecto): a una empresa que ya monto
 * varias fotos en un articulo se le deja el tope en lo que ya usa, para que pueda seguir editando
 * sin que el formulario le rechace lo que ya tenia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('thumb_path')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['service_id', 'sort_order']);
        });

        $now = now();

        DB::table('services')->whereNotNull('image_path')->orderBy('id')->chunkById(200, function ($services) use ($now) {
            DB::table('service_images')->insert($services->map(fn ($service) => [
                'service_id' => $service->id,
                'path' => $service->image_path,
                'thumb_path' => $service->thumb_path,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });

        $this->keepCurrentUsage();
    }

    public function down(): void
    {
        Schema::dropIfExists('service_images');
    }

    protected function keepCurrentUsage(): void
    {
        $used = [];

        $counts = [
            DB::table('product_images')->join('products', 'products.id', '=', 'product_images.product_id')
                ->groupBy('products.company_id', 'product_images.product_id')
                ->selectRaw('products.company_id as company_id, COUNT(*) as total'),
            DB::table('product_family_images')->join('product_families', 'product_families.id', '=', 'product_family_images.product_family_id')
                ->groupBy('product_families.company_id', 'product_family_images.product_family_id')
                ->selectRaw('product_families.company_id as company_id, COUNT(*) as total'),
            DB::table('service_images')->join('services', 'services.id', '=', 'service_images.service_id')
                ->groupBy('services.company_id', 'service_images.service_id')
                ->selectRaw('services.company_id as company_id, COUNT(*) as total'),
        ];

        foreach ($counts as $query) {
            foreach ($query->get() as $row) {
                $used[(int) $row->company_id] = max($used[(int) $row->company_id] ?? 0, (int) $row->total);
            }
        }

        $settings = app(SettingsService::class);

        foreach ($used as $companyId => $total) {
            if ($total > 1) {
                $settings->set($companyId, 'catalog.max_images', min(CatalogImages::MAX_LIMIT, $total));
            }
        }
    }
};

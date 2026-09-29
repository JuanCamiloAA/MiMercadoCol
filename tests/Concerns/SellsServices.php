<?php

namespace Tests\Concerns;

use App\Models\Product;
use App\Models\Service;
use App\Services\ServiceManager;

/**
 * Ayudantes para las pruebas de servicios: un servicio armado por el mismo camino del
 * formulario, con sus articulos.
 */
trait SellsServices
{
    /**
     * @param  list<array{0: Product, 1: float, 2?: bool, 3?: int|null}>  $components  [articulo, cantidad, vuelve al inventario, precio fijo]
     * @param  array<string, mixed>  $attributes
     */
    protected function makeService(string $name, int $price, array $components = [], string $mode = Service::PRICING_BUNDLED, array $attributes = []): Service
    {
        return app(ServiceManager::class)->save(null, array_merge([
            'code' => 'SRV-'.random_int(1000, 999999),
            'name' => $name,
            'price' => $price,
            'cost' => 0,
            'tax_rate' => 0,
            'pricing_mode' => $mode,
            'open_price' => false,
            'is_active' => true,
            'is_online_visible' => false,
            'is_featured' => false,
            'components' => array_map(fn (array $component) => [
                'product_id' => $component[0]->id,
                'quantity' => $component[1],
                'unit_price' => $component[3] ?? null,
                'returnable_by_default' => $component[2] ?? true,
            ], $components),
        ], $attributes), $this->company->id);
    }
}

<?php

namespace Tests\Concerns;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductSupplier;
use App\Models\Supplier;

/**
 * Ayudantes para las pruebas de compras.
 *
 * Un articulo de compras no es solo un articulo: necesita quien se lo surta a la empresa y
 * cuanto hay en la sede. Armar eso a mano en cada prueba esconderia lo que cada una comprueba.
 */
trait BuysCatalog
{
    protected function makeSupplier(string $name): Supplier
    {
        return Supplier::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => $name,
            'document_number' => (string) random_int(900000000, 999999999),
            'is_active' => true,
        ]);
    }

    /**
     * Un articulo con su proveedor y su existencia en la sede activa.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeSuppliedProduct(
        string $name,
        ?Supplier $supplier = null,
        int $cost = 1000,
        float $stock = 10,
        int $minStock = 5,
        int $packSize = 1,
        float $multiplier = 2.0,
        array $attributes = [],
    ): Product {
        $product = Product::query()->withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'category_id' => $this->category->id,
            'supplier_id' => $supplier?->id,
            'name' => $name,
            'sku' => 'SKU-'.random_int(10000, 999999),
            'barcode' => (string) random_int(1000000000000, 9999999999999),
            'unit' => 'unit',
            'cost' => $cost,
            'price' => $cost * 2,
            'tax_rate' => 0,
            'min_stock' => $minStock,
            'purchase_pack_size' => $packSize,
            'reorder_multiplier' => $multiplier,
            'is_active' => true,
        ], $attributes));

        ProductStock::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'store_id' => $this->store->id,
            'warehouse_id' => $this->warehouse()->id,
            'quantity' => $stock,
            'reserved_quantity' => 0,
        ]);

        if ($supplier) {
            ProductSupplier::query()->withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'product_id' => $product->id,
                'supplier_id' => $supplier->id,
                'last_cost' => $cost,
                'is_primary' => true,
            ]);
        }

        return $product->load('suppliers');
    }
}

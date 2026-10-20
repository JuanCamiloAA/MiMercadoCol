<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductStock;
use Illuminate\Support\Collection;

/**
 * El catalogo como lo necesita la pantalla de entradas y salidas de bodega.
 *
 * Es primo del de compras, pero mira otra cosa: alli importa quien surte el articulo y cuanto
 * conviene pedir; aqui importa cuanto hay hoy en la sede y cuanto de eso esta comprometido.
 * Por eso la existencia se devuelve sin recortar en cero: un saldo negativo es justo lo que
 * alguien viene a corregir, y esconderlo dejaria la pantalla mintiendo.
 */
class InventoryCatalogService
{
    /**
     * Articulos de la sede con su existencia.
     *
     * @param  string|null  $search  Nombre, SKU o codigo de barras.
     * @return array{products: list<array<string, mixed>>, total: int, low_stock: int}
     */
    public function catalog(
        int $companyId,
        int $storeId,
        ?string $search = null,
        bool $onlyLowStock = false,
        int $limit = 120,
        ?int $warehouseId = null,
    ): array {
        $query = Product::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->when($search, fn ($builder, $term) => $builder->where(function ($inner) use ($term) {
                $inner->where('name', 'like', "%{$term}%")
                    ->orWhere('sku', 'like', "%{$term}%")
                    // El codigo de barras se compara exacto: un escaner manda el codigo entero.
                    ->orWhere('barcode', $term);
            }));

        $total = (clone $query)->count();

        $products = $query->orderBy('name')->limit($limit)->get();
        $stock = $this->stockByProduct($companyId, $storeId, $products->pluck('id')->all(), $warehouseId);

        $rows = $products
            ->map(fn (Product $product) => $this->row($product, $stock[$product->id] ?? ['quantity' => 0.0, 'reserved' => 0.0]))
            ->filter(fn (array $row) => ! $onlyLowStock || $row['is_low'])
            ->values();

        return [
            'products' => $rows->all(),
            'total' => $total,
            'low_stock' => $this->lowStockCount($companyId, $storeId),
        ];
    }

    /**
     * Lo que responde el buscador de las pantallas con catalogo: busqueda, filtro o escaneo.
     *
     * @param  array{search?: ?string, low_stock?: ?bool, barcode?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function lookup(int $companyId, int $storeId, array $filters, ?int $warehouseId = null): array
    {
        // Un escaneo es otra pregunta: o esta ese codigo exacto, o no esta.
        if (! empty($filters['barcode'])) {
            $product = $this->byBarcode($companyId, $storeId, $filters['barcode'], $warehouseId);

            return [
                'products' => $product ? [$product] : [],
                'found' => $product !== null,
            ];
        }

        $catalog = $this->catalog(
            $companyId,
            $storeId,
            ($filters['search'] ?? null) ?: null,
            (bool) ($filters['low_stock'] ?? false),
            warehouseId: $warehouseId,
        );

        return [
            'products' => $catalog['products'],
            'total' => $catalog['total'],
            'low_stock' => $catalog['low_stock'],
            'found' => true,
        ];
    }

    /**
     * Un articulo con todo lo que la tarjeta necesita.
     *
     * @param  array{quantity: float, reserved: float}  $stock
     * @return array<string, mixed>
     */
    public function row(Product $product, array $stock): array
    {
        $quantity = round((float) $stock['quantity'], 3);
        $reserved = round((float) $stock['reserved'], 3);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'unit' => $product->unit,
            'stock' => $quantity,
            'reserved' => $reserved,
            // Lo que de verdad se puede sacar: lo apartado ya tiene dueno.
            'available' => round($quantity - $reserved, 3),
            'min_stock' => (int) $product->min_stock,
            'unit_cost' => (int) $product->cost,
            'is_low' => $product->min_stock > 0 && $quantity <= $product->min_stock,
            'is_out' => $quantity <= 0,
            'allow_negative_stock' => (bool) $product->allow_negative_stock,
        ];
    }

    /**
     * Un articulo por su codigo de barras, para el escaner.
     *
     * @return array<string, mixed>|null
     */
    public function byBarcode(int $companyId, int $storeId, string $barcode, ?int $warehouseId = null): ?array
    {
        $product = Product::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('barcode', $barcode)
            ->first();

        if (! $product) {
            return null;
        }

        $stock = $this->stockByProduct($companyId, $storeId, [$product->id], $warehouseId);

        return $this->row($product, $stock[$product->id] ?? ['quantity' => 0.0, 'reserved' => 0.0]);
    }

    /**
     * Unos articulos puntuales con su existencia, para precargar un formulario (una solicitud
     * de traslado que llega desde «Tienda en linea»). En el orden en que se pidieron.
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    public function rowsFor(int $companyId, int $storeId, array $ids, ?int $warehouseId = null): array
    {
        if ($ids === []) {
            return [];
        }

        $products = Product::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
        $stock = $this->stockByProduct($companyId, $storeId, $products->keys()->all(), $warehouseId);

        return collect($ids)
            ->filter(fn (int $id) => $products->has($id))
            ->map(fn (int $id) => $this->row($products->get($id), $stock[$id] ?? ['quantity' => 0.0, 'reserved' => 0.0]))
            ->values()
            ->all();
    }

    /**
     * Existencia y apartado de cada articulo en la sede.
     *
     * @param  list<int>  $productIds
     * @return Collection<int, array{quantity: float, reserved: float}>
     */
    public function stockByProduct(int $companyId, int $storeId, array $productIds = [], ?int $warehouseId = null): Collection
    {
        return ProductStock::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('store_id', $storeId)
            ->when($warehouseId, fn ($query, $id) => $query->where('warehouse_id', $id))
            ->when($productIds !== [], fn ($query) => $query->whereIn('product_id', $productIds))
            // Sin bodega elegida se suman las de la sede; sin agrupar, una sede con dos
            // bodegas se quedaria solo con la fila de la ultima.
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as quantity, SUM(reserved_quantity) as reserved_quantity')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->product_id => [
                    'quantity' => (float) $row->quantity,
                    'reserved' => (float) $row->reserved_quantity,
                ],
            ]);
    }

    public function lowStockCount(int $companyId, int $storeId): int
    {
        // Por articulo, sumando bodegas: lo que hay atras tambien surte el estante.
        return ProductStock::query()
            ->withoutGlobalScopes()
            ->join('products', 'products.id', '=', 'product_stocks.product_id')
            ->where('product_stocks.company_id', $companyId)
            ->where('product_stocks.store_id', $storeId)
            ->whereNull('products.deleted_at')
            ->where('products.is_active', true)
            ->where('products.min_stock', '>', 0)
            ->groupBy('product_stocks.product_id', 'products.min_stock')
            ->havingRaw('SUM(product_stocks.quantity) <= products.min_stock')
            ->selectRaw('product_stocks.product_id')
            ->get()
            ->count();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Product>
     */
    public function productsFor(int $companyId, array $ids): Collection
    {
        return Product::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }
}

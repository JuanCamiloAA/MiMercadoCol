<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductFamily;
use App\Models\ProductSupplier;
use Illuminate\Support\Collection;

/**
 * El catalogo como lo necesita la pantalla de compras.
 *
 * La regla de fondo de este modulo: **el proveedor no filtra, resalta**. Antes solo se podia
 * pedir lo que el proveedor elegido surtia, y para pedirle dos cosas a dos proveedores habia
 * que armar dos ordenes desde cero. Aqui se devuelve todo el catalogo de la sede, marcando
 * cual surte quien, y la pantalla ordena para que lo relevante quede arriba.
 */
class PurchaseCatalogService
{
    public function __construct(protected PurchaseSuggestionService $suggestions) {}

    /**
     * Articulos de la sede, con su existencia, sus proveedores y cuanto se sugiere pedir.
     *
     * @param  string|null  $search  Nombre, SKU o codigo de barras.
     * @return array{products: list<array<string, mixed>>, total: int, low_stock: int}
     */
    public function catalog(
        int $companyId,
        int $storeId,
        ?int $supplierId = null,
        ?string $search = null,
        bool $onlyLowStock = false,
        int $limit = 120,
    ): array {
        $query = Product::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->with(['suppliers.supplier:id,name', 'family:id,name'])
            ->when($search, fn ($builder, $term) => $builder->where(function ($inner) use ($term) {
                $inner->where('name', 'like', "%{$term}%")
                    ->orWhere('sku', 'like', "%{$term}%")
                    // El codigo de barras se compara exacto: un escaner manda el codigo entero.
                    ->orWhere('barcode', $term);
            }));

        $total = (clone $query)->count();

        $products = $query->orderBy('name')->limit($limit)->get();

        $stock = $this->suggestions->stockByProduct($companyId, $storeId, $products->pluck('id')->all());

        $rows = $products
            ->map(fn (Product $product) => $this->row($product, (float) ($stock[$product->id] ?? 0), $supplierId))
            ->filter(fn (array $row) => ! $onlyLowStock || $row['is_low'])
            ->values();

        return [
            // Lo del proveedor y bajo minimo primero; nunca se esconde nada.
            'products' => $rows->sortBy([
                fn (array $a, array $b) => $this->rank($a) <=> $this->rank($b),
                fn (array $a, array $b) => strcmp($a['name'], $b['name']),
            ])->values()->all(),
            'total' => $total,
            'low_stock' => $this->suggestions->lowStockCount($companyId, $storeId),
        ];
    }

    /**
     * Un articulo con todo lo que la tarjeta necesita.
     *
     * @return array<string, mixed>
     */
    public function row(Product $product, float $stock, ?int $supplierId): array
    {
        $suppliers = $product->suppliers
            ->map(fn (ProductSupplier $link) => [
                'id' => (int) $link->supplier_id,
                'name' => $link->supplier?->name,
                'last_cost' => (int) $link->last_cost,
                'is_primary' => (bool) $link->is_primary,
            ])
            ->values();

        $isLow = $product->min_stock > 0 && $stock <= $product->min_stock;
        $fromSupplier = $supplierId !== null && $suppliers->contains('id', $supplierId);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'unit' => $product->unit,
            'stock' => round($stock, 3),
            'min_stock' => (int) $product->min_stock,
            'pack_size' => max(1, (int) $product->purchase_pack_size),
            'suppliers' => $suppliers->all(),
            // El nombre que se pinta en la tarjeta cuando no es del proveedor elegido.
            'supplier_label' => $suppliers->firstWhere('is_primary', true)['name'] ?? $suppliers->first()['name'] ?? null,
            'is_from_supplier' => $fromSupplier,
            'is_low' => $isLow,
            'is_out' => $stock <= 0,
            'suggested' => max(1, $this->suggestions->suggestFor($product, $stock)),
            'unit_cost' => $this->costFor($product, $supplierId),
            // Un subarticulo sale en el catalogo como su articulo principal, que abre la matriz.
            'family_id' => $product->product_family_id,
            'family_name' => $product->product_family_id ? $product->family?->name : null,
            'variant_label' => $product->variant_label,
            'value_1_id' => $product->variant_value_1_id,
            'value_2_id' => $product->variant_value_2_id,
        ];
    }

    /**
     * La matriz de compra de un articulo con subarticulos: sus valores en orden y, por cada
     * subarticulo activo, lo mismo que la tarjeta (existencia de la sede, minimo, sugerido y el
     * costo de ese subarticulo con el proveedor de la orden).
     *
     * La matriz es solo pantalla: al guardar llegan renglones normales por subarticulo.
     *
     * @return array<string, mixed>
     */
    public function familyMatrix(ProductFamily $family, int $storeId, ?int $supplierId): array
    {
        $family->loadMissing(['attribute1', 'attribute2']);

        $variants = Product::query()
            ->withoutGlobalScopes()
            ->where('company_id', $family->company_id)
            ->where('product_family_id', $family->id)
            ->where('is_active', true)
            ->with(['suppliers.supplier:id,name', 'family:id,name'])
            ->orderBy('variant_sort')
            ->get();

        $stock = $this->suggestions->stockByProduct((int) $family->company_id, $storeId, $variants->pluck('id')->all());
        $values = ProductAttributeValue::query()->whereIn('id', $variants->pluck('variant_value_1_id')->merge($variants->pluck('variant_value_2_id'))->filter()->unique())->orderBy('sort_order')->get();
        $axis = fn (?int $attributeId) => $values->where('product_attribute_id', $attributeId)->map(fn (ProductAttributeValue $value) => ['id' => $value->id, 'value' => $value->value])->values()->all();

        return [
            'id' => $family->id,
            'name' => $family->name,
            'attribute_1' => ['name' => $family->attribute1?->name, 'values' => $axis($family->attribute_1_id)],
            'attribute_2' => $family->attribute_2_id ? ['name' => $family->attribute2?->name, 'values' => $axis($family->attribute_2_id)] : null,
            'rows' => $variants->map(fn (Product $variant) => $this->row($variant, (float) ($stock[$variant->id] ?? 0), $supplierId))->values()->all(),
        ];
    }

    /**
     * A como sale el articulo.
     *
     * Primero lo que ese proveedor cobro la ultima vez; si nunca lo ha surtido, lo mas reciente
     * de cualquier otro; y si nadie lo ha comprado, el costo del articulo. Siempre editable: la
     * pantalla propone, el comprador decide.
     */
    public function costFor(Product $product, ?int $supplierId): int
    {
        $links = $product->suppliers;

        if ($supplierId !== null) {
            $own = $links->firstWhere('supplier_id', $supplierId);

            if ($own && $own->last_cost > 0) {
                return (int) $own->last_cost;
            }
        }

        $latest = $links
            ->filter(fn (ProductSupplier $link) => $link->last_cost > 0 && $link->last_purchased_at !== null)
            ->sortByDesc('last_purchased_at')
            ->first();

        return (int) ($latest?->last_cost ?: $product->cost);
    }

    /**
     * En que orden se muestra: lo urgente y lo del proveedor primero.
     *
     * @param  array<string, mixed>  $row
     */
    protected function rank(array $row): int
    {
        return match (true) {
            $row['is_from_supplier'] && $row['is_low'] => 0,
            $row['is_from_supplier'] => 1,
            $row['is_low'] => 2,
            default => 3,
        };
    }

    /**
     * Los articulos bajo minimo de la sede, listos para agregarse de una.
     *
     * @return list<array<string, mixed>>
     */
    public function lowStock(int $companyId, int $storeId, ?int $supplierId, int $limit = 200): array
    {
        return $this->catalog($companyId, $storeId, $supplierId, null, true, $limit)['products'];
    }

    /**
     * Un articulo por su codigo de barras exacto, para el escaner.
     *
     * @return array<string, mixed>|null
     */
    public function byBarcode(int $companyId, int $storeId, string $barcode, ?int $supplierId): ?array
    {
        $product = Product::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('barcode', trim($barcode))
            ->with(['suppliers.supplier:id,name'])
            ->first();

        if (! $product) {
            return null;
        }

        $stock = $this->suggestions->stockByProduct($companyId, $storeId, [$product->id]);

        return $this->row($product, (float) ($stock[$product->id] ?? 0), $supplierId);
    }

    /**
     * @return Collection<int, Product>
     */
    public function productsFor(int $companyId, array $ids): Collection
    {
        return Product::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('id', $ids)
            ->with('suppliers')
            ->get()
            ->keyBy('id');
    }
}

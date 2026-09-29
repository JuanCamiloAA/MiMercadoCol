<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductLot;
use App\Models\ProductStock;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnItem;
use Illuminate\Support\Collection;

/**
 * Lo que la pantalla de devoluciones necesita saber de cada articulo: cuanto hay disponible en
 * cada bodega de la sede (existencia menos apartado), sus lotes y el costo que se le reclama al
 * proveedor. El formulario no inventa disponibles: los calcula de lo que llega de aqui.
 */
class SupplierReturnCatalog
{
    public function __construct(protected SupplierReturnService $returns) {}

    /**
     * Todos los renglones de la orden, con lo recibido, lo ya devuelto y el maximo.
     *
     * @return list<array<string, mixed>>
     */
    public function orderLines(PurchaseOrder $order, ?SupplierReturn $editing = null): array
    {
        $order->loadMissing('items.product');
        $limits = $this->returns->orderLimits($order, $editing?->id);
        $products = $order->items->pluck('product')->filter();
        $stock = $this->stockMap($products, $order->store_id);
        $lots = $this->lotsFor($products, $order->store_id);
        $current = $editing ? $editing->items->keyBy('purchase_order_item_id') : collect();

        return $order->items
            ->filter(fn (PurchaseOrderItem $item) => $item->product !== null)
            ->map(function (PurchaseOrderItem $item) use ($limits, $stock, $lots, $current) {
                $limit = $limits[$item->id];
                /** @var SupplierReturnItem|null $line */
                $line = $current->get($item->id);

                return [
                    ...$this->productPayload($item->product, $stock, $lots, $item),
                    'purchase_order_item_id' => $item->id,
                    'received' => $limit['received'],
                    'returned' => $limit['returned'],
                    'in_drafts' => $limit['in_drafts'],
                    'returnable' => $limit['returnable'],
                    'unit_cost' => (int) $item->unit_cost,
                    'lot_code' => $item->lot_code,
                    'quantity' => $line ? round((float) $line->quantity, 3) : 0,
                    'reason' => $line?->reason,
                    'note' => $line?->note,
                    'lot_id' => $line?->lot_id,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Los renglones de una devolucion suelta que se esta corrigiendo.
     *
     * @return list<array<string, mixed>>
     */
    public function looseLines(SupplierReturn $return): array
    {
        $return->loadMissing('items.product');
        $products = $return->items->pluck('product')->filter();
        $stock = $this->stockMap($products, $return->store_id);
        $lots = $this->lotsFor($products, $return->store_id);
        $linked = $this->linkedProducts($products->pluck('id')->all(), $return->supplier_id);

        return $return->items
            ->filter(fn (SupplierReturnItem $item) => $item->product !== null)
            ->map(fn (SupplierReturnItem $item) => [
                ...$this->productPayload($item->product, $stock, $lots),
                'suggested_cost' => $this->returns->suggestedCost($item->product, $return->supplier_id),
                'is_supplier_product' => in_array($item->product_id, $linked, true),
                'unit_cost' => $item->unit_cost,
                'quantity' => round((float) $item->quantity, 3),
                'reason' => $item->reason,
                'note' => $item->note,
                'lot_id' => $item->lot_id,
            ])
            ->values()
            ->all();
    }

    /**
     * El buscador de la devolucion suelta: por nombre, SKU o codigo de barras exacto (escaner).
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    public function search(int $companyId, int $storeId, ?int $supplierId, ?string $term, ?string $barcode, array $ids = []): array
    {
        $query = Product::query()
            ->where('company_id', $companyId)
            ->where('is_active', true);

        // Los renglones que ya estan en pantalla, para refrescarlos al cambiar de proveedor.
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        } elseif ($barcode !== null && $barcode !== '') {
            $query->where(fn ($inner) => $inner->where('barcode', trim($barcode))->orWhere('sku', trim($barcode)));
        } elseif ($term !== null && $term !== '') {
            $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhere('barcode', $term));
        }

        $products = $query->orderBy('name')->limit($ids !== [] ? 200 : 20)->get();
        $stock = $this->stockMap($products, $storeId);
        $lots = $this->lotsFor($products, $storeId);
        $linked = $supplierId ? $this->linkedProducts($products->pluck('id')->all(), $supplierId) : [];

        return $products
            ->map(fn (Product $product) => [
                ...$this->productPayload($product, $stock, $lots),
                'suggested_cost' => $supplierId ? $this->returns->suggestedCost($product, $supplierId) : (int) $product->cost,
                'is_supplier_product' => $supplierId ? in_array($product->id, $linked, true) : true,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<int, array{quantity: float, reserved: float, available: float}>>  $stock
     * @param  array<int, list<array<string, mixed>>>  $lots
     * @return array<string, mixed>
     */
    protected function productPayload(Product $product, array $stock, array $lots, ?PurchaseOrderItem $orderItem = null): array
    {
        $productLots = collect($lots[$product->id] ?? []);

        // Con orden, primero los lotes que entraron con ella y los de su mismo codigo (S5).
        if ($orderItem) {
            $productLots = $productLots
                ->map(fn (array $lot) => [...$lot, 'from_order' => $lot['purchase_order_item_id'] === $orderItem->id
                    || ($orderItem->lot_code !== null && $lot['code'] === $orderItem->lot_code)])
                ->sortBy(fn (array $lot) => [$lot['from_order'] ? 0 : 1, $lot['expires_at'] ?? '9999-12-31', $lot['id']])
                ->values();
        }

        return [
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'unit' => $product->unit,
            'sold_by_weight' => (bool) $product->sold_by_weight,
            'track_lots' => (bool) $product->track_lots,
            'tax_rate' => (float) $product->tax_rate,
            'stock' => (object) ($stock[$product->id] ?? []),
            'lots' => $productLots->map(fn (array $lot) => array_diff_key($lot, ['purchase_order_item_id' => true]))->values()->all(),
        ];
    }

    /**
     * Existencia, apartado y disponible por bodega de la sede.
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, array<int, array{quantity: float, reserved: float, available: float}>>
     */
    protected function stockMap(Collection $products, int $storeId): array
    {
        $map = [];

        ProductStock::query()->withoutGlobalScopes()
            ->where('store_id', $storeId)
            ->whereIn('product_id', $products->pluck('id'))
            ->get(['product_id', 'warehouse_id', 'quantity', 'reserved_quantity'])
            ->each(function (ProductStock $row) use (&$map) {
                $map[$row->product_id][$row->warehouse_id] = [
                    'quantity' => round((float) $row->quantity, 3),
                    'reserved' => round((float) $row->reserved_quantity, 3),
                    'available' => round(max(0, (float) $row->quantity - (float) $row->reserved_quantity), 3),
                ];
            });

        return $map;
    }

    /**
     * Los lotes con existencia de cada articulo en las bodegas de la sede, el que vence antes primero.
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, list<array<string, mixed>>>
     */
    protected function lotsFor(Collection $products, int $storeId): array
    {
        $ids = $products->filter(fn (Product $product) => $product->track_lots)->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        return ProductLot::query()->withoutGlobalScopes()
            ->where('store_id', $storeId)
            ->whereIn('product_id', $ids)
            ->where('quantity', '>', 0)
            ->orderByRaw('expires_at IS NULL, expires_at ASC')
            ->orderBy('received_at')
            ->get()
            ->groupBy('product_id')
            ->map(fn (Collection $lots) => $lots->map(fn (ProductLot $lot) => [
                'id' => $lot->id,
                'code' => $lot->code,
                'expires_at' => $lot->expires_at?->toDateString(),
                'quantity' => round((float) $lot->quantity, 3),
                'warehouse_id' => $lot->warehouse_id,
                'purchase_order_item_id' => $lot->purchase_order_item_id,
                'from_order' => false,
            ])->values()->all())
            ->all();
    }

    /**
     * Cuales de estos articulos surte el proveedor.
     *
     * @param  list<int>  $productIds
     * @return list<int>
     */
    protected function linkedProducts(array $productIds, int $supplierId): array
    {
        // El principal cuenta aunque todavia no tenga su fila en `product_suppliers`.
        $primary = Product::query()->withoutGlobalScopes()
            ->whereIn('id', $productIds)
            ->where('supplier_id', $supplierId)
            ->pluck('id');

        return ProductSupplier::query()->withoutGlobalScopes()
            ->where('supplier_id', $supplierId)
            ->whereIn('product_id', $productIds)
            ->pluck('product_id')
            ->merge($primary)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}

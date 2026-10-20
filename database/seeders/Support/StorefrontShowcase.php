<?php

namespace Database\Seeders\Support;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\NumberSequenceService;
use App\Services\StockService;
use App\Services\WarehouseService;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Deja en una sede lo que las pantallas de «Tienda en linea» necesitan para tener de que hablar:
 * un publicado que se agoto sin reposicion, otro agotado que otra bodega de la sede si tiene,
 * otro con una compra en camino hacia la bodega de la tienda y uno sin publicar que se vende en
 * caja. Las existencias se mueven por `StockService`, como en la operacion.
 */
class StorefrontShowcase
{
    public function __construct(
        protected StockService $stock,
        protected WarehouseService $warehouses,
        protected NumberSequenceService $numbers,
    ) {}

    /**
     * @param  Warehouse  $depot  Otra bodega de la sede (no la de la tienda).
     * @return array<string, string> el nombre del articulo que quedo en cada caso
     */
    public function seed(Store $store, User $user, Warehouse $depot): array
    {
        $shelf = $this->warehouses->onlineSourceFor($store->id);
        $used = [];
        $result = [];

        // Publicados con algo en el estante y nada apartado: tocarlos no le quita a ningun pedido.
        $candidates = Product::query()->withoutGlobalScopes()
            ->where('company_id', $store->company_id)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('is_online_visible', true)
            ->where('allow_negative_stock', false)
            ->where('price', '>', 0)
            ->orderBy('name')
            ->get()
            ->filter(fn (Product $product) => $this->stock->available($product, $shelf) > 0 && $this->reserved($product, $shelf) <= 0)
            ->values();

        $pick = function (callable $accepts) use ($candidates, &$used, $depot): ?Product {
            $product = $candidates->first(fn (Product $product) => ! in_array($product->id, $used, true) && $accepts($this->stock->available($product, $depot)));

            if ($product) {
                $used[] = $product->id;
            }

            return $product;
        };

        $soldOut = $pick(fn (float $inDepot) => $inDepot <= 0);
        $transferable = $pick(fn (float $inDepot) => $inDepot > 0) ?? $pick(fn () => true);
        $incoming = $pick(fn (float $inDepot) => $inDepot <= 0);

        DB::transaction(function () use ($shelf, $depot, $user, $soldOut, $transferable, $incoming, $store, &$result) {
            foreach (array_filter([$soldOut, $transferable, $incoming]) as $product) {
                $this->stock->move($product, $shelf, StockMovement::TYPE_ADJUSTMENT, -$this->stock->available($product, $shelf), $user->id, note: 'Conteo del estante: no quedó nada');
            }

            if ($soldOut) {
                $result['sold_out'] = $soldOut->name;
            }

            if ($transferable) {
                if ($this->stock->available($transferable, $depot) <= 0) {
                    $this->stock->move($transferable, $depot, StockMovement::TYPE_ADJUSTMENT, max(12, (float) $transferable->min_stock * 2), $user->id, note: 'Inventario del depósito');
                }

                $result['transferable'] = $transferable->name;
            }

            if ($incoming && $this->purchase($store, $shelf, $incoming, $user)) {
                $result['incoming'] = $incoming->name;
            }
        });

        $seller = $this->posSeller($store, $shelf, $used);

        if ($seller) {
            if ($seller->is_online_visible) {
                $seller->forceFill(['is_online_visible' => false])->save();
            }

            $result['pos_seller'] = $seller->name;
        }

        return $result;
    }

    /** Una compra enviada al proveedor que llega a la bodega de la tienda. */
    protected function purchase(Store $store, Warehouse $shelf, Product $product, User $user): bool
    {
        $supplierId = $product->supplier_id ?? Supplier::query()->withoutGlobalScopes()->where('company_id', $store->company_id)->orderBy('id')->value('id');

        if (! $supplierId) {
            return false;
        }

        $quantity = max(10, (float) $product->min_stock * 2);
        $cost = (int) $product->cost;
        $gross = (int) round($quantity * $cost);
        $base = Money::baseFromGross($gross, (float) $product->tax_rate);

        $order = PurchaseOrder::query()->withoutGlobalScopes()->create([
            'company_id' => $store->company_id,
            'store_id' => $store->id,
            'warehouse_id' => $shelf->id,
            'supplier_id' => $supplierId,
            'code' => $this->numbers->nextPurchaseOrderCode($store->company_id),
            'status' => PurchaseOrder::STATUS_SENT,
            'expected_at' => now()->addDays(2)->toDateString(),
            'subtotal' => $base,
            'tax_total' => $gross - $base,
            'total' => $gross,
            'source' => PurchaseOrder::SOURCE_MANUAL,
            'user_id' => $user->id,
            'note' => 'Reposición de lo que se agotó en la tienda en línea',
        ]);

        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'supplier_id' => $supplierId,
            'is_off_supplier' => false,
            'quantity' => $quantity,
            'suggested_quantity' => $quantity,
            'stock_at_order' => 0,
            'min_at_order' => (float) $product->min_stock,
            'origin' => PurchaseOrderItem::ORIGIN_MANUAL,
            'received_quantity' => 0,
            'unit_cost' => $cost,
        ]);

        return true;
    }

    /**
     * El que mas se vendio en caja en los ultimos 30 dias, con existencia en el estante y que no
     * se uso arriba. Si ya hay uno sin publicar asi, ese; si no, se despublica uno.
     *
     * @param  list<int>  $used
     */
    protected function posSeller(Store $store, Warehouse $shelf, array $used): ?Product
    {
        $sold = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.store_id', $store->id)
            ->where('sales.channel', Sale::CHANNEL_POS)
            ->whereIn('sales.status', [Sale::STATUS_PAID, Sale::STATUS_CREDIT])
            ->where('sales.sold_at', '>=', now()->subDays(30))
            ->whereNotNull('sale_items.product_id')
            ->whereNotIn('sale_items.product_id', $used === [] ? [0] : $used)
            ->groupBy('sale_items.product_id')
            ->orderByRaw('SUM(sale_items.quantity) DESC')
            ->pluck('sale_items.product_id');

        /** @var Collection<int, Product> $products */
        $products = Product::query()->withoutGlobalScopes()->whereIn('id', $sold)->where('is_active', true)->get()->keyBy('id');
        $ordered = $sold->map(fn ($id) => $products->get($id))->filter()
            ->filter(fn (Product $product) => $this->stock->available($product, $shelf) > 0);

        return $ordered->first(fn (Product $product) => ! $product->is_online_visible) ?? $ordered->first();
    }

    protected function reserved(Product $product, Warehouse $warehouse): float
    {
        return (float) ProductStock::query()->withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('reserved_quantity');
    }
}

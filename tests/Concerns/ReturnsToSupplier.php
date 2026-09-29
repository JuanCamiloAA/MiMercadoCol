<?php

namespace Tests\Concerns;

use App\Models\Product;
use App\Models\ProductLot;
use App\Models\ProductStock;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SupplierReturnService;

/**
 * Ayudantes para las pruebas de devoluciones a proveedor.
 *
 * La orden se crea y se recibe por las rutas de siempre: asi los lotes quedan ligados al renglon
 * de la orden igual que en la vida real, y la prueba no depende de como se arma eso por dentro.
 */
trait ReturnsToSupplier
{
    use BuysCatalog;

    protected User $admin;

    protected Supplier $granos;

    protected function returns(): SupplierReturnService
    {
        return app(SupplierReturnService::class);
    }

    /**
     * Una orden recibida completa.
     *
     * @param  list<array{product: Product, quantity: float, cost: int, lot?: string, expires?: string}>  $lines
     */
    protected function receivedOrder(array $lines, ?Supplier $supplier = null, ?Warehouse $warehouse = null): PurchaseOrder
    {
        $this->actingAs($this->admin)->withSession($this->withActiveStore())->post(route('purchases.store'), [
            'supplier_id' => ($supplier ?? $this->granos)->id,
            'status' => PurchaseOrder::STATUS_SENT,
            'items' => array_map(fn (array $line) => [
                'product_id' => $line['product']->id,
                'quantity' => $line['quantity'],
                'unit_cost' => $line['cost'],
            ], $lines),
        ])->assertSessionHasNoErrors();

        $order = PurchaseOrder::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $items = $order->items()->get()->keyBy('product_id');

        $this->actingAs($this->admin)->withSession($this->withActiveStore())->post(route('purchases.receive', $order), [
            'warehouse_id' => $warehouse?->id,
            'items' => array_map(fn (array $line) => [
                'id' => $items[$line['product']->id]->id,
                'received_quantity' => $line['quantity'],
                'lot_code' => $line['lot'] ?? null,
                'expires_at' => $line['expires'] ?? null,
            ], $lines),
        ])->assertSessionHasNoErrors();

        return $order->refresh()->load('items');
    }

    protected function orderItem(PurchaseOrder $order, Product $product): PurchaseOrderItem
    {
        return $order->items()->where('product_id', $product->id)->firstOrFail();
    }

    /**
     * Un borrador por el servicio, con lo minimo.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     */
    protected function draft(array $items, ?PurchaseOrder $order = null, ?Warehouse $warehouse = null, array $extra = []): SupplierReturn
    {
        return $this->returns()->create(array_merge([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'supplier_id' => $order?->supplier_id ?? $this->granos->id,
            'purchase_order_id' => $order?->id,
            'warehouse_id' => ($warehouse ?? $this->warehouse())->id,
            'items' => array_map(fn (array $item) => array_merge(['reason' => 'damaged'], $item), $items),
        ], $extra), $this->admin);
    }

    /** Un renglon de la orden listo para el borrador. */
    protected function lineFor(PurchaseOrder $order, Product $product, float $quantity, array $extra = []): array
    {
        return array_merge([
            'purchase_order_item_id' => $this->orderItem($order, $product)->id,
            'quantity' => $quantity,
        ], $extra);
    }

    protected function stockOf(Product $product, ?Warehouse $warehouse = null): float
    {
        return (float) ProductStock::query()->withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->where('warehouse_id', ($warehouse ?? $this->warehouse())->id)
            ->value('quantity');
    }

    protected function reserve(Product $product, float $quantity, ?Warehouse $warehouse = null): void
    {
        ProductStock::query()->withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->where('warehouse_id', ($warehouse ?? $this->warehouse())->id)
            ->update(['reserved_quantity' => $quantity]);
    }

    /** @return list<array{code: ?string, quantity: float}> */
    protected function lotsOf(Product $product, ?Warehouse $warehouse = null): array
    {
        return ProductLot::query()->withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->where('warehouse_id', ($warehouse ?? $this->warehouse())->id)
            ->orderBy('id')
            ->get()
            ->map(fn (ProductLot $lot) => ['code' => $lot->code, 'quantity' => round((float) $lot->quantity, 3)])
            ->all();
    }
}

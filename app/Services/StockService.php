<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductLot;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Unico punto que toca `product_stocks`.
 *
 * Toda variacion deja su fila en `stock_movements` con el saldo resultante, dentro de la
 * misma transaccion: si el kardex y la existencia se pudieran separar, nadie podria
 * explicar una diferencia de inventario.
 *
 * Todo se lleva **por bodega**, no por sede. Ningun metodo recibe una sede y adivina la
 * bodega: quien mueve mercancia dice de cual, y si lo que quiere es la de caja se la pide a
 * `WarehouseService::posSourceFor`. Adivinar aqui significaria descontar del deposito de atras
 * una venta que salio del piso.
 *
 * `store_id` se sigue escribiendo, desnormalizado, porque medio sistema lo usa en joins e
 * indices; sale siempre de la bodega, nunca de un parametro aparte.
 */
class StockService
{
    public function stockFor(Product $product, Warehouse $warehouse): ProductStock
    {
        return ProductStock::query()->firstOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $warehouse->id],
            [
                'company_id' => $product->company_id,
                'store_id' => $warehouse->store_id,
                'quantity' => 0,
                'reserved_quantity' => 0,
            ]
        );
    }

    public function available(Product $product, Warehouse $warehouse): float
    {
        $stock = ProductStock::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->first();

        if (! $stock) {
            return 0;
        }

        return round($stock->quantity - $stock->reserved_quantity, 3);
    }

    /**
     * Lo que hay en toda la sede, sumando sus bodegas.
     *
     * Sirve para decidir si hay que comprar, que es una pregunta de la sede y no del estante:
     * un articulo con cero en el piso y doscientos atras no se compra, se repone.
     */
    public function availableInStore(Product $product, int $storeId): float
    {
        $row = ProductStock::query()
            ->where('product_id', $product->id)
            ->where('store_id', $storeId)
            ->selectRaw('COALESCE(SUM(quantity), 0) as quantity, COALESCE(SUM(reserved_quantity), 0) as reserved')
            ->first();

        return round((float) ($row->quantity ?? 0) - (float) ($row->reserved ?? 0), 3);
    }

    /**
     * Aplica una variacion de existencias y escribe el kardex.
     *
     * Debe llamarse dentro de una transaccion. `$quantity` positiva suma, negativa resta.
     *
     * Con `$requireAvailable` una salida no puede pasar de lo disponible (existencia menos lo
     * apartado para pedidos), permita o no el articulo quedar en negativo. Se mide aqui, con la
     * fila ya bloqueada, porque un chequeo previo fuera de la transaccion no ve la venta que entra
     * en el medio.
     */
    public function move(
        Product $product,
        Warehouse $warehouse,
        string $type,
        float $quantity,
        ?int $userId = null,
        ?Model $reference = null,
        ?string $note = null,
        ?int $unitCost = null,
        ?\DateTimeInterface $occurredAt = null,
        bool $touchLots = true,
        bool $requireAvailable = false,
    ): StockMovement {
        $stock = ProductStock::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->lockForUpdate()
            ->first();

        if (! $stock) {
            $stock = $this->stockFor($product, $warehouse);
            $stock = ProductStock::query()->whereKey($stock->id)->lockForUpdate()->first();
        }

        $newQuantity = round($stock->quantity + $quantity, 3);

        if ($requireAvailable && $quantity < 0) {
            $available = round((float) $stock->quantity - (float) $stock->reserved_quantity, 3);

            if (abs($quantity) - $available > 0.0005) {
                $verb = $type === StockMovement::TYPE_SUPPLIER_RETURN ? 'devolver' : 'sacar';
                $message = "No hay suficiente {$product->name} en {$warehouse->name} para {$verb} ".self::quantityText(abs($quantity))
                    .': hay '.self::quantityText((float) $stock->quantity);

                $message .= (float) $stock->reserved_quantity > 0
                    ? ' y '.self::quantityText((float) $stock->reserved_quantity).' están apartadas para pedidos (disponibles '.self::quantityText(max(0, $available)).').'
                    : '.';

                throw ValidationException::withMessages(['stock' => [$message]]);
            }
        }

        if ($newQuantity < 0 && ! $product->allow_negative_stock) {
            throw ValidationException::withMessages([
                'stock' => ["No hay existencias suficientes de {$product->name} en {$warehouse->name}: quedan ".rtrim(rtrim(number_format($stock->quantity, 3, ',', '.'), '0'), ',').'.'],
            ]);
        }

        $stock->quantity = $newQuantity;
        $this->stampAvailability($stock);
        $stock->save();

        // Los lotes siguen al saldo: lo que sale se toma del que vence antes. El traslado
        // es la excepcion: el ya los movio a mano para conservarles el vencimiento.
        if ($touchLots && $product->track_lots) {
            if ($quantity < 0) {
                $this->consumeLots($product, $warehouse, abs($quantity));
            } elseif ($type !== StockMovement::TYPE_PURCHASE) {
                $this->returnToLots($product, $warehouse, $quantity);
            }
        }

        return StockMovement::create([
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'store_id' => $warehouse->store_id,
            'warehouse_id' => $warehouse->id,
            'type' => $type,
            'quantity' => round($quantity, 3),
            'balance_after' => $newQuantity,
            'unit_cost' => $unitCost,
            'reference_type' => $reference ? $reference->getMorphClass() : null,
            'reference_id' => $reference?->getKey(),
            'user_id' => $userId,
            'note' => $note,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    /**
     * Entrada de mercancia con lote y vencimiento.
     *
     * Solo se registra el lote si el articulo lo controla; para el resto basta el saldo.
     */
    public function receiveLot(
        Product $product,
        Warehouse $warehouse,
        float $quantity,
        ?string $code,
        ?string $expiresAt,
        ?int $unitCost,
        ?\DateTimeInterface $receivedAt = null,
        ?int $purchaseOrderItemId = null,
    ): ?ProductLot {
        if (! $product->track_lots || $quantity <= 0) {
            return null;
        }

        return ProductLot::create([
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'store_id' => $warehouse->store_id,
            'warehouse_id' => $warehouse->id,
            'purchase_order_item_id' => $purchaseOrderItemId,
            'code' => $code,
            'expires_at' => $expiresAt,
            'quantity' => round($quantity, 3),
            'unit_cost' => $unitCost,
            'received_at' => $receivedAt ?? now(),
        ]);
    }

    /**
     * Descuenta de los lotes empezando por el que vence antes.
     *
     * Devuelve lo que no alcanzo a cubrirse con lotes: puede pasar con existencias que
     * entraron antes de que el articulo empezara a controlarlos.
     */
    public function consumeLots(Product $product, Warehouse $warehouse, float $quantity): float
    {
        return $this->takeFromLots($product, $warehouse, $quantity)['pending'];
    }

    /**
     * Lo mismo, pero contando de que lotes salio.
     *
     * Lo necesita el traslado: la mercancia tiene que llegar al destino con el vencimiento y
     * el lote con que salio, o mover una caja de un estante a otro le borraria la fecha.
     *
     * @return array{pending: float, taken: list<array{code: ?string, expires_at: ?string, unit_cost: ?int, quantity: float}>}
     */
    public function takeFromLots(Product $product, Warehouse $warehouse, float $quantity): array
    {
        if (! $product->track_lots || $quantity <= 0) {
            return ['pending' => 0.0, 'taken' => []];
        }

        $pending = round($quantity, 3);
        $taken = [];

        $lots = ProductLot::query()
            ->withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('quantity', '>', 0)
            ->orderByRaw('expires_at IS NULL, expires_at ASC')
            ->orderBy('received_at')
            ->lockForUpdate()
            ->get();

        foreach ($lots as $lot) {
            if ($pending <= 0) {
                break;
            }

            $amount = min($pending, (float) $lot->quantity);
            $lot->quantity = round($lot->quantity - $amount, 3);
            $lot->save();

            $taken[] = [
                'code' => $lot->code,
                'expires_at' => $lot->expires_at?->toDateString(),
                'unit_cost' => $lot->unit_cost,
                'quantity' => $amount,
            ];

            $pending = round($pending - $amount, 3);
        }

        return ['pending' => max(0, $pending), 'taken' => $taken];
    }

    /** Devuelve mercancia al lote mas reciente, que es de donde suele salir una devolucion. */
    public function returnToLots(Product $product, Warehouse $warehouse, float $quantity): void
    {
        if (! $product->track_lots || $quantity <= 0) {
            return;
        }

        $lot = ProductLot::query()
            ->withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->orderByDesc('received_at')
            ->lockForUpdate()
            ->first();

        if (! $lot) {
            $this->receiveLot($product, $warehouse, $quantity, null, null, $product->cost);

            return;
        }

        $lot->quantity = round($lot->quantity + $quantity, 3);
        $lot->save();
    }

    /**
     * Lotes por vencer en los proximos dias.
     *
     * Consulta de lectura: por sede, porque lo que vence en el deposito vence igual que lo que
     * vence en el piso y quien revisa la bandeja los quiere ver todos. Con `$warehouseId` se
     * limita a una sola bodega.
     *
     * @return Collection<int, ProductLot>
     */
    public function expiringLots(int $companyId, int $storeId, int $days = 15, ?int $warehouseId = null)
    {
        return ProductLot::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('store_id', $storeId)
            ->when($warehouseId, fn ($query, $id) => $query->where('warehouse_id', $id))
            ->where('quantity', '>', 0)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', now()->addDays($days))
            ->with(['product:id,name,sku,unit', 'warehouse:id,name'])
            ->orderBy('expires_at')
            ->get();
    }

    /** Reserva de un pedido en linea: no descuenta, aparta. */
    public function reserve(Product $product, Warehouse $warehouse, float $quantity): void
    {
        $stock = $this->lockedStock($product, $warehouse);

        $available = round($stock->quantity - $stock->reserved_quantity, 3);

        if ($quantity > $available && ! $product->allow_negative_stock) {
            throw ValidationException::withMessages([
                'stock' => ["No hay existencias suficientes de {$product->name}."],
            ]);
        }

        $stock->reserved_quantity = round($stock->reserved_quantity + $quantity, 3);
        $this->stampAvailability($stock);
        $stock->save();
    }

    public function releaseReservation(Product $product, Warehouse $warehouse, float $quantity): void
    {
        $stock = $this->lockedStock($product, $warehouse);

        $stock->reserved_quantity = max(0, round($stock->reserved_quantity - $quantity, 3));
        $this->stampAvailability($stock);
        $stock->save();
    }

    /**
     * Costo promedio ponderado tras recibir mercancia.
     *
     * Se calcula con la existencia total de la empresa, no la de la bodega ni la de la sede:
     * el costo es del articulo, no del estante donde quedo. Por eso un traslado no lo toca.
     */
    public function recalculateAverageCost(Product $product, float $incomingQuantity, int $incomingUnitCost): void
    {
        if ($incomingQuantity <= 0) {
            return;
        }

        $currentQuantity = (float) ProductStock::query()
            ->where('product_id', $product->id)
            ->sum('quantity');

        $previousQuantity = max(0, $currentQuantity - $incomingQuantity);
        $totalQuantity = $previousQuantity + $incomingQuantity;

        if ($totalQuantity <= 0) {
            $product->cost = $incomingUnitCost;
            $product->save();

            return;
        }

        $product->cost = (int) round(
            (($previousQuantity * $product->cost) + ($incomingQuantity * $incomingUnitCost)) / $totalQuantity
        );
        $product->save();
    }

    /** Una cantidad como la lee una persona: 25, 2,5 o 0,125. */
    public static function quantityText(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, ',', '.'), '0'), ',');
    }

    /**
     * Desde cuando la fila no tiene disponible (`out_of_stock_since`): se llena la primera vez que
     * existencia menos apartado llega a cero y vuelve a nulo apenas hay. Lo lee «Publicados sin
     * stock» en la tienda en linea. Nadie mas escribe esa columna.
     */
    protected function stampAvailability(ProductStock $stock): void
    {
        $available = round((float) $stock->quantity - (float) $stock->reserved_quantity, 3);

        if ($available > 0) {
            $stock->out_of_stock_since = null;
        } elseif ($stock->out_of_stock_since === null) {
            $stock->out_of_stock_since = now();
        }
    }

    protected function lockedStock(Product $product, Warehouse $warehouse): ProductStock
    {
        $stock = ProductStock::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->lockForUpdate()
            ->first();

        if ($stock) {
            return $stock;
        }

        $created = $this->stockFor($product, $warehouse);

        return ProductStock::query()->whereKey($created->id)->lockForUpdate()->first();
    }
}

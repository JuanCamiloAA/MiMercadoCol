<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductLot;
use App\Models\ProductStock;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnItem;
use App\Models\SupplierReturnSettlement;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Devoluciones a proveedor, de punta a punta. Es el unico camino que las crea, despacha,
 * rechaza, responde y anula.
 *
 * - **Borrador**: no toca inventario. Se valida contra lo disponible como aviso temprano.
 * - **Despachar**: la mercancia sale con `TYPE_SUPPLIER_RETURN` y `requireAvailable`, que mide
 *   existencia menos apartado con la fila bloqueada. Todo o nada.
 * - **Rechazo**: lo que el proveedor no acepto vuelve a entrar a la misma bodega, con sus lotes.
 * - **Respuesta**: nota credito, reembolso o reposicion (una orden de compra que se recibe por
 *   el flujo normal). Cuando respondido + rechazado cubre el total, se cierra.
 *
 * Ningun paso recalcula el costo promedio: sale y vuelve al costo vigente, congelado en el
 * renglon. Lo que se le reclama al proveedor (`unit_cost`) es otro dato.
 */
class SupplierReturnService
{
    public function __construct(
        protected StockService $stock,
        protected NumberSequenceService $numbers,
        protected WarehouseService $warehouses,
        protected PurchaseOrderSplitter $splitter,
    ) {}

    /**
     * @param  array<string, mixed>  $data  company_id, store_id, supplier_id, purchase_order_id?, warehouse_id,
     *                                      note?, shipping_reference?, items: list<array<string, mixed>>
     */
    public function create(array $data, User $user): SupplierReturn
    {
        return DB::transaction(function () use ($data, $user) {
            $prepared = $this->prepare((int) $data['company_id'], $data, null);

            $return = SupplierReturn::create([
                'company_id' => (int) $data['company_id'],
                'store_id' => $prepared['store_id'],
                'warehouse_id' => $prepared['warehouse']->id,
                'supplier_id' => $prepared['supplier']->id,
                'purchase_order_id' => $prepared['order']?->id,
                'number' => $this->numbers->nextSupplierReturnNumber((int) $data['company_id']),
                'status' => SupplierReturn::STATUS_DRAFT,
                'note' => $this->text($data['note'] ?? null),
                'shipping_reference' => $this->text($data['shipping_reference'] ?? null),
                'created_by_user_id' => $user->id,
                ...$prepared['totals'],
            ]);

            $this->writeItems($return, $prepared['lines']);

            return $return->load('items');
        });
    }

    /**
     * Corrige un borrador. La orden y la sede no cambian: una devolucion con orden es de esa
     * orden. Suelta, el proveedor si se puede cambiar.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(SupplierReturn $return, array $data, User $user): SupplierReturn
    {
        return DB::transaction(function () use ($return, $data, $user) {
            $locked = $this->lock($return);

            if (! $locked->isDraft()) {
                throw ValidationException::withMessages([
                    'status' => ['Solo se corrige una devolución en borrador.'],
                ]);
            }

            $data['store_id'] = $locked->store_id;
            $data['purchase_order_id'] = $locked->purchase_order_id;

            $prepared = $this->prepare($locked->company_id, $data, $locked);

            $locked->update([
                'warehouse_id' => $prepared['warehouse']->id,
                'supplier_id' => $prepared['supplier']->id,
                'note' => $this->text($data['note'] ?? null),
                'shipping_reference' => $this->text($data['shipping_reference'] ?? null),
                'edited_by_user_id' => $user->id,
                'edited_at' => now(),
                ...$prepared['totals'],
            ]);

            // En borrador los renglones no han movido nada: se reemplazan completos.
            $locked->items()->delete();
            $this->writeItems($locked, $prepared['lines']);

            return $locked->load('items');
        });
    }

    /**
     * Saca la mercancia hacia el proveedor. Todo o nada: si un renglon no alcanza, no sale ninguno.
     */
    public function dispatch(SupplierReturn $return, User $user): SupplierReturn
    {
        return DB::transaction(function () use ($return, $user) {
            $locked = $this->lock($return);

            if (! $locked->isDraft()) {
                throw ValidationException::withMessages([
                    'status' => ['Solo se despacha una devolución en borrador.'],
                ]);
            }

            $locked->load(['items.product', 'warehouse', 'supplier', 'purchaseOrder']);
            $warehouse = $locked->warehouse;

            if (! $warehouse || ! $warehouse->is_active) {
                throw ValidationException::withMessages([
                    'warehouse_id' => ['La bodega de la devolución ya no está activa: corrígela en el borrador.'],
                ]);
            }

            // Lo validado al guardar el borrador pudo cambiar: se revisa de nuevo, bloqueando los
            // renglones de la orden para que dos despachos a la vez no se pasen del tope.
            $orderItems = $this->lockOrderItems($locked);

            foreach ($locked->items as $item) {
                $product = $item->product;

                if (! $product || $product->trashed()) {
                    throw ValidationException::withMessages([
                        'items' => ['Uno de los artículos de la devolución ya no existe. Corrige el borrador.'],
                    ]);
                }

                if ($item->purchase_order_item_id) {
                    $orderItem = $orderItems->get($item->purchase_order_item_id);
                    $returnable = $orderItem ? $orderItem->returnableQuantity() : 0.0;

                    if ($item->quantity - $returnable > 0.0005) {
                        throw ValidationException::withMessages([
                            'items' => ["De {$product->name} solo se pueden devolver ".StockService::quantityText($returnable).' de esa orden: ya se devolvió el resto.'],
                        ]);
                    }
                }

                $cost = (int) $product->cost;
                $lots = $this->takeLots($item, $product, $warehouse, $item->purchase_order_item_id ? $orderItems->get($item->purchase_order_item_id) : null);

                $item->update(['inventory_unit_cost' => $cost, 'lots' => $lots]);

                $this->stock->move(
                    product: $product,
                    warehouse: $warehouse,
                    type: StockMovement::TYPE_SUPPLIER_RETURN,
                    quantity: -$item->quantity,
                    userId: $user->id,
                    reference: $locked,
                    note: "Devolución {$locked->number} a {$locked->supplier?->name}",
                    unitCost: $cost,
                    touchLots: false,
                    requireAvailable: true,
                );
            }

            $locked->update([
                'status' => SupplierReturn::STATUS_DISPATCHED,
                'dispatched_at' => now(),
                'dispatched_by_user_id' => $user->id,
            ]);

            $locked->purchaseOrder?->syncReturns();

            return $locked->refresh();
        });
    }

    /**
     * Lo que el proveedor no acepto y volvio: entra otra vez a la misma bodega, con sus lotes.
     *
     * @param  array<int|string, float|int|string|null>  $lines  Cantidad rechazada por id de renglon.
     */
    public function reject(SupplierReturn $return, array $lines, User $user, ?string $note = null): SupplierReturn
    {
        return DB::transaction(function () use ($return, $lines, $user, $note) {
            $locked = $this->lock($return);

            if (! $locked->isPendingResponse()) {
                throw ValidationException::withMessages([
                    'status' => ['Solo se registra un rechazo en una devolución pendiente de respuesta.'],
                ]);
            }

            $locked->load(['items.product', 'warehouse', 'purchaseOrder']);
            $wanted = $this->positiveLines($lines, $locked->items);
            $errors = [];
            $value = 0;

            foreach ($wanted as $itemId => $quantity) {
                /** @var SupplierReturnItem $item */
                $item = $locked->items->firstWhere('id', $itemId);
                $name = $item->product?->name ?? 'el artículo';

                if ($quantity - $item->netQuantity() > 0.0005) {
                    $errors["lines.{$itemId}"] = "De {$name} el proveedor se quedó con ".StockService::quantityText($item->netQuantity()).': no se puede rechazar más.';
                }

                if (! $item->product?->sold_by_weight && floor($quantity) !== $quantity) {
                    $errors["lines.{$itemId}"] = "{$name} se rechaza en unidades enteras.";
                }

                $value += (int) round($quantity * $item->unit_cost);
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            // Lo que el proveedor ya respondio no se puede rechazar tambien: pagaria dos veces.
            if ($value > $locked->pendingTotal()) {
                throw ValidationException::withMessages([
                    'lines' => ['Con lo que ya respondió el proveedor, solo se puede rechazar hasta '.Money::format($locked->pendingTotal()).' en mercancía; lo marcado vale '.Money::format($value).'.'],
                ]);
            }

            $movementNote = "Rechazo de {$locked->number}".($this->text($note) ? ': '.$this->text($note) : '');

            foreach ($wanted as $itemId => $quantity) {
                $item = $locked->items->firstWhere('id', $itemId);
                $alreadyRejected = (float) $item->rejected_quantity;

                $item->update(['rejected_quantity' => round($alreadyRejected + $quantity, 3)]);

                $this->stock->move(
                    product: $item->product,
                    warehouse: $locked->warehouse,
                    type: StockMovement::TYPE_SUPPLIER_RETURN,
                    quantity: $quantity,
                    userId: $user->id,
                    reference: $locked,
                    note: $movementNote,
                    unitCost: $item->inventory_unit_cost,
                    touchLots: false,
                );

                $this->restoreLots($item, $locked->warehouse, $alreadyRejected, $quantity);
            }

            $locked->load('items');
            $locked->update([
                'rejected_total' => (int) $locked->items->sum(fn (SupplierReturnItem $item) => (int) round($item->rejected_quantity * $item->unit_cost)),
            ]);

            $locked->purchaseOrder?->syncReturns();
            $locked->syncSettlement();

            return $locked->refresh();
        });
    }

    /**
     * Nota credito o reembolso.
     *
     * @param  array<string, mixed>  $data  type, amount, document_number?, received_at?, payment_method?, note?
     */
    public function settle(SupplierReturn $return, array $data, User $user): SupplierReturnSettlement
    {
        return DB::transaction(function () use ($return, $data, $user) {
            $locked = $this->lock($return);

            if (! $locked->isPendingResponse()) {
                throw ValidationException::withMessages([
                    'status' => ['Esa devolución no está pendiente de respuesta.'],
                ]);
            }

            $type = (string) ($data['type'] ?? '');

            if (! in_array($type, [SupplierReturnSettlement::TYPE_CREDIT_NOTE, SupplierReturnSettlement::TYPE_REFUND], true)) {
                throw ValidationException::withMessages(['type' => ['La respuesta es una nota crédito o un reembolso.']]);
            }

            $document = $this->text($data['document_number'] ?? null);

            if ($type === SupplierReturnSettlement::TYPE_CREDIT_NOTE && $document === null) {
                throw ValidationException::withMessages(['document_number' => ['Escribe el número de la nota crédito.']]);
            }

            $method = $type === SupplierReturnSettlement::TYPE_REFUND ? ($data['payment_method'] ?? null) : null;

            if ($type === SupplierReturnSettlement::TYPE_REFUND && ! array_key_exists((string) $method, SupplierReturnSettlement::METHOD_LABELS)) {
                throw ValidationException::withMessages(['payment_method' => ['Elige cómo llegó el reembolso.']]);
            }

            $amount = (int) ($data['amount'] ?? 0);
            $this->guardAmount($locked, $amount);

            $settlement = $locked->settlements()->create([
                'type' => $type,
                'document_number' => $document,
                'amount' => $amount,
                'received_at' => $data['received_at'] ?? now(),
                'payment_method' => $method,
                'note' => $this->text($data['note'] ?? null),
                'user_id' => $user->id,
            ]);

            $locked->syncSettlement();

            return $settlement;
        });
    }

    /**
     * El proveedor manda mercancia nueva a cambio: se crea una orden de reposicion, que se
     * recibe con el boton de siempre (lotes, vencimiento, costo). No lleva plazo de pago.
     *
     * @param  array<int|string, float|int|string|null>  $lines  Cantidad por id de renglon de la devolucion.
     */
    public function createReplacementOrder(SupplierReturn $return, array $lines, User $user, ?string $note = null): PurchaseOrder
    {
        return DB::transaction(function () use ($return, $lines, $user, $note) {
            $locked = $this->lock($return);

            if (! $locked->isPendingResponse()) {
                throw ValidationException::withMessages([
                    'status' => ['Esa devolución no está pendiente de respuesta.'],
                ]);
            }

            $locked->load(['items.product', 'replacementOrders.items']);
            $wanted = $this->positiveLines($lines, $locked->items);
            $replaced = $this->replacedByProduct($locked);
            $errors = [];
            $value = 0;

            foreach ($wanted as $itemId => $quantity) {
                $item = $locked->items->firstWhere('id', $itemId);
                $name = $item->product?->name ?? 'el artículo';
                $room = round($item->netQuantity() - ($replaced[$item->product_id] ?? 0), 3);

                if ($quantity - $room > 0.0005) {
                    $errors["lines.{$itemId}"] = "De {$name} solo quedan ".StockService::quantityText(max(0, $room)).' por reponer.';
                }

                if (! $item->product?->sold_by_weight && floor($quantity) !== $quantity) {
                    $errors["lines.{$itemId}"] = "{$name} se repone en unidades enteras.";
                }

                $value += (int) round($quantity * $item->unit_cost);
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $this->guardAmount($locked, $value);

            $order = PurchaseOrder::create([
                'company_id' => $locked->company_id,
                'store_id' => $locked->store_id,
                // Por defecto vuelve a la bodega de donde salio; al recibir se puede elegir otra.
                'warehouse_id' => $locked->warehouse_id,
                'supplier_id' => $locked->supplier_id,
                'supplier_return_id' => $locked->id,
                'payment_term_id' => null,
                'code' => $this->numbers->nextPurchaseOrderCode($locked->company_id),
                'status' => PurchaseOrder::STATUS_SENT,
                'subtotal' => 0,
                'tax_total' => 0,
                'total' => 0,
                'suggested_total' => 0,
                'source' => PurchaseOrder::SOURCE_REPLACEMENT,
                'user_id' => $user->id,
                'note' => $this->text($note) ?? "Reposición de {$locked->number}",
            ]);

            foreach ($wanted as $itemId => $quantity) {
                $item = $locked->items->firstWhere('id', $itemId);

                PurchaseOrderItem::create([
                    'purchase_order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'supplier_id' => $locked->supplier_id,
                    'is_off_supplier' => false,
                    'quantity' => $quantity,
                    'suggested_quantity' => 0,
                    'stock_at_order' => 0,
                    'min_at_order' => 0,
                    'origin' => PurchaseOrderItem::ORIGIN_MANUAL,
                    'received_quantity' => 0,
                    'unit_cost' => $item->unit_cost,
                ]);
            }

            $this->splitter->recalculate($order->refresh());

            $locked->settlements()->create([
                'type' => SupplierReturnSettlement::TYPE_REPLACEMENT,
                'document_number' => $order->code,
                'amount' => $value,
                'received_at' => now(),
                'purchase_order_id' => $order->id,
                'note' => $this->text($note),
                'user_id' => $user->id,
            ]);

            $locked->syncSettlement();

            return $order->refresh();
        });
    }

    /**
     * Una orden de reposicion anulada deja de ser respuesta del proveedor: su respuesta se borra
     * y la devolucion vuelve a quedar pendiente por ese valor.
     */
    public function replacementCancelled(PurchaseOrder $order): void
    {
        if (! $order->supplier_return_id) {
            return;
        }

        DB::transaction(function () use ($order) {
            $return = SupplierReturn::query()->withoutGlobalScopes()->lockForUpdate()->find($order->supplier_return_id);

            if (! $return) {
                return;
            }

            $return->settlements()->where('purchase_order_id', $order->id)->delete();
            $return->syncSettlement();
        });
    }

    /** Solo el borrador se anula: despachada, la mercancia ya salio. */
    public function cancel(SupplierReturn $return, User $user, string $reason): SupplierReturn
    {
        return DB::transaction(function () use ($return, $user, $reason) {
            $locked = $this->lock($return);

            if (! $locked->isDraft()) {
                throw ValidationException::withMessages([
                    'status' => ['Una devolución despachada no se anula: si la mercancía vuelve, registra un rechazo.'],
                ]);
            }

            $locked->update([
                'status' => SupplierReturn::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $user->id,
                'cancel_reason' => $this->text($reason),
            ]);

            $locked->purchaseOrder?->syncReturns();

            return $locked->refresh();
        });
    }

    /**
     * Lo que la orden deja devolver por renglon: recibido, devuelto, lo que tienen otros
     * borradores y el maximo que queda.
     *
     * @return array<int, array{received: float, returned: float, in_drafts: float, returnable: float}>
     */
    public function orderLimits(PurchaseOrder $order, ?int $exceptReturnId = null): array
    {
        $drafts = $this->draftQuantities($order->id, $exceptReturnId);
        $limits = [];

        foreach ($order->items()->get() as $item) {
            $inDrafts = (float) ($drafts[$item->id] ?? 0);

            $limits[$item->id] = [
                'received' => round((float) $item->received_quantity, 3),
                'returned' => round((float) $item->returned_quantity, 3),
                'in_drafts' => round($inDrafts, 3),
                'returnable' => round(max(0, $item->returnableQuantity() - $inDrafts), 3),
            ];
        }

        return $limits;
    }

    /**
     * Las bodegas donde entro la mercancia de una orden, con las unidades que entraron a cada una.
     *
     * @return array<int, float> warehouse_id => unidades
     */
    public function receivingWarehouses(PurchaseOrder $order): array
    {
        return StockMovement::query()
            ->withoutGlobalScopes()
            ->where('reference_type', $order->getMorphClass())
            ->where('reference_id', $order->id)
            ->where('type', StockMovement::TYPE_PURCHASE)
            ->groupBy('warehouse_id')
            ->selectRaw('warehouse_id, SUM(quantity) as units')
            ->orderByDesc('units')
            ->pluck('units', 'warehouse_id')
            ->map(fn ($units) => round((float) $units, 3))
            ->all();
    }

    /** La bodega por defecto de una devolucion con orden: donde entro la mayor parte. */
    public function defaultWarehouseFor(PurchaseOrder $order): Warehouse
    {
        foreach (array_keys($this->receivingWarehouses($order)) as $warehouseId) {
            $warehouse = Warehouse::query()->withoutGlobalScopes()
                ->where('store_id', $order->store_id)
                ->where('is_active', true)
                ->find($warehouseId);

            if ($warehouse) {
                return $warehouse;
            }
        }

        return $this->warehouses->receivingDefaultFor($order->store_id);
    }

    /** El valor sugerido por unidad en una devolucion suelta: el ultimo costo con ese proveedor. */
    public function suggestedCost(Product $product, int $supplierId): int
    {
        $last = ProductSupplier::query()
            ->withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->where('supplier_id', $supplierId)
            ->value('last_cost');

        return (int) ($last ?: $product->cost);
    }

    /**
     * Valida el borrador y lo deja listo para guardar.
     *
     * @param  array<string, mixed>  $data
     * @return array{store_id: int, supplier: Supplier, order: ?PurchaseOrder, warehouse: Warehouse, lines: list<array<string, mixed>>, totals: array<string, int>}
     */
    protected function prepare(int $companyId, array $data, ?SupplierReturn $existing): array
    {
        $order = null;

        if (! empty($data['purchase_order_id'])) {
            $order = PurchaseOrder::query()->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->with('items.product')
                ->find((int) $data['purchase_order_id']);

            if (! $order) {
                throw ValidationException::withMessages(['purchase_order_id' => ['Esa orden de compra no es de tu empresa.']]);
            }

            if (! in_array($order->status, [PurchaseOrder::STATUS_PARTIAL, PurchaseOrder::STATUS_RECEIVED], true)) {
                throw ValidationException::withMessages([
                    'purchase_order_id' => ["La orden {$order->code} no tiene mercancía recibida: solo se devuelve de órdenes recibidas o recibidas en parte."],
                ]);
            }

            if (! empty($data['supplier_id']) && (int) $data['supplier_id'] !== $order->supplier_id) {
                throw ValidationException::withMessages(['supplier_id' => ["La orden {$order->code} es de otro proveedor."]]);
            }

            $data['supplier_id'] = $order->supplier_id;
            $data['store_id'] = $order->store_id;
        }

        $supplier = Supplier::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->find((int) ($data['supplier_id'] ?? 0));

        if (! $supplier) {
            throw ValidationException::withMessages(['supplier_id' => ['Elige un proveedor activo de tu empresa.']]);
        }

        $storeId = (int) ($data['store_id'] ?? 0);

        $warehouse = Warehouse::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->find((int) ($data['warehouse_id'] ?? 0));

        if (! $warehouse) {
            throw ValidationException::withMessages(['warehouse_id' => ['Elige una bodega activa de la sede de la devolución.']]);
        }

        $rows = collect($data['items'] ?? [])
            ->map(fn ($row, $index) => [...(array) $row, '_index' => $index])
            ->filter(fn (array $row) => round((float) ($row['quantity'] ?? 0), 3) > 0)
            ->values();

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['items' => ['Indica al menos un artículo con cantidad a devolver.']]);
        }

        $limits = $order ? $this->orderLimits($order, $existing?->id) : [];
        $orderItems = $order ? $order->items->keyBy('id') : collect();

        $productIds = $rows->map(function (array $row) use ($orderItems) {
            return $orderItems->isNotEmpty() && ! empty($row['purchase_order_item_id'])
                ? $orderItems->get((int) $row['purchase_order_item_id'])?->product_id
                : (int) ($row['product_id'] ?? 0);
        })->filter()->all();

        $products = Product::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $stock = ProductStock::query()->withoutGlobalScopes()
            ->where('warehouse_id', $warehouse->id)
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        $errors = [];
        $lines = [];
        $seen = [];

        foreach ($rows as $row) {
            $key = 'items.'.$row['_index'];
            $orderItem = null;

            if ($order) {
                $orderItem = $orderItems->get((int) ($row['purchase_order_item_id'] ?? 0));

                if (! $orderItem) {
                    $errors["{$key}.purchase_order_item_id"] = "Ese renglón no es de la orden {$order->code}.";

                    continue;
                }

                $product = $products->get($orderItem->product_id);
            } else {
                $product = $products->get((int) ($row['product_id'] ?? 0));

                if ($product && ! $product->is_active) {
                    $product = null;
                }
            }

            if (! $product) {
                $errors["{$key}.product_id"] = 'Ese artículo no existe, está inactivo o no es de tu empresa.';

                continue;
            }

            if (isset($seen[$product->id])) {
                $errors["{$key}.product_id"] = "{$product->name} ya está en la devolución: si hay dos motivos, elige el principal y explícalo en la nota.";

                continue;
            }

            $seen[$product->id] = true;
            $quantity = round((float) $row['quantity'], 3);

            if (! $product->sold_by_weight && floor($quantity) !== $quantity) {
                $errors["{$key}.quantity"] = "{$product->name} se devuelve en unidades enteras.";

                continue;
            }

            $reason = (string) ($row['reason'] ?? '');
            $note = $this->text($row['note'] ?? null);

            if (! array_key_exists($reason, SupplierReturnItem::REASONS)) {
                $errors["{$key}.reason"] = 'Elige el motivo.';

                continue;
            }

            if ($reason === SupplierReturnItem::REASON_OTHER && $note === null) {
                $errors["{$key}.note"] = 'Con el motivo «Otro», explica en la nota por qué se devuelve.';

                continue;
            }

            if ($orderItem) {
                $limit = $limits[$orderItem->id];

                if ($quantity - $limit['returnable'] > 0.0005) {
                    $errors["{$key}.quantity"] = "De {$product->name} se recibieron ".StockService::quantityText($limit['received'])
                        .'; ya se devolvieron '.StockService::quantityText($limit['returned'])
                        .($limit['in_drafts'] > 0 ? ' y hay '.StockService::quantityText($limit['in_drafts']).' en otro borrador' : '')
                        .': puedes devolver hasta '.StockService::quantityText($limit['returnable']).'.';

                    continue;
                }

                // Lo que se reclama es lo que costo en esa orden; lo que mande el navegador no cuenta.
                $unitCost = (int) $orderItem->unit_cost;
            } else {
                $unitCost = isset($row['unit_cost']) && $row['unit_cost'] !== '' && $row['unit_cost'] !== null
                    ? max(0, (int) $row['unit_cost'])
                    : $this->suggestedCost($product, $supplier->id);
            }

            $rowStock = $stock->get($product->id);
            $available = round((float) ($rowStock->quantity ?? 0) - (float) ($rowStock->reserved_quantity ?? 0), 3);

            if ($quantity - $available > 0.0005) {
                $errors["{$key}.quantity"] = "En {$warehouse->name} hay ".StockService::quantityText(max(0, $available))." de {$product->name} disponibles: no alcanza para devolver ".StockService::quantityText($quantity).'.';

                continue;
            }

            $lotId = null;

            if ($product->track_lots && ! empty($row['lot_id'])) {
                $lot = ProductLot::query()->withoutGlobalScopes()
                    ->where('product_id', $product->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->where('quantity', '>', 0)
                    ->find((int) $row['lot_id']);

                if (! $lot) {
                    $errors["{$key}.lot_id"] = "Ese lote de {$product->name} no tiene existencias en {$warehouse->name}.";

                    continue;
                }

                if ($quantity - (float) $lot->quantity > 0.0005) {
                    $errors["{$key}.lot_id"] = 'El lote '.($lot->code ?: 'sin código').' solo tiene '.StockService::quantityText((float) $lot->quantity).'.';

                    continue;
                }

                $lotId = $lot->id;
            }

            $lines[] = [
                'product_id' => $product->id,
                'purchase_order_item_id' => $orderItem?->id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'tax_rate' => (float) $product->tax_rate,
                'line_total' => (int) round($quantity * $unitCost),
                'reason' => $reason,
                'note' => $note,
                'lot_id' => $lotId,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'store_id' => $storeId,
            'supplier' => $supplier,
            'order' => $order,
            'warehouse' => $warehouse,
            'lines' => $lines,
            'totals' => $this->totals($lines),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{subtotal: int, tax_total: int, total: int}
     */
    protected function totals(array $lines): array
    {
        $subtotal = 0;
        $total = 0;

        foreach ($lines as $line) {
            $subtotal += Money::baseFromGross($line['line_total'], $line['tax_rate']);
            $total += $line['line_total'];
        }

        return ['subtotal' => $subtotal, 'tax_total' => $total - $subtotal, 'total' => $total];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    protected function writeItems(SupplierReturn $return, array $lines): void
    {
        foreach ($lines as $line) {
            $return->items()->create($line);
        }
    }

    /**
     * Toma los lotes a mano para que viajen en el renglon: el elegido; si no, con orden, los que
     * entraron con ese renglon, luego los del mismo codigo y despues el que vence antes.
     *
     * Si los lotes no alcanzan pero el saldo si (existencias de antes de controlar lotes), sale
     * lo que haya en lotes y el resto sin lote.
     *
     * @return list<array{lot_id: int, code: ?string, expires_at: ?string, unit_cost: ?int, quantity: float}>
     */
    protected function takeLots(SupplierReturnItem $item, Product $product, Warehouse $warehouse, ?PurchaseOrderItem $orderItem): array
    {
        if (! $product->track_lots) {
            return [];
        }

        $query = ProductLot::query()->withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('quantity', '>', 0);

        if ($item->lot_id) {
            $lot = (clone $query)->lockForUpdate()->find($item->lot_id);

            if (! $lot) {
                throw ValidationException::withMessages([
                    'items' => ["El lote elegido de {$product->name} ya no tiene existencias en {$warehouse->name}."],
                ]);
            }

            if ($item->quantity - (float) $lot->quantity > 0.0005) {
                throw ValidationException::withMessages([
                    'items' => ['El lote '.($lot->code ?: 'sin código')." de {$product->name} solo tiene ".StockService::quantityText((float) $lot->quantity).': no alcanza para devolver '.StockService::quantityText($item->quantity).'.'],
                ]);
            }

            $lots = collect([$lot]);
        } else {
            if ($orderItem) {
                $query->orderByRaw('CASE WHEN purchase_order_item_id = ? THEN 0 ELSE 1 END', [$orderItem->id]);

                if ($orderItem->lot_code) {
                    $query->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [$orderItem->lot_code]);
                }
            }

            $lots = $query->orderByRaw('expires_at IS NULL, expires_at ASC')
                ->orderBy('received_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }

        $pending = round($item->quantity, 3);
        $taken = [];

        foreach ($lots as $lot) {
            if ($pending <= 0) {
                break;
            }

            $amount = round(min($pending, (float) $lot->quantity), 3);
            $lot->quantity = round($lot->quantity - $amount, 3);
            $lot->save();

            $taken[] = [
                'lot_id' => $lot->id,
                'code' => $lot->code,
                'expires_at' => $lot->expires_at?->toDateString(),
                'unit_cost' => $lot->unit_cost,
                'quantity' => $amount,
            ];

            $pending = round($pending - $amount, 3);
        }

        return $taken;
    }

    /**
     * Vuelve a armar los lotes de lo rechazado, con el codigo y el vencimiento con que salio.
     *
     * Se recorre lo que salio en orden inverso: primero lo que salio sin lote (lo ultimo que se
     * tomo) y luego los lotes del ultimo al primero, saltando lo que otros rechazos ya devolvieron.
     */
    protected function restoreLots(SupplierReturnItem $item, Warehouse $warehouse, float $alreadyRejected, float $quantity): void
    {
        if (! $item->product?->track_lots) {
            return;
        }

        $segments = collect($item->lots ?? [])->map(fn (array $lot) => [...$lot, 'quantity' => (float) $lot['quantity']]);
        $unlotted = round($item->quantity - $segments->sum('quantity'), 3);

        if ($unlotted > 0) {
            $segments->push(['lot_id' => null, 'code' => null, 'expires_at' => null, 'unit_cost' => null, 'quantity' => $unlotted, 'unlotted' => true]);
        }

        $skip = $alreadyRejected;
        $pending = $quantity;

        foreach ($segments->reverse() as $segment) {
            if ($pending <= 0) {
                break;
            }

            $size = $segment['quantity'];
            $used = min($skip, $size);
            $skip = round($skip - $used, 3);
            $room = round($size - $used, 3);

            if ($room <= 0) {
                continue;
            }

            $amount = round(min($room, $pending), 3);
            $pending = round($pending - $amount, 3);

            // Lo que salio sin lote vuelve sin lote, como estaba.
            if (! empty($segment['unlotted'])) {
                continue;
            }

            $origin = $segment['lot_id'] ? ProductLot::query()->withoutGlobalScopes()->find($segment['lot_id']) : null;

            $this->stock->receiveLot(
                product: $item->product,
                warehouse: $warehouse,
                quantity: $amount,
                code: $segment['code'] ?? null,
                expiresAt: $segment['expires_at'] ?? null,
                unitCost: $segment['unit_cost'] ?? $item->inventory_unit_cost,
                purchaseOrderItemId: $origin?->purchase_order_item_id,
            );
        }
    }

    /**
     * Las cantidades mayores que cero, por renglon de la devolucion.
     *
     * @param  array<int|string, mixed>  $lines
     * @param  Collection<int, SupplierReturnItem>  $items
     * @return array<int, float>
     */
    protected function positiveLines(array $lines, Collection $items): array
    {
        $ids = $items->pluck('id')->all();
        $wanted = [];

        foreach ($lines as $itemId => $quantity) {
            $quantity = round((float) $quantity, 3);

            if ($quantity <= 0) {
                continue;
            }

            if (! in_array((int) $itemId, $ids, true)) {
                throw ValidationException::withMessages(['lines' => ['Uno de los renglones no es de esta devolución.']]);
            }

            $wanted[(int) $itemId] = $quantity;
        }

        if ($wanted === []) {
            throw ValidationException::withMessages(['lines' => ['Indica al menos una cantidad.']]);
        }

        return $wanted;
    }

    /**
     * Lo que ya se pidio en reposicion, por articulo, sin contar las ordenes anuladas.
     *
     * @return array<int, float>
     */
    protected function replacedByProduct(SupplierReturn $return): array
    {
        $replaced = [];

        foreach ($return->replacementOrders as $order) {
            if ($order->status === PurchaseOrder::STATUS_CANCELLED) {
                continue;
            }

            foreach ($order->items as $item) {
                $replaced[$item->product_id] = round(($replaced[$item->product_id] ?? 0) + (float) $item->quantity, 3);
            }
        }

        return $replaced;
    }

    protected function guardAmount(SupplierReturn $return, int $amount): void
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['El valor debe ser mayor que cero.']]);
        }

        if ($amount > $return->pendingTotal()) {
            throw ValidationException::withMessages([
                'amount' => ['El proveedor solo debe '.Money::format($return->pendingTotal()).' por esta devolución.'],
            ]);
        }
    }

    /**
     * Lo que tienen otros borradores de la misma orden, por renglon.
     *
     * @return array<int, float>
     */
    protected function draftQuantities(int $orderId, ?int $exceptReturnId): array
    {
        return SupplierReturnItem::query()
            ->join('supplier_returns', 'supplier_returns.id', '=', 'supplier_return_items.supplier_return_id')
            ->where('supplier_returns.purchase_order_id', $orderId)
            ->where('supplier_returns.status', SupplierReturn::STATUS_DRAFT)
            ->when($exceptReturnId, fn ($query, $id) => $query->where('supplier_returns.id', '!=', $id))
            ->whereNotNull('supplier_return_items.purchase_order_item_id')
            ->groupBy('supplier_return_items.purchase_order_item_id')
            ->selectRaw('supplier_return_items.purchase_order_item_id as item_id, SUM(supplier_return_items.quantity) as quantity')
            ->pluck('quantity', 'item_id')
            ->map(fn ($quantity) => (float) $quantity)
            ->all();
    }

    /**
     * @return Collection<int, PurchaseOrderItem>
     */
    protected function lockOrderItems(SupplierReturn $return): Collection
    {
        if (! $return->purchase_order_id) {
            return collect();
        }

        return PurchaseOrderItem::query()
            ->where('purchase_order_id', $return->purchase_order_id)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    protected function lock(SupplierReturn $return): SupplierReturn
    {
        return SupplierReturn::query()->withoutGlobalScopes()->whereKey($return->id)->lockForUpdate()->firstOrFail();
    }

    protected function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}

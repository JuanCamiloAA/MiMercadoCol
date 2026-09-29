<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\StockTransferRequest;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El traslado de mercancia, de punta a punta.
 *
 * Tres momentos, y cada uno significa algo distinto en el inventario:
 *
 * - **Por despachar**: no mueve nada. Se arma a mano o sale de una solicitud. No se valida la
 *   existencia aqui: entre armarlo y despacharlo puede entrar mercancia.
 * - **Despachar**: sale del origen. De ahi en adelante la mercancia esta en camino y no cuenta
 *   en ninguna bodega, que es lo que de verdad pasa cuando va en el carro.
 * - **Recibir**: entra en el destino el despacho completo y enseguida se da de baja como
 *   averia lo que no aparecio. Meter solo lo recibido cuadraria el saldo, pero esas unidades
 *   se perderian sin dejar rastro en ningun informe de merma.
 */
class StockTransferService
{
    public function __construct(
        protected StockService $stock,
        protected NumberSequenceService $numbers,
        protected WarehouseService $warehouses,
    ) {}

    /**
     * @param  list<array{product_id: int, quantity: float}>  $items
     */
    public function create(
        int $companyId,
        Warehouse $from,
        Warehouse $to,
        array $items,
        ?User $user,
        ?string $note = null,
        ?StockTransferRequest $request = null,
    ): StockTransfer {
        if ($from->id === $to->id) {
            throw ValidationException::withMessages([
                'to_warehouse_id' => ['La bodega de destino debe ser distinta a la de origen.'],
            ]);
        }

        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => ['Agrega al menos un articulo.'],
            ]);
        }

        return DB::transaction(function () use ($companyId, $from, $to, $items, $user, $note, $request) {
            $transfer = StockTransfer::create([
                'company_id' => $companyId,
                'stock_transfer_request_id' => $request?->id,
                'number' => $this->numbers->nextTransferNumber($companyId),
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $to->id,
                'status' => StockTransfer::STATUS_DRAFT,
                'note' => $note,
                'created_by_user_id' => $user?->id,
            ]);

            $products = $this->products($companyId, array_column($items, 'product_id'));

            foreach ($items as $item) {
                $product = $products[$item['product_id']];

                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $product->id,
                    'quantity' => round(abs((float) $item['quantity']), 3),
                    'received_quantity' => 0,
                    'unit_cost' => (int) $product->cost,
                ]);
            }

            $request?->syncProgress();

            return $transfer->load('items.product');
        });
    }

    /**
     * Saca la mercancia del origen. De aqui en adelante va en camino.
     */
    public function dispatch(StockTransfer $transfer, ?User $user): StockTransfer
    {
        if (! $transfer->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['Solo se despacha un traslado que este por despachar.'],
            ]);
        }

        $transfer->load(['items.product', 'origin', 'destination']);
        $this->guardAvailability($transfer);

        return DB::transaction(function () use ($transfer, $user) {
            $from = $transfer->origin;

            foreach ($transfer->items as $item) {
                if (! $item->product) {
                    continue;
                }

                // Los lotes se toman a mano, FEFO, para que el vencimiento viaje con la caja.
                // Si `move()` los volviera a tocar, los consumiria por segunda vez.
                $lots = $item->product->track_lots
                    ? $this->stock->takeFromLots($item->product, $from, $item->quantity)['taken']
                    : [];

                // El costo se congela al despachar: lo que va en camino vale lo que valia al salir.
                $item->update(['unit_cost' => (int) $item->product->cost, 'lots' => $lots]);

                $this->stock->move(
                    product: $item->product,
                    warehouse: $from,
                    type: StockMovement::TYPE_TRANSFER_OUT,
                    quantity: -$item->quantity,
                    userId: $user?->id,
                    reference: $transfer,
                    note: "Traslado {$transfer->number} a {$transfer->destination?->name}",
                    unitCost: (int) $item->product->cost,
                    touchLots: false,
                );
            }

            $transfer->update([
                'status' => StockTransfer::STATUS_IN_TRANSIT,
                'dispatched_by_user_id' => $user?->id,
                'dispatched_at' => now(),
            ]);

            return $transfer->refresh();
        });
    }

    /**
     * Mete en el destino lo que llego.
     *
     * @param  array<int, float>  $received  Cantidad recibida por id de renglon.
     */
    public function receive(StockTransfer $transfer, array $received, ?User $user): StockTransfer
    {
        if (! $transfer->isInTransit()) {
            throw ValidationException::withMessages([
                'status' => ['Solo se recibe un traslado que este en camino.'],
            ]);
        }

        $transfer->load(['items.product', 'origin', 'destination']);

        return DB::transaction(function () use ($transfer, $received, $user) {
            $to = $transfer->destination;
            $from = $transfer->origin;

            foreach ($transfer->items as $item) {
                if (! $item->product) {
                    continue;
                }

                // Sin dato explicito se asume que llego completo: es lo normal.
                $quantity = array_key_exists($item->id, $received)
                    ? round(min(max(0, (float) $received[$item->id]), $item->quantity), 3)
                    : $item->quantity;

                $item->update(['received_quantity' => $quantity]);
                $missing = $item->missingQuantity();

                /*
                 * Entra el despacho completo y despues se da de baja lo que no aparecio.
                 *
                 * Meter solo lo recibido haria cuadrar el saldo, pero esas unidades se
                 * perderian sin dejar rastro: salieron del origen y no entraron a ninguna
                 * parte. Asi la merma queda como `waste`, con el numero del traslado, y los
                 * informes de perdida la ven. El saldo neto del destino es el mismo.
                 */
                $this->stock->move(
                    product: $item->product,
                    warehouse: $to,
                    type: StockMovement::TYPE_TRANSFER_IN,
                    quantity: $item->quantity,
                    userId: $user?->id,
                    reference: $transfer,
                    note: "Traslado {$transfer->number} desde {$from?->name}",
                    unitCost: $item->unit_cost,
                    touchLots: false,
                );

                $this->restoreLots($item, $to);

                if ($missing > 0) {
                    $this->stock->move(
                        product: $item->product,
                        warehouse: $to,
                        type: StockMovement::TYPE_WASTE,
                        quantity: -$missing,
                        userId: $user?->id,
                        reference: $transfer,
                        note: "Faltante en traslado {$transfer->number}",
                        unitCost: $item->unit_cost,
                    );
                }
            }

            $transfer->update([
                'status' => StockTransfer::STATUS_RECEIVED,
                'received_by_user_id' => $user?->id,
                'received_at' => now(),
            ]);

            return $transfer->refresh();
        });
    }

    /**
     * El traslado directo: despacha y recibe en el mismo acto.
     *
     * Solo entre bodegas de la misma sede: ahi no hay camino, es el mismo mercaderista subiendo
     * la mercancia al estante. Entre sedes siempre son dos actos, porque en el medio hay un
     * carro y alguien del otro lado que tiene que contar lo que llego.
     */
    public function dispatchAndReceive(StockTransfer $transfer, ?User $user): StockTransfer
    {
        $transfer->load(['origin', 'destination']);

        if (! $transfer->isInternal()) {
            throw ValidationException::withMessages([
                'status' => ['Un traslado entre sedes no puede ser directo: se despacha y se recibe por separado.'],
            ]);
        }

        return DB::transaction(function () use ($transfer, $user) {
            $this->dispatch($transfer, $user);

            return $this->receive($transfer->refresh(), [], $user);
        });
    }

    /**
     * Que hay que subir del deposito al piso de venta.
     *
     * Un articulo bajo minimo en la bodega que vende, pero con existencia atras, no es una
     * compra: es una reposicion. Comprarlo seria pagar dos veces por lo mismo y dejar el
     * deposito lleno de algo que nunca sube al estante.
     *
     * Se propone llevarlo al doble del minimo, que es la misma regla con que se sugiere
     * comprar, y nunca mas de lo que hay atras.
     *
     * @return array<int, array{warehouse: Warehouse, lines: list<array{product_id: int, quantity: float, name: string, stock: float, min_stock: int}>}>
     */
    public function replenishmentFor(int $companyId, int $storeId, Warehouse $target): array
    {
        $rows = ProductStock::query()
            ->withoutGlobalScopes()
            ->join('products', 'products.id', '=', 'product_stocks.product_id')
            ->where('product_stocks.company_id', $companyId)
            ->where('product_stocks.store_id', $storeId)
            ->whereNull('products.deleted_at')
            ->where('products.is_active', true)
            ->where('products.min_stock', '>', 0)
            ->get([
                'product_stocks.product_id',
                'product_stocks.warehouse_id',
                'product_stocks.quantity',
                'product_stocks.reserved_quantity',
                'products.name',
                'products.min_stock',
            ])
            ->groupBy('product_id');

        $warehouses = $this->warehouses->forStore($storeId)->keyBy('id');
        $plan = [];

        foreach ($rows as $productId => $group) {
            $atTarget = (float) $group->firstWhere('warehouse_id', $target->id)?->quantity;
            $minStock = (int) $group->first()->min_stock;

            if ($atTarget > $minStock) {
                continue;
            }

            // De donde traerlo: la bodega de la sede que mas tenga, sin contar la de destino
            // ni lo que ya esta apartado para un pedido.
            $source = $group
                ->filter(fn ($row) => (int) $row->warehouse_id !== $target->id)
                ->filter(fn ($row) => (float) $row->quantity - (float) $row->reserved_quantity > 0)
                ->sortByDesc(fn ($row) => (float) $row->quantity - (float) $row->reserved_quantity)
                ->first();

            if (! $source || ! $warehouses->has((int) $source->warehouse_id)) {
                continue;
            }

            $available = round((float) $source->quantity - (float) $source->reserved_quantity, 3);
            $quantity = min($available, max(1.0, ceil($minStock * 2 - $atTarget)));

            if ($quantity <= 0) {
                continue;
            }

            $id = (int) $source->warehouse_id;
            $plan[$id] ??= ['warehouse' => $warehouses->get($id), 'lines' => []];
            $plan[$id]['lines'][] = [
                'product_id' => (int) $productId,
                'quantity' => $quantity,
                'name' => $source->name,
                'stock' => $atTarget,
                'min_stock' => $minStock,
            ];
        }

        return $plan;
    }

    public function cancel(StockTransfer $transfer, ?User $user): StockTransfer
    {
        if (! $transfer->isDraft()) {
            throw ValidationException::withMessages([
                // Anular con la mercancia en camino la dejaria fuera de las dos bodegas y sin
                // documento que lo explique. Un traslado despachado se recibe, aunque sea en cero.
                'status' => ['Un traslado que ya salio no se anula: se recibe con lo que haya llegado.'],
            ]);
        }

        return DB::transaction(function () use ($transfer, $user) {
            $transfer->update([
                'status' => StockTransfer::STATUS_CANCELLED,
                'cancelled_by_user_id' => $user?->id,
                'cancelled_at' => now(),
            ]);

            // Lo que iba en este traslado vuelve a quedar pendiente en la solicitud.
            $transfer->request?->syncProgress();

            return $transfer->refresh();
        });
    }

    /**
     * Vuelve a armar en el destino los lotes con que salio la mercancia.
     *
     * Se recrean por el total despachado, porque el saldo que entra es el total despachado;
     * lo que falte se da de baja enseguida y esa baja consume FEFO, que es lo correcto: si se
     * perdio una caja, lo razonable es que fuera la que vencia antes.
     */
    protected function restoreLots(StockTransferItem $item, Warehouse $to): void
    {
        if (! $item->product?->track_lots) {
            return;
        }

        foreach ($item->lots ?? [] as $lot) {
            $this->stock->receiveLot(
                product: $item->product,
                warehouse: $to,
                quantity: (float) $lot['quantity'],
                code: $lot['code'] ?? null,
                expiresAt: $lot['expires_at'] ?? null,
                unitCost: $lot['unit_cost'] ?? $item->unit_cost,
            );
        }
    }

    /**
     * Nadie despacha lo que no tiene.
     *
     * Se mira contra lo disponible (existencia menos reservado): lo apartado para un pedido en
     * linea ya tiene dueno y mandarlo a otra bodega dejaria ese pedido sin con que despacharse.
     */
    protected function guardAvailability(StockTransfer $transfer): void
    {
        $stock = ProductStock::query()
            ->withoutGlobalScopes()
            ->where('warehouse_id', $transfer->from_warehouse_id)
            ->whereIn('product_id', $transfer->items->pluck('product_id'))
            ->get()
            ->keyBy('product_id');

        $short = [];

        foreach ($transfer->items as $item) {
            $row = $stock->get($item->product_id);
            $available = round((float) ($row->quantity ?? 0) - (float) ($row->reserved_quantity ?? 0), 3);

            if ($item->quantity > $available) {
                $short[] = "{$item->product?->name} (hay {$available})";
            }
        }

        if ($short !== []) {
            throw ValidationException::withMessages([
                'items' => ['No hay suficiente en la bodega de origen: '.implode(', ', $short).'.'],
            ]);
        }
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, Product>
     */
    protected function products(int $companyId, array $ids): array
    {
        $products = Product::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('id', array_unique($ids))
            ->get()
            ->keyBy('id');

        foreach ($ids as $id) {
            if (! $products->has($id)) {
                throw ValidationException::withMessages([
                    'items' => ['Uno de los articulos ya no existe. Recarga la pantalla.'],
                ]);
            }
        }

        return $products->all();
    }
}

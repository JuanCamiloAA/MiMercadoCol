<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferRequest;
use App\Models\StockTransferRequestItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Solicitudes de traslado: una bodega pide, la otra decide.
 *
 * La solicitud nunca toca existencias. Quien surte genera a partir de ella un traslado normal,
 * con las cantidades que de verdad puede mandar, y ese traslado sigue su camino: despachar en
 * el origen, recibir en el destino. Una solicitud puede atenderse en varios traslados.
 */
class StockTransferRequestService
{
    public function __construct(
        protected StockTransferService $transfers,
        protected NumberSequenceService $numbers,
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
    ): StockTransferRequest {
        if ($from->id === $to->id) {
            throw ValidationException::withMessages([
                'from_warehouse_id' => ['Se le pide a otra bodega, no a la misma que pide.'],
            ]);
        }

        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => ['Agrega al menos un articulo.'],
            ]);
        }

        return DB::transaction(function () use ($companyId, $from, $to, $items, $user, $note) {
            $request = StockTransferRequest::create([
                'company_id' => $companyId,
                'number' => $this->numbers->nextTransferRequestNumber($companyId),
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $to->id,
                'status' => StockTransferRequest::STATUS_PENDING,
                'note' => $note,
                'created_by_user_id' => $user?->id,
            ]);

            $known = Product::query()->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereIn('id', array_column($items, 'product_id'))
                ->pluck('id')
                ->all();

            foreach ($items as $item) {
                if (! in_array($item['product_id'], $known, true)) {
                    throw ValidationException::withMessages([
                        'items' => ['Uno de los articulos ya no existe. Recarga la pantalla.'],
                    ]);
                }

                StockTransferRequestItem::create([
                    'stock_transfer_request_id' => $request->id,
                    'product_id' => $item['product_id'],
                    'quantity' => round(abs((float) $item['quantity']), 3),
                    'sent_quantity' => 0,
                ]);
            }

            return $request->load('items');
        });
    }

    /**
     * Genera el traslado con lo que la bodega que surte decide mandar.
     *
     * Nunca mas de lo pendiente: lo que no se pidio no se manda por esta via. Menos si, y es lo
     * normal: se manda lo que hay y la solicitud queda abierta por el resto.
     *
     * @param  array<int, float>  $quantities  Cantidad a mandar por id de renglon de la solicitud.
     */
    public function fulfill(StockTransferRequest $request, array $quantities, ?User $user, bool $dispatch = false): StockTransfer
    {
        return DB::transaction(function () use ($request, $quantities, $user, $dispatch) {
            // Se bloquea la fila: dos personas atendiendo la misma solicitud a la vez mandarian
            // el pendiente dos veces.
            $request = StockTransferRequest::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($request->id);
            $request->load(['items.product', 'origin', 'destination']);

            if (! $request->isOpen()) {
                throw ValidationException::withMessages([
                    'status' => ['Esta solicitud ya no esta abierta: no se le pueden generar traslados.'],
                ]);
            }

            $lines = [];
            $over = [];

            foreach ($request->items as $item) {
                $quantity = round(max(0, (float) ($quantities[$item->id] ?? 0)), 3);

                if ($quantity <= 0) {
                    continue;
                }

                if ($quantity > $item->pendingQuantity()) {
                    $over[] = "{$item->product?->name} (quedan {$item->pendingQuantity()})";

                    continue;
                }

                $lines[] = ['product_id' => (int) $item->product_id, 'quantity' => $quantity];
            }

            if ($over !== []) {
                throw ValidationException::withMessages([
                    'items' => ['Se esta mandando mas de lo que falta por atender: '.implode(', ', $over).'.'],
                ]);
            }

            if ($lines === []) {
                throw ValidationException::withMessages([
                    'items' => ['Indica cuanto vas a mandar de al menos un articulo.'],
                ]);
            }

            $transfer = $this->transfers->create(
                (int) $request->company_id,
                $request->origin,
                $request->destination,
                $lines,
                $user,
                "Atiende la solicitud {$request->number}",
                $request,
            );

            // Aun con la mercancia fuera sigue siendo un traslado de dos actos: quien la pidio
            // la recibe del otro lado.
            if ($dispatch) {
                $this->transfers->dispatch($transfer, $user);
            }

            return $transfer->refresh();
        });
    }

    /** Quien surte dice que no, antes de mandar nada. */
    public function reject(StockTransferRequest $request, string $reason, ?User $user): StockTransferRequest
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'status' => ['Solo se rechaza una solicitud pendiente. Si ya se mando una parte, cierrala.'],
            ]);
        }

        $request->update([
            'status' => StockTransferRequest::STATUS_REJECTED,
            'response_note' => $reason,
            'responded_by_user_id' => $user?->id,
            'responded_at' => now(),
        ]);

        return $request->refresh();
    }

    /**
     * Quien surte deja de mandar lo que falta.
     *
     * No toca los traslados ya generados: lo que va en camino sigue su curso y se recibe.
     */
    public function close(StockTransferRequest $request, ?string $reason, ?User $user): StockTransferRequest
    {
        if (! $request->isPartial()) {
            throw ValidationException::withMessages([
                'status' => ['Solo se cierra una solicitud atendida en parte. Si no se ha mandado nada, rechazala.'],
            ]);
        }

        $request->update([
            'status' => StockTransferRequest::STATUS_CLOSED,
            'response_note' => $reason,
            'responded_by_user_id' => $user?->id,
            'responded_at' => now(),
        ]);

        return $request->refresh();
    }

    /** Quien pidio se arrepiente, mientras nadie haya empezado a atenderla. */
    public function cancel(StockTransferRequest $request, ?User $user): StockTransferRequest
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'status' => ['Solo se anula una solicitud que nadie ha empezado a atender.'],
            ]);
        }

        $request->update([
            'status' => StockTransferRequest::STATUS_CANCELLED,
            'cancelled_by_user_id' => $user?->id,
            'cancelled_at' => now(),
        ]);

        return $request->refresh();
    }
}

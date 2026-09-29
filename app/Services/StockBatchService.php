<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Entradas y salidas de bodega de varios articulos de una sola vez.
 *
 * Todo pasa en una transaccion a proposito: quien registra una averia de nueve referencias
 * esta contando un solo hecho. Si la novena no alcanza, guardar las ocho primeras dejaria el
 * inventario a medio corregir y a nadie con la lista de lo que falto.
 */
class StockBatchService
{
    public function __construct(protected StockService $stock) {}

    /**
     * @param  list<array{product_id: int, quantity: float}>  $items
     * @return array{movements: int, units: float}
     */
    public function register(
        int $companyId,
        Warehouse $warehouse,
        string $direction,
        string $reason,
        array $items,
        ?string $note,
        ?int $userId,
    ): array {
        $type = StockMovement::reasonType($direction, $reason);

        if ($type === null) {
            throw ValidationException::withMessages([
                'reason' => ['Ese motivo no existe para este movimiento.'],
            ]);
        }

        $products = $this->products($companyId, array_column($items, 'product_id'));
        $text = $this->note($direction, $reason, $note);

        return DB::transaction(function () use ($items, $products, $warehouse, $type, $direction, $text, $userId) {
            $units = 0.0;

            foreach ($items as $item) {
                $product = $products[$item['product_id']];
                $quantity = abs((float) $item['quantity']);
                $units += $quantity;

                $this->stock->move(
                    product: $product,
                    warehouse: $warehouse,
                    type: $type,
                    // La direccion manda el signo: el formulario siempre pide cantidades positivas.
                    quantity: $direction === StockMovement::DIRECTION_OUT ? -$quantity : $quantity,
                    userId: $userId,
                    note: $text,
                    unitCost: $product->cost,
                );
            }

            return ['movements' => count($items), 'units' => round($units, 3)];
        });
    }

    /**
     * La nota que queda en el kardex: el motivo al frente y el detalle detras.
     *
     * Sin el motivo por delante, el kardex de una averia y el de un faltante de conteo se leen
     * igual, porque los dos son movimientos del mismo tipo.
     */
    protected function note(string $direction, string $reason, ?string $note): string
    {
        $label = StockMovement::reasonLabel($direction, $reason) ?? 'Movimiento manual';
        $detail = trim((string) $note);

        return $detail === '' ? $label : "{$label}: {$detail}";
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

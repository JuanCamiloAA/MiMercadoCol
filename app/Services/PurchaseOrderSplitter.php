<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Parte una orden mezclada en una por proveedor.
 *
 * Es lo que hace viable pedirle a varios en una sola pasada: el comprador recorre la tienda una
 * vez, arma todo lo que falta sin pensar en quien se lo surte, y al final la orden se parte
 * sola. Sin esto habria que recorrer la tienda una vez por proveedor.
 *
 * Todo va en una transaccion: o se parte entera, o no se parte. Una orden a medio partir
 * dejaria mercancia pedida dos veces o ninguna.
 */
class PurchaseOrderSplitter
{
    public function __construct(protected NumberSequenceService $numbers) {}

    /**
     * @return list<PurchaseOrder>  Las ordenes nuevas. Vacio si no habia nada que separar.
     */
    public function split(PurchaseOrder $order): array
    {
        if (! in_array($order->status, [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_SENT], true)) {
            throw ValidationException::withMessages([
                // Partirla con mercancia adentro dejaria renglones a medio recibir en dos
                // ordenes distintas, y ninguna de las dos diria la verdad.
                'status' => ['Solo se separa una orden que todavia no ha recibido nada.'],
            ]);
        }

        $order->loadMissing('items');

        // Los renglones sin proveedor se quedan en la original: no hay a quien mandarlos.
        $groups = $order->items
            ->filter(fn (PurchaseOrderItem $item) => $item->supplier_id !== null
                && (int) $item->supplier_id !== (int) $order->supplier_id)
            ->groupBy('supplier_id');

        if ($groups->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => ['Esa orden ya es de un solo proveedor: no hay nada que separar.'],
            ]);
        }

        return DB::transaction(function () use ($order, $groups) {
            $created = [];

            foreach ($groups as $supplierId => $items) {
                $child = PurchaseOrder::create([
                    'company_id' => $order->company_id,
                    'store_id' => $order->store_id,
                    'warehouse_id' => $order->warehouse_id,
                    'supplier_id' => (int) $supplierId,
                    // Cada orden hija lleva el plazo de su proveedor; si no tiene, el de la madre.
                    'payment_term_id' => Supplier::query()->withoutGlobalScopes()->whereKey((int) $supplierId)->value('payment_term_id') ?? $order->payment_term_id,
                    'parent_order_id' => $order->id,
                    'code' => $this->numbers->nextPurchaseOrderCode($order->company_id),
                    'status' => $order->status,
                    'expected_at' => $order->expected_at,
                    'source' => $order->source,
                    'user_id' => $order->user_id,
                    'note' => $order->note,
                    'subtotal' => 0,
                    'tax_total' => 0,
                    'total' => 0,
                    'suggested_total' => 0,
                ]);

                foreach ($items as $item) {
                    // Se mueve el renglon, no se copia: duplicarlo pediria la mercancia dos veces.
                    $item->update([
                        'purchase_order_id' => $child->id,
                        // En su orden nueva ya es del proveedor correcto.
                        'is_off_supplier' => false,
                    ]);
                }

                $this->recalculate($child);
                $created[] = $child;
            }

            $this->recalculate($order->refresh());

            return $created;
        });
    }

    /**
     * Vuelve a sumar una orden desde sus renglones.
     *
     * El IVA se calcula hacia atras sobre el costo, igual que en el resto del sistema: el
     * precio que se le paga al proveedor lo incluye.
     */
    public function recalculate(PurchaseOrder $order): void
    {
        $order->loadMissing('items.product');

        $subtotal = 0;
        $taxTotal = 0;
        $suggested = 0;

        foreach ($order->items as $item) {
            $gross = (int) round($item->quantity * $item->unit_cost);
            $base = Money::baseFromGross($gross, (float) ($item->product?->tax_rate ?? 0));

            $subtotal += $base;
            $taxTotal += $gross - $base;
            $suggested += (int) round($item->suggested_quantity * $item->unit_cost);
        }

        $order->update([
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'total' => $subtotal + $taxTotal,
            'suggested_total' => $suggested,
        ]);
    }
}

<?php

namespace App\Services;

use App\Models\DocumentCounter;
use Illuminate\Support\Facades\DB;

/**
 * Consecutivos con bloqueo.
 *
 * Nunca `count() + 1`: dos cajas vendiendo a la vez generarian el mismo numero. Se bloquea
 * la fila del contador dentro de la transaccion de quien pide el numero.
 */
class NumberSequenceService
{
    public function next(int $companyId, string $key, int $storeId = 0): int
    {
        return DB::transaction(function () use ($companyId, $key, $storeId) {
            $counter = DocumentCounter::query()
                ->where('company_id', $companyId)
                ->where('store_id', $storeId)
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            if (! $counter) {
                $counter = DocumentCounter::create([
                    'company_id' => $companyId,
                    'store_id' => $storeId,
                    'key' => $key,
                    'last_number' => 0,
                ]);
            }

            $counter->last_number++;
            $counter->save();

            return $counter->last_number;
        });
    }

    /**
     * Numero de venta: prefijo de la caja + su consecutivo. Ej: C1-000482.
     *
     * El consecutivo es de la empresa y del prefijo, no de la sede: el numero es unico en la
     * empresa, y dos sedes con su «Caja 1» (C1), o dos tiendas en linea (MOS), sacaban las dos el
     * C1-000001 y la segunda venta reventaba. Asi cada caja lleva el suyo y nunca se repite.
     */
    public function nextSaleNumber(int $companyId, string $prefix): string
    {
        $number = $this->next($companyId, self::saleKey($prefix));

        return $prefix.'-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    /** La llave del contador de un prefijo de venta (cabe en `document_counters.key`). */
    public static function saleKey(string $prefix): string
    {
        return 'sale:'.mb_substr($prefix, 0, 25);
    }

    public function nextPurchaseOrderCode(int $companyId): string
    {
        $number = $this->next($companyId, 'purchase_order');

        return 'EM-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    /** Numero de traslado. Ej: TR-0042. */
    public function nextTransferNumber(int $companyId): string
    {
        $number = $this->next($companyId, 'stock_transfer');

        return 'TR-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    /** Numero de solicitud de traslado. Ej: SOL-0007. */
    public function nextTransferRequestNumber(int $companyId): string
    {
        $number = $this->next($companyId, 'stock_transfer_request');

        return 'SOL-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    /** Numero de devolucion a proveedor. Ej: DP-0003. */
    public function nextSupplierReturnNumber(int $companyId): string
    {
        $number = $this->next($companyId, 'supplier_return');

        return 'DP-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    /** Numero de devolucion de venta. Ej: DV-0003. */
    public function nextCustomerReturnNumber(int $companyId): string
    {
        $number = $this->next($companyId, 'customer_return');

        return 'DV-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    public function nextOrderNumber(int $companyId): string
    {
        return (string) (1000 + $this->next($companyId, 'order'));
    }
}

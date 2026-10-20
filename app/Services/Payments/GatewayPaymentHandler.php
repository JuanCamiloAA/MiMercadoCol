<?php

namespace App\Services\Payments;

use App\Models\PaymentTransaction;

/**
 * Lo que hace quien origino un cobro por pasarela cuando la pasarela responde. Se llama dentro
 * de la transaccion de base de datos de `GatewayPaymentService::settle`, con la fila bloqueada.
 * Nada con efectos fuera de la base (correos) sin `DB::afterCommit`.
 */
interface GatewayPaymentHandler
{
    /** La pasarela aprobo: convertirlo en venta o confirmar el pedido. Si no se puede, lanzar. */
    public function onApproved(PaymentTransaction $transaction): void;

    /** Rechazado, vencido, anulado o con error: liberar lo que estaba apartado. */
    public function onFailed(PaymentTransaction $transaction): void;
}

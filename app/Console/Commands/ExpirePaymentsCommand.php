<?php

namespace App\Console\Commands;

use App\Services\Payments\GatewayPaymentService;
use Illuminate\Console\Command;

/**
 * Cada minuto: los cobros por pasarela que vencieron sin pagarse. Antes de darlos por vencidos se
 * releen (uno pagado a ultimo minuto no se pierde). Un pedido en linea vencido se cancela y libera
 * lo apartado; un QR de la caja vencido devuelve la caja al tiquete.
 */
class ExpirePaymentsCommand extends Command
{
    protected $signature = 'payments:expire';

    protected $description = 'Vence los cobros por pasarela que no se pagaron a tiempo y libera lo apartado';

    public function handle(GatewayPaymentService $payments): int
    {
        $this->info('Cobros revisados: '.$payments->expireDue());

        return self::SUCCESS;
    }
}

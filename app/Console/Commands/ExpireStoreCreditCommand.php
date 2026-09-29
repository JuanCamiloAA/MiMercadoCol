<?php

namespace App\Console\Commands;

use App\Services\StoreCreditService;
use Illuminate\Console\Command;

class ExpireStoreCreditCommand extends Command
{
    protected $signature = 'store-credit:expire';

    protected $description = 'Cierra el saldo a favor de los clientes que ya vencio, segun la vigencia de Ajustes.';

    public function handle(StoreCreditService $credits): int
    {
        $count = $credits->expire();

        $this->info("Listo. {$count} abonos de saldo a favor vencidos.");

        return self::SUCCESS;
    }
}

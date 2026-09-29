<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\CreditService;
use App\Services\SettingsService;
use Illuminate\Console\Command;

class BlockOverdueCreditCommand extends Command
{
    protected $signature = 'credit:block-overdue';

    protected $description = 'Bloquea el credito de los clientes con facturas vencidas hace mas dias de los tolerados.';

    public function handle(CreditService $credit, SettingsService $settings): int
    {
        $total = 0;

        Company::query()->where('is_active', true)->each(function (Company $company) use ($credit, $settings, &$total) {
            $days = (int) $settings->get($company->id, 'credit.block_on_overdue_days');
            $blocked = $credit->blockOverdueCustomers($company->id, $days);
            $total += $blocked;

            $this->line("{$company->name}: {$blocked} clientes bloqueados (tolerancia {$days} dias).");
        });

        $this->info("Listo. {$total} clientes bloqueados en total.");

        return self::SUCCESS;
    }
}

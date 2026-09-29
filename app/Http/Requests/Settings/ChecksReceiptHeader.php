<?php

namespace App\Http\Requests\Settings;

use App\Models\Store;
use App\Services\ReceiptService;
use App\Services\SettingsService;
use App\Settings\SettingsSchema;
use App\Support\TenantContext;
use Illuminate\Support\Collection;

/**
 * El encabezado del tiquete se mide contra el papel en que de verdad se imprime.
 *
 * Una sede con excepcion de 58 mm que hereda un encabezado de 45 caracteres imprimiria
 * renglones partidos: eso se rechaza al guardar, diciendo que sede y que renglon.
 */
trait ChecksReceiptHeader
{
    /**
     * El primer problema del encabezado en ese papel, o null si cabe.
     */
    protected function headerProblem(mixed $header, int $paper, ?string $storeName = null): ?string
    {
        $lines = ReceiptService::headerLines($header);
        $maxLines = SettingsSchema::RECEIPT_HEADER_MAX_LINES;

        if (count($lines) > $maxLines) {
            return 'El encabezado tiene '.count($lines)." líneas; caben máximo {$maxLines}.";
        }

        $max = SettingsSchema::RECEIPT_CHARS_PER_LINE[$paper] ?? SettingsSchema::RECEIPT_CHARS_PER_LINE[80];

        foreach ($lines as $index => $line) {
            $length = mb_strlen($line);

            if ($length > $max) {
                $where = $storeName !== null ? " La sede {$storeName} imprime en {$paper} mm." : '';

                return 'La línea '.($index + 1)." tiene {$length} caracteres; en papel de {$paper} mm caben {$max}.{$where}";
            }
        }

        return null;
    }

    /**
     * Las sedes de la empresa con sus excepciones del tiquete.
     *
     * @return Collection<int, array{store: Store, overrides: array<string, mixed>}>
     */
    protected function storesWithOverrides(): Collection
    {
        $companyId = TenantContext::requireCompanyId();
        $settings = app(SettingsService::class);

        return Store::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get()
            ->map(fn (Store $store) => [
                'store' => $store,
                'overrides' => $settings->overridesFor($companyId, (int) $store->id),
            ]);
    }

    /**
     * `receipt.fields` solo puede traer campos que se pueden apagar.
     */
    protected function fieldsProblem(mixed $fields): ?string
    {
        if (! is_array($fields)) {
            return null;
        }

        foreach ($fields as $field) {
            if (! is_string($field) || ! in_array($field, SettingsSchema::RECEIPT_FIELDS, true)) {
                return 'Uno de los campos del tiquete no se puede elegir.';
            }
        }

        return null;
    }
}

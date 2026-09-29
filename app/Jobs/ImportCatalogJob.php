<?php

namespace App\Jobs;

use App\Models\CatalogImport;
use App\Services\Catalog\CatalogImporter;
use App\Services\Catalog\CsvCatalogReader;
use App\Services\Catalog\OpenFoodFactsClient;
use App\Services\Catalog\SearchCriteria;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Recorre la fuente y va metiendo lo que encuentra.
 *
 * Es un job y no una peticion porque traer cinco mil articulos a una peticion por segundo son
 * ochenta minutos: ninguna pantalla aguanta eso abierta. La corrida va persistiendo sus
 * contadores bloque a bloque para que la barra de avance muestre algo real, no una animacion.
 *
 * Pase lo que pase, la fila no se queda en `processing`: si el job revienta, `failed()` la
 * cierra con el motivo.
 */
class ImportCatalogJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 180];

    public function __construct(public int $catalogImportId, public int $fallbackCategoryId, public ?string $csvPath = null)
    {
        $this->onQueue((string) config('catalog.import.queue'));
    }

    /**
     * Una empresa a la vez.
     *
     * Dos corridas simultaneas sobre el mismo catalogo se pisarian en el upsert y dejarian
     * contadores que no cuadran con nada.
     */
    public function uniqueId(): string
    {
        return 'catalog-import:'.$this->companyId();
    }

    public function uniqueFor(): int
    {
        return $this->timeout;
    }

    public function handle(
        OpenFoodFactsClient $client,
        CatalogImporter $importer,
        CsvCatalogReader $reader,
    ): void {
        $import = CatalogImport::query()->withoutGlobalScopes()->find($this->catalogImportId);

        if (! $import || $import->isFinal()) {
            return;
        }

        $import->update([
            'status' => CatalogImport::STATUS_PROCESSING,
            'started_at' => $import->started_at ?? now(),
        ]);

        // Si esto revienta, la excepcion sube y la cola reintenta. Marcar `failed` aqui haria
        // que el siguiente intento viera la corrida como terminada y se devolviera sin hacer
        // nada: el estado final lo pone `failed()`, cuando ya no quedan intentos.
        $eans = $import->criteria['eans'] ?? [];

        match (true) {
            $import->source === CatalogImport::SOURCE_CSV => $this->runCsv($import, $importer, $reader),
            is_array($eans) && $eans !== [] => $this->runSelected($import, $importer, $client, $eans),
            default => $this->runSearch($import, $importer, $client),
        };

        // Cancelar es una decision del administrador, no un fallo: se respeta el estado.
        if ($this->status($import) === CatalogImport::STATUS_CANCELLED) {
            $import->update(['finished_at' => now()]);

            return;
        }

        $import->update(['status' => CatalogImport::STATUS_COMPLETED, 'finished_at' => now()]);
    }

    protected function runSearch(CatalogImport $import, CatalogImporter $importer, OpenFoodFactsClient $client): void
    {
        $criteria = SearchCriteria::fromArray($import->criteria ?? []);
        $perPage = (int) config('catalog.openfoodfacts.page_size');
        $seen = [];
        $processed = 0;
        $page = 1;

        while ($processed < $criteria->limit) {
            if ($this->cancelled($import)) {
                return;
            }

            $result = $client->search($criteria, $page, $perPage);

            if ($page === 1) {
                $import->update(['total_detected' => min($criteria->limit, (int) $result['count'])]);
            }

            $products = $result['products'];

            if ($products === []) {
                break;
            }

            // Nunca se pasa del limite pedido, aunque la pagina traiga de mas.
            $products = array_slice($products, 0, $criteria->limit - $processed);
            $processed += count($products);

            $this->apply($import, $importer->importChunk($import, $products, $this->fallbackCategoryId, $seen));

            if ($processed >= $criteria->limit || count($result['products']) < $perPage) {
                break;
            }

            $page++;
        }

        // Lo detectado se ajusta a lo que de verdad se recorrio: prometer 14.382 y procesar 500
        // dejaria la barra de avance en el 3 % para siempre.
        $import->update(['total_detected' => $processed]);
    }

    /**
     * Trae exactamente los codigos que el administrador marco en la previsualizacion.
     *
     * Es el camino normal: mirar lo que hay y escoger. Volver a correr la busqueda traeria
     * otra cosa si el catalogo de la fuente cambio entre un paso y el otro.
     *
     * @param  list<string>  $eans
     */
    protected function runSelected(CatalogImport $import, CatalogImporter $importer, OpenFoodFactsClient $client, array $eans): void
    {
        $eans = array_values(array_unique(array_map('strval', $eans)));
        $import->update(['total_detected' => count($eans)]);

        $chunkSize = (int) config('catalog.import.chunk');
        $seen = [];

        foreach (array_chunk($eans, $chunkSize) as $group) {
            if ($this->cancelled($import)) {
                return;
            }

            $found = [];
            $missing = 0;

            foreach ($group as $ean) {
                // El ritmo de una peticion por segundo lo lleva el cliente.
                $product = $client->findByEan($ean);

                if ($product === null) {
                    $importer->recordMissing($import, $ean);
                    $missing++;

                    continue;
                }

                $found[] = $product;
            }

            $counters = $importer->importChunk($import, $found, $this->fallbackCategoryId, $seen);
            $counters['skipped'] += $missing;

            $this->apply($import, $counters);
        }
    }

    protected function runCsv(CatalogImport $import, CatalogImporter $importer, CsvCatalogReader $reader): void
    {
        if ($this->csvPath === null || ! Storage::disk('local')->exists($this->csvPath)) {
            throw new \RuntimeException('El archivo de la importación ya no está disponible.');
        }

        $path = Storage::disk('local')->path($this->csvPath);
        $chunkSize = (int) config('catalog.import.chunk');
        $seen = [];
        $processed = 0;

        try {
            foreach ($reader->read($path)->chunk($chunkSize) as $chunk) {
                if ($this->cancelled($import)) {
                    return;
                }

                $rows = $chunk->values()->all();
                $processed += count($rows);

                $this->apply($import, $importer->importChunk($import, $rows, $this->fallbackCategoryId, $seen));
                $import->update(['total_detected' => $processed]);
            }
        } finally {
            Storage::disk('local')->delete($this->csvPath);
        }
    }

    /**
     * Suma los contadores del bloque.
     *
     * Va con `withoutGlobalScopes()` porque en el worker no hay sesion y el filtro por empresa
     * se resuelve en la consulta, no en el modelo.
     *
     * @param  array{imported: int, updated: int, skipped: int, failed: int}  $counters
     */
    protected function apply(CatalogImport $import, array $counters): void
    {
        CatalogImport::query()->withoutGlobalScopes()->whereKey($import->id)->update([
            'total_imported' => DB::raw('total_imported + '.(int) $counters['imported']),
            'total_updated' => DB::raw('total_updated + '.(int) $counters['updated']),
            'total_skipped' => DB::raw('total_skipped + '.(int) $counters['skipped']),
            'total_failed' => DB::raw('total_failed + '.(int) $counters['failed']),
        ]);
    }

    protected function cancelled(CatalogImport $import): bool
    {
        $status = CatalogImport::query()->withoutGlobalScopes()->whereKey($import->id)->value('status');

        return $status === CatalogImport::STATUS_CANCELLED;
    }

    protected function status(CatalogImport $import): ?string
    {
        return CatalogImport::query()->withoutGlobalScopes()->whereKey($import->id)->value('status');
    }

    public function failed(?\Throwable $exception): void
    {
        $import = CatalogImport::query()->withoutGlobalScopes()->find($this->catalogImportId);

        if ($import && ! $import->isFinal()) {
            $this->markFailed($import, $exception?->getMessage() ?? 'La importación falló sin dejar mensaje.');
        }

        if ($this->csvPath !== null) {
            Storage::disk('local')->delete($this->csvPath);
        }
    }

    protected function markFailed(CatalogImport $import, string $message): void
    {
        $import->update([
            'status' => CatalogImport::STATUS_FAILED,
            'error_message' => mb_substr(preg_replace('/\s+/', ' ', $message) ?? $message, 0, 500),
            'finished_at' => now(),
        ]);
    }

    protected function companyId(): int
    {
        return (int) CatalogImport::query()
            ->withoutGlobalScopes()
            ->whereKey($this->catalogImportId)
            ->value('company_id');
    }
}

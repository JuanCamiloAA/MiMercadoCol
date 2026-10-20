<?php

namespace App\Services\Platform;

use App\Imports\CompanyImport;
use App\Imports\ImportRegistry;
use App\Imports\MasterImport;
use App\Models\AccessLog;
use App\Models\PlatformImportBatch;
use App\Models\User;
use App\Services\MasterImportService;
use App\Support\Uploads;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * La carga masiva de plataforma.
 *
 * No trae su propio lector: usa `MasterImportService` y las mismas maestras del panel, mas
 * `CompanyImport`. Lo propio de aqui es la empresa destino, que se elige en el formulario y no
 * en la barra superior, y la bitacora con sus archivos.
 */
class PlatformImportService
{
    /** Disco privado: ni el original ni el reporte de errores se sirven como archivo publico. */
    /** El disco de los archivos de la carga masiva: el privado (Firebase en produccion). */
    public static function disk(): string
    {
        return Uploads::privateDiskName();
    }

    public function __construct(protected MasterImportService $imports) {}

    /**
     * @return array<string, class-string<MasterImport>>
     */
    public static function types(): array
    {
        return ['companies' => CompanyImport::class] + ImportRegistry::IMPORTS;
    }

    public static function supports(string $type): bool
    {
        return array_key_exists($type, self::types());
    }

    public static function import(string $type): MasterImport
    {
        $class = self::types()[$type] ?? null;

        if ($class === null) {
            throw new RuntimeException('Ese tipo de carga no existe.');
        }

        return new $class();
    }

    /** Las empresas se crean; todo lo demas va dentro de una empresa. */
    public static function needsCompany(string $type): bool
    {
        return $type !== 'companies';
    }

    /**
     * Guarda el archivo, abre la corrida y la simula: la vista previa cuenta exactamente lo que
     * pasaria, sin guardar nada.
     *
     * @return array{batch: PlatformImportBatch, result: array<string, mixed>}
     */
    public function preview(UploadedFile $file, string $type, ?int $companyId, User $user): array
    {
        $path = $file->storeAs('platform-imports/'.now()->format('Y/m'), Str::uuid().'.csv', self::disk());

        $batch = PlatformImportBatch::query()->create([
            'user_id' => $user->id,
            'company_id' => self::needsCompany($type) ? $companyId : null,
            'type' => $type,
            'original_filename' => mb_substr($file->getClientOriginalName(), 0, 250),
            'stored_path' => $path,
            'status' => PlatformImportBatch::STATUS_PENDING,
        ]);

        try {
            $result = $this->imports->run(self::import($type), Uploads::localPath(self::disk(), $path), (int) ($batch->company_id ?? 0), dryRun: true);
        } catch (Throwable $exception) {
            // Un archivo que ni se puede leer no queda como pendiente.
            $batch->forceFill(['status' => PlatformImportBatch::STATUS_FAILED, 'message' => $this->message($exception), 'finished_at' => now()])->save();

            throw $exception;
        }

        $batch->forceFill(['rows_total' => $result['rows']])->save();

        return ['batch' => $batch, 'result' => $result];
    }

    /**
     * Procesa de verdad una corrida simulada.
     */
    public function process(PlatformImportBatch $batch, User $user): PlatformImportBatch
    {
        if ($batch->status !== PlatformImportBatch::STATUS_PENDING) {
            throw new RuntimeException('Esa carga ya se procesó.');
        }

        $batch->forceFill(['status' => PlatformImportBatch::STATUS_PROCESSING, 'started_at' => now()])->save();
        $source = Uploads::localPath(self::disk(), $batch->stored_path);

        try {
            $result = $this->imports->run(self::import($batch->type), $source, (int) ($batch->company_id ?? 0));
        } catch (Throwable $exception) {
            $batch->forceFill(['status' => PlatformImportBatch::STATUS_FAILED, 'message' => $this->message($exception), 'finished_at' => now()])->save();

            return $batch;
        }

        $success = $result['created'] + $result['updated'];
        $failed = $result['skipped'];

        $batch->forceFill([
            'status' => match (true) {
                $failed === 0 => PlatformImportBatch::STATUS_COMPLETED,
                $success === 0 => PlatformImportBatch::STATUS_FAILED,
                default => PlatformImportBatch::STATUS_PARTIAL,
            },
            'rows_total' => $result['rows'],
            'rows_success' => $success,
            'rows_failed' => $failed,
            'error_report_path' => $result['failures'] !== [] ? $this->errorReport($batch, $source, $result['failures']) : null,
            'message' => "{$result['created']} nuevos, {$result['updated']} actualizados, {$failed} omitidos.",
            'finished_at' => now(),
        ])->save();

        AccessLog::log('platform_import', $user->id, [
            'batch' => $batch->id,
            'type' => $batch->type,
            'company_id' => $batch->company_id,
            'created' => $result['created'],
            'updated' => $result['updated'],
            'skipped' => $failed,
        ]);

        return $batch;
    }

    /**
     * El reporte de errores: las filas que no entraron, tal como venian, con el motivo al final.
     * Se puede corregir y volver a subir.
     *
     * @param  list<array{line: int, reason: string}>  $failures
     */
    protected function errorReport(PlatformImportBatch $batch, string $source, array $failures): string
    {
        $reasons = [];

        foreach ($failures as $failure) {
            $reasons[$failure['line']] = $failure['reason'];
        }

        $input = fopen($source, 'r');
        $output = fopen('php://temp', 'w+');
        $delimiter = $this->delimiter($source);
        $line = 0;

        fwrite($output, "\xEF\xBB\xBF");

        while (($row = fgetcsv($input, 0, $delimiter)) !== false) {
            $line++;

            if ($line === 1) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
                fputcsv($output, [...$row, 'error'], ';');

                continue;
            }

            if (isset($reasons[$line])) {
                fputcsv($output, [...$row, $reasons[$line]], ';');
            }
        }

        fclose($input);
        rewind($output);

        $path = 'platform-imports/errores/'.$batch->id.'-'.Str::random(8).'.csv';
        Storage::disk(self::disk())->put($path, (string) stream_get_contents($output));
        fclose($output);

        return $path;
    }

    /**
     * La plantilla de un tipo: encabezados y una fila de ejemplo, con punto y coma, que es como
     * guarda Excel en Colombia.
     */
    public function template(string $type): string
    {
        $import = self::import($type);
        $output = fopen('php://temp', 'w+');

        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, array_keys($import->columns()), ';');
        fputcsv($output, $import->example(), ';');
        rewind($output);

        $csv = (string) stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Todas las plantillas en un ZIP. Devuelve la ruta de un archivo temporal.
     */
    public function templatesZip(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'plantillas');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo armar el ZIP de plantillas.');
        }

        foreach (array_keys(self::types()) as $type) {
            $zip->addFromString("plantilla-{$type}.csv", $this->template($type));
        }

        $zip->close();

        return $path;
    }

    /** El mismo criterio del lector: el separador que mas parte el encabezado. */
    protected function delimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        $first = $handle ? (string) fgets($handle) : '';

        if ($handle) {
            fclose($handle);
        }

        return substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
    }

    protected function message(Throwable $exception): string
    {
        if ($exception instanceof \Illuminate\Validation\ValidationException) {
            return (string) collect($exception->errors())->flatten()->first();
        }

        return mb_substr($exception->getMessage(), 0, 250);
    }
}

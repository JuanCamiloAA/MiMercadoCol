<?php

namespace App\Services;

use App\Imports\MasterImport;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Lector de los archivos de importacion de maestras.
 *
 * Una sola implementacion para todos los modulos: abre el archivo, empareja encabezados con
 * columnas y le entrega cada fila a la definicion del modulo. Guarda el detalle de lo que no
 * pudo entrar, porque «se omitieron 12 filas» sin decir por que obliga a adivinar.
 */
class MasterImportService
{
    /** Cuantos errores se le devuelven a la persona: mas que esto no se alcanza a leer. */
    public const MAX_ERRORS = 12;

    /** @var list<array{line: int, reason: string}> todas las filas que no entraron, para el reporte */
    protected array $failures = [];

    /**
     * Recorre el archivo y guarda fila por fila.
     *
     * Cada fila va en su propio punto de guardado: si una revienta (un tope del plan, un indice
     * unico, lo que sea) se deshace solo esa y se reporta, sin tumbar las demas. Con `$dryRun`
     * todo se deshace al final: es la vista previa, que cuenta exactamente lo que pasaria.
     *
     * @return array{created: int, updated: int, skipped: int, errors: list<string>, rows: int, failures: list<array{line: int, reason: string}>, sample: list<array{line: int, action: string, label: string, reason: ?string}>}
     */
    public function run(MasterImport $import, string $path, int $companyId, bool $dryRun = false): array
    {
        $this->failures = [];
        $rows = 0;
        $sample = [];

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw ValidationException::withMessages(['file' => ['No se pudo abrir el archivo.']]);
        }

        try {
            $delimiter = $this->delimiter($path);
            $header = fgetcsv($handle, 0, $delimiter);

            if ($header === false) {
                throw ValidationException::withMessages(['file' => ['El archivo esta vacio.']]);
            }

            $header = $this->normalizeHeader($header, $import);
            $this->assertHeaderIsUsable($import, $header);

            $created = 0;
            $updated = 0;
            $skipped = 0;
            $errors = [];
            $line = 1;

            DB::beginTransaction();

            try {
                while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                    $line++;

                    if ($this->isBlank($row)) {
                        continue;
                    }

                    $rows++;
                    $data = $this->combine($header, $row);
                    [$action, $reason] = $this->importRow($import, $data, $companyId);

                    match ($action) {
                        'create' => $created++,
                        'update' => $updated++,
                        default => $skipped++,
                    };

                    if ($reason !== null) {
                        $this->addError($errors, $line, $reason);
                    }

                    if (count($sample) < 20) {
                        $sample[] = ['line' => $line, 'action' => $action, 'label' => $import->rowLabel($data), 'reason' => $reason];
                    }
                }

                $dryRun ? DB::rollBack() : DB::commit();
            } catch (\Throwable $exception) {
                DB::rollBack();

                throw $exception;
            }

            return [
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors' => $errors,
                'rows' => $rows,
                'failures' => $this->failures,
                'sample' => $sample,
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * Una fila: devuelve que paso (`create`, `update` o `skip`) y, si no entro, por que.
     *
     * @param  array<string, string|null>  $data
     * @return array{0: string, 1: ?string}
     */
    protected function importRow(MasterImport $import, array $data, int $companyId): array
    {
        $missing = $this->missingRequired($import, $data);

        if ($missing !== []) {
            return ['skip', 'falta '.implode(' y ', $missing)];
        }

        // Lo que no se puede traducir se explica con el mensaje de la maestra, tal cual.
        try {
            $payload = $import->payload($data, $companyId);
        } catch (\Throwable $exception) {
            return ['skip', $exception->getMessage()];
        }

        if ($payload === null) {
            return ['skip', 'los datos no corresponden a nada conocido'];
        }

        try {
            return DB::transaction(function () use ($import, $data, $payload, $companyId) {
                $existing = $import->existing($data, $companyId);

                if ($existing) {
                    if (! $import->allowsUpdate()) {
                        return ['skip', 'ya existe'];
                    }

                    $existing->fill($payload)->save();
                    $import->saved($existing, $data, $companyId);

                    return ['update', null];
                }

                // Los topes del plan: una fila que los pase no entra, y se dice por que.
                $import->beforeCreate($payload, $companyId);

                $record = $import->store($payload, $companyId);
                $import->created($record);
                $import->saved($record, $data, $companyId);

                return ['create', null];
            });
        } catch (ValidationException $exception) {
            return ['skip', (string) collect($exception->errors())->flatten()->first()];
        } catch (\Throwable $exception) {
            report($exception);

            return ['skip', $exception instanceof UniqueConstraintViolationException
                ? 'ya hay un registro con esos datos'
                : 'no se pudo guardar: '.mb_substr($exception->getMessage(), 0, 120)];
        }
    }

    /**
     * Excel de Colombia guarda con punto y coma, el de otras partes con coma: se mira el
     * encabezado y se usa el que mas separa.
     */
    protected function delimiter(string $path): string
    {
        $first = (string) fgets(fopen($path, 'r'));

        return substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
    }

    /**
     * «Artículo principal», «articulo principal» y «articulo_principal» son la misma columna.
     *
     * @param  list<string|null>  $header
     * @return list<string>
     */
    protected function normalizeHeader(array $header, MasterImport $import): array
    {
        $aliases = $import->aliases();

        return array_map(function ($column, $index) use ($aliases) {
            $value = (string) $column;

            // Excel antepone el BOM de UTF-8 y sin quitarlo la primera columna nunca coincide.
            if ($index === 0) {
                $value = preg_replace('/^\x{FEFF}/u', '', $value) ?? $value;
            }

            $key = preg_replace('/[\s\-]+/', '_', Str::ascii(mb_strtolower(trim($value)))) ?? '';

            return $aliases[$key] ?? $key;
        }, $header, array_keys($header));
    }

    /**
     * @param  list<string>  $header
     */
    protected function assertHeaderIsUsable(MasterImport $import, array $header): void
    {
        $missing = array_values(array_diff($import->required(), $header));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'file' => ['Al archivo le faltan columnas obligatorias: '.implode(', ', $missing).'.'],
            ]);
        }
    }

    /**
     * @param  list<string>  $header
     * @param  list<string|null>  $row
     * @return array<string, string|null>
     */
    protected function combine(array $header, array $row): array
    {
        $values = array_pad(array_slice($row, 0, count($header)), count($header), null);

        return array_combine($header, $values);
    }

    /**
     * @param  list<string|null>  $row
     */
    protected function isBlank(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string|null>  $data
     * @return list<string>
     */
    protected function missingRequired(MasterImport $import, array $data): array
    {
        return array_values(array_filter(
            $import->required(),
            fn (string $column) => trim((string) ($data[$column] ?? '')) === ''
        ));
    }

    /**
     * @param  list<string>  $errors
     */
    protected function addError(array &$errors, int $line, string $reason): void
    {
        $this->failures[] = ['line' => $line, 'reason' => $reason];

        if (count($errors) >= self::MAX_ERRORS) {
            return;
        }

        $errors[] = "Fila {$line}: {$reason}.";
    }
}

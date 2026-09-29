<?php

namespace App\Services\Catalog;

use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;

/**
 * Lee el volcado de catalogo en CSV.
 *
 * Es la salida del script que filtra el dump de Open Food Facts por Colombia, con columnas
 * `ean,nombre,marca,presentacion,categoria,imagen`. Se lee en streaming: el archivo puede traer
 * decenas de miles de filas y cargarlo entero en memoria tumba el proceso.
 *
 * Cada fila sale con la misma forma que devuelve la API, para que el normalizador y el
 * importador sean exactamente los mismos por los dos caminos. Si fueran dos, cada arreglo en
 * uno habria que acordarse de hacerlo en el otro.
 *
 * El lector de maestras (`MasterImportService`) no sirve aqui: ese trabaja por SKU, con precios,
 * en una sola transaccion y sin trazabilidad por fila. Este va por EAN y deja bitacora.
 */
class CsvCatalogReader
{
    /** @var array<string, string> Columna del archivo => campo de la fuente. */
    public const COLUMNS = [
        'ean' => 'code',
        'nombre' => 'product_name',
        'marca' => 'brands',
        'presentacion' => 'quantity',
        'categoria' => 'categories_tags',
        'imagen' => 'image_front_small_url',
    ];

    /** @var list<string> */
    public const REQUIRED = ['ean', 'nombre'];

    /**
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function read(string $path): LazyCollection
    {
        $delimiter = $this->delimiter($path);
        $header = $this->header($path, $delimiter);

        return LazyCollection::make(function () use ($path, $delimiter, $header) {
            $file = new \SplFileObject($path, 'r');
            $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
            $file->setCsvControl($delimiter);

            $first = true;

            foreach ($file as $row) {
                if ($first) {
                    $first = false;

                    continue;
                }

                if (! is_array($row) || $this->isBlank($row)) {
                    continue;
                }

                yield $this->toSourceShape($header, $row);
            }
        });
    }

    /**
     * @param  list<string>  $header
     * @param  list<string|null>  $row
     * @return array<string, mixed>
     */
    protected function toSourceShape(array $header, array $row): array
    {
        $values = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), null));

        $raw = [];

        foreach (self::COLUMNS as $column => $field) {
            $value = trim((string) ($values[$column] ?? ''));

            if ($value === '') {
                continue;
            }

            // La categoria viaja como lista, igual que `categories_tags` de la API, para que el
            // normalizador no tenga que saber de donde vino la fila.
            $raw[$field] = $field === 'categories_tags' ? [$value] : $value;
        }

        return $raw;
    }

    /**
     * @return list<string>
     */
    protected function header(string $path, string $delimiter): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw ValidationException::withMessages(['file' => ['No se pudo abrir el archivo.']]);
        }

        $header = fgetcsv($handle, 0, $delimiter);
        fclose($handle);

        if ($header === false) {
            throw ValidationException::withMessages(['file' => ['El archivo está vacío.']]);
        }

        $header = array_map(function ($column, $index) {
            $value = (string) $column;

            // Excel antepone el BOM de UTF-8 y sin quitarlo la primera columna nunca coincide.
            if ($index === 0) {
                $value = preg_replace('/^\x{FEFF}/u', '', $value) ?? $value;
            }

            return mb_strtolower(trim($value));
        }, $header, array_keys($header));

        $missing = array_values(array_diff(self::REQUIRED, $header));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'file' => ['Al archivo le faltan columnas obligatorias: '.implode(', ', $missing).'.'],
            ]);
        }

        return $header;
    }

    protected function delimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        $first = $handle === false ? '' : (string) fgets($handle);

        if ($handle !== false) {
            fclose($handle);
        }

        return substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
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
}

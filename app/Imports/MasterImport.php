<?php

namespace App\Imports;

use App\Models\Store;
use App\Support\StoreContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Definicion de la importacion de un modulo de maestras.
 *
 * Cada maestra declara sus columnas y como se traduce una fila a un registro; el recorrido
 * del archivo, la transaccion y el conteo los pone `MasterImportService`. Asi agregar una
 * maestra nueva es escribir una clase corta, no repetir el lector de CSV.
 */
abstract class MasterImport
{
    /** Clave del modulo. De aqui salen el permiso y el nombre de la ruta. */
    abstract public function module(): string;

    abstract public function title(): string;

    /** Una linea que explique que hace la importacion y cuando actualiza en vez de crear. */
    abstract public function description(): string;

    /**
     * Columnas del archivo, en orden, con lo que significa cada una.
     *
     * @return array<string, string>
     */
    abstract public function columns(): array;

    /**
     * Columnas sin las cuales la fila no sirve.
     *
     * @return list<string>
     */
    abstract public function required(): array;

    /**
     * Fila de ejemplo, en el mismo orden de `columns()`.
     *
     * @return list<string>
     */
    abstract public function example(): array;

    /**
     * El registro que ya existe con esa clave, si lo hay.
     *
     * @param  array<string, string|null>  $row
     */
    abstract public function existing(array $row, int $companyId): ?Model;

    /**
     * Datos listos para guardar. Devolver `null` omite la fila.
     *
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>|null
     */
    abstract public function payload(array $row, int $companyId): ?array;

    /** @return class-string<Model> */
    abstract public function model(): string;

    /**
     * Otros nombres con que llega una columna: el encabezado ya normalizado (minusculas, sin
     * tildes, espacios como `_`) apuntando a la columna. Sirve para que el archivo que sale de
     * Exportar vuelva a entrar tal cual.
     *
     * @return array<string, string>
     */
    public function aliases(): array
    {
        return [];
    }

    /**
     * Advertencias que se muestran al lado del formato.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        return [];
    }

    /** Lo que el registro recien creado necesite aparte de sus propias columnas. */
    public function created(Model $record): void {}

    /**
     * Despues de crear o actualizar: lo que cuelga del registro y viene en la misma fila (los
     * articulos de un servicio, por ejemplo). Corre dentro del punto de guardado de la fila.
     *
     * @param  array<string, string|null>  $row
     */
    public function saved(Model $record, array $row, int $companyId): void {}

    /**
     * Crea el registro. Por defecto, el modelo con la empresa destino; una maestra que nazca
     * por otro camino (las empresas, por el aprovisionador) lo reemplaza.
     *
     * @param  array<string, mixed>  $payload
     */
    public function store(array $payload, int $companyId): Model
    {
        $model = $this->model();

        return $model::query()->withoutGlobalScopes()->create(array_merge($payload, ['company_id' => $companyId]));
    }

    /** Si una fila que ya existe se actualiza. Si no, se omite con «ya existe». */
    public function allowsUpdate(): bool
    {
        return true;
    }

    /**
     * Como se nombra una fila en la vista previa: la primera columna obligatoria con valor.
     *
     * @param  array<string, string|null>  $row
     */
    public function rowLabel(array $row): string
    {
        foreach ([...$this->required(), ...array_keys($this->columns())] as $column) {
            if (filled($row[$column] ?? null)) {
                return mb_substr((string) $row[$column], 0, 80);
            }
        }

        return '—';
    }

    /**
     * Antes de crear un registro nuevo: aqui se revisan los topes del plan. Lo que lance se
     * reporta como error de esa fila y la fila no entra.
     *
     * @param  array<string, mixed>  $payload
     */
    public function beforeCreate(array $payload, int $companyId): void {}

    /**
     * La sede a usar cuando la fila no dice cual: la activa, si es de la empresa destino, o la
     * primera de esa empresa. Nunca una sede de otra empresa: la carga masiva de plataforma
     * importa para una empresa distinta de la elegida arriba.
     */
    protected function fallbackStoreId(int $companyId): ?int
    {
        $active = StoreContext::activeStore();

        if ($active && (int) $active->company_id === $companyId) {
            return $active->id;
        }

        return Store::query()->withoutGlobalScopes()->where('company_id', $companyId)->orderBy('id')->value('id');
    }

    public function permission(): string
    {
        return $this->module().'.index.import';
    }

    public function indexRoute(): string
    {
        return $this->module().'.index';
    }

    public function formRoute(): string
    {
        return $this->module().'.import.form';
    }

    public function submitRoute(): string
    {
        return $this->module().'.import';
    }

    // Lectores de celda: el archivo lo escribe una persona en Excel, no un sistema.

    /**
     * @param  array<string, string|null>  $row
     */
    protected function text(array $row, string $key): ?string
    {
        $value = trim((string) ($row[$key] ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, string|null>  $row
     */
    protected function integer(array $row, string $key, int $default = 0): int
    {
        $value = $this->text($row, $key);

        if ($value === null) {
            return $default;
        }

        // «1.250,50» y «1250.5» tienen que caer en el mismo entero.
        $clean = str_replace(['.', ' '], '', $value);
        $clean = str_replace(',', '.', $clean);

        return (int) round((float) $clean);
    }

    /**
     * @param  array<string, string|null>  $row
     */
    protected function boolean(array $row, string $key, bool $default = true): bool
    {
        $value = $this->text($row, $key);

        if ($value === null) {
            return $default;
        }

        return in_array(mb_strtolower($value), ['si', 'sí', 's', '1', 'true', 'x', 'activo', 'activa'], true);
    }

    /**
     * @param  array<string, string|null>  $row
     */
    protected function date(array $row, string $key): ?string
    {
        $value = $this->text($row, $key);

        if ($value === null) {
            return null;
        }

        foreach (['d/m/Y', 'Y-m-d', 'd-m-Y'] as $format) {
            $parsed = \DateTime::createFromFormat($format, $value);

            if ($parsed !== false) {
                return $parsed->format('Y-m-d');
            }
        }

        return null;
    }
}

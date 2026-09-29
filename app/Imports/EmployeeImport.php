<?php

namespace App\Imports;

use App\Models\Employee;
use App\Models\Store;
use Illuminate\Database\Eloquent\Model;

class EmployeeImport extends MasterImport
{
    public function module(): string
    {
        return 'employees';
    }

    public function title(): string
    {
        return 'Importar empleados';
    }

    public function description(): string
    {
        return 'Se reconoce por el numero de documento; si ya existe, se actualiza su hoja.';
    }

    public function model(): string
    {
        return Employee::class;
    }

    public function columns(): array
    {
        return [
            'documento' => 'Numero de cedula, sin puntos',
            'tipo_documento' => implode(' / ', Employee::DOCUMENT_TYPES),
            'nombres' => 'Nombres de pila',
            'apellidos' => 'Apellidos',
            'cargo' => 'Por ejemplo Cajera, Auxiliar de bodega',
            'tipo_contrato' => implode(' / ', Employee::CONTRACT_TYPES),
            'ingreso' => 'Fecha de ingreso, formato 31/12/2025',
            'sede' => 'Codigo o nombre de la sede donde trabaja',
            'telefono' => 'Opcional',
            'correo' => 'Opcional',
        ];
    }

    public function required(): array
    {
        return ['documento', 'nombres', 'apellidos', 'cargo'];
    }

    public function example(): array
    {
        return ['1017223344', 'CC', 'Diana Carolina', 'Pulgarin Mesa', 'Cajera', 'indefinido', '01/03/2025', 'CEN', '3114445566', 'diana@correo.co'];
    }

    public function notes(): array
    {
        return [
            'Sin fecha de ingreso se toma la de hoy.',
            'Si la sede no se encuentra, el empleado queda en la sede activa.',
            'La importacion no crea usuarios ni contrasenas: el acceso se otorga despues, uno por uno.',
        ];
    }

    public function existing(array $row, int $companyId): ?Model
    {
        return Employee::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('document_number', $this->text($row, 'documento'))
            ->first();
    }

    public function payload(array $row, int $companyId): ?array
    {
        $type = mb_strtoupper((string) $this->text($row, 'tipo_documento'));
        $contract = mb_strtolower((string) $this->text($row, 'tipo_contrato'));
        $store = $this->resolveStore($row, $companyId);

        if (! $store) {
            throw new \RuntimeException('no hay ninguna sede a la cual asignarlo');
        }

        return [
            'store_id' => $store,
            'document_number' => $this->text($row, 'documento'),
            'document_type' => in_array($type, Employee::DOCUMENT_TYPES, true) ? $type : 'CC',
            'first_name' => $this->text($row, 'nombres'),
            'last_name' => $this->text($row, 'apellidos'),
            'position' => $this->text($row, 'cargo'),
            'contract_type' => in_array($contract, Employee::CONTRACT_TYPES, true) ? $contract : 'indefinido',
            'hired_at' => $this->date($row, 'ingreso') ?? now()->toDateString(),
            'phone' => $this->text($row, 'telefono'),
            'email' => $this->text($row, 'correo'),
            'status' => Employee::STATUS_ACTIVE,
        ];
    }

    /** @param array<string, string|null> $row */
    protected function resolveStore(array $row, int $companyId): ?int
    {
        $name = $this->text($row, 'sede');

        if ($name) {
            $store = Store::query()
                ->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where(fn ($query) => $query->where('code', mb_strtoupper($name))->orWhere('name', $name))
                ->first();

            if ($store) {
                return $store->id;
            }
        }

        return $this->fallbackStoreId($companyId);
    }
}

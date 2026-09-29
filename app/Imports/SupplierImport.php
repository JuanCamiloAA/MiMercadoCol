<?php

namespace App\Imports;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;

class SupplierImport extends MasterImport
{
    public function module(): string
    {
        return 'suppliers';
    }

    public function title(): string
    {
        return 'Importar proveedores';
    }

    public function description(): string
    {
        return 'Se reconoce por el NIT; si viene vacio, por el nombre.';
    }

    public function model(): string
    {
        return Supplier::class;
    }

    public function columns(): array
    {
        return [
            'nombre' => 'Razon social o nombre comercial',
            'nit' => 'Con digito de verificacion, si lo tiene',
            'contacto' => 'Persona con quien se habla',
            'telefono' => 'Opcional',
            'correo' => 'Opcional',
            'direccion' => 'Opcional',
            'dias_entrega' => 'Cuantos dias se demora en despachar',
            'activo' => 'si / no',
        ];
    }

    public function required(): array
    {
        return ['nombre'];
    }

    public function example(): array
    {
        return ['Distribuidora El Trigal', '900123456-7', 'Hernan Dario Pulgarin', '3105551234', 'ventas@eltrigal.co', 'Cra 45 # 30-12', '3', 'si'];
    }

    public function existing(array $row, int $companyId): ?Model
    {
        $taxId = $this->text($row, 'nit');

        return Supplier::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->when($taxId, fn ($query) => $query->where('tax_id', $taxId))
            ->when(! $taxId, fn ($query) => $query->where('name', $this->text($row, 'nombre')))
            ->first();
    }

    public function payload(array $row, int $companyId): ?array
    {
        return [
            'name' => $this->text($row, 'nombre'),
            'tax_id' => $this->text($row, 'nit'),
            'contact_name' => $this->text($row, 'contacto'),
            'phone' => $this->text($row, 'telefono'),
            'email' => $this->text($row, 'correo'),
            'address' => $this->text($row, 'direccion'),
            'lead_time_days' => $this->integer($row, 'dias_entrega', 3),
            'is_active' => $this->boolean($row, 'activo'),
        ];
    }
}

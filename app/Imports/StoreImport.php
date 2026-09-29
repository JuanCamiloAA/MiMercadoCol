<?php

namespace App\Imports;

use App\Models\Company;
use App\Models\Store;
use App\Services\Membership\MembershipLimiter;
use Illuminate\Database\Eloquent\Model;

class StoreImport extends MasterImport
{
    public function module(): string
    {
        return 'stores';
    }

    public function title(): string
    {
        return 'Importar sedes';
    }

    public function description(): string
    {
        return 'Se reconoce por el codigo; si ya existe, se actualizan sus datos.';
    }

    public function model(): string
    {
        return Store::class;
    }

    public function columns(): array
    {
        return [
            'codigo' => 'Corto y unico, por ejemplo CEN',
            'nombre' => 'Como se conoce la sede',
            'direccion' => 'Opcional',
            'ciudad' => 'Opcional; con ella la sede aparece en el buscador de la landing',
            'latitud' => 'Opcional, por ejemplo 6.2442',
            'longitud' => 'Opcional, por ejemplo -75.5812',
            'telefono' => 'Opcional',
            'abre' => 'Hora de apertura, formato 07:00',
            'cierra' => 'Hora de cierre, formato 21:00',
            'base_caja' => 'Base sugerida para abrir caja, en pesos',
            'activa' => 'si / no',
        ];
    }

    public function required(): array
    {
        return ['codigo', 'nombre'];
    }

    public function example(): array
    {
        return ['CEN', 'Centro', 'Calle 51 # 49-20', 'Medellín', '6.2442', '-75.5812', '6044445566', '07:00', '21:00', '200000', 'si'];
    }

    public function existing(array $row, int $companyId): ?Model
    {
        return Store::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('code', mb_strtoupper((string) $this->text($row, 'codigo')))
            ->first();
    }

    public function payload(array $row, int $companyId): ?array
    {
        return [
            'code' => mb_strtoupper((string) $this->text($row, 'codigo')),
            'name' => $this->text($row, 'nombre'),
            'address' => $this->text($row, 'direccion'),
            'city' => $this->text($row, 'ciudad'),
            'latitude' => $this->coordinate($row, 'latitud', 90),
            'longitude' => $this->coordinate($row, 'longitud', 180),
            'phone' => $this->text($row, 'telefono'),
            'opens_at' => $this->time($row, 'abre', '07:00:00'),
            'closes_at' => $this->time($row, 'cierra', '21:00:00'),
            'suggested_cash_base' => $this->integer($row, 'base_caja', 200000),
            'is_active' => $this->boolean($row, 'activa'),
        ];
    }

    public function beforeCreate(array $payload, int $companyId): void
    {
        $company = Company::query()->find($companyId);

        if ($company) {
            app(MembershipLimiter::class)->assertCanAdd($company, 'stores');
        }
    }

    /**
     * Una coordenada en grados; con coma o punto decimal. Fuera de rango, no se guarda.
     *
     * @param  array<string, string|null>  $row
     */
    protected function coordinate(array $row, string $key, float $max): ?float
    {
        $value = $this->text($row, $key);

        if ($value === null || ! is_numeric(str_replace(',', '.', $value))) {
            return null;
        }

        $number = (float) str_replace(',', '.', $value);

        return abs($number) <= $max ? round($number, 7) : null;
    }

    /** @param array<string, string|null> $row */
    protected function time(array $row, string $key, string $default): string
    {
        $value = $this->text($row, $key);

        if ($value === null || preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value) !== 1) {
            return $default;
        }

        return substr_count($value, ':') === 1 ? $value.':00' : $value;
    }
}

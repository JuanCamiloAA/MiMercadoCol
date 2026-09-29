<?php

namespace App\Imports;

use App\Models\Register;
use App\Models\Store;
use App\Services\Membership\MembershipLimiter;
use Illuminate\Database\Eloquent\Model;

class RegisterImport extends MasterImport
{
    public function module(): string
    {
        return 'registers';
    }

    public function title(): string
    {
        return 'Importar cajas';
    }

    public function description(): string
    {
        return 'Se reconoce por el codigo dentro de la sede; si ya existe, se actualiza.';
    }

    public function model(): string
    {
        return Register::class;
    }

    public function columns(): array
    {
        return [
            'codigo' => 'Corto y unico en la sede, por ejemplo C1',
            'nombre' => 'Como la llaman en la tienda',
            'sede' => 'Codigo o nombre de la sede donde esta',
            'activa' => 'si / no',
        ];
    }

    public function required(): array
    {
        return ['codigo', 'nombre'];
    }

    public function example(): array
    {
        return ['C1', 'Caja 1', 'CEN', 'si'];
    }

    public function notes(): array
    {
        return ['Si la sede no se encuentra, la caja queda en la sede activa.'];
    }

    public function existing(array $row, int $companyId): ?Model
    {
        $store = $this->resolveStore($row, $companyId);

        return Register::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('store_id', $store)
            ->where('code', mb_strtoupper((string) $this->text($row, 'codigo')))
            ->first();
    }

    public function payload(array $row, int $companyId): ?array
    {
        $store = $this->resolveStore($row, $companyId);

        if (! $store) {
            throw new \RuntimeException('no hay ninguna sede a la cual asignarla');
        }

        return [
            'store_id' => $store,
            'code' => mb_strtoupper((string) $this->text($row, 'codigo')),
            'name' => $this->text($row, 'nombre'),
            'is_active' => $this->boolean($row, 'activa'),
        ];
    }

    public function beforeCreate(array $payload, int $companyId): void
    {
        $company = \App\Models\Company::query()->find($companyId);

        if ($company) {
            app(MembershipLimiter::class)->assertCanAdd($company, 'registers');
        }
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

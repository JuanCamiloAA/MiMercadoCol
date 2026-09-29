<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Lo que comparten traslados y solicitudes al hablar de bodegas.
 *
 * La regla de fondo: quien actua sobre una bodega tiene que estar asignado a su sede. El
 * permiso dice que puede despachar o recibir; esto dice donde. Sin ella, alguien de otra sede
 * podria mover mercancia de un estante que no puede ver.
 */
trait HandlesTransferWarehouses
{
    /** Las sedes a las que el usuario esta asignado. Un super administrador llega a todas. */
    protected function reaches(?Warehouse $warehouse): bool
    {
        $user = auth('web')->user();

        if (! $warehouse || ! $user) {
            return false;
        }

        return $user->isSuperAdmin() || $user->stores()->whereKey($warehouse->store_id)->exists();
    }

    protected function guardAccess(?Warehouse $warehouse, string $verb): void
    {
        if ($this->reaches($warehouse)) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => ["No tienes acceso a la sede de {$warehouse?->store?->name}: no puedes {$verb} esa bodega."],
        ]);
    }

    /**
     * Las bodegas como las muestra un selector: siempre con su sede al lado.
     *
     * @param  Collection<int, Warehouse>  $warehouses
     * @return list<array<string, mixed>>
     */
    protected function warehouseOptions(Collection $warehouses): array
    {
        return $warehouses
            ->map(fn (Warehouse $warehouse) => [
                'value' => $warehouse->id,
                'label' => $warehouse->fullName(),
                'store_id' => (int) $warehouse->store_id,
                'store_name' => $warehouse->store?->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function side(?Warehouse $warehouse): ?array
    {
        return $warehouse ? [
            'id' => $warehouse->id,
            'name' => $warehouse->name,
            'store_id' => (int) $warehouse->store_id,
            'store_name' => $warehouse->store?->name,
        ] : null;
    }

    protected function warehouseOf(int $companyId, int $id): Warehouse
    {
        return Warehouse::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);
    }
}

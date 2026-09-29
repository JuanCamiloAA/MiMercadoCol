<?php

namespace App\Support;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Sede activa de la peticion.
 *
 * Caja, existencias, ventas y pedidos ocurren siempre en una sede. Se guarda en sesion; si
 * la persona solo tiene una permitida, se elige sola y nunca se le pregunta.
 */
final class StoreContext
{
    public const SESSION_KEY = 'active_store_id';

    /**
     * @return Collection<int, Store>
     */
    public static function availableStores(?User $user = null): Collection
    {
        $user = $user ?? Auth::guard('web')->user();

        if (! $user) {
            return collect();
        }

        $query = Store::query()->where('is_active', true)->orderBy('name');

        // El super usuario ve las sedes de la empresa que eligio arriba. En consolidado no hay
        // sede: mezclar las de todas las empresas cruzaria caja, inventario y ventas.
        if ($user->isSuperAdmin()) {
            $companyId = TenantContext::activeCompanyId($user);

            return $companyId === null
                ? collect()
                : $query->where('stores.company_id', $companyId)->get();
        }

        $allowed = $user->stores()->where('stores.is_active', true)->pluck('stores.id');

        if ($allowed->isNotEmpty()) {
            return $query->whereIn('stores.id', $allowed)->get();
        }

        // Sin sedes asignadas se asume la de su ficha de empleado, y si no hay, todas las de la empresa.
        if ($user->employee?->store_id) {
            return $query->where('stores.id', $user->employee->store_id)->get();
        }

        return $query->get();
    }

    public static function activeStoreId(?User $user = null): ?int
    {
        return self::activeStore($user)?->id;
    }

    public static function activeStore(?User $user = null): ?Store
    {
        $user = $user ?? Auth::guard('web')->user();

        if (! $user) {
            return null;
        }

        $stores = self::availableStores($user);

        if ($stores->isEmpty()) {
            return null;
        }

        $sessionId = session(self::SESSION_KEY);

        if ($sessionId !== null) {
            $found = $stores->firstWhere('id', (int) $sessionId);

            if ($found) {
                return $found;
            }
        }

        $first = $stores->first();
        session([self::SESSION_KEY => $first->id]);

        return $first;
    }

    public static function setActiveStore(int $storeId, ?User $user = null): bool
    {
        if (! self::availableStores($user)->contains('id', $storeId)) {
            return false;
        }

        session([self::SESSION_KEY => $storeId]);

        return true;
    }

    public static function requireStoreId(?User $user = null): int
    {
        $id = self::activeStoreId($user);

        abort_if($id === null, 409, 'No tienes ninguna sede asignada. Pidele a un administrador que te habilite una.');

        return $id;
    }
}

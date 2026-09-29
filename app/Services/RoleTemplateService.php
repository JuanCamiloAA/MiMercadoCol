<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * El rol como plantilla de permisos.
 *
 * Cambiar un rol no altera a nadie por si solo: el cambio se guarda en la plantilla y se
 * ofrece aplicarlo eligiendo a quien. Lo que se aplica es la diferencia, no la plantilla
 * entera, para no borrar los ajustes propios de cada usuario.
 */
class RoleTemplateService
{
    /** Clave de sesion donde espera el cambio pendiente de propagar, por rol. */
    public const PENDING_SESSION_PREFIX = 'role_permission_diff.';

    public function __construct(protected UserPermissionService $userPermissions) {}

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return array{added: list<string>, removed: list<string>}
     */
    public function diff(array $before, array $after): array
    {
        return [
            'added' => array_values(array_diff($after, $before)),
            'removed' => array_values(array_diff($before, $after)),
        ];
    }

    /**
     * Usuarios que tienen el rol, con lo que ganaria o perderia cada uno si se le aplica.
     *
     * @param  list<string>  $added
     * @param  list<string>  $removed
     * @return list<array<string, mixed>>
     */
    public function usersForPropagation(Role $role, array $added = [], array $removed = []): array
    {
        $userIds = DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->where('model_type', (new User)->getMorphClass())
            ->pluck('model_id')
            ->all();

        if ($userIds === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'last_name', 'email', 'is_active', 'company_id'])
            ->map(function (User $user) use ($added, $removed) {
                $current = $user->permissions->pluck('name')->all();

                return [
                    'id' => $user->id,
                    'name' => $user->full_name,
                    'email' => $user->email,
                    'is_active' => (bool) $user->is_active,
                    'permissions_count' => count($current),
                    // Solo lo que de verdad cambiaria para esta persona.
                    'will_gain' => count(array_diff($added, $current)),
                    'will_lose' => count(array_intersect($removed, $current)),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Aplica la diferencia a los usuarios elegidos.
     *
     * @param  list<int>  $userIds
     * @param  list<string>  $added
     * @param  list<string>  $removed
     * @return int usuarios afectados
     */
    public function propagate(Role $role, array $userIds, array $added, array $removed, User $actor): int
    {
        if ($userIds === [] || ($added === [] && $removed === [])) {
            return 0;
        }

        $eligible = DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->where('model_type', (new User)->getMorphClass())
            ->pluck('model_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // El id llega del cliente: solo se propaga a quien de verdad tenga el rol.
        $targets = array_values(array_intersect(array_map('intval', $userIds), $eligible));

        if ($targets === []) {
            return 0;
        }

        $affected = 0;

        foreach (User::query()->whereIn('id', $targets)->get() as $user) {
            if ($user->isSuperAdmin() || ! $user->company_id) {
                continue;
            }

            if (! $actor->isSuperAdmin() && (int) $actor->company_id !== (int) $user->company_id) {
                continue;
            }

            $this->userPermissions->applyDelta($user, $added, $removed);
            $affected++;
        }

        return $affected;
    }
}

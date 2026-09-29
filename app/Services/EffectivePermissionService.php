<?php

namespace App\Services;

use App\Models\User;
use Spatie\Permission\Models\Permission;

/**
 * Que puede hacer exactamente un usuario.
 *
 * El rol es solo una plantilla, asi que la respuesta es directa: lo que tenga asignado en
 * `model_has_permissions`, ni mas ni menos.
 */
class EffectivePermissionService
{
    /**
     * @return list<string>
     */
    public function getEffectivePermissionNames(User $user): array
    {
        if ($user->isSuperAdmin()) {
            return Permission::query()->orderBy('name')->pluck('name')->all();
        }

        return $user->permissions()
            ->orderBy('name')
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }

    public function userHasEffectivePermission(User $user, string $permissionName): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return in_array($permissionName, $this->getEffectivePermissionNames($user), true);
    }
}

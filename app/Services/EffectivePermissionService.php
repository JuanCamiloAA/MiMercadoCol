<?php

namespace App\Services;

use App\Models\User;
use App\Services\Membership\Entitlement;
use App\Services\Membership\EntitlementService;
use App\Support\TenantContext;
use Spatie\Permission\Models\Permission;

/**
 * Que puede hacer exactamente un usuario.
 *
 * El rol es solo una plantilla: cuenta lo que tenga asignado en `model_has_permissions`. Y el
 * plan de su empresa es el techo: de eso, solo lo que el plan incluye. Lo que quedo fuera no se
 * borra, se ignora; si la empresa sube de plan lo recupera sin que nadie lo vuelva a asignar.
 *
 * Con esto quedan cubiertos de una vez `CheckPermission`, la prop de permisos del front, el
 * menu y cada `can()` del servidor. El super usuario no se limita.
 */
class EffectivePermissionService
{
    public function __construct(protected EntitlementService $entitlements) {}

    /**
     * @return list<string>
     */
    public function getEffectivePermissionNames(User $user): array
    {
        if ($user->isSuperAdmin()) {
            return Permission::query()->orderBy('name')->pluck('name')->all();
        }

        $assigned = $this->assignedPermissionNames($user);
        $entitlement = $this->entitlementFor($user);

        return $entitlement ? $entitlement->filter($assigned) : $assigned;
    }

    public function userHasEffectivePermission(User $user, string $permissionName): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return in_array($permissionName, $this->getEffectivePermissionNames($user), true);
    }

    /**
     * Lo que tiene asignado, este o no en el plan.
     *
     * @return list<string>
     */
    public function assignedPermissionNames(User $user): array
    {
        return $user->permissions()
            ->orderBy('name')
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Si el usuario tiene el permiso asignado pero el plan de su empresa no lo incluye: es el
     * caso en que se responde con el mensaje del plan y no con «no tienes permiso».
     */
    public function blockedByPlan(User $user, string $permissionName): bool
    {
        if ($user->isSuperAdmin()) {
            return false;
        }

        $entitlement = $this->entitlementFor($user);

        return $entitlement !== null
            && ! $entitlement->allows($permissionName)
            && in_array($permissionName, $this->assignedPermissionNames($user), true);
    }

    /** El plan de la empresa activa. La empresa se lee con el guard `web` explicito. */
    public function entitlementFor(User $user): ?Entitlement
    {
        return $this->entitlements->forCompanyId(TenantContext::activeCompanyId($user));
    }
}

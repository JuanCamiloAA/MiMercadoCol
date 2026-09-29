<?php

namespace App\Support;

use App\Models\User;

/**
 * Quien puede escuchar que canal.
 *
 * Es la pieza mas delicada del tiempo real: un canal mal autorizado le manda a una empresa lo
 * que pasa en otra, en vivo y sin dejar rastro en ningun listado. Por eso todas las respuestas
 * pasan por aqui y no por condiciones sueltas en `channels.php`.
 *
 * Tres preguntas, siempre en este orden:
 *   1. ¿El canal es de su empresa?
 *   2. ¿Tiene acceso a esa sede?
 *   3. ¿Tiene el permiso que corresponde a lo que se transmite?
 *
 * La tercera no sobra: escuchar el canal de caja es ver los montos del turno pasar en vivo, y
 * eso es la misma informacion que protege `registers.close.view`.
 */
final class ChannelAccess
{
    /** El guard `web` es el del personal; la tienda en linea usa `customer` y no escucha nada. */
    public static function company(?User $user, int|string $companyId): bool
    {
        if (! $user) {
            return false;
        }

        $companyId = (int) $companyId;

        if ($user->isSuperAdmin()) {
            $selected = TenantContext::superAdminSelectedCompanyId();

            // En consolidado ve todo; con una empresa elegida, solo esa.
            return $selected === null || $selected === $companyId;
        }

        return (int) $user->company_id === $companyId;
    }

    /**
     * Ademas de la empresa, la sede tiene que estar entre las suyas.
     *
     * Un cajero de la sede Centro no puede quedarse escuchando lo que se vende en Norte.
     */
    public static function store(?User $user, int|string $companyId, int|string $storeId): bool
    {
        if (! self::company($user, $companyId)) {
            return false;
        }

        return StoreContext::availableStores($user)->contains('id', (int) $storeId);
    }

    /**
     * Sede mas permiso.
     *
     * @param  list<string>  $permissions  Basta con uno de ellos.
     */
    public static function storeWithAny(?User $user, int|string $companyId, int|string $storeId, array $permissions): bool
    {
        if (! self::store($user, $companyId, $storeId)) {
            return false;
        }

        return self::hasAny($user, $permissions);
    }

    /**
     * Empresa mas permiso, para lo que no depende de una sede: precios, catalogo, cupones.
     *
     * @param  list<string>  $permissions
     */
    public static function companyWithAny(?User $user, int|string $companyId, array $permissions): bool
    {
        return self::company($user, $companyId) && self::hasAny($user, $permissions);
    }

    /**
     * @param  list<string>  $permissions
     */
    protected static function hasAny(?User $user, array $permissions): bool
    {
        if ($permissions === []) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($user?->can($permission)) {
                return true;
            }
        }

        return false;
    }
}

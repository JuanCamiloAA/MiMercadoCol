<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reparte los permisos de bodegas y traslados en las bases que ya estaban en uso.
 *
 * Agregarlos al catalogo no se los da a nadie: lo que manda son los permisos directos del
 * usuario. Se reparten siguiendo lo que cada quien ya podia hacer: quien trasladaba mercancia
 * sigue trasladandola, ahora con documento, y quien edita sedes administra sus bodegas.
 */
return new class extends Migration
{
    /** @var list<string> */
    protected const TRANSFERS = [
        'inventory.transfers.view',
        'inventory.transfers.create',
        'inventory.transfers.dispatch',
        'inventory.transfers.receive',
        'inventory.transfers.cancel',
    ];

    /** @var list<string> */
    protected const WAREHOUSES = [
        'stores.warehouses.view',
        'stores.warehouses.create',
        'stores.warehouses.edit',
        'stores.warehouses.deactivate',
    ];

    public function up(): void
    {
        $this->grant(self::TRANSFERS, 'inventory.index.transfer');
        $this->grant(self::WAREHOUSES, 'stores.index.edit');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = Permission::query()
            ->whereIn('name', [...self::TRANSFERS, ...self::WAREHOUSES])
            ->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Le da los permisos nuevos a todo el que ya tenga el viejo.
     *
     * @param  list<string>  $names
     */
    protected function grant(array $names, string $basedOn): void
    {
        $source = Permission::query()->where('name', $basedOn)->first();

        if (! $source) {
            return;
        }

        $created = collect($names)->map(
            fn (string $name) => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web'])
        );

        $roleIds = DB::table('role_has_permissions')->where('permission_id', $source->id)->pluck('role_id');
        $userIds = DB::table('model_has_permissions')
            ->where('permission_id', $source->id)
            ->where('model_type', User::class)
            ->pluck('model_id');

        foreach ($created as $permission) {
            foreach ($roleIds as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permission->id,
                    'role_id' => $roleId,
                ]);
            }

            foreach ($userIds as $userId) {
                DB::table('model_has_permissions')->insertOrIgnore([
                    'permission_id' => $permission->id,
                    'model_type' => User::class,
                    'model_id' => $userId,
                ]);
            }
        }
    }
};

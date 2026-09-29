<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reparte el permiso de mirar las existencias de otras sedes.
 *
 * Se lo lleva quien ya podia ajustar inventario, que es quien necesita saber si lo que le
 * falta esta en la sede de al lado antes de pedirselo al proveedor. Es de solo lectura: ver
 * no es mover, y mover sigue pidiendo acceso a la sede.
 */
return new class extends Migration
{
    protected const PERMISSION = 'inventory.index.view_other_stores';

    protected const BASED_ON = 'inventory.index.adjust';

    public function up(): void
    {
        $source = Permission::query()->where('name', self::BASED_ON)->first();

        if (! $source) {
            return;
        }

        $permission = Permission::query()->firstOrCreate(['name' => self::PERMISSION, 'guard_name' => 'web']);

        foreach (DB::table('role_has_permissions')->where('permission_id', $source->id)->pluck('role_id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $permission->id,
                'role_id' => $roleId,
            ]);
        }

        $userIds = DB::table('model_has_permissions')
            ->where('permission_id', $source->id)
            ->where('model_type', User::class)
            ->pluck('model_id');

        foreach ($userIds as $userId) {
            DB::table('model_has_permissions')->insertOrIgnore([
                'permission_id' => $permission->id,
                'model_type' => User::class,
                'model_id' => $userId,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $id = Permission::query()->where('name', self::PERMISSION)->value('id');

        if (! $id) {
            return;
        }

        DB::table('role_has_permissions')->where('permission_id', $id)->delete();
        DB::table('model_has_permissions')->where('permission_id', $id)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reparte el permiso del historial de ventas en las bases que ya estaban en uso.
 *
 * El rol es una plantilla y los permisos que mandan son los directos del usuario, asi que
 * agregar el permiso al catalogo no se lo da a nadie. Aqui se le entrega a quien ya tenia
 * la responsabilidad equivalente: administracion, supervision y contabilidad. En una base
 * recien creada no hay nadie todavia y la migracion no hace nada.
 */
return new class extends Migration
{
    protected const PERMISSION = 'sales.index.view';

    protected const ROLES = ['super_admin', 'admin', 'supervisor', 'auxiliar_contable'];

    public function up(): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['name' => self::PERMISSION, 'guard_name' => 'web'],
        );

        $roles = Role::query()->whereIn('name', self::ROLES)->get();

        foreach ($roles as $role) {
            $role->givePermissionTo($permission);
        }

        User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', self::ROLES))
            ->get()
            ->each(fn (User $user) => $user->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permission = Permission::query()->where('name', self::PERMISSION)->where('guard_name', 'web')->first();

        if (! $permission) {
            return;
        }

        DB::table('role_has_permissions')->where('permission_id', $permission->id)->delete();
        DB::table('model_has_permissions')->where('permission_id', $permission->id)->delete();
        $permission->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

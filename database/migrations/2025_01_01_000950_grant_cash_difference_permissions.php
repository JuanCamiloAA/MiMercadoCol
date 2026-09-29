<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reparte los permisos de diferencias de caja en las bases que ya estaban en uso.
 *
 * Ver los descuadres lo necesita tambien quien cuadra la plata todos los dias, asi que el
 * auxiliar contable los ve; decidir que se hace con ellos es de administracion y supervision.
 */
return new class extends Migration
{
    protected const VIEWERS = ['super_admin', 'admin', 'supervisor', 'auxiliar_contable'];

    protected const RESOLVERS = ['super_admin', 'admin', 'supervisor'];

    public function up(): void
    {
        $view = Permission::query()->firstOrCreate(['name' => 'registers.differences.view', 'guard_name' => 'web']);
        $resolve = Permission::query()->firstOrCreate(['name' => 'registers.differences.resolve', 'guard_name' => 'web']);

        $this->give(self::VIEWERS, $view);
        $this->give(self::RESOLVERS, $resolve);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = Permission::query()
            ->whereIn('name', ['registers.differences.view', 'registers.differences.resolve'])
            ->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  list<string>  $roles
     */
    protected function give(array $roles, Permission $permission): void
    {
        Role::query()->whereIn('name', $roles)->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $roles))
            ->get()
            ->each(fn (User $user) => $user->givePermissionTo($permission));
    }
};

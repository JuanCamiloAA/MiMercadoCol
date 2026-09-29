<?php

use App\Imports\ImportRegistry;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reparte los permisos de importacion de maestras en las bases que ya estaban en uso.
 *
 * Cargar un archivo cambia cientos de registros de un golpe, asi que nace solo para
 * administracion y super usuario. Cualquier otro rol lo puede recibir desde Roles: el
 * permiso existe en el catalogo de los siete modulos y es asignable uno por uno.
 */
return new class extends Migration
{
    protected const ROLES = ['super_admin', 'admin'];

    public function up(): void
    {
        $permissions = $this->permissions();

        Role::query()->whereIn('name', self::ROLES)->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions));

        User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', self::ROLES))
            ->get()
            ->each(fn (User $user) => $user->givePermissionTo($permissions));

        $this->revokeFromEveryoneElse($permissions->pluck('id'));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Deja el mismo criterio en los siete modulos.
     *
     * Antes de esto solo Articulos tenia importacion y el supervisor la heredaba; con la
     * importacion en todas las maestras el liston sube a administracion, que es lo pedido.
     * Devolversela a un rol es un clic en la pantalla de Roles.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $permissionIds
     */
    protected function revokeFromEveryoneElse($permissionIds): void
    {
        $keep = Role::query()->whereIn('name', self::ROLES)->pluck('id');

        DB::table('role_has_permissions')
            ->whereIn('permission_id', $permissionIds)
            ->whereNotIn('role_id', $keep)
            ->delete();

        $keepUsers = User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', self::ROLES))
            ->pluck('id');

        DB::table('model_has_permissions')
            ->whereIn('permission_id', $permissionIds)
            ->where('model_type', User::class)
            ->whereNotIn('model_id', $keepUsers)
            ->delete();
    }

    public function down(): void
    {
        $ids = Permission::query()->whereIn('name', $this->names())->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Permission>
     */
    protected function permissions()
    {
        return collect($this->names())->map(
            fn (string $name) => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web'])
        );
    }

    /**
     * @return list<string>
     */
    protected function names(): array
    {
        return array_map(fn (string $module) => "{$module}.index.import", ImportRegistry::modules());
    }
};

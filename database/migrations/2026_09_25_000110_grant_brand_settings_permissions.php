<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reparte los permisos de Ajustes que nacieron con la marca configurable.
 *
 * `settings.index.brand` (marca, apariencia y menu) y `settings.index.pos_layout` (caja y
 * tiquete) se separan de `settings.index.edit`, que queda para las reglas de caja y el credito.
 * Agregarlos al catalogo no se los da a nadie —mandan los permisos directos del usuario—, asi
 * que aqui se entregan a quien ya podia cambiar los ajustes: sin esto, en una base en uso nadie
 * podria entrar a las pestanas nuevas. En una base recien creada no hay nadie y lo hace el seeder.
 */
return new class extends Migration
{
    protected const PERMISSIONS = ['settings.index.brand', 'settings.index.pos_layout'];

    protected const BASED_ON = 'settings.index.edit';

    protected const ROLES = ['super_admin', 'admin'];

    public function up(): void
    {
        $source = Permission::query()->where('name', self::BASED_ON)->where('guard_name', 'web')->first();

        // Base recien creada: todavia no hay permisos; los siembra `PermissionSeeder`.
        if (! $source) {
            return;
        }

        $permissions = collect(self::PERMISSIONS)->map(
            fn (string $name) => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web'])
        );

        Role::query()->whereIn('name', self::ROLES)->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions));

        User::query()
            ->whereHas('permissions', fn ($query) => $query->where('name', self::BASED_ON))
            ->get()
            ->each(fn (User $user) => $user->givePermissionTo($permissions));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = Permission::query()->whereIn('name', self::PERMISSIONS)->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        Permission::query()->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permisos de la importacion de catalogo.
 *
 * Traer mil articulos de una fuente externa toca el catalogo entero, asi que va a quien ya
 * administra la empresa. El supervisor puede mirar y previsualizar, pero no lanzarla: mirar no
 * cuesta nada y lanzarla si.
 *
 * Los permisos son directos (`model_has_permissions`), no heredados del rol: el rol solo sirve
 * de plantilla al crear el usuario, asi que hay que repartirlos uno por uno a los que existen.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    protected const GRANTS = [
        'super_admin' => ['products.catalog_import.view', 'products.catalog_import.run', 'products.catalog_import.cancel'],
        'admin' => ['products.catalog_import.view', 'products.catalog_import.run', 'products.catalog_import.cancel'],
        'supervisor' => ['products.catalog_import.view'],
    ];

    public function up(): void
    {
        $all = collect(self::GRANTS)->flatten()->unique();

        $permissions = $all->mapWithKeys(fn (string $name) => [
            $name => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']),
        ]);

        foreach (self::GRANTS as $roleName => $names) {
            $granted = $permissions->only($names)->values();

            Role::query()->where('name', $roleName)->get()
                ->each(fn (Role $role) => $role->givePermissionTo($granted));

            User::query()
                ->whereHas('roles', fn ($query) => $query->where('name', $roleName))
                ->get()
                ->each(fn (User $user) => $user->givePermissionTo($granted));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = Permission::query()
            ->whereIn('name', collect(self::GRANTS)->flatten()->unique()->all())
            ->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

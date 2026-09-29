<?php

use App\Helpers\PermissionHelper;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reparte los permisos de Atributos en las bases que ya estaban en uso.
 *
 * Por equivalencia: quien veia el catalogo ve las caracteristicas; quien editaba articulos las
 * administra. Crear y editar articulos con subarticulos usa los permisos de articulos que ya
 * existian: no hay otros.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    protected const BY_PERMISSION = [
        'products.index.view' => ['products.attributes.view'],
        'products.index.edit' => ['products.attributes.manage'],
    ];

    protected const ADMIN_ROLES = ['admin', 'super_admin'];

    protected const TEMPLATES = ['super_admin', 'admin', 'supervisor', 'cajero', 'bodega', 'auxiliar_contable'];

    protected const NEW = ['products.attributes.view', 'products.attributes.manage'];

    public function up(): void
    {
        // Base recien creada: los permisos los siembra `PermissionSeeder`.
        if (! Permission::query()->where('name', 'products.index.view')->where('guard_name', 'web')->exists()) {
            return;
        }

        $permissions = collect(self::NEW)->mapWithKeys(fn (string $name) => [
            $name => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']),
        ]);

        foreach (self::BY_PERMISSION as $source => $granted) {
            User::query()
                ->whereHas('permissions', fn ($query) => $query->where('name', $source))
                ->get()
                ->each(fn (User $user) => $user->givePermissionTo($permissions->only($granted)->values()));
        }

        User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', self::ADMIN_ROLES))
            ->get()
            ->each(fn (User $user) => $user->givePermissionTo($permissions->values()));

        foreach (self::TEMPLATES as $name) {
            $granted = array_values(array_intersect(self::NEW, PermissionHelper::presetPermissions($name)));

            if ($granted === []) {
                continue;
            }

            Role::query()->where('name', $name)->get()
                ->each(fn (Role $role) => $role->givePermissionTo($permissions->only($granted)->values()));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = Permission::query()->whereIn('name', self::NEW)->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        Permission::query()->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

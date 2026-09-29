<?php

use App\Helpers\PermissionHelper;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reparte los permisos de Servicios y de los ajustes de servicio en caja, en las bases que ya
 * estaban en uso.
 *
 * Por equivalencia: quien podia algo con los articulos puede lo mismo con los servicios, y quien
 * vendia puede quitar, bajar o subir los articulos de un servicio en la venta. Agregarle a un
 * servicio un articulo que no trae queda solo para la administracion; los demas lo piden con
 * PIN. Las plantillas de rol quedan como las pinta `PermissionHelper::presetPermissions`.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> Permiso que ya se tiene => los nuevos que le tocan. */
    protected const BY_PERMISSION = [
        'products.index.view' => ['services.index.view'],
        'products.index.create' => ['services.index.create'],
        'products.index.edit' => ['services.index.edit'],
        'products.index.delete' => ['services.index.delete'],
        'products.index.export' => ['services.index.export'],
        'products.index.import' => ['services.index.import'],
        'products.show.view' => ['services.show.view'],
        'products.show.view_cost' => ['services.show.view_cost'],
        'products.show.edit_price' => ['services.show.edit_price'],
        'pos.sale.create' => ['pos.sale.service_remove', 'pos.sale.service_reduce', 'pos.sale.service_increase'],
    ];

    protected const ADMIN_ROLES = ['admin', 'super_admin'];

    protected const TEMPLATES = ['super_admin', 'admin', 'supervisor', 'cajero', 'bodega', 'auxiliar_contable'];

    public function up(): void
    {
        // Base recien creada: los permisos los siembra `PermissionSeeder`.
        if (! Permission::query()->where('name', 'products.index.view')->where('guard_name', 'web')->exists()) {
            return;
        }

        $all = $this->newPermissions();
        $permissions = collect($all)->mapWithKeys(fn (string $name) => [
            $name => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']),
        ]);

        foreach (self::BY_PERMISSION as $source => $granted) {
            User::query()
                ->whereHas('permissions', fn ($query) => $query->where('name', $source))
                ->get()
                ->each(fn (User $user) => $user->givePermissionTo($permissions->only($granted)->values()));
        }

        // La administracion tiene todo, por definicion.
        User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', self::ADMIN_ROLES))
            ->get()
            ->each(fn (User $user) => $user->givePermissionTo($permissions->values()));

        foreach (self::TEMPLATES as $name) {
            $granted = array_values(array_intersect($all, PermissionHelper::presetPermissions($name)));

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
        $ids = Permission::query()->whereIn('name', $this->newPermissions())->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        Permission::query()->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return list<string> */
    protected function newPermissions(): array
    {
        return [
            ...PermissionHelper::modulePermissions('services'),
            'pos.sale.service_remove',
            'pos.sale.service_reduce',
            'pos.sale.service_increase',
            'pos.sale.service_add',
        ];
    }
};

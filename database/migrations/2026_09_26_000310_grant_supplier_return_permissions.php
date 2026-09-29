<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reparte los permisos de devoluciones a proveedor en las bases que ya estaban en uso.
 *
 * Por equivalencia: quien recibia mercancia arma y despacha devoluciones, quien anulaba ordenes
 * anula borradores, y la respuesta del proveedor (que es plata) queda en administracion y
 * contabilidad. Las plantillas de rol quedan como las pinta `PermissionHelper::presetPermissions`.
 * En una base recien creada no hay nadie y los reparte el seeder.
 */
return new class extends Migration
{
    protected const ALL = [
        'purchases.returns.view',
        'purchases.returns.create',
        'purchases.returns.dispatch',
        'purchases.returns.respond',
        'purchases.returns.cancel',
    ];

    /** @var array<string, list<string>> Permiso que ya se tiene => los nuevos que le tocan. */
    protected const BY_PERMISSION = [
        'purchases.index.receive' => ['purchases.returns.view', 'purchases.returns.create', 'purchases.returns.dispatch'],
        'purchases.index.cancel' => ['purchases.returns.cancel'],
    ];

    /** @var array<string, list<string>> Rol => los nuevos que le tocan a sus usuarios. */
    protected const BY_ROLE = [
        'super_admin' => self::ALL,
        'admin' => self::ALL,
        'auxiliar_contable' => ['purchases.returns.view', 'purchases.returns.respond'],
    ];

    /** @var array<string, list<string>> Plantilla de rol => sus permisos nuevos. */
    protected const TEMPLATES = [
        'super_admin' => self::ALL,
        'admin' => self::ALL,
        'supervisor' => self::ALL,
        'bodega' => ['purchases.returns.view', 'purchases.returns.create', 'purchases.returns.dispatch', 'purchases.returns.cancel'],
        'auxiliar_contable' => ['purchases.returns.view', 'purchases.returns.respond'],
    ];

    public function up(): void
    {
        // Base recien creada: los permisos los siembra `PermissionSeeder`.
        if (! Permission::query()->where('name', 'purchases.index.view')->where('guard_name', 'web')->exists()) {
            return;
        }

        $permissions = collect(self::ALL)->mapWithKeys(fn (string $name) => [
            $name => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']),
        ]);

        foreach (self::BY_PERMISSION as $source => $granted) {
            User::query()
                ->whereHas('permissions', fn ($query) => $query->where('name', $source))
                ->get()
                ->each(fn (User $user) => $user->givePermissionTo($permissions->only($granted)->values()));
        }

        foreach (self::BY_ROLE as $role => $granted) {
            User::query()
                ->whereHas('roles', fn ($query) => $query->where('name', $role))
                ->get()
                ->each(fn (User $user) => $user->givePermissionTo($permissions->only($granted)->values()));
        }

        foreach (self::TEMPLATES as $name => $granted) {
            Role::query()->where('name', $name)->get()
                ->each(fn (Role $role) => $role->givePermissionTo($permissions->only($granted)->values()));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = Permission::query()->whereIn('name', self::ALL)->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        Permission::query()->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

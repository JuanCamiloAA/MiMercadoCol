<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reparte los permisos de Membresia y Plazos de pago en las bases que ya estaban en uso.
 *
 * Agregarlos al catalogo no se los da a nadie: mandan los permisos directos. Se entregan a los
 * roles de administracion y a quien ya podia cambiar los ajustes de la empresa. En una base
 * recien creada no hay nadie y los reparte el seeder.
 */
return new class extends Migration
{
    protected const PERMISSIONS = [
        'settings.membership.view',
        'settings.membership.manage_payment',
        'payment_terms.index.view',
        'payment_terms.index.create',
        'payment_terms.index.edit',
        'payment_terms.index.deactivate',
    ];

    protected const BASED_ON = 'settings.index.edit';

    protected const ROLES = ['super_admin', 'admin'];

    public function up(): void
    {
        $source = Permission::query()->where('name', self::BASED_ON)->where('guard_name', 'web')->first();

        // Base recien creada: los permisos los siembra `PermissionSeeder`.
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

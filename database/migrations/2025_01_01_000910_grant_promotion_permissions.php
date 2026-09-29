<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reparte los permisos de promociones en las bases que ya estaban en uso.
 *
 * Quien ya administra el surtido de la tienda es quien pone las promociones, asi que se le
 * entregan a los mismos roles que manejan la vitrina.
 */
return new class extends Migration
{
    protected const ROLES = ['super_admin', 'admin', 'supervisor'];

    protected const PERMISSIONS = [
        'promotions.index.view',
        'promotions.index.create',
        'promotions.index.edit',
        'promotions.index.delete',
    ];

    public function up(): void
    {
        $permissions = collect(self::PERMISSIONS)->map(
            fn (string $name) => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web'])
        );

        Role::query()->whereIn('name', self::ROLES)->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions));

        User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', self::ROLES))
            ->get()
            ->each(fn (User $user) => $user->givePermissionTo($permissions));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = Permission::query()->whereIn('name', self::PERMISSIONS)->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

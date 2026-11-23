<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reparte los permisos de Plataforma > Usuarios (de plataforma y de empresas) en las bases que ya
 * estaban en uso.
 *
 * Son de la plataforma: solo el super usuario. Se le dan al rol y a quien ya podia crear empresas.
 * En una base recien creada los siembra `PermissionSeeder` y se los da `UserSeeder`.
 */
return new class extends Migration
{
    protected const PERMISSIONS = [
        'platform.users.view',
        'platform.users.create',
        'platform.users.edit',
        'platform.users.deactivate',
        'platform.users.reset_password',
        'platform.company_users.view',
        'platform.company_users.create',
        'platform.company_users.edit',
        'platform.company_users.deactivate',
        'platform.company_users.reset_password',
    ];

    protected const BASED_ON = 'companies.index.create';

    public function up(): void
    {
        $source = Permission::query()->where('name', self::BASED_ON)->where('guard_name', 'web')->first();

        if (! $source) {
            return;
        }

        $permissions = collect(self::PERMISSIONS)->map(
            fn (string $name) => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web'])
        );

        Role::query()->where('name', 'super_admin')->get()
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

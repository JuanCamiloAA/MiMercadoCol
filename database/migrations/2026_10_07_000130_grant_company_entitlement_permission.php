<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reparte `companies.index.entitlements` (excepciones al plan y precio especial) en las bases que
 * ya estaban en uso.
 *
 * Es de la plataforma: solo el super usuario. Se le da al rol y a quien ya podia editar
 * empresas. En una base recien creada lo siembra `PermissionSeeder`.
 */
return new class extends Migration
{
    protected const PERMISSIONS = ['companies.index.entitlements'];

    protected const BASED_ON = 'companies.index.edit';

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

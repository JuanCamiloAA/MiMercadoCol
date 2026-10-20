<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reparte «Tienda en línea › Suscriptores» en las bases que ya estaban en uso.
 *
 * Agregarlo al catalogo no se lo da a nadie: mandan los permisos directos. Son correos de
 * clientes (datos personales), asi que va solo a los roles de administracion y a quien hoy publica
 * la tienda, que es quien decide encender el formulario. En una base recien creada lo siembra
 * `PermissionSeeder`.
 */
return new class extends Migration
{
    protected const PERMISSION = 'storefront.newsletter.view';

    public function up(): void
    {
        if (! Permission::query()->where('name', 'storefront.editor.publish')->where('guard_name', 'web')->exists()) {
            return;
        }

        $permission = Permission::query()->firstOrCreate(['name' => self::PERMISSION, 'guard_name' => 'web']);

        Role::query()->whereIn('name', ['super_admin', 'admin'])->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        User::query()
            ->whereHas('permissions', fn ($query) => $query->where('name', 'storefront.editor.publish'))
            ->get()
            ->each(fn (User $user) => $user->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = Permission::query()->where('name', self::PERMISSION)->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        Permission::query()->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

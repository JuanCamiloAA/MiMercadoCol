<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permisos del editor nuevo y de los cupones.
 *
 * El editor viejo tenia un solo permiso de edicion; el nuevo separa contenido, aspecto y
 * funciones, porque no es lo mismo cambiar el color de la marca que tocar el costo del
 * domicilio. Quien ya podia editar recibe los tres, para que nadie pierda lo que tenia.
 *
 * Los permisos son directos (`model_has_permissions`), no heredados del rol: el rol solo sirve
 * de plantilla al crear el usuario, asi que hay que repartirlos uno por uno a los que existen.
 */
return new class extends Migration
{
    protected const ROLES = ['super_admin', 'admin', 'supervisor'];

    protected const PERMISSIONS = [
        'storefront.editor.content',
        'storefront.editor.appearance',
        'storefront.editor.settings',
        'storefront.editor.history',
        'storefront.editor.copy_store',
        'coupons.index.view',
        'coupons.index.create',
        'coupons.index.edit',
        'coupons.index.delete',
    ];

    /** Quedo sin ruta al reemplazar el editor: si se deja, aparece en el catalogo sin hacer nada. */
    protected const RETIRED = ['storefront.editor.edit'];

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

        $retired = Permission::query()->whereIn('name', self::RETIRED)->pluck('id');

        if ($retired->isNotEmpty()) {
            DB::table('role_has_permissions')->whereIn('permission_id', $retired)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $retired)->delete();
            Permission::query()->whereIn('id', $retired)->delete();
        }

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

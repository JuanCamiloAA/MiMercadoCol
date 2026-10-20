<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reparte los permisos de Medios de pago en las bases que ya estaban en uso.
 *
 * Agregarlos al catalogo no se los da a nadie: mandan los permisos directos. El modulo se le da a
 * quien hoy administra la empresa (quien podia cambiar las reglas de caja y credito) y a los roles
 * de administracion. Cobrar con QR, link o datafono (`pos.sale.gateway`) se le da a quien ya cobra
 * en la caja, que es quien lo usa. En una base recien creada los siembra `PermissionSeeder`.
 */
return new class extends Migration
{
    protected const MODULE = [
        'payment_methods.index.view',
        'payment_methods.index.create',
        'payment_methods.index.edit',
        'payment_methods.index.delete',
        'payment_methods.gateways.view',
        'payment_methods.gateways.edit',
        'payment_methods.gateways.test',
        'payment_methods.terminals.view',
        'payment_methods.terminals.edit',
        'payment_methods.transactions.view',
        'payment_methods.transactions.reconcile',
    ];

    protected const POS = ['pos.sale.gateway'];

    public function up(): void
    {
        if (! Permission::query()->where('name', 'settings.index.edit')->where('guard_name', 'web')->exists()) {
            return;
        }

        $module = collect(self::MODULE)->map(fn (string $name) => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        $pos = collect(self::POS)->map(fn (string $name) => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));

        Role::query()->whereIn('name', ['super_admin', 'admin'])->get()
            ->each(fn (Role $role) => $role->givePermissionTo([...$module, ...$pos]));

        Role::query()->whereIn('name', ['supervisor', 'cajero'])->get()
            ->each(fn (Role $role) => $role->givePermissionTo($pos));

        User::query()
            ->whereHas('permissions', fn ($query) => $query->where('name', 'settings.index.edit'))
            ->get()
            ->each(fn (User $user) => $user->givePermissionTo($module));

        User::query()
            ->whereHas('permissions', fn ($query) => $query->where('name', 'pos.sale.create'))
            ->get()
            ->each(fn (User $user) => $user->givePermissionTo($pos));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = Permission::query()->whereIn('name', [...self::MODULE, ...self::POS])->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        Permission::query()->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

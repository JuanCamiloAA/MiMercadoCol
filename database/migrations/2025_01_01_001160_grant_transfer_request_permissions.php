<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reparte los permisos de solicitudes y traslado directo en las bases que ya estaban en uso.
 *
 * Se siguen los permisos que cada quien ya tenia: quien creaba traslados puede pedirlos, quien
 * los despachaba puede atender lo que le piden, y el traslado directo —que despacha y recibe en
 * el mismo acto— queda solo para quien ya podia hacer las dos cosas por separado.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> Permiso nuevo => permisos que hay que tener todos. */
    protected const GRANTS = [
        'inventory.transfer_requests.view' => ['inventory.transfers.view'],
        'inventory.transfer_requests.create' => ['inventory.transfers.create'],
        'inventory.transfer_requests.cancel' => ['inventory.transfers.create'],
        'inventory.transfer_requests.fulfill' => ['inventory.transfers.dispatch'],
        'inventory.transfers.direct' => ['inventory.transfers.dispatch', 'inventory.transfers.receive'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $name => $basedOn) {
            $this->grant($name, $basedOn);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = Permission::query()->whereIn('name', array_keys(self::GRANTS))->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Le da el permiso nuevo a quien ya tenga todos los de la lista.
     *
     * @param  list<string>  $basedOn
     */
    protected function grant(string $name, array $basedOn): void
    {
        $sources = Permission::query()->whereIn('name', $basedOn)->pluck('id');

        // En una base recien creada todavia no hay permisos: los siembra el seeder.
        if ($sources->count() !== count($basedOn)) {
            return;
        }

        $permission = Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);

        $roleIds = DB::table('role_has_permissions')
            ->whereIn('permission_id', $sources)
            ->groupBy('role_id')
            ->havingRaw('COUNT(DISTINCT permission_id) = ?', [$sources->count()])
            ->pluck('role_id');

        $userIds = DB::table('model_has_permissions')
            ->whereIn('permission_id', $sources)
            ->where('model_type', User::class)
            ->groupBy('model_id')
            ->havingRaw('COUNT(DISTINCT permission_id) = ?', [$sources->count()])
            ->pluck('model_id');

        foreach ($roleIds as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $permission->id,
                'role_id' => $roleId,
            ]);
        }

        foreach ($userIds as $userId) {
            DB::table('model_has_permissions')->insertOrIgnore([
                'permission_id' => $permission->id,
                'model_type' => User::class,
                'model_id' => $userId,
            ]);
        }
    }
};

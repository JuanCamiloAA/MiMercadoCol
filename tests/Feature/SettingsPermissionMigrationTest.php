<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * En una base en uso los permisos nuevos de Ajustes no los tiene nadie: la migracion los reparte
 * a quien ya podia cambiar los ajustes.
 */
class SettingsPermissionMigrationTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected const NEW = ['settings.index.brand', 'settings.index.pos_layout'];

    public function test_quien_cambiaba_ajustes_recibe_marca_y_caja_y_el_supervisor_no_recibe_marca(): void
    {
        $this->seedEnvironment();

        $admin = $this->makeUser('admin');
        $supervisor = $this->makeUser('supervisor');
        $custom = $this->makeUser('cajero');
        $custom->givePermissionTo('settings.index.edit');

        // Como estaba la base antes: los permisos nuevos no existian para nadie.
        $ids = Permission::query()->whereIn('name', self::NEW)->pluck('id');
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($admin->fresh()->hasDirectPermission('settings.index.brand'));

        $migration = require database_path('migrations/2026_09_25_000110_grant_brand_settings_permissions.php');
        $migration->up();

        foreach (self::NEW as $permission) {
            $this->assertTrue($admin->fresh()->hasDirectPermission($permission), "El administrador no recibio {$permission}.");
            $this->assertTrue($custom->fresh()->hasDirectPermission($permission), "Quien tenia settings.index.edit no recibio {$permission}.");
        }

        $this->assertFalse($supervisor->fresh()->hasDirectPermission('settings.index.brand'));
        $this->assertFalse($supervisor->fresh()->can('settings.index.brand'));
    }

    public function test_en_una_base_nueva_el_supervisor_trae_caja_pero_no_marca(): void
    {
        $this->seedEnvironment();
        $supervisor = $this->makeUser('supervisor');

        $this->assertTrue($supervisor->can('settings.index.pos_layout'));
        $this->assertFalse($supervisor->can('settings.index.brand'));
        $this->assertFalse($supervisor->can('settings.index.edit'));
    }
}

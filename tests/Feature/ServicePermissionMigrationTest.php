<?php

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * En una base en uso los permisos de Servicios no los tiene nadie: la migracion los reparte por
 * equivalencia con los de Articulos y de la caja.
 */
class ServicePermissionMigrationTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected const NEW = [
        'services.index.view', 'services.index.create', 'services.index.edit', 'services.index.delete',
        'services.index.export', 'services.index.import', 'services.show.view', 'services.show.view_cost',
        'services.show.edit_price', 'pos.sale.service_remove', 'pos.sale.service_reduce',
        'pos.sale.service_increase', 'pos.sale.service_add',
    ];

    public function test_reparte_por_equivalencia(): void
    {
        $this->seedEnvironment();

        $admin = $this->makeUser('admin');
        $cajero = $this->makeUser('cajero');
        $contable = $this->makeUser('auxiliar_contable');
        $catalogo = $this->makeUser('domiciliario');
        $catalogo->givePermissionTo(['products.index.view', 'products.index.edit', 'products.show.edit_price']);

        // Como estaba la base antes: los permisos nuevos no existian para nadie.
        $ids = Permission::query()->whereIn('name', self::NEW)->pluck('id');
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $migration = require database_path('migrations/2026_09_26_000420_grant_service_permissions.php');
        $migration->up();

        // El administrador tiene todo, agregar incluido.
        foreach (self::NEW as $permission) {
            $this->assertTrue($admin->fresh()->hasDirectPermission($permission), "El administrador no recibio {$permission}.");
        }

        // El cajero vende: quita, baja y sube, pero no agrega.
        $this->assertTrue($cajero->fresh()->hasDirectPermission('services.index.view'));
        $this->assertTrue($cajero->fresh()->hasDirectPermission('pos.sale.service_remove'));
        $this->assertTrue($cajero->fresh()->hasDirectPermission('pos.sale.service_increase'));
        $this->assertFalse($cajero->fresh()->hasDirectPermission('pos.sale.service_add'));
        $this->assertFalse($cajero->fresh()->hasDirectPermission('services.index.create'));

        // Equivalencia exacta con lo que ya tenia en Articulos.
        $this->assertTrue($catalogo->fresh()->hasDirectPermission('services.index.edit'));
        $this->assertTrue($catalogo->fresh()->hasDirectPermission('services.show.edit_price'));
        $this->assertFalse($catalogo->fresh()->hasDirectPermission('services.index.delete'));

        $this->assertTrue($contable->fresh()->hasDirectPermission('services.show.view_cost'));

        // Las plantillas quedan como las pinta PermissionHelper.
        $supervisor = Role::query()->where('company_id', $this->company->id)->where('name', 'supervisor')->firstOrFail();
        $this->assertTrue($supervisor->hasPermissionTo('pos.sale.service_remove'));
        $this->assertFalse($supervisor->hasPermissionTo('pos.sale.service_add'));
        $cashierRole = Role::query()->where('company_id', $this->company->id)->where('name', 'cajero')->firstOrFail();
        $this->assertTrue($cashierRole->hasPermissionTo('pos.sale.service_reduce'));
    }

    public function test_en_una_base_nueva_el_cajero_trae_sus_permisos_de_servicio(): void
    {
        $this->seedEnvironment();
        $cajero = $this->makeUser('cajero');

        $this->assertTrue($cajero->can('services.index.view'));
        $this->assertTrue($cajero->can('pos.sale.service_reduce'));
        $this->assertFalse($cajero->can('pos.sale.service_add'));
    }
}

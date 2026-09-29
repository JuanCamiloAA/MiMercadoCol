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
 * En una base en uso los permisos de devoluciones de venta no los tiene nadie: la migracion los
 * reparte por equivalencia con los de la caja, los pedidos y el historial.
 */
class CustomerReturnPermissionMigrationTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected const NEW = [
        'sales.returns.view', 'sales.returns.create', 'sales.returns.approve',
        'sales.returns.receive', 'sales.returns.resolve', 'sales.returns.cancel',
    ];

    public function test_reparte_por_equivalencia(): void
    {
        $this->seedEnvironment();

        $admin = $this->makeUser('admin');
        $bodega = $this->makeUser('bodega', ['email' => 'bodega@lacanasta.co']);
        $contable = $this->makeUser('auxiliar_contable', ['email' => 'conta@lacanasta.co']);
        $cajero = $this->makeUser('cajero', ['email' => 'caja@lacanasta.co']);
        $cajero->givePermissionTo('pos.sale.refund');
        $domiciliario = $this->makeUser('domiciliario', ['email' => 'moto@lacanasta.co']);

        // Como estaba la base antes: los permisos nuevos no existian para nadie.
        $ids = Permission::query()->whereIn('name', self::NEW)->pluck('id');
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $migration = require database_path('migrations/2026_09_27_000210_grant_customer_return_permissions.php');
        $migration->up();

        foreach (self::NEW as $permission) {
            $this->assertTrue($admin->fresh()->hasDirectPermission($permission), "El administrador no recibio {$permission}.");
        }

        // Quien ya devolvia plata en caja registra y resuelve; no aprueba solicitudes.
        $this->assertTrue($cajero->fresh()->hasDirectPermission('sales.returns.create'));
        $this->assertTrue($cajero->fresh()->hasDirectPermission('sales.returns.resolve'));
        $this->assertFalse($cajero->fresh()->hasDirectPermission('sales.returns.approve'));

        // Quien alistaba pedidos recibe la mercancia; la plata no es suya.
        $this->assertTrue($bodega->fresh()->hasDirectPermission('sales.returns.receive'));
        $this->assertFalse($bodega->fresh()->hasDirectPermission('sales.returns.resolve'));

        $this->assertTrue($contable->fresh()->hasDirectPermission('sales.returns.resolve'));
        $this->assertFalse($contable->fresh()->hasDirectPermission('sales.returns.receive'));

        // El domiciliario cambia estados de pedidos, pero no aprueba devoluciones.
        $this->assertFalse($domiciliario->fresh()->hasDirectPermission('sales.returns.approve'));

        $supervisor = Role::query()->where('company_id', $this->company->id)->where('name', 'supervisor')->firstOrFail();
        $this->assertTrue($supervisor->hasPermissionTo('sales.returns.approve'));
    }

    public function test_en_una_base_nueva_las_plantillas_traen_lo_suyo(): void
    {
        $this->seedEnvironment();

        $this->assertTrue($this->makeUser('bodega', ['email' => 'b@lacanasta.co'])->can('sales.returns.receive'));
        $this->assertTrue($this->makeUser('auxiliar_contable', ['email' => 'c@lacanasta.co'])->can('sales.returns.resolve'));
        $this->assertTrue($this->makeUser('supervisor', ['email' => 's@lacanasta.co'])->can('sales.returns.approve'));
        $this->assertFalse($this->makeUser('cajero', ['email' => 'k@lacanasta.co'])->can('sales.returns.view'));
    }
}

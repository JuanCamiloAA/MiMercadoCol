<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\Concerns\ReturnsToSupplier;
use Tests\TestCase;

/**
 * Cada ruta de devoluciones con su permiso, y el reparto de los permisos en una base en uso.
 */
class SupplierReturnPermissionsTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase, ReturnsToSupplier;

    protected const NEW = [
        'purchases.returns.view',
        'purchases.returns.create',
        'purchases.returns.dispatch',
        'purchases.returns.respond',
        'purchases.returns.cancel',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();

        $this->admin = $this->makeUser('admin');
        $this->granos = $this->makeSupplier('Granos del Campo');
    }

    public function test_bodega_arma_y_despacha_pero_no_registra_la_respuesta(): void
    {
        $bodega = $this->makeUser('bodega');

        $this->assertTrue($bodega->can('purchases.returns.view'));
        $this->assertTrue($bodega->can('purchases.returns.create'));
        $this->assertTrue($bodega->can('purchases.returns.dispatch'));
        $this->assertTrue($bodega->can('purchases.returns.cancel'));
        $this->assertFalse($bodega->can('purchases.returns.respond'));

        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 30);

        $this->as($bodega)->post(route('supplier_returns.store'), [
            'supplier_id' => $this->granos->id,
            'warehouse_id' => $this->warehouse()->id,
            'dispatch_now' => true,
            'items' => [['product_id' => $arroz->id, 'quantity' => 4, 'reason' => 'damaged']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $return = SupplierReturn::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(SupplierReturn::STATUS_DISPATCHED, $return->status);

        $this->as($bodega)->post(route('supplier_returns.settle', $return), [
            'type' => 'credit_note', 'document_number' => 'NC-1', 'amount' => 1000, 'received_at' => now()->toDateString(),
        ])->assertForbidden();

        // Y la ficha no le pinta el boton: el permiso no le llega.
        $this->as($bodega)->get(route('supplier_returns.show', $return))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('SupplierReturns/Show')->where('supplierReturn.number', 'DP-0001')->where('can.act_here', true));
    }

    public function test_el_auxiliar_contable_responde_pero_no_arma_devoluciones(): void
    {
        $contable = $this->makeUser('auxiliar_contable');
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, cost: 1000, stock: 30);
        $return = $this->returns()->dispatch($this->draft([['product_id' => $arroz->id, 'quantity' => 4, 'unit_cost' => 1000]]), $this->admin);

        $this->as($contable)->get(route('supplier_returns.create'))->assertForbidden();
        $this->as($contable)->post(route('supplier_returns.dispatch', $return))->assertForbidden();

        $this->as($contable)->post(route('supplier_returns.settle', $return), [
            'type' => 'credit_note', 'document_number' => 'NC-7', 'amount' => 4000, 'received_at' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(SupplierReturn::STATUS_CLOSED, $return->refresh()->status);
    }

    public function test_sin_despachar_no_se_guarda_y_despacha(): void
    {
        $user = $this->makeUser('cajero');
        $user->givePermissionTo(['purchases.returns.view', 'purchases.returns.create']);
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 30);

        $this->as($user)->post(route('supplier_returns.store'), [
            'supplier_id' => $this->granos->id,
            'warehouse_id' => $this->warehouse()->id,
            'dispatch_now' => true,
            'items' => [['product_id' => $arroz->id, 'quantity' => 4, 'reason' => 'damaged']],
        ])->assertForbidden();

        $this->assertSame(0, SupplierReturn::query()->withoutGlobalScopes()->count());
    }

    public function test_cada_ruta_pide_su_permiso(): void
    {
        $nadie = $this->makeUser('domiciliario');
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 30);
        $return = $this->draft([['product_id' => $arroz->id, 'quantity' => 2]]);

        foreach ([
            ['get', route('supplier_returns.index')],
            ['get', route('supplier_returns.export')],
            ['get', route('supplier_returns.products')],
            ['get', route('supplier_returns.create')],
            ['get', route('supplier_returns.show', $return)],
            ['get', route('supplier_returns.edit', $return)],
            ['get', route('supplier_returns.document', $return)],
            ['post', route('supplier_returns.store')],
            ['put', route('supplier_returns.update', $return)],
            ['post', route('supplier_returns.dispatch', $return)],
            ['post', route('supplier_returns.reject', $return)],
            ['post', route('supplier_returns.settle', $return)],
            ['post', route('supplier_returns.replacement', $return)],
            ['post', route('supplier_returns.cancel', $return)],
        ] as [$method, $url]) {
            $this->as($nadie)->{$method}($url)->assertForbidden();
        }
    }

    public function test_las_pantallas_abren_con_lo_que_necesitan(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 0);
        $order = $this->receivedOrder([['product' => $arroz, 'quantity' => 12, 'cost' => 4000]]);
        $return = $this->draft([$this->lineFor($order, $arroz, 3)], $order);

        $this->as()->get(route('supplier_returns.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('SupplierReturns/Index')->where('cards.drafts', 1)->has('returns.data', 1));

        $this->as()->get(route('supplier_returns.create', ['orden' => $order->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('SupplierReturns/Form')
                ->where('mode', 'order')
                ->where('warehouseId', $this->warehouse()->id)
                ->where('lines.0.received', 12)
                // Tres en otro borrador: quedan nueve.
                ->where('lines.0.returnable', 9)
                ->where('lines.0.unit_cost', 4000));

        $this->as()->get(route('supplier_returns.create'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('SupplierReturns/Form')->where('mode', 'loose')->has('returnableOrders', 1));

        $this->as()->get(route('supplier_returns.edit', $return))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('SupplierReturns/Form')->where('lines.0.quantity', 3)->where('lines.0.returnable', 12));

        $this->as()->get(route('supplier_returns.products', ['search' => 'Arr', 'supplier' => $this->granos->id]))
            ->assertOk()
            ->assertJsonPath('products.0.name', 'Arroz')
            ->assertJsonPath('products.0.is_supplier_product', true);

        $this->as()->get(route('supplier_returns.document', $return))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('DP-0001', $this->as()->get(route('supplier_returns.export'))->streamedContent());

        $this->as()->get(route('purchases.show', $order))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('order.can_return', true)
                ->has('order.returns', 1)
                ->where('order.items.0.returned_quantity', 0));
    }

    public function test_una_devolucion_de_otra_empresa_no_se_abre(): void
    {
        $arroz = $this->makeSuppliedProduct('Arroz', $this->granos, stock: 30);
        $return = $this->draft([['product_id' => $arroz->id, 'quantity' => 2]]);
        $other = $this->makeOtherCompany();
        $ajeno = User::create([
            'company_id' => $other['company']->id, 'name' => 'Ajeno', 'email' => 'ajeno@vecino.co',
            'password' => bcrypt('Password123*'), 'is_active' => true, 'password_changed_at' => now(),
        ]);
        $ajeno->givePermissionTo(self::NEW);
        $ajeno->stores()->sync([$other['store']->id]);

        $status = $this->actingAs($ajeno)
            ->withSession([StoreContext::SESSION_KEY => $other['store']->id])
            ->get(route('supplier_returns.show', $return))
            ->getStatusCode();

        $this->assertContains($status, [403, 404]);
    }

    public function test_la_migracion_reparte_por_equivalencia(): void
    {
        $bodega = $this->makeUser('bodega');
        $contable = $this->makeUser('auxiliar_contable');
        $supervisor = $this->makeUser('supervisor');
        $solo = $this->makeUser('cajero');
        $solo->givePermissionTo('purchases.index.receive');

        // Como estaba la base antes: los permisos nuevos no existian para nadie.
        $ids = Permission::query()->whereIn('name', self::NEW)->pluck('id');
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $migration = require database_path('migrations/2026_09_26_000310_grant_supplier_return_permissions.php');
        $migration->up();

        $this->assertTrue($this->admin->fresh()->hasDirectPermission('purchases.returns.respond'));
        $this->assertTrue($this->admin->fresh()->hasDirectPermission('purchases.returns.cancel'));

        // Quien recibia mercancia arma y despacha; si anulaba ordenes, anula borradores.
        foreach (['view', 'create', 'dispatch', 'cancel'] as $action) {
            $this->assertTrue($bodega->fresh()->hasDirectPermission("purchases.returns.{$action}"), "Bodega sin {$action}.");
        }
        $this->assertFalse($bodega->fresh()->hasDirectPermission('purchases.returns.respond'));

        $this->assertTrue($solo->fresh()->hasDirectPermission('purchases.returns.dispatch'));
        $this->assertFalse($solo->fresh()->hasDirectPermission('purchases.returns.cancel'));

        $this->assertTrue($contable->fresh()->hasDirectPermission('purchases.returns.view'));
        $this->assertTrue($contable->fresh()->hasDirectPermission('purchases.returns.respond'));
        $this->assertFalse($contable->fresh()->hasDirectPermission('purchases.returns.create'));

        $this->assertTrue($supervisor->fresh()->hasDirectPermission('purchases.returns.dispatch'));

        // Las plantillas quedan como las pinta PermissionHelper.
        $bodegaRole = Role::query()->where('company_id', $this->company->id)->where('name', 'bodega')->firstOrFail();
        $this->assertTrue($bodegaRole->hasPermissionTo('purchases.returns.dispatch'));
        $this->assertFalse($bodegaRole->hasPermissionTo('purchases.returns.respond'));
    }

    protected function as(?User $user = null): static
    {
        return $this->actingAs($user ?? $this->admin)->withSession($this->withActiveStore());
    }
}

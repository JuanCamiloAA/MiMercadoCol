<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

class PermissionMiddlewareTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
    }

    public function test_un_usuario_sin_permiso_recibe_403_y_queda_el_registro(): void
    {
        // El cajero no administra roles: es la accion mas lejana de su trabajo.
        $cashier = $this->makeUser('cajero');

        $response = $this->actingAs($cashier)->get(route('roles.index'));

        $response->assertStatus(403);

        $this->assertDatabaseHas('access_logs', [
            'user_id' => $cashier->id,
            'action' => AccessLog::ACTION_PERMISSION_DENIED,
        ]);

        $log = AccessLog::query()->where('user_id', $cashier->id)->latest()->first();
        $this->assertSame('roles.index.view', $log->context['permission_checked']);
    }

    public function test_un_usuario_con_permiso_entra_sin_problema(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->get(route('roles.index'))->assertOk();
    }

    public function test_quitar_un_permiso_bloquea_la_ruta(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->get(route('products.create'))->assertOk();

        $admin->permissions()->detach(
            \Spatie\Permission\Models\Permission::query()->where('name', 'products.index.create')->value('id')
        );
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($admin->fresh())->get(route('products.create'))->assertStatus(403);
    }
}

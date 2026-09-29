<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Services\RoleTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

class RolePropagationTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
    }

    public function test_editar_el_rol_no_cambia_a_nadie_hasta_propagar(): void
    {
        $admin = $this->makeUser('admin');
        $cashier = $this->makeUser('cajero');

        $role = Role::query()->where('company_id', $this->company->id)->where('name', 'cajero')->firstOrFail();
        $before = $cashier->permissions()->pluck('name')->sort()->values()->all();

        $newPermissions = array_merge($before, ['pos.sale.credit']);

        $response = $this->actingAs($admin)->put(route('roles.update', $role), [
            'display_name' => 'Cajero',
            'description' => 'Rol de caja',
            'color' => '#0E6E4E',
            'permissions' => $newPermissions,
        ]);

        $response->assertRedirect(route('roles.propagate.show', $role));

        // La plantilla cambio...
        $this->assertTrue($role->fresh()->permissions->pluck('name')->contains('pos.sale.credit'));

        // ...pero la persona sigue igual.
        $this->assertSame($before, $cashier->fresh()->permissions()->pluck('name')->sort()->values()->all());
        $this->assertFalse($cashier->fresh()->can('pos.sale.credit'));
    }

    public function test_al_propagar_aplica_solo_la_diferencia(): void
    {
        $admin = $this->makeUser('admin');
        $cashier = $this->makeUser('cajero');

        // Ajuste propio de esta persona: la propagacion no puede borrarlo.
        $cashier->givePermissionTo('products.show.view');
        $cashier->flushEffectivePermissionCache();

        $role = Role::query()->where('company_id', $this->company->id)->where('name', 'cajero')->firstOrFail();
        $before = $role->permissions()->pluck('name')->all();
        $after = array_values(array_diff(array_merge($before, ['pos.sale.credit']), ['pos.sale.discount']));

        $this->actingAs($admin)->put(route('roles.update', $role), [
            'display_name' => 'Cajero',
            'color' => '#0E6E4E',
            'permissions' => $after,
        ]);

        $this->assertNotNull(session(RoleTemplateService::PENDING_SESSION_PREFIX.$role->id));

        $this->actingAs($admin)
            ->post(route('roles.propagate', $role), ['user_ids' => [$cashier->id]])
            ->assertRedirect(route('roles.index'));

        $fresh = $cashier->fresh();
        $names = $fresh->permissions()->pluck('name')->all();

        $this->assertContains('pos.sale.credit', $names, 'Debio ganar el permiso nuevo.');
        $this->assertNotContains('pos.sale.discount', $names, 'Debio perder el permiso retirado.');
        $this->assertContains('products.show.view', $names, 'El ajuste propio no se puede perder.');
    }

    public function test_si_no_se_elige_a_nadie_no_cambia_ningun_usuario(): void
    {
        $admin = $this->makeUser('admin');
        $cashier = $this->makeUser('cajero');

        $role = Role::query()->where('company_id', $this->company->id)->where('name', 'cajero')->firstOrFail();
        $before = $cashier->permissions()->pluck('name')->sort()->values()->all();

        $this->actingAs($admin)->put(route('roles.update', $role), [
            'display_name' => 'Cajero',
            'color' => '#0E6E4E',
            'permissions' => array_merge($before, ['pos.sale.credit']),
        ]);

        $this->actingAs($admin)->post(route('roles.propagate', $role), ['user_ids' => []]);

        $this->assertSame($before, $cashier->fresh()->permissions()->pluck('name')->sort()->values()->all());
    }
}

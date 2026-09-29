<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

class EmployeeAccessTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
    }

    public function test_crear_el_acceso_genera_un_usuario_que_debe_cambiar_la_clave(): void
    {
        $admin = $this->makeUser('admin');
        $employee = $this->makeEmployee();

        $response = $this->actingAs($admin)->post(route('employees.access.store', $employee), [
            'email' => 'diana@lacanasta.co',
            'role' => 'cajero',
            'pin' => '1234',
        ]);

        $response->assertSessionHas('temporary_password');

        $user = User::query()->where('email', 'diana@lacanasta.co')->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->password_change_required);
        $this->assertSame($employee->id, $user->employee_id);
        $this->assertTrue($user->hasRole('cajero'));
        $this->assertTrue(Hash::check('1234', $user->pos_pin));
        $this->assertTrue($user->can('pos.sale.create'));
    }

    public function test_la_clave_temporal_obliga_a_cambiarla_antes_de_entrar_al_panel(): void
    {
        $admin = $this->makeUser('admin');
        $employee = $this->makeEmployee();

        $this->actingAs($admin)->post(route('employees.access.store', $employee), [
            'email' => 'diana@lacanasta.co',
            'role' => 'cajero',
        ]);

        $temporary = session('temporary_password');
        $user = User::query()->where('email', 'diana@lacanasta.co')->firstOrFail();

        $this->post('/logout');

        $this->post('/login', [
            'login_mode' => 'email',
            'email' => 'diana@lacanasta.co',
            'password' => $temporary,
        ])->assertRedirect(route('password.change.show'));

        $this->assertAuthenticatedAs($user);

        $this->actingAs($user->fresh())
            ->get(route('dashboard'))
            ->assertRedirect(route('password.change.show'));
    }

    public function test_cambiar_el_rol_reemplaza_los_permisos_por_los_de_la_plantilla(): void
    {
        $admin = $this->makeUser('admin');
        $employee = $this->makeEmployee();

        $this->actingAs($admin)->post(route('employees.access.store', $employee), [
            'email' => 'diana@lacanasta.co',
            'role' => 'cajero',
        ]);

        $user = User::query()->where('email', 'diana@lacanasta.co')->firstOrFail();
        $this->assertFalse($user->can('inventory.index.adjust'));

        $this->actingAs($admin)->patch(route('employees.access.change_role', $employee), ['role' => 'bodega']);

        $user = $user->fresh();
        $user->flushEffectivePermissionCache();

        $this->assertTrue($user->hasRole('bodega'));
        $this->assertTrue($user->can('inventory.index.adjust'));
        $this->assertFalse($user->can('pos.sale.create'));
    }

    public function test_desactivar_al_empleado_apaga_su_acceso(): void
    {
        $admin = $this->makeUser('admin');
        $employee = $this->makeEmployee();

        $this->actingAs($admin)->post(route('employees.access.store', $employee), [
            'email' => 'diana@lacanasta.co',
            'role' => 'cajero',
        ]);

        $this->actingAs($admin)->patch(route('employees.deactivate', $employee));

        $this->assertSame('inactive', $employee->fresh()->status);
        $this->assertFalse(User::query()->where('email', 'diana@lacanasta.co')->firstOrFail()->is_active);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Plataforma > Usuarios: los super usuarios se crean desde la plataforma, sin empresa ni sede.
 */
class PlatformUserTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->super = $this->makeSuperAdmin(['email' => 'soporte@plataforma.co']);
    }

    public function test_el_listado_muestra_solo_los_super_usuarios(): void
    {
        $other = $this->makeSuperAdmin(['name' => 'Laura', 'email' => 'laura@plataforma.co']);
        $this->makeUser('admin', ['email' => 'admin.empresa@lacanasta.co']);

        $this->actingAs($this->super)->get(route('platform.users.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Users/Index')
                ->has('users.data', 2)
                ->where('tabCounts.platform', 2)
                ->where('tabCounts.companies', 1)
                ->where('active', 2)
                ->where('users.data', fn ($rows) => collect($rows)->pluck('email')->sort()->values()->all() === ['laura@plataforma.co', 'soporte@plataforma.co'])
                ->where('users.data', fn ($rows) => collect($rows)->firstWhere('id', $this->super->id)['is_me'] === true
                    && collect($rows)->firstWhere('id', $other->id)['is_me'] === false));
    }

    public function test_crea_un_super_usuario_sin_empresa_con_clave_temporal(): void
    {
        $response = $this->actingAs($this->super)->post(route('platform.users.store'), [
            'name' => 'Andrea',
            'last_name' => 'Velez',
            'email' => '  Andrea@Plataforma.co ',
            'document' => '1030111222',
            'job_title' => 'Socia',
        ]);

        $response->assertSessionHasNoErrors()->assertSessionHas('temporary_password');

        $created = User::query()->where('email', 'andrea@plataforma.co')->firstOrFail();

        $this->assertNull($created->company_id);
        $this->assertTrue($created->isSuperAdmin());
        $this->assertTrue($created->is_active);
        $this->assertTrue($created->password_change_required);
        $this->assertTrue(Hash::check(session('temporary_password'), $created->password));
        $this->assertSame([], $created->stores()->pluck('stores.id')->all());

        // Ya administra la plataforma (despues de cambiar la clave temporal).
        $created->update(['password_change_required' => false]);
        $this->actingAs($created->fresh())->get(route('platform.users.index'))->assertOk();
        $this->actingAs($created->fresh())->get(route('companies.index'))->assertOk();
    }

    public function test_el_correo_no_se_repite_con_nadie_y_el_documento_no_entre_super_usuarios(): void
    {
        $this->makeUser('admin', ['email' => 'ocupado@lacanasta.co', 'document' => '555']);

        $this->actingAs($this->super)->post(route('platform.users.store'), ['name' => 'Otro', 'email' => 'ocupado@lacanasta.co'])
            ->assertSessionHasErrors('email');

        // El mismo documento que alguien de una empresa si se puede: el indice va por empresa.
        $this->actingAs($this->super)->post(route('platform.users.store'), ['name' => 'Uno', 'email' => 'uno@plataforma.co', 'document' => '555'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->super)->post(route('platform.users.store'), ['name' => 'Dos', 'email' => 'dos@plataforma.co', 'document' => '555'])
            ->assertSessionHasErrors('document');
    }

    public function test_edita_los_datos_y_no_toca_usuarios_de_empresa(): void
    {
        $other = $this->makeSuperAdmin(['email' => 'viejo@plataforma.co']);

        $this->actingAs($this->super)->put(route('platform.users.update', $other), [
            'name' => 'Camila', 'last_name' => 'Rios', 'email' => 'camila@plataforma.co', 'job_title' => 'Soporte',
        ])->assertSessionHasNoErrors();

        $this->assertSame(['Camila', 'camila@plataforma.co', 'Soporte'], [$other->fresh()->name, $other->fresh()->email, $other->fresh()->job_title]);

        // Su propio correo no choca consigo mismo.
        $this->actingAs($this->super)->put(route('platform.users.update', $other), ['name' => 'Camila', 'email' => 'camila@plataforma.co'])
            ->assertSessionHasNoErrors();

        $employee = $this->makeUser('admin');
        $this->actingAs($this->super)->put(route('platform.users.update', $employee), ['name' => 'X', 'email' => 'x@x.co'])->assertNotFound();
        $this->actingAs($this->super)->put(route('platform.users.update', $employee), [])->assertNotFound();
        $this->actingAs($this->super)->post(route('platform.users.toggle', $employee))->assertNotFound();
        $this->actingAs($this->super)->post(route('platform.users.reset_password', $employee))->assertNotFound();
    }

    public function test_desactivar_cierra_la_puerta_y_nadie_se_desactiva_a_si_mismo(): void
    {
        $other = $this->makeSuperAdmin(['email' => 'saliente@plataforma.co']);

        $this->actingAs($this->super)->post(route('platform.users.toggle', $this->super))->assertSessionHas('error');
        $this->assertTrue($this->super->fresh()->is_active);

        $this->actingAs($this->super)->post(route('platform.users.toggle', $other))->assertSessionHas('success');
        $this->assertFalse($other->fresh()->is_active);

        auth()->logout();
        $this->post('/login', ['email' => 'saliente@plataforma.co', 'password' => 'Password123*']);
        $this->assertGuest();

        $this->actingAs($this->super)->post(route('platform.users.toggle', $other))->assertSessionHas('success');
        $this->assertTrue($other->fresh()->is_active);
    }

    public function test_restablece_la_clave_de_otro_pero_no_la_propia(): void
    {
        $other = $this->makeSuperAdmin(['email' => 'olvidadizo@plataforma.co']);

        $this->actingAs($this->super)->post(route('platform.users.reset_password', $other))->assertSessionHas('temporary_password');

        $this->assertTrue($other->fresh()->password_change_required);
        $this->assertTrue(Hash::check(session('temporary_password'), $other->fresh()->password));
        $this->assertFalse(Hash::check('Password123*', $other->fresh()->password));

        $this->actingAs($this->super)->post(route('platform.users.reset_password', $this->super))->assertSessionHas('error');
        $this->assertTrue(Hash::check('Password123*', $this->super->fresh()->password));
    }

    public function test_un_administrador_de_empresa_no_entra(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->get(route('platform.users.index'))->assertForbidden();
        $this->actingAs($admin)->post(route('platform.users.store'), ['name' => 'Intruso', 'email' => 'intruso@x.co'])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'intruso@x.co']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\UserPermissionService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Plataforma > Usuarios > De empresas: las cuentas de todas las empresas se administran desde la
 * plataforma por el mismo camino que usa Usuarios dentro de la empresa.
 */
class PlatformCompanyUserTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
        $this->super = $this->makeSuperAdmin();
    }

    /**
     * @return list<string>
     */
    protected function roleTemplate(int $companyId, string $role): array
    {
        return Role::query()->where('company_id', $companyId)->where('name', $role)->firstOrFail()
            ->permissions->pluck('name')->sort()->values()->all();
    }

    /**
     * @return list<string>
     */
    protected function permissionsOf(User $user): array
    {
        return collect(app(UserPermissionService::class)->namesFor($user->fresh()))->sort()->values()->all();
    }

    public function test_lista_las_cuentas_de_todas_las_empresas_sin_los_super_usuarios(): void
    {
        ['company' => $other, 'store' => $otherStore] = $this->makeOtherCompany();
        $mine = $this->makeUser('cajero', ['email' => 'cajera@lacanasta.co']);
        $theirs = User::query()->create(['company_id' => $other->id, 'name' => 'Pedro', 'email' => 'pedro@vecino.co', 'password' => Hash::make('x'), 'is_active' => true]);
        $theirs->stores()->sync([$otherStore->id]);

        // Con La Canasta elegida en el selector, las sedes de la otra empresa igual se ven.
        $this->actingAs($this->super)->withSession([TenantContext::SESSION_KEY => $this->company->id])
            ->get(route('platform.company_users.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Users/Companies')
                ->where('tabCounts.platform', 1)
                ->where('users.data', function ($rows) use ($mine, $theirs) {
                    $rows = collect($rows);

                    return $rows->pluck('id')->sort()->values()->all() === collect([$mine->id, $theirs->id])->sort()->values()->all()
                        && $rows->firstWhere('id', $theirs->id)['stores'][0]['name'] === 'Envigado'
                        && $rows->firstWhere('id', $theirs->id)['company']['name'] === 'Mercado El Vecino';
                }));

        $this->actingAs($this->super)->get(route('platform.company_users.index', ['company' => $other->id]))
            ->assertInertia(fn ($page) => $page->has('users.data', 1)->where('users.data.0.id', $theirs->id));
    }

    public function test_las_opciones_son_los_roles_y_sedes_de_esa_empresa(): void
    {
        ['company' => $other, 'store' => $otherStore] = $this->makeOtherCompany();

        $response = $this->actingAs($this->super)->getJson(route('platform.company_users.options', $other))->assertOk();

        $this->assertSame([['value' => $otherStore->id, 'label' => 'Envigado']], $response->json('stores'));
        $this->assertContains('cajero', array_column($response->json('roles'), 'value'));
        $this->assertNotContains('super_admin', array_column($response->json('roles'), 'value'));
    }

    public function test_crea_en_otra_empresa_con_la_plantilla_del_rol_y_sus_sedes(): void
    {
        ['company' => $other, 'store' => $otherStore] = $this->makeOtherCompany();

        $this->actingAs($this->super)->post(route('platform.company_users.store'), [
            'company_id' => $other->id,
            'name' => 'Sofia',
            'email' => 'sofia@vecino.co',
            'role' => 'cajero',
            'store_ids' => [$otherStore->id],
        ])->assertSessionHasNoErrors()->assertSessionHas('temporary_password');

        $created = User::query()->where('email', 'sofia@vecino.co')->firstOrFail();

        $this->assertSame($other->id, $created->company_id);
        $this->assertSame('cajero', $created->roles->first()->name);
        $this->assertSame($other->id, $created->roles->first()->company_id);
        $this->assertSame($this->roleTemplate($other->id, 'cajero'), $this->permissionsOf($created));
        $this->assertSame([$otherStore->id], $created->stores()->withoutGlobalScopes()->pluck('stores.id')->all());
        $this->assertTrue($created->password_change_required);
        $this->assertTrue(Hash::check(session('temporary_password'), $created->password));
    }

    public function test_el_rol_y_las_sedes_tienen_que_ser_de_la_empresa_elegida(): void
    {
        ['company' => $other] = $this->makeOtherCompany();
        Role::query()->create(['company_id' => $this->company->id, 'name' => 'solo_canasta', 'guard_name' => 'web', 'display_name' => 'Solo Canasta']);

        $this->actingAs($this->super)->post(route('platform.company_users.store'), [
            'company_id' => $other->id, 'name' => 'X', 'email' => 'x@vecino.co', 'role' => 'solo_canasta', 'store_ids' => [$this->store->id],
        ])->assertSessionHasErrors(['role', 'store_ids.0']);

        $this->actingAs($this->super)->post(route('platform.company_users.store'), ['name' => 'X', 'email' => 'x@vecino.co', 'role' => 'cajero'])
            ->assertSessionHasErrors('company_id');

        $this->assertDatabaseMissing('users', ['email' => 'x@vecino.co']);
    }

    public function test_el_tope_del_plan_tambien_vale_desde_la_plataforma(): void
    {
        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan(['max_users' => 1]);
        $this->putCompanyOnPlan($plan, $cycle);
        $first = $this->makeUser('cajero');

        $this->actingAs($this->super)->post(route('platform.company_users.store'), [
            'company_id' => $this->company->id, 'name' => 'Otro', 'email' => 'otro@lacanasta.co', 'role' => 'cajero',
        ])->assertSessionHasErrors(['company_id' => "{$this->company->name} ya tiene 1 usuario activo, el tope de su plan. Desactiva a alguien o súbele el plan."]);

        // Desactivar libera el cupo y reactivar lo revisa.
        $this->actingAs($this->super)->post(route('platform.company_users.toggle', $first))->assertSessionHas('success');
        $this->assertFalse($first->fresh()->is_active);

        $this->actingAs($this->super)->post(route('platform.company_users.store'), [
            'company_id' => $this->company->id, 'name' => 'Otro', 'email' => 'otro@lacanasta.co', 'role' => 'cajero',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->super)->post(route('platform.company_users.toggle', $first))->assertSessionHas('error');
        $this->assertFalse($first->fresh()->is_active);
    }

    public function test_editar_cambia_datos_rol_y_sedes_pero_no_la_empresa(): void
    {
        ['company' => $other] = $this->makeOtherCompany();
        $user = $this->makeUser('cajero');
        $second = Store::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Laureles', 'code' => 'LAU', 'opens_at' => '07:00:00', 'closes_at' => '21:00:00', 'suggested_cash_base' => 0, 'is_active' => true,
        ]);

        $this->actingAs($this->super)->put(route('platform.company_users.update', $user), [
            'name' => 'Diana', 'last_name' => 'Rojas', 'email' => 'diana@lacanasta.co', 'role' => 'supervisor', 'store_ids' => [$second->id],
        ])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame(['Diana', 'diana@lacanasta.co', 'supervisor'], [$user->name, $user->email, $user->roles->first()->name]);
        $this->assertSame($this->roleTemplate($this->company->id, 'supervisor'), $this->permissionsOf($user));
        $this->assertSame([$second->id], $user->stores()->withoutGlobalScopes()->pluck('stores.id')->all());

        $this->actingAs($this->super)->put(route('platform.company_users.update', $user), [
            'company_id' => $other->id, 'name' => 'Diana', 'email' => 'diana@lacanasta.co', 'role' => 'supervisor',
        ])->assertSessionHasErrors('company_id');
        $this->assertSame($this->company->id, $user->fresh()->company_id);

        // Un super usuario no se toca por esta pestaña.
        $this->actingAs($this->super)->put(route('platform.company_users.update', $this->super), ['name' => 'X', 'email' => 'x@x.co', 'role' => 'cajero'])->assertNotFound();
        $this->actingAs($this->super)->post(route('platform.company_users.reset_password', $this->super))->assertNotFound();
    }

    public function test_restablece_la_clave_y_abre_los_permisos_dentro_de_su_empresa(): void
    {
        ['company' => $other] = $this->makeOtherCompany();
        $theirs = User::query()->create(['company_id' => $other->id, 'name' => 'Pedro', 'email' => 'pedro@vecino.co', 'password' => Hash::make('vieja'), 'is_active' => true]);

        $this->actingAs($this->super)->post(route('platform.company_users.reset_password', $theirs))->assertSessionHas('temporary_password');
        $this->assertTrue(Hash::check(session('temporary_password'), $theirs->fresh()->password));
        $this->assertTrue($theirs->fresh()->password_change_required);

        $this->actingAs($this->super)->withSession([TenantContext::SESSION_KEY => $this->company->id])
            ->post(route('platform.company_users.permissions', $theirs))
            ->assertRedirect(route('users.permissions', $theirs))
            ->assertSessionHas(TenantContext::SESSION_KEY, $other->id);
    }

    public function test_usuarios_dentro_de_la_empresa_sigue_creando_y_editando_igual(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->withSession($this->withActiveStore())->post(route('users.store'), [
            'name' => 'Nuevo', 'email' => 'nuevo@lacanasta.co', 'role' => 'cajero', 'store_ids' => [$this->store->id],
        ])->assertSessionHasNoErrors()->assertSessionHas('temporary_password');

        $created = User::query()->where('email', 'nuevo@lacanasta.co')->firstOrFail();
        $this->assertSame($this->company->id, $created->company_id);
        $this->assertSame($this->roleTemplate($this->company->id, 'cajero'), $this->permissionsOf($created));
        $this->assertSame([$this->store->id], $created->stores()->withoutGlobalScopes()->pluck('stores.id')->all());

        $this->actingAs($admin)->withSession($this->withActiveStore())->put(route('users.update', $created), [
            'name' => 'Nuevo', 'email' => 'nuevo@lacanasta.co', 'role' => 'bodega', 'store_ids' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame('bodega', $created->fresh()->roles->first()->name);
        $this->assertSame($this->roleTemplate($this->company->id, 'bodega'), $this->permissionsOf($created));
        $this->assertSame([], $created->stores()->withoutGlobalScopes()->pluck('stores.id')->all());
    }

    public function test_un_administrador_de_empresa_no_entra(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->get(route('platform.company_users.index'))->assertForbidden();
        $this->actingAs($admin)->getJson(route('platform.company_users.options', $this->company))->assertForbidden();
    }
}

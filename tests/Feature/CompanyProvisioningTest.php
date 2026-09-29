<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembershipEvent;
use App\Models\PaymentTerm;
use App\Models\Register;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Storefront;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Platform\CompanyProvisioner;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Crear una empresa desde Plataforma: nace lista para trabajar, o no nace.
 */
class CompanyProvisioningTest extends TestCase
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
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan(['name' => 'Pro', 'max_stores' => 3, 'max_users' => 10, 'max_registers' => 5, 'trial_days' => 14], ['online_store', 'promotions']);

        return array_merge([
            'name' => 'Mercado El Vecino',
            'legal_name' => 'Mercado El Vecino S.A.S.',
            'tax_id' => '900555666-1',
            'email' => 'Contacto@ElVecino.co',
            'city' => 'Envigado',
            'store_name' => 'Envigado',
            'store_code' => 'env',
            'store_latitude' => '6.1719',
            'store_longitude' => '-75.5872',
            'admin_name' => 'Rubén',
            'admin_last_name' => 'Arango',
            'admin_email' => 'ruben@elvecino.co',
            'membership_plan_id' => $plan->id,
            'billing_cycle_id' => $cycle->id,
        ], $overrides);
    }

    public function test_la_empresa_nace_con_todo_lo_que_necesita(): void
    {
        $response = $this->actingAs($this->super)->post(route('companies.store'), $this->payload());

        $company = Company::query()->where('tax_id', '900555666-1')->firstOrFail();

        $response->assertRedirect(route('companies.edit', $company))
            ->assertSessionHas('temporary_password');

        $this->assertSame('mercado-el-vecino', $company->slug);
        $this->assertSame('contacto@elvecino.co', $company->email);
        $this->assertSame(Company::MEMBERSHIP_TRIAL, $company->membership_status);
        $this->assertEqualsWithDelta(14, now()->diffInDays($company->membership_ends_at), 1);

        $store = Store::query()->withoutGlobalScopes()->where('company_id', $company->id)->sole();
        $this->assertSame('ENV', $store->code);
        $this->assertSame('Envigado', $store->city);
        $this->assertEqualsWithDelta(6.1719, (float) $store->latitude, 0.0001);

        $this->assertSame(1, Warehouse::query()->withoutGlobalScopes()->where('store_id', $store->id)->count());
        $this->assertSame(1, Register::query()->withoutGlobalScopes()->where('company_id', $company->id)->count());

        $storefront = Storefront::query()->withoutGlobalScopes()->where('store_id', $store->id)->sole();
        $this->assertFalse((bool) $storefront->is_online);

        $this->assertGreaterThanOrEqual(4, Role::query()->where('company_id', $company->id)->count());
        $this->assertSame(4, PaymentTerm::query()->withoutGlobalScopes()->where('company_id', $company->id)->count());
        $this->assertSame(4, Setting::query()->where('company_id', $company->id)->count());

        $admin = User::query()->where('email', 'ruben@elvecino.co')->sole();
        $this->assertSame($company->id, $admin->company_id);
        $this->assertTrue((bool) $admin->password_change_required);
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue($admin->hasDirectPermission('products.index.view'));
        $this->assertTrue(Hash::check(session('temporary_password'), $admin->password));

        $this->assertDatabaseHas('company_membership_events', [
            'company_id' => $company->id,
            'type' => CompanyMembershipEvent::PROVISIONED,
            'user_id' => $this->super->id,
        ]);
    }

    public function test_el_administrador_nuevo_solo_ve_su_empresa(): void
    {
        $this->makeProduct(['name' => 'Arroz de La Canasta']);
        $this->actingAs($this->super)->post(route('companies.store'), $this->payload())->assertSessionHasNoErrors();

        $admin = User::query()->where('email', 'ruben@elvecino.co')->sole();
        $admin->forceFill(['password_change_required' => false, 'password_changed_at' => now()])->save();

        $this->actingAs($admin)
            ->get(route('products.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeCompany.name', 'Mercado El Vecino')
                ->count('products.data', 0));
    }

    public function test_un_nit_repetido_no_crea_nada(): void
    {
        $companies = Company::query()->count();
        $users = User::query()->count();

        $this->actingAs($this->super)
            ->post(route('companies.store'), $this->payload(['tax_id' => '900111222-3']))
            ->assertSessionHasErrors('tax_id');

        $this->assertSame($companies, Company::query()->count());
        $this->assertSame($users, User::query()->count());
    }

    public function test_un_correo_de_administrador_repetido_se_rechaza(): void
    {
        $existing = $this->makeUser('admin');

        $this->actingAs($this->super)
            ->post(route('companies.store'), $this->payload(['admin_email' => $existing->email]))
            ->assertSessionHasErrors('admin_email');

        $this->assertFalse(Company::query()->where('tax_id', '900555666-1')->exists());
    }

    public function test_si_un_paso_falla_no_queda_media_empresa(): void
    {
        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan(['max_stores' => 0]);
        $companies = Company::query()->count();
        $stores = Store::query()->withoutGlobalScopes()->count();

        try {
            app(CompanyProvisioner::class)->provision([
                'company' => ['name' => 'Sin cupo', 'tax_id' => '900777888-9'],
                'admin' => ['name' => 'Ana', 'email' => 'ana@sincupo.co'],
                'plan_id' => $plan->id,
                'cycle_id' => $cycle->id,
            ], $this->super);
            $this->fail('Un plan sin sedes no deberia dejar crear la empresa.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Tu plan permite 0 sedes', collect($exception->errors())->flatten()->first());
        }

        $this->assertSame($companies, Company::query()->count());
        $this->assertSame($stores, Store::query()->withoutGlobalScopes()->count());
        $this->assertFalse(User::query()->where('email', 'ana@sincupo.co')->exists());
    }

    public function test_la_contrasena_elegida_no_pide_cambio(): void
    {
        $result = app(CompanyProvisioner::class)->provision([
            'company' => ['name' => 'Autoservicio La 80', 'tax_id' => '901000111-2'],
            'admin' => ['name' => 'Paula', 'email' => 'paula@la80.co', 'password_hash' => Hash::make('ClaveSegura#2026')],
        ]);

        $this->assertNull($result->temporaryPassword);
        $this->assertFalse((bool) $result->admin->password_change_required);
        $this->assertTrue(Hash::check('ClaveSegura#2026', $result->admin->password));
    }

    public function test_desactivar_la_empresa_saca_a_sus_usuarios(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($this->super)
            ->post(route('companies.status', $this->company), ['is_active' => false, 'reason' => 'Cierre del negocio'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->company->fresh()->is_active);
        $this->assertDatabaseHas('company_membership_events', ['company_id' => $this->company->id, 'type' => CompanyMembershipEvent::DEACTIVATED]);

        $this->actingAs($admin)->get(route('products.index'))->assertRedirect(route('login'));
    }

    public function test_cambiar_la_membresia_a_mano_exige_motivo_y_queda_en_bitacora(): void
    {
        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan(['name' => 'Cadena']);
        $base = [
            'name' => 'La Canasta',
            'legal_name' => 'La Canasta S.A.S.',
            'tax_id' => '900111222-3',
            'show_on_landing' => true,
            'membership_plan_id' => $plan->id,
            'billing_cycle_id' => $cycle->id,
            'membership_status' => Company::MEMBERSHIP_ACTIVE,
            'membership_ends_at' => now()->addMonths(2)->toDateString(),
        ];

        $this->actingAs($this->super)
            ->put(route('companies.update', $this->company), $base)
            ->assertSessionHasErrors('membership_reason');

        $this->assertNull($this->company->fresh()->membership_plan_id);

        $this->actingAs($this->super)
            ->put(route('companies.update', $this->company), $base + ['membership_reason' => 'Convenio comercial'])
            ->assertSessionHasNoErrors();

        $this->assertSame($plan->id, $this->company->fresh()->membership_plan_id);
        $this->assertDatabaseHas('company_membership_events', [
            'company_id' => $this->company->id,
            'type' => CompanyMembershipEvent::PLAN_CHANGED,
            'user_id' => $this->super->id,
        ]);
    }

    public function test_entrar_a_una_empresa_la_deja_elegida(): void
    {
        $this->actingAs($this->super)
            ->post(route('companies.enter', $this->company))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas(TenantContext::SESSION_KEY, $this->company->id);
    }

    public function test_el_listado_filtra_y_exporta(): void
    {
        $this->actingAs($this->super)->post(route('companies.store'), $this->payload())->assertSessionHasNoErrors();

        $this->actingAs($this->super)
            ->get(route('companies.index', ['q' => 'vecino']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Companies/Index')
                ->where('filters.q', 'vecino')
                ->count('companies.data', 1)
                ->where('companies.data.0.name', 'Mercado El Vecino'));

        $csv = $this->actingAs($this->super)->get(route('companies.export'))->assertOk()->streamedContent();

        $this->assertStringContainsString('Mercado El Vecino', $csv);
        $this->assertStringContainsString('La Canasta', $csv);
    }

    public function test_la_ficha_de_la_empresa_abre(): void
    {
        $this->actingAs($this->super)
            ->get(route('companies.edit', $this->company))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Companies/Edit')
                ->where('company.id', $this->company->id)
                ->has('usage')
                ->has('events'));
    }
}

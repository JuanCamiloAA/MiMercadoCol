<?php

namespace Tests\Feature;

use App\Helpers\PermissionHelper;
use App\Models\AccessLog;
use App\Models\Company;
use App\Models\CompanyEntitlement;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Services\Membership\Entitlement;
use App\Services\Membership\Entitlements;
use App\Services\Membership\EntitlementService;
use App\Services\Membership\MembershipFeatures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Planes descritos con nodos del arbol de permisos: el plan es un techo y nadie ejerce un
 * permiso que el plan de su empresa no incluye.
 */
class PlanEntitlementsTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvironment();
    }

    protected function service(): EntitlementService
    {
        return app(EntitlementService::class);
    }

    /** Un plan con exactamente esos nodos, asignado a la empresa de pruebas. */
    protected function planWith(array $nodes): MembershipPlan
    {
        ['plan' => $plan, 'cycle' => $cycle] = $this->makePlan();
        $plan->update(['entitlements' => $nodes]);
        $this->putCompanyOnPlan($plan, $cycle);

        return $plan;
    }

    protected function entitlement(): Entitlement
    {
        $this->service()->forget();

        return $this->service()->for($this->company->fresh());
    }

    public function test_un_permiso_asignado_fuera_del_plan_responde_con_el_mensaje_del_plan(): void
    {
        $this->planWith(Entitlements::LEGACY_BASE);
        $admin = $this->makeUser('admin');

        // El administrador tiene el permiso asignado...
        $this->assertTrue($admin->permissions()->where('name', 'promotions.index.view')->exists());

        // ...pero el plan no lo trae: la ruta lo dice con el mensaje del plan.
        $this->actingAs($admin)->withSession($this->withActiveStore())
            ->get(route('promotions.index'))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Errors/403')
                ->where('message', 'Tu plan no incluye «Ver las promociones». Cámbialo en Ajustes > Membresía.'));

        $this->assertTrue(AccessLog::query()->where('action', AccessLog::ACTION_PLAN_DENIED)->where('user_id', $admin->id)->exists());
        $this->assertFalse(AccessLog::query()->where('action', AccessLog::ACTION_PERMISSION_DENIED)->where('user_id', $admin->id)->exists());

        // La prop de permisos no lo trae, asi que el menu no lo pinta; lo demas sigue.
        $this->actingAs($admin)->withSession($this->withActiveStore())
            ->get(route('products.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.permissions', fn ($permissions) => ! collect($permissions)->contains('promotions.index.view')
                    && collect($permissions)->contains('products.index.view')));

        // Y el permiso no se borro: si la empresa sube de plan, vuelve.
        $this->assertTrue($admin->permissions()->where('name', 'promotions.index.view')->exists());
    }

    public function test_quien_no_tiene_el_permiso_recibe_el_mensaje_de_siempre(): void
    {
        $this->planWith(Entitlements::LEGACY_BASE);
        $cashier = $this->makeUser('cajero');

        $this->actingAs($cashier)->withSession($this->withActiveStore())
            ->get(route('promotions.index'))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->where('message', 'No tienes permiso para abrir esta pagina.'));
    }

    public function test_al_subir_de_plan_recupera_lo_asignado_sin_reasignar(): void
    {
        $plan = $this->planWith(Entitlements::LEGACY_BASE);
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->withSession($this->withActiveStore())->get(route('promotions.index'))->assertForbidden();

        $plan->update(['entitlements' => [...Entitlements::LEGACY_BASE, 'promotions.*']]);

        $this->actingAs($admin)->withSession($this->withActiveStore())->get(route('promotions.index'))->assertOk();
    }

    public function test_el_comodin_de_modulo_incluye_paginas_futuras_y_la_accion_suelta_no(): void
    {
        $matrix = PermissionHelper::getPermissionMatrix();
        $matrix['inventory']['pages']['counts'] = [
            'display' => 'Conteos ciclicos',
            'actions' => ['view', 'close'],
        ];

        $module = Entitlements::expand(['inventory.*'], $matrix);
        $this->assertContains('inventory.counts.view', $module);
        $this->assertContains('inventory.counts.close', $module);

        $page = Entitlements::expand(['inventory.transfers.*'], $matrix);
        $this->assertContains('inventory.transfers.direct', $page);
        $this->assertNotContains('inventory.counts.view', $page);

        $action = Entitlements::expand(['inventory.transfers.direct'], $matrix);
        $this->assertSame(['inventory.transfers.direct'], $action);

        // Un nodo que no existe se ignora al leer.
        $this->assertSame([], Entitlements::expand(['inventory.no_existe.*', 'nada.*'], $matrix));
    }

    public function test_la_pagina_comodin_incluye_acciones_que_se_le_agregan(): void
    {
        $matrix = PermissionHelper::getPermissionMatrix();
        $matrix['inventory']['pages']['transfers']['actions'][] = 'reprint';

        $this->assertContains('inventory.transfers.reprint', Entitlements::expand(['inventory.transfers.*'], $matrix));
        $this->assertNotContains('inventory.transfers.reprint', Entitlements::expand(['inventory.transfers.direct'], $matrix));
    }

    public function test_una_excepcion_que_suma_vale_hasta_su_fecha_y_ni_un_minuto_despues(): void
    {
        $this->planWith(Entitlements::LEGACY_BASE);
        $ends = Carbon::parse('2026-10-30 18:00:00', 'America/Bogota');

        Carbon::setTestNow(Carbon::parse('2026-10-20 08:00:00', 'America/Bogota'));

        CompanyEntitlement::query()->create([
            'company_id' => $this->company->id,
            'node' => 'promotions.*',
            'effect' => CompanyEntitlement::EFFECT_GRANT,
            'starts_at' => now(),
            'ends_at' => $ends,
            'reason' => 'Prueba de promociones por un mes',
        ]);

        try {
            $this->assertTrue($this->entitlement()->allows('promotions.index.view'));
            $this->assertSame(Entitlement::SOURCE_GRANT, $this->entitlement()->explain('promotions.index.view')['source']);

            Carbon::setTestNow($ends->copy()->subMinute());
            $this->assertTrue($this->entitlement()->allows('promotions.index.create'));

            Carbon::setTestNow($ends->copy());
            $this->assertFalse($this->entitlement()->allows('promotions.index.view'));

            Carbon::setTestNow($ends->copy()->addMinute());
            $this->assertFalse($this->entitlement()->allows('promotions.index.view'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_en_la_misma_peticion_el_vencimiento_se_nota_sin_olvidar_nada(): void
    {
        $this->planWith(Entitlements::LEGACY_BASE);
        Carbon::setTestNow(Carbon::parse('2026-10-20 08:00:00', 'America/Bogota'));

        CompanyEntitlement::query()->create([
            'company_id' => $this->company->id,
            'node' => 'promotions.*',
            'effect' => CompanyEntitlement::EFFECT_GRANT,
            'starts_at' => now(),
            'ends_at' => now()->addHour(),
            'reason' => 'Una hora',
        ]);

        try {
            $company = $this->company->fresh();
            $this->assertTrue($this->service()->for($company)->allows('promotions.index.view'));

            // Sin llamar a forget(): lo memorizado sabe hasta cuando vale.
            Carbon::setTestNow(now()->addHours(2));
            $this->assertFalse($this->service()->for($company)->allows('promotions.index.view'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_una_excepcion_programada_empieza_a_su_hora(): void
    {
        $this->planWith(Entitlements::LEGACY_BASE);
        Carbon::setTestNow(Carbon::parse('2026-10-20 08:00:00', 'America/Bogota'));

        CompanyEntitlement::query()->create([
            'company_id' => $this->company->id,
            'node' => 'storefront.*',
            'effect' => CompanyEntitlement::EFFECT_GRANT,
            'starts_at' => now()->addDay(),
            'ends_at' => null,
            'reason' => 'Arranca mañana',
        ]);

        try {
            $this->assertFalse($this->entitlement()->allowsNode('storefront.*'));

            Carbon::setTestNow(now()->addDay());
            $this->assertTrue($this->entitlement()->allowsNode('storefront.*'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_quitar_gana_a_sumar(): void
    {
        $this->planWith(Entitlements::LEGACY_BASE);

        foreach ([CompanyEntitlement::EFFECT_GRANT, CompanyEntitlement::EFFECT_REVOKE] as $effect) {
            CompanyEntitlement::query()->create([
                'company_id' => $this->company->id,
                'node' => 'promotions.*',
                'effect' => $effect,
                'starts_at' => now()->subDay(),
                'reason' => 'Choque de excepciones',
            ]);
        }

        $entitlement = $this->entitlement();

        $this->assertFalse($entitlement->allows('promotions.index.view'));
        $this->assertSame(Entitlement::SOURCE_REVOKE, $entitlement->explain('promotions.*')['source']);
    }

    public function test_una_excepcion_que_quita_corta_algo_del_plan(): void
    {
        $this->planWith(Entitlements::LEGACY_BASE);

        CompanyEntitlement::query()->create([
            'company_id' => $this->company->id,
            'node' => 'inventory.transfers.direct',
            'effect' => CompanyEntitlement::EFFECT_REVOKE,
            'starts_at' => now()->subDay(),
            'reason' => 'Que sigan el flujo completo',
        ]);

        $entitlement = $this->entitlement();

        $this->assertFalse($entitlement->allows('inventory.transfers.direct'));
        $this->assertTrue($entitlement->allows('inventory.transfers.dispatch'));
        $this->assertSame('some', $entitlement->explain('inventory.transfers.*')['state']);
    }

    public function test_lo_que_siempre_va_no_se_puede_quitar(): void
    {
        $this->planWith([]);

        CompanyEntitlement::query()->create([
            'company_id' => $this->company->id,
            'node' => 'settings.*',
            'effect' => CompanyEntitlement::EFFECT_REVOKE,
            'starts_at' => now()->subDay(),
            'reason' => 'Intento de encerrar a la empresa',
        ]);

        $entitlement = $this->entitlement();

        // Aunque el plan venga vacio y una excepcion quite Ajustes: inicio, ajustes y membresia quedan.
        foreach (['dashboard.index.view', 'settings.index.view', 'settings.membership.view', 'settings.membership.manage_payment'] as $permission) {
            $this->assertTrue($entitlement->allows($permission), $permission);
            $this->assertSame(Entitlement::SOURCE_ALWAYS, $entitlement->explain($permission)['source']);
        }

        $this->assertFalse($entitlement->allows('settings.index.edit'));
        $this->assertTrue(Entitlements::isAlways('settings.membership.*'));
        $this->assertFalse(Entitlements::isAlways('settings.*'));
    }

    public function test_lo_de_la_plataforma_se_rechaza_y_se_ignora(): void
    {
        $this->assertFalse(Entitlements::isValid('platform.*'));
        $this->assertFalse(Entitlements::isValid('companies.index.view'));
        $this->assertSame([], Entitlements::expand(['platform.*', 'companies.*']));

        $plan = $this->planWith(['platform.*', 'companies.*', 'inventory.*']);
        $entitlement = $this->entitlement();

        $this->assertFalse($entitlement->allows('platform.plans.view'));
        $this->assertFalse($entitlement->allows('companies.index.view'));
        $this->assertTrue($entitlement->allows('inventory.index.view'));

        // Y al guardar el plan, ni siquiera entra.
        $super = $this->makeSuperAdmin();
        $this->actingAs($super)
            ->put(route('platform.plans.update', $plan), [
                'name' => $plan->name, 'monthly_price' => 100000, 'trial_days' => 0,
                'entitlements' => ['inventory.*', 'platform.*'],
                'is_public' => true, 'is_featured' => false, 'is_active' => true, 'sort_order' => 1,
            ])
            ->assertSessionHasErrors('entitlements.1');
    }

    public function test_una_empresa_sin_plan_tiene_todo(): void
    {
        $this->assertNull($this->company->membership_plan_id);

        $entitlement = $this->entitlement();

        foreach (Entitlements::all() as $permission) {
            $this->assertTrue($entitlement->allows($permission), $permission);
        }

        foreach (array_keys(MembershipFeatures::CATALOGUE) as $feature) {
            $this->assertTrue($this->company->fresh()->hasFeature($feature), $feature);
        }

        // Lo de la plataforma tampoco lo tiene una empresa sin plan.
        $this->assertFalse($entitlement->allows('platform.plans.view'));
    }

    public function test_las_funciones_viejas_se_traducen_a_nodos(): void
    {
        $this->planWith([...Entitlements::LEGACY_BASE, 'promotions.*', 'stores.warehouses.create']);
        $company = $this->company->fresh();

        $this->assertTrue($company->hasFeature(MembershipFeatures::PROMOTIONS));
        $this->assertTrue($company->hasFeature(MembershipFeatures::MULTI_WAREHOUSE));
        $this->assertFalse($company->hasFeature(MembershipFeatures::ONLINE_STORE));
        $this->assertFalse($company->hasFeature(MembershipFeatures::CATALOG_IMPORT));
        $this->assertSame([MembershipFeatures::PROMOTIONS, MembershipFeatures::MULTI_WAREHOUSE], $company->features());
    }

    public function test_el_asignador_solo_pinta_lo_del_plan_y_cuenta_lo_demas(): void
    {
        $this->planWith(Entitlements::LEGACY_BASE);
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->withSession($this->withActiveStore())
            ->get(route('roles.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissionMatrix', fn ($modules) => ! collect($modules)->pluck('key')->contains('storefront')
                    && ! collect($modules)->pluck('key')->contains('promotions')
                    && collect($modules)->pluck('key')->contains('inventory'))
                ->where('permissionMatrixHidden', fn ($hidden) => $hidden > 0));
    }

    public function test_guardar_los_permisos_no_borra_lo_que_quedo_fuera_del_plan(): void
    {
        $this->planWith(Entitlements::LEGACY_BASE);
        $admin = $this->makeUser('admin');
        $cashier = $this->makeUser('cajero');
        $cashier->givePermissionTo('promotions.index.view');

        // La pantalla manda solo lo que pinta, que es lo del plan.
        $this->actingAs($admin)->withSession($this->withActiveStore())
            ->put(route('users.permissions.update', $cashier), ['permissions' => ['pos.sale.view', 'pos.sale.create']])
            ->assertRedirect(route('users.index'));

        $names = $cashier->fresh()->permissions()->pluck('name')->sort()->values()->all();

        $this->assertSame(['pos.sale.create', 'pos.sale.view', 'promotions.index.view'], $names);
    }

    public function test_las_rutas_que_vigilaba_el_middleware_viejo_quedan_cubiertas_por_sus_nodos(): void
    {
        // Copia congelada de `EnsurePlanFeatures::ROUTES` antes de retirarlo.
        $prefixes = [
            'storefront.editor.' => MembershipFeatures::ONLINE_STORE,
            'storefront.catalog.' => MembershipFeatures::ONLINE_STORE,
            'media.' => MembershipFeatures::ONLINE_STORE,
            'promotions.' => MembershipFeatures::PROMOTIONS,
            'coupons.' => MembershipFeatures::PROMOTIONS,
            'catalog.import.' => MembershipFeatures::CATALOG_IMPORT,
        ];

        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            $feature = collect($prefixes)->first(fn ($value, string $prefix) => str_starts_with($name, $prefix));

            if ($feature === null) {
                continue;
            }

            $permissions = collect($route->gatherMiddleware())
                ->filter(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'permission:'))
                ->flatMap(fn (string $middleware) => explode('|', substr($middleware, strlen('permission:'))))
                ->values();

            $this->assertNotEmpty($permissions, "La ruta {$name} no exige ningun permiso.");

            foreach ($permissions as $permission) {
                $covered = collect(Entitlements::LEGACY_FEATURES[$feature])->contains(fn (string $node) => Entitlements::covers($node, $permission));
                $this->assertTrue($covered, "La ruta {$name} exige {$permission}, que no cubren los nodos de {$feature}.");
            }

            $checked++;
        }

        $this->assertGreaterThan(20, $checked);
    }

    public function test_la_conversion_de_funciones_no_le_quita_ni_le_da_nada_a_nadie(): void
    {
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        $this->seed(\Database\Seeders\CompanySeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\UserSeeder::class);

        // Lo que vigilaba cada funcion antes: los permisos de sus rutas y la bodega extra.
        $prefixes = [
            'storefront.editor.' => MembershipFeatures::ONLINE_STORE,
            'storefront.catalog.' => MembershipFeatures::ONLINE_STORE,
            'media.' => MembershipFeatures::ONLINE_STORE,
            'promotions.' => MembershipFeatures::PROMOTIONS,
            'coupons.' => MembershipFeatures::PROMOTIONS,
            'catalog.import.' => MembershipFeatures::CATALOG_IMPORT,
        ];
        $gated = [MembershipFeatures::MULTI_WAREHOUSE => ['stores.warehouses.create']];

        foreach (Route::getRoutes() as $route) {
            $feature = collect($prefixes)->first(fn ($value, string $prefix) => str_starts_with((string) $route->getName(), $prefix));

            if ($feature === null) {
                continue;
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                    $gated[$feature] = [...($gated[$feature] ?? []), ...explode('|', substr($middleware, strlen('permission:')))];
                }
            }
        }

        $sets = [
            'basico' => [],
            'pro' => [MembershipFeatures::ONLINE_STORE, MembershipFeatures::PROMOTIONS],
            'cadena' => array_keys(MembershipFeatures::CATALOGUE),
        ];

        $company = Company::query()->where('slug', 'la-canasta')->firstOrFail();
        $users = User::query()->where('company_id', $company->id)->get();
        $this->assertGreaterThan(5, $users->count());

        foreach ($sets as $slug => $features) {
            $plan = MembershipPlan::query()->where('slug', $slug)->firstOrFail();
            $plan->update(['entitlements' => Entitlements::fromLegacyFeatures($features)]);
            $company->forceFill(['membership_plan_id' => $plan->id])->save();
            $this->service()->forget();

            $blocked = collect($gated)
                ->reject(fn ($permissions, string $feature) => in_array($feature, $features, true))
                ->flatten()
                ->unique()
                ->all();

            // Lo que nacio despues de los planes por funciones (medios de pago, suscriptores de la
            // tienda) no existia antes: no se puede ganar ni perder; se compara lo que si existia.
            $existed = fn (string $name) => ! str_starts_with($name, 'payment_methods.') && $name !== 'pos.sale.gateway' && ! str_starts_with($name, 'storefront.newsletter.');

            foreach ($users as $user) {
                $user->flushEffectivePermissionCache();
                $assigned = array_values(array_filter($user->permissions()->pluck('name')->all(), $existed));

                $before = array_values(array_diff($assigned, $blocked));
                $after = array_values(array_filter($user->getEffectivePermissionNames(), $existed));

                sort($before);
                sort($after);

                $this->assertSame($before, $after, "{$user->email} en el plan {$slug}");
            }
        }
    }

    public function test_los_planes_de_la_plataforma_nacen_con_sus_nodos(): void
    {
        $this->seed(\Database\Seeders\PlatformSeeder::class);

        $basico = MembershipPlan::query()->where('slug', 'basico')->firstOrFail();
        $pro = MembershipPlan::query()->where('slug', 'pro')->firstOrFail();
        $cadena = MembershipPlan::query()->where('slug', 'cadena')->firstOrFail();

        $this->assertNotContains('storefront.*', $basico->entitlements);
        $this->assertContains('storefront.*', $pro->entitlements);
        $this->assertContains('promotions.*', $pro->entitlements);
        $this->assertContains('coupons.*', $pro->entitlements);
        $this->assertNotContains('stores.*', $pro->entitlements);
        $this->assertContains('stores.*', $cadena->entitlements);
        $this->assertNotEmpty($pro->highlights);
    }
}

<?php

namespace Tests\Concerns;

use App\Models\BillingCycle;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerBalance;
use App\Models\Employee;
use App\Models\MembershipPlan;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Register;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Membership\Entitlements;
use App\Services\StoreDefaultRolesService;
use App\Services\UserPermissionService;
use App\Support\StoreContext;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;

/**
 * Entorno minimo para las pruebas: una empresa, una sede, una caja y un catalogo corto.
 *
 * Se arma a mano en vez de correr el seeder completo porque una prueba que tarda veinte
 * segundos en arrancar deja de ejecutarse.
 */
trait CreatesCompanyEnvironment
{
    protected Company $company;

    protected Store $store;

    protected Register $register;

    protected Category $category;

    public function seedEnvironment(): void
    {
        $this->seed(PermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'La Canasta',
            'legal_name' => 'La Canasta S.A.S.',
            'tax_id' => '900111222-3',
            'currency' => 'COP',
            'is_active' => true,
        ]);

        $this->store = Store::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Centro',
            'code' => 'CEN',
            'opens_at' => '07:00:00',
            'closes_at' => '21:00:00',
            'suggested_cash_base' => 200000,
            'is_active' => true,
        ]);

        $this->register = Register::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'name' => 'Caja 1',
            'code' => 'C1',
            'is_active' => true,
        ]);

        $this->category = Category::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Despensa',
            'slug' => 'despensa',
            'is_active' => true,
        ]);

        app(StoreDefaultRolesService::class)->createSuperAdminRole();
        app(StoreDefaultRolesService::class)->createFor($this->company);
    }

    protected function makeUser(string $role = 'admin', array $attributes = []): User
    {
        $user = User::create(array_merge([
            'company_id' => $this->company->id,
            'name' => 'Marcela',
            'last_name' => 'Ospina',
            'email' => 'usuario'.random_int(1000, 999999).'@lacanasta.co',
            'password' => Hash::make('Password123*'),
            'is_active' => true,
            'password_changed_at' => now(),
        ], $attributes));

        /** @var Role $roleModel */
        $roleModel = Role::query()->where('company_id', $this->company->id)->where('name', $role)->firstOrFail();

        $user->syncRoles([$roleModel]);
        $user->stores()->sync([$this->store->id]);

        $actor = User::query()->whereKeyNot($user->id)->first() ?? $user;
        app(UserPermissionService::class)->applyRoleTemplate($user, $roleModel, $actor);

        return $user->refresh();
    }

    protected function makeProduct(array $attributes = [], float $stock = 100): Product
    {
        $product = Product::query()->withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'category_id' => $this->category->id,
            'name' => 'Arroz blanco 1 kg',
            'sku' => 'SKU-'.random_int(1000, 999999),
            'unit' => 'unit',
            'cost' => 3600,
            'price' => 5100,
            'tax_rate' => 0,
            'min_stock' => 10,
            'is_active' => true,
        ], $attributes));

        ProductStock::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'store_id' => $this->store->id,
            'warehouse_id' => $this->warehouse()->id,
            'quantity' => $stock,
            'reserved_quantity' => 0,
        ]);

        return $product;
    }

    /**
     * La bodega principal de la sede de pruebas.
     *
     * Existe siempre: el modelo `Store` la crea al nacer la sede. Las pruebas que quieran una
     * segunda la crean aparte con `makeWarehouse()`.
     */
    protected function warehouse(): Warehouse
    {
        // Se busca por codigo y no por bandera: una prueba que mueva las banderas seguiria
        // refiriendose a la misma bodega, que es lo que casi siempre quiere decir.
        return Warehouse::query()->withoutGlobalScopes()
            ->where('store_id', $this->store->id)
            ->where('code', 'PRINCIPAL')
            ->firstOrFail();
    }

    /**
     * Una bodega mas en la sede activa.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeWarehouse(string $name = 'Bodega de almacenaje', array $attributes = []): Warehouse
    {
        return Warehouse::query()->withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'name' => $name,
            'code' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 6)).random_int(10, 99),
            'type' => Warehouse::TYPE_STORAGE,
            'is_active' => true,
        ], $attributes));
    }

    protected function makeCustomer(array $attributes = []): Customer
    {
        $customer = Customer::query()->withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'document_type' => 'CC',
            'document_number' => (string) random_int(10000000, 99999999),
            'name' => 'Luz Marina Agudelo',
            'credit_limit' => 0,
            'credit_term_days' => 0,
            'credit_enabled' => false,
            'is_active' => true,
        ], $attributes));

        CustomerBalance::create(['customer_id' => $customer->id, 'balance' => 0, 'overdue_balance' => 0]);

        return $customer->refresh();
    }

    protected function makeEmployee(array $attributes = []): Employee
    {
        return Employee::query()->withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'first_name' => 'Diana',
            'last_name' => 'Pulgarin',
            'document_type' => 'CC',
            'document_number' => (string) random_int(10000000, 99999999),
            'position' => 'Cajera',
            'contract_type' => 'indefinido',
            'hired_at' => now()->subYear()->toDateString(),
            'status' => Employee::STATUS_ACTIVE,
        ], $attributes));
    }

    /**
     * El cliente de la tienda en linea eligio esta sede (como lo haria en la landing). Sin sede
     * elegida la tienda devuelve al buscador.
     */
    protected function shopAt(?Store $store = null): static
    {
        return $this->withSession([\App\Support\StorefrontContext::SESSION_KEY => ($store ?? $this->store)->id]);
    }

    /**
     * Deja la tienda de una sede publicada y encendida: la sede queda vendible y aparece en la
     * landing. Cobertura por radio para que publicar no pida zonas.
     */
    protected function publishStorefront(?Store $store = null, ?User $author = null): \App\Models\Storefront
    {
        $store ??= $this->store;
        $storefront = app(\App\Services\Storefront\StorefrontManager::class)->for($store);

        $storefront->settings()->updateOrCreate(['key' => 'coverage'], ['value' => ['mode' => 'radius', 'radius_km' => 3]]);

        $author ??= User::query()->where('company_id', $store->company_id)->first() ?? User::query()->first() ?? $this->makeSuperAdmin();
        app(\App\Services\Storefront\StorefrontPublisher::class)->publish($storefront->refresh()->load('settings'), $author, 'Prueba');
        $storefront->forceFill(['is_online' => true])->save();

        return $storefront->refresh();
    }

    /** La sede activa vive en sesion; las pruebas que operan sobre ella la fijan primero. */
    protected function withActiveStore(): array
    {
        return [StoreContext::SESSION_KEY => $this->store->id];
    }

    /**
     * El super usuario: sin empresa, sin empleado, sin sedes asignadas.
     */
    protected function makeSuperAdmin(array $attributes = []): User
    {
        $user = User::create(array_merge([
            'company_id' => null,
            'name' => 'Soporte',
            'last_name' => 'Plataforma',
            'email' => 'super'.random_int(1000, 999999).'@plataforma.co',
            'password' => Hash::make('Password123*'),
            'is_active' => true,
            'password_changed_at' => now(),
        ], $attributes));

        $user->syncRoles(['super_admin']);
        $user->syncPermissions(Permission::query()->where('guard_name', 'web')->get());

        return $user->refresh();
    }

    /**
     * Otra empresa con su sede, su caja y sus roles, para probar que nada se cruza.
     *
     * @return array{company: Company, store: Store, register: Register}
     */
    protected function makeOtherCompany(string $name = 'Mercado El Vecino', string $taxId = '900333444-5'): array
    {
        $company = Company::create([
            'name' => $name,
            'legal_name' => $name.' S.A.S.',
            'tax_id' => $taxId,
            'currency' => 'COP',
            'is_active' => true,
        ]);

        $store = Store::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'name' => 'Envigado',
            'code' => 'ENV',
            'opens_at' => '07:00:00',
            'closes_at' => '21:00:00',
            'suggested_cash_base' => 150000,
            'is_active' => true,
        ]);

        $register = Register::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'store_id' => $store->id,
            'name' => 'Caja 1',
            'code' => 'C1',
            'is_active' => true,
        ]);

        app(StoreDefaultRolesService::class)->createFor($company);

        return ['company' => $company, 'store' => $store, 'register' => $register];
    }

    /**
     * Un plan con sus topes y funciones, y un ciclo mensual.
     *
     * @param  list<string>  $features
     * @return array{plan: MembershipPlan, cycle: BillingCycle}
     */
    protected function makePlan(array $attributes = [], array $features = []): array
    {
        $cycle = BillingCycle::query()->firstOrCreate(['code' => 'monthly'], [
            'name' => 'Mensual',
            'months' => 1,
            'discount_percent' => 0,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $plan = MembershipPlan::create(array_merge([
            'name' => 'Plan de prueba',
            'slug' => 'plan-'.random_int(1000, 999999),
            'monthly_price' => 100000,
            'trial_days' => 0,
            'max_stores' => null,
            'max_users' => null,
            'max_registers' => null,
            // Las pruebas siguen hablando de funciones (`online_store`, `promotions`): se traducen a
            // nodos igual que la conversion de los planes que ya existian.
            'entitlements' => Entitlements::fromLegacyFeatures($features),
            'is_public' => true,
            'is_active' => true,
            'sort_order' => 1,
        ], $attributes));

        return ['plan' => $plan, 'cycle' => $cycle];
    }

    /** Asigna un plan a la empresa de pruebas, activa y al dia. */
    protected function putCompanyOnPlan(MembershipPlan $plan, BillingCycle $cycle, string $status = Company::MEMBERSHIP_ACTIVE): void
    {
        $this->company->forceFill([
            'membership_plan_id' => $plan->id,
            'billing_cycle_id' => $cycle->id,
            'membership_status' => $status,
            'membership_started_at' => now()->subDays(5),
            'membership_ends_at' => now()->addDays(25),
        ])->save();
    }
}

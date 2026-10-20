<?php

namespace App\Services\Platform;

use App\Models\BillingCycle;
use App\Models\Company;
use App\Models\MembershipPlan;
use App\Models\Register;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\Membership\MembershipLimiter;
use App\Services\Membership\MembershipService;
use App\Services\PaymentTermService;
use App\Services\ProductAttributeCatalog;
use App\Services\SettingsService;
use App\Services\StoreDefaultRolesService;
use App\Services\Storefront\StorefrontManager;
use App\Services\UserPermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Crear una empresa lista para trabajar.
 *
 * Es el unico camino: lo usan Empresas, la contratacion en linea y la carga masiva. Una empresa
 * creada por otro lado queda sin roles, sin caja o sin tienda. Todo va en una transaccion: si un
 * paso falla, no queda media empresa.
 */
class CompanyProvisioner
{
    /** Las reglas de negocio que quedan como filas, igual que en `CompanySeeder`. */
    public const SEEDED_SETTINGS = ['pos.receipt_footer', 'pos.allow_discount_percent', 'pos.cash_rounding', 'credit.block_on_overdue_days'];

    public function __construct(
        protected StoreDefaultRolesService $roles,
        protected StorefrontManager $storefronts,
        protected SettingsService $settings,
        protected PaymentTermService $paymentTerms,
        protected UserPermissionService $permissions,
        protected MembershipService $membership,
        protected MembershipLimiter $limiter,
        protected ProductAttributeCatalog $attributes,
    ) {}

    /**
     * @param  array{
     *     company: array{name: string, legal_name?: ?string, tax_id: string, email?: ?string, phone?: ?string, address?: ?string, city?: ?string},
     *     store?: array{name?: ?string, code?: ?string, address?: ?string, city?: ?string, phone?: ?string, latitude?: ?float, longitude?: ?float},
     *     admin: array{name: string, last_name?: ?string, email: string, document?: ?string, phone?: ?string, password_hash?: ?string},
     *     plan_id?: ?int,
     *     cycle_id?: ?int,
     *     paid?: bool,
     *     signup_id?: ?int,
     * }  $data
     */
    public function provision(array $data, ?User $actor = null): ProvisionResult
    {
        $this->assertAvailable($data);

        return DB::transaction(function () use ($data, $actor) {
            $plan = isset($data['plan_id']) ? MembershipPlan::query()->find($data['plan_id']) : null;
            $cycle = isset($data['cycle_id']) ? BillingCycle::query()->find($data['cycle_id']) : null;

            // 1. La empresa, con su direccion web propia.
            $companyData = $data['company'];
            $company = Company::query()->create([
                'name' => trim($companyData['name']),
                'slug' => Company::uniqueSlug($companyData['name']),
                'legal_name' => trim((string) ($companyData['legal_name'] ?? '')) ?: trim($companyData['name']),
                'tax_id' => trim($companyData['tax_id']),
                'email' => $this->nullable($companyData['email'] ?? null, lower: true),
                'phone' => $this->nullable($companyData['phone'] ?? null),
                'address' => $this->nullable($companyData['address'] ?? null),
                'city' => $this->nullable($companyData['city'] ?? null),
                'business_category_id' => ! empty($companyData['business_category_id']) ? (int) $companyData['business_category_id'] : null,
                'currency' => 'COP',
                'is_active' => true,
                'membership_plan_id' => $plan?->id,
                'billing_cycle_id' => $cycle?->id,
                'signup_id' => $data['signup_id'] ?? null,
            ]);

            // Los topes del plan tambien valen aqui: un plan con cero sedes no puede nacer.
            foreach (['stores', 'users', 'registers'] as $resource) {
                $this->limiter->assertCanAdd($company->setRelation('plan', $plan), $resource);
            }

            // 2. Roles por defecto.
            $roles = $this->roles->createFor($company);

            // 3. Primera sede. El gancho `Store::created` le crea la «Bodega principal».
            $storeData = $data['store'] ?? [];
            $store = Store::query()->withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'name' => $this->nullable($storeData['name'] ?? null) ?? 'Principal',
                'code' => Str::upper($this->nullable($storeData['code'] ?? null) ?? 'PRI'),
                'address' => $this->nullable($storeData['address'] ?? null) ?? $company->address,
                'city' => $this->nullable($storeData['city'] ?? null) ?? $company->city,
                'phone' => $this->nullable($storeData['phone'] ?? null) ?? $company->phone,
                'latitude' => $storeData['latitude'] ?? null,
                'longitude' => $storeData['longitude'] ?? null,
                'opens_at' => '07:00:00',
                'closes_at' => '21:00:00',
                'suggested_cash_base' => 200000,
                'is_active' => true,
            ]);

            // 4. Una caja.
            Register::query()->withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'store_id' => $store->id,
                'name' => 'Caja 1',
                'code' => 'C1',
                'is_active' => true,
            ]);

            // 5. La tienda en linea de la sede, apagada hasta que la empresa ponga precios y publique.
            $storefront = $this->storefronts->for($store);
            $storefront->update(['is_online' => false]);

            // 6. Las reglas de negocio como filas; lo demas vive en `SettingsService::DEFAULTS`.
            foreach (self::SEEDED_SETTINGS as $key) {
                $this->settings->set($company->id, $key, SettingsService::DEFAULTS[$key]);
            }

            // 7. Plazos de pago y caracteristicas de fabrica (volumen, peso, sabor, talla…).
            $this->paymentTerms->seedDefaults($company);
            $this->attributes->seedDefaults($company->id);

            // 8. El administrador: rol `admin` y sus permisos directos, que son los que mandan.
            $adminData = $data['admin'];
            $temporaryPassword = null;

            if (empty($adminData['password_hash'])) {
                $temporaryPassword = Str::password(12, true, true, false);
            }

            $admin = User::query()->create([
                'company_id' => $company->id,
                'name' => trim($adminData['name']),
                'last_name' => $this->nullable($adminData['last_name'] ?? null),
                'email' => Str::lower(trim($adminData['email'])),
                'document' => $this->nullable($adminData['document'] ?? null),
                'phone' => $this->nullable($adminData['phone'] ?? null),
                'job_title' => 'Administrador',
                'password' => $adminData['password_hash'] ?? Hash::make((string) $temporaryPassword),
                'is_active' => true,
                // Con contrasena temporal, cambio obligatorio; la que eligio en la contratacion no.
                'password_change_required' => $temporaryPassword !== null,
                'password_changed_at' => $temporaryPassword !== null ? null : now(),
            ]);

            /** @var Role $adminRole */
            $adminRole = $roles['admin'];
            $admin->syncRoles([$adminRole]);
            $this->permissions->applyRoleTemplate($admin, $adminRole, $actor ?? $admin);

            // 9. La membresia.
            $this->membership->start($company, $plan, $cycle, $actor, (bool) ($data['paid'] ?? false));

            return new ProvisionResult($company->refresh(), $admin, $store, $temporaryPassword);
        });
    }

    /**
     * NIT y correo libres. Se revisa antes de abrir la transaccion para dar un error claro en vez
     * de una violacion de indice unico.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertAvailable(array $data): void
    {
        $errors = [];

        if (Company::query()->where('tax_id', trim((string) ($data['company']['tax_id'] ?? '')))->exists()) {
            $errors['tax_id'] = 'Ya hay una empresa con ese NIT.';
        }

        if (User::query()->withTrashed()->where('email', Str::lower(trim((string) ($data['admin']['email'] ?? ''))))->exists()) {
            $errors['admin_email'] = 'Ese correo ya tiene una cuenta en la plataforma.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    protected function nullable(mixed $value, bool $lower = false): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        if ($value === null || $value === '') {
            return null;
        }

        return $lower ? Str::lower($value) : $value;
    }
}

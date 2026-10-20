<?php

namespace App\Http\Middleware;

use App\Helpers\PermissionHelper;
use App\Models\Company;
use App\Models\Store;
use App\Models\Storefront;
use App\Models\User;
use App\Realtime\Topics;
use App\Services\BrandThemeService;
use App\Services\CartService;
use App\Services\Membership\EntitlementService;
use App\Services\Membership\MembershipService;
use App\Services\SettingsService;
use App\Services\Storefront\StorefrontRenderer;
use App\Support\Channels;
use App\Support\StoreContext;
use App\Support\StorefrontContext;
use App\Support\Surface;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // Guard explicito: en la tienda en linea el usuario de sesion es un cliente, no personal.
        $user = $request->user('web');
        $activeStore = $user ? StoreContext::activeStore($user) : null;
        $activeCompanyId = $user ? TenantContext::activeCompanyId($user) : null;

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'last_name' => $user->last_name,
                    'full_name' => $user->full_name,
                    'initials' => $user->initials,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                    'job_title' => $user->job_title,
                    'is_super_admin' => $user->isSuperAdmin(),
                    'permissions' => $user->getEffectivePermissionNames(),
                    'accessible_pages' => $user->getAccessiblePages(),
                    'role' => $this->roleFor($user),
                    'company' => $user->company ? [
                        'id' => $user->company->id,
                        'name' => $user->company->name,
                    ] : null,
                    'store' => $activeStore ? [
                        'id' => $activeStore->id,
                        'name' => $activeStore->name,
                        'code' => $activeStore->code,
                    ] : null,
                ] : null,
            ],
            // La tienda en linea tiene su propia sesion; se comparte aparte del personal.
            'customer' => fn () => auth('customer')->check() ? [
                'id' => auth('customer')->id(),
                'name' => auth('customer')->user()->name,
                'document_number' => auth('customer')->user()->document_number,
            ] : null,
            // El aspecto que la sede publico, para que TODA la tienda —no solo la portada— se
            // pinte con el color y la tipografia que se eligieron en el editor.
            'storeTheme' => fn () => $this->storeTheme($request),
            // De que empresa y sede es la tienda que se esta viendo: sale de la sede elegida.
            'storefrontCompany' => fn () => $this->storefrontCompany($request),
            // El cascaron de la tienda (barra superior, encabezado, menu, pie) tal como se publico.
            // Lo pintan todas sus paginas; por eso va aqui y no en cada controlador.
            'storefront' => fn () => $this->storefrontShell($request),
            // El resumen del carrito: cantidad, total, lineas y avance al domicilio gratis. No crea
            // carrito: mirar la tienda no deja filas en la base.
            'cart' => fn () => $this->storefrontCart($request),
            // La marca del panel y la caja. En la tienda en linea no: alli manda `storeTheme`.
            'brand' => fn () => match (true) {
                Surface::isStorefront($request) => null,
                // La landing, la contratacion y los textos legales son de la plataforma.
                $this->isPlatformPage($request) => app(BrandThemeService::class)->forCompany(null),
                default => app(BrandThemeService::class)->forCompany($user ? $activeCompanyId : $this->loginCompanyId()),
            },
            // El orden del riel de la caja. Solo en las pantallas de caja: las tres lo pintan y
            // tiene que ser el mismo en todas, pero el panel no lo necesita.
            'posRail' => fn () => $activeCompanyId !== null && Surface::for($request) === Surface::POS
                ? app(SettingsService::class)->get($activeCompanyId, 'pos.rail')
                : null,
            // Los canales que esta persona puede escuchar, ya armados. El navegador no compone
            // nombres de canal: si lo hiciera, un id mal puesto seria un intento de escuchar
            // otra empresa, y eso no deberia depender de codigo del navegador.
            'realtime' => $this->realtime($user, $activeCompanyId, $activeStore, $request->route()?->getName()),
            'activeCompany' => $this->activeCompany($activeCompanyId),
            // El selector de empresa del super usuario: «Todas» + la lista. Solo para el.
            'companiesForSelector' => fn () => $user?->isSuperAdmin()
                ? Company::query()->orderBy('name')->get(['id', 'name', 'is_active', 'membership_status'])
                    ->map(fn (Company $company) => [
                        'id' => $company->id,
                        'name' => $company->name,
                        'is_active' => $company->is_active,
                        'membership_status' => $company->membership_status,
                        'membership_label' => $company->membershipLabel(),
                    ])->values()->all()
                : [],
            'activeCompanyId' => $activeCompanyId,
            'isConsolidatedView' => (bool) $user?->isSuperAdmin() && $activeCompanyId === null,
            // La franja de aviso: prueba por vencer, gracia o suspension.
            'membershipNotice' => fn () => $user ? $this->membershipNotice($user, $activeCompanyId) : null,
            // El enlace «Ver la tienda» del panel: la tienda de la sede activa, si puede vender.
            'storefrontUrl' => fn () => $activeStore && ! Surface::isStorefront($request) ? $this->storefrontUrl($activeStore) : null,
            'activeStore' => $activeStore ? [
                'id' => $activeStore->id,
                'name' => $activeStore->name,
                'code' => $activeStore->code,
                'suggested_cash_base' => $activeStore->suggested_cash_base,
            ] : null,
            'storesForSelector' => $user
                ? StoreContext::availableStores($user)
                    ->map(fn (Store $store) => [
                        'id' => $store->id,
                        'name' => $store->name,
                        'code' => $store->code,
                    ])
                    ->values()
                    ->all()
                : [],
            'permissionMatrix' => fn () => $this->shouldSharePermissionMatrix($request)
                ? PermissionHelper::catalogue(only: app(EntitlementService::class)->forCompanyId($activeCompanyId)?->permissions())
                : null,
            // Cuantas funciones del catalogo no trae el plan: la nota al pie del asignador.
            'permissionMatrixHidden' => fn () => $this->shouldSharePermissionMatrix($request)
                ? $this->hiddenByPlan($activeCompanyId)
                : 0,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
                'temporary_password' => fn () => $request->session()->get('temporary_password'),
                'last_sale' => fn () => $request->session()->get('last_sale'),
                'last_return' => fn () => $request->session()->get('last_return'),
            ],
            'ziggy' => fn (): array => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ]);
    }

    /**
     * Los canales de tiempo real de esta persona.
     *
     * Se arman aqui y no en el navegador: el nombre del canal lleva la empresa adentro y es la
     * frontera de seguridad. Que lo componga el servidor evita que un id mal puesto en el front
     * termine pidiendo escuchar otra empresa. La autorizacion lo rechazaria igual, pero ese
     * intento no deberia existir.
     *
     * @return array<string, mixed>
     */
    protected function realtime(?User $user, ?int $companyId, ?Store $store, ?string $routeName = null): array
    {
        if (! $user || $companyId === null) {
            return ['enabled' => false, 'company_id' => null, 'store_id' => null, 'channels' => [], 'topics' => []];
        }

        // El catalogo es de la empresa: un precio nuevo lo es en todas las sedes. `changes` es el
        // aviso de que algo cambio en otra pestaña (`ResourcesChanged`).
        $channels = ['catalog' => Channels::catalog($companyId), 'changes' => Channels::changes($companyId)];

        if ($store) {
            $channels += [
                'orders' => Channels::orders($companyId, $store->id),
                'pos' => Channels::pos($companyId, $store->id),
                'registers' => Channels::registers($companyId, $store->id),
                'inventory' => Channels::inventory($companyId, $store->id),
                'presence' => Channels::presence($companyId, $store->id),
            ];
        }

        return [
            'enabled' => config('broadcasting.default') !== 'null',
            'company_id' => $companyId,
            'store_id' => $store?->id,
            'channels' => $channels,
            // Lo que esta pantalla muestra: un cambio de otro tema no la recarga.
            'topics' => Topics::forRoute($routeName),
        ];
    }

    /**
     * El tema publicado de la sede que atiende la tienda en linea.
     *
     * Solo se resuelve en las rutas de la tienda: el panel no lo necesita y consultarlo en
     * cada peticion del backoffice seria una consulta de mas por pantalla.
     *
     * @return array<string, mixed>|null
     */
    protected function storeTheme(Request $request): ?array
    {
        if (! str_starts_with($request->route()?->getName() ?? '', 'storefront.')) {
            return null;
        }

        $store = StorefrontContext::store();

        if (! $store) {
            return null;
        }

        $theme = app(StorefrontRenderer::class)->shell($store)['theme'] ?? [];

        return $theme === [] ? null : $theme;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function storefrontShell(Request $request): ?array
    {
        if (! Surface::isStorefront($request) || ($request->route()?->getName() === 'storefront.preview')) {
            return null;
        }

        $store = StorefrontContext::store();

        if (! $store) {
            return null;
        }

        return array_merge(app(StorefrontRenderer::class)->shell($store), [
            // Las otras sedes de la misma empresa que venden hoy, para «Recibir en».
            'stores' => StorefrontContext::stores()->map(fn (Store $item) => ['id' => $item->id, 'name' => $item->name])->values()->all(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function storefrontCart(Request $request): ?array
    {
        if (! Surface::isStorefront($request) || ($request->route()?->getName() === 'storefront.preview')) {
            return null;
        }

        $store = StorefrontContext::store();

        if (! $store) {
            return null;
        }

        $carts = app(CartService::class);

        return $carts->brief($carts->find($request, $store, auth('customer')->user()), $store);
    }

    protected function storefrontUrl(Store $store): ?string
    {
        if (! StorefrontContext::isSellable($store)) {
            return null;
        }

        $slug = Storefront::query()->withoutGlobalScopes()->where('store_id', $store->id)->value('slug');
        $company = Company::query()->whereKey($store->company_id)->value('slug');

        return $slug && $company ? route('storefront.entry', [$company, $slug]) : null;
    }

    /**
     * @return array{name: string, slug: string, store: array{id: int, name: string}}|null
     */
    protected function storefrontCompany(Request $request): ?array
    {
        if (! str_starts_with($request->route()?->getName() ?? '', 'storefront.')) {
            return null;
        }

        $store = StorefrontContext::store();

        if (! $store || ! $store->company) {
            return null;
        }

        return [
            'name' => $store->company->name,
            'slug' => $store->company->slug,
            'store' => ['id' => $store->id, 'name' => $store->name],
        ];
    }

    /**
     * El aviso de membresia: en prueba a menos de 7 dias, en gracia o suspendida.
     *
     * @return array<string, mixed>|null
     */
    protected function membershipNotice(User $user, ?int $companyId): ?array
    {
        $company = $companyId !== null ? TenantContext::company($user) : null;

        if (! $company) {
            return null;
        }

        $days = MembershipService::daysLeft($company);
        $status = $company->membership_status;

        $message = match (true) {
            $status === Company::MEMBERSHIP_SUSPENDED => 'La membresía está suspendida. Solo se puede entrar a pagarla.',
            $status === Company::MEMBERSHIP_CANCELLED => 'La membresía está cancelada. Comunícate con soporte.',
            $status === Company::MEMBERSHIP_GRACE => 'La membresía venció. Tienes hasta el '
                .$company->grace_ends_at?->timezone('America/Bogota')->format('d/m/Y').' para pagar antes de que se suspenda.',
            $status === Company::MEMBERSHIP_TRIAL && $days !== null && $days <= 7 => $days > 0
                ? "La prueba gratis termina en {$days} ".($days === 1 ? 'día' : 'días').'.'
                : 'La prueba gratis termina hoy.',
            default => null,
        };

        if ($message === null) {
            return null;
        }

        return [
            'status' => $status,
            'tone' => in_array($status, [Company::MEMBERSHIP_SUSPENDED, Company::MEMBERSHIP_CANCELLED], true) ? 'danger' : 'warn',
            'message' => $message,
            // «Pagar ahora» solo para quien puede pagar: el boton no se pinta sin permiso.
            'can_pay' => $user->isSuperAdmin() || $user->can('settings.membership.manage_payment'),
        ];
    }

    protected function isPlatformPage(Request $request): bool
    {
        $name = $request->route()?->getName() ?? '';

        return in_array($name, ['home', 'legal.terms', 'legal.privacy', 'platform.landing.preview'], true) || str_starts_with($name, 'signup.');
    }

    /**
     * La empresa cuya marca se ve en el ingreso: la unica activa. Con varias no se puede saber
     * cual antes de que la persona entre, y se muestra la marca neutra.
     */
    protected function loginCompanyId(): ?int
    {
        $ids = Company::query()->where('is_active', true)->limit(2)->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function roleFor($user): ?array
    {
        $role = $user->primaryRole();

        if (! $role) {
            return null;
        }

        return [
            'id' => $role->id,
            'name' => $role->name,
            'display_name' => $role->label(),
            'color' => $role->color,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function activeCompany(?int $companyId): ?array
    {
        if ($companyId === null) {
            return null;
        }

        $company = TenantContext::company();

        if (! $company || $company->id !== $companyId) {
            return null;
        }

        return [
            'id' => $company->id,
            'name' => $company->name,
            'slug' => $company->slug,
            'legal_name' => $company->legal_name,
            'tax_id' => $company->tax_id,
            'logo' => $company->logo,
            'is_active' => $company->is_active,
            'membership_status' => $company->membership_status,
            // Lo que el plan incluye: lo que no, no se pinta.
            'features' => $company->features(),
        ];
    }

    /** Cuantos permisos asignables deja fuera el plan de la empresa activa. */
    protected function hiddenByPlan(?int $companyId): int
    {
        $entitlement = app(EntitlementService::class)->forCompanyId($companyId);

        if (! $entitlement) {
            return 0;
        }

        return count(array_diff(PermissionHelper::assignablePermissions(), $entitlement->permissions()));
    }

    /**
     * La matriz completa pesa; solo la necesitan las pantallas que reparten permisos.
     */
    protected function shouldSharePermissionMatrix(Request $request): bool
    {
        $name = $request->route()?->getName() ?? '';

        return str_starts_with($name, 'roles.') || str_starts_with($name, 'users.');
    }
}

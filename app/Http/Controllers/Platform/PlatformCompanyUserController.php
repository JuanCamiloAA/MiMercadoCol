<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\SaveCompanyUserRequest;
use App\Models\Company;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\Membership\MembershipLimiter;
use App\Services\UserAccountService;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plataforma > Usuarios > De empresas: las cuentas de todas las empresas en un solo listado.
 *
 * Crear y editar pasa por `UserAccountService`, el mismo camino de Usuarios dentro de la empresa:
 * tope del plan, clave temporal, rol con su plantilla y sedes. Los permisos uno a uno se ajustan en
 * la empresa, porque el asignador pinta lo que su plan incluye: `permissions` entra a la empresa y
 * abre esa pantalla.
 *
 * Las sedes se leen sin `CompanyScope`: con una empresa elegida en el selector, el alcance
 * esconderia las sedes de las demas y el listado mentiria.
 */
class PlatformCompanyUserController extends Controller
{
    public function __construct(
        protected UserAccountService $accounts,
        protected MembershipLimiter $limiter,
    ) {}

    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'company' => $request->string('company')->toString(),
            'status' => $request->string('status')->toString(),
        ];

        $users = $this->companyUsers()
            ->with([
                'company:id,name,is_active',
                'roles:id,name,display_name,color',
                'stores' => fn ($query) => $query->withoutGlobalScopes()->select('stores.id', 'stores.name'),
            ])
            ->when($filters['search'], fn (Builder $query, string $search) => $query->where(function (Builder $inner) use ($search) {
                $inner->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.last_name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%")
                    ->orWhere('users.document', 'like', "%{$search}%");
            }))
            ->when(ctype_digit($filters['company']), fn (Builder $query) => $query->where('company_id', (int) $filters['company']))
            ->when(in_array($filters['status'], ['active', 'inactive'], true), fn (Builder $query) => $query->where('is_active', $filters['status'] === 'active'))
            ->orderBy(Company::query()->select('name')->whereColumn('companies.id', 'users.company_id'))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $user) => $this->present($user));

        return Inertia::render('Platform/Users/Companies', [
            'users' => $users,
            'filters' => $filters,
            'companies' => Company::query()->orderBy('name')->get(['id', 'name', 'is_active'])
                ->map(fn (Company $company) => [
                    'value' => $company->id,
                    'label' => $company->is_active ? $company->name : "{$company->name} (desactivada)",
                ])->values(),
            'tabCounts' => PlatformUserController::tabCounts(),
        ]);
    }

    /**
     * Roles y sedes de una empresa, para el formulario: se piden al elegirla.
     */
    public function options(Company $company): JsonResponse
    {
        return response()->json([
            'roles' => Role::query()
                ->where('company_id', $company->id)
                ->orderBy('display_name')
                ->get()
                ->map(fn (Role $role) => ['value' => $role->name, 'label' => $role->label()])
                ->values(),
            'stores' => Store::query()
                ->withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Store $store) => ['value' => $store->id, 'label' => $store->name])
                ->values(),
        ]);
    }

    public function store(SaveCompanyUserRequest $request): RedirectResponse
    {
        /** @var Company $company */
        $company = Company::query()->findOrFail($request->companyId());

        if (! $this->limiter->canAdd($company, 'users')) {
            throw ValidationException::withMessages(['company_id' => $this->limitMessage($company)]);
        }

        [$user, $temporaryPassword] = $this->accounts->createForCompany($company, $request->validated(), $request->user());

        return back()
            ->with('success', "{$user->full_name} quedó creado en {$company->name}. Debe cambiar la contraseña temporal al entrar.")
            ->with('temporary_password', $temporaryPassword);
    }

    public function update(SaveCompanyUserRequest $request, User $user): RedirectResponse
    {
        $this->ensureCompanyUser($user);

        $this->accounts->updateCompanyUser($user, $request->validated(), $request->user());

        return back()->with('success', "Los datos de {$user->full_name} quedaron guardados.");
    }

    public function toggle(User $user): RedirectResponse
    {
        $this->ensureCompanyUser($user);

        if (! $user->is_active && ! $this->limiter->canAdd($user->company, 'users')) {
            return back()->with('error', $this->limitMessage($user->company));
        }

        $user->update(['is_active' => ! $user->is_active]);

        return back()->with('success', $user->is_active
            ? "{$user->full_name} puede volver a entrar."
            : "{$user->full_name} quedó desactivado: no puede entrar y su sesión se cierra en la próxima acción.");
    }

    public function resetPassword(User $user): RedirectResponse
    {
        $this->ensureCompanyUser($user);

        $temporaryPassword = $this->accounts->resetPassword($user);

        return back()
            ->with('success', "La contraseña de {$user->full_name} quedó restablecida. Debe cambiarla al entrar.")
            ->with('temporary_password', $temporaryPassword);
    }

    /**
     * Entra a la empresa del usuario y abre sus permisos uno a uno.
     */
    public function permissions(User $user): RedirectResponse
    {
        $this->ensureCompanyUser($user);

        TenantContext::enter((int) $user->company_id);

        return redirect()->route('users.permissions', $user)
            ->with('success', "Ahora trabajas en {$user->company->name}.");
    }

    /**
     * @return Builder<User>
     */
    protected function companyUsers(): Builder
    {
        return User::query()->whereNotNull('company_id');
    }

    /** Un super usuario no se administra por aqui: va por la pestaña De plataforma. */
    protected function ensureCompanyUser(User $user): void
    {
        abort_if($user->company_id === null || $user->isSuperAdmin() || $user->company === null, 404);
    }

    protected function limitMessage(Company $company): string
    {
        $limit = (int) $this->limiter->limit($company, 'users');

        return "{$company->name} ya tiene {$limit} ".($limit === 1 ? 'usuario activo' : 'usuarios activos')
            .', el tope de su plan. Desactiva a alguien o súbele el plan.';
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(User $user): array
    {
        $role = $user->roles->first();

        return [
            'id' => $user->id,
            'full_name' => $user->full_name,
            'name' => $user->name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'document' => $user->document,
            'phone' => $user->phone,
            'job_title' => $user->job_title,
            'is_active' => (bool) $user->is_active,
            'password_change_required' => (bool) $user->password_change_required,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'company' => $user->company ? [
                'id' => $user->company->id,
                'name' => $user->company->name,
                'is_active' => (bool) $user->company->is_active,
            ] : null,
            'role' => $role ? [
                'name' => $role->name,
                'display_name' => $role->label(),
                'color' => $role->color,
            ] : null,
            'stores' => $user->stores->map(fn (Store $store) => ['id' => $store->id, 'name' => $store->name])->values()->all(),
        ];
    }
}

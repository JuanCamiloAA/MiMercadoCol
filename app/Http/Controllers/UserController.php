<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\Membership\MembershipLimiter;
use App\Services\UserAccountService;
use App\Services\UserPermissionService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(protected UserPermissionService $permissions) {}

    public function index(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();

        $filters = [
            'search' => $request->string('search')->toString(),
            'role' => $request->string('role')->toString(),
            'store' => $request->string('store')->toString(),
            'status' => $request->string('status')->toString(),
        ];

        $users = User::query()
            ->where('company_id', $companyId)
            ->with(['roles:id,name,display_name,color', 'stores:id,name'])
            ->when($filters['search'], fn ($query, $search) => $query->where(function ($inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('document', 'like', "%{$search}%");
            }))
            ->when($filters['role'], fn ($query, $role) => $query->whereHas('roles', fn ($inner) => $inner->where('name', $role)))
            ->when($filters['store'], fn ($query, $store) => $query->whereHas('stores', fn ($inner) => $inner->where('stores.id', $store)))
            ->when($filters['status'] !== '', fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $user) => $this->present($user));

        return Inertia::render('Users/Index', [
            'users' => $users,
            'filters' => $filters,
            'roles' => $this->roleOptions($companyId),
            'stores' => $this->storeOptions($companyId),
        ]);
    }

    public function create(): Response
    {
        $companyId = TenantContext::requireCompanyId();

        return Inertia::render('Users/Form', [
            'mode' => 'create',
            'user' => null,
            'roles' => $this->roleOptions($companyId),
            'stores' => $this->storeOptions($companyId),
        ]);
    }

    public function store(StoreUserRequest $request, UserAccountService $accounts): RedirectResponse
    {
        TenantContext::requireCompanyId();

        [, $temporaryPassword] = $accounts->createForCompany(TenantContext::company(), $request->validated(), $request->user());

        return redirect()->route('users.index')
            ->with('success', 'El usuario quedo creado.')
            ->with('temporary_password', $temporaryPassword);
    }

    public function show(User $user): Response
    {
        $this->authorizeCompany($user);

        $user->load(['roles:id,name,display_name,color', 'stores:id,name', 'employee']);

        return Inertia::render('Users/Show', [
            'user' => array_merge($this->present($user), [
                'permissions' => $this->permissions->namesFor($user),
                'employee' => $user->employee ? [
                    'id' => $user->employee->id,
                    'name' => $user->employee->full_name,
                    'position' => $user->employee->position,
                ] : null,
            ]),
        ]);
    }

    public function edit(User $user): Response
    {
        $this->authorizeCompany($user);

        $companyId = TenantContext::requireCompanyId();
        $user->load(['roles:id,name', 'stores:id']);

        return Inertia::render('Users/Form', [
            'mode' => 'edit',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'document' => $user->document,
                'phone' => $user->phone,
                'job_title' => $user->job_title,
                'role' => $user->roles->first()?->name,
                'store_ids' => $user->stores->pluck('id')->all(),
            ],
            'roles' => $this->roleOptions($companyId),
            'stores' => $this->storeOptions($companyId),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user, UserAccountService $accounts): RedirectResponse
    {
        $this->authorizeCompany($user);

        TenantContext::requireCompanyId();

        $accounts->updateCompanyUser($user, $request->validated(), $request->user());

        return redirect()->route('users.index')->with('success', 'El usuario quedo actualizado.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorizeCompany($user);

        if ($user->id === $request->user()->id) {
            return back()->with('error', 'No puedes eliminar tu propia cuenta.');
        }

        if ($user->sales()->exists()) {
            return back()->with('error', 'Ese usuario ya tiene ventas registradas: desactivalo en vez de eliminarlo.');
        }

        $user->delete();

        return redirect()->route('users.index')->with('success', 'El usuario quedo eliminado.');
    }

    public function toggle(Request $request, User $user, MembershipLimiter $limiter): RedirectResponse
    {
        $this->authorizeCompany($user);

        if ($user->id === $request->user()->id) {
            return back()->with('error', 'No puedes desactivar tu propia cuenta.');
        }

        if (! $user->is_active && ! $limiter->canAdd(TenantContext::company(), 'users')) {
            return back()->with('error', $limiter->message(TenantContext::company(), 'users'));
        }

        $user->update(['is_active' => ! $user->is_active]);

        return back()->with('success', $user->is_active ? 'La cuenta quedo activa.' : 'La cuenta quedo desactivada.');
    }

    public function permissions(User $user): Response
    {
        $this->authorizeCompany($user);

        return Inertia::render('Users/Permissions', [
            'user' => $this->present($user->load('roles:id,name,display_name,color')),
            'userPermissions' => $this->permissions->namesFor($user),
        ]);
    }

    public function updatePermissions(Request $request, User $user): RedirectResponse
    {
        $this->authorizeCompany($user);

        $validated = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ]);

        $this->permissions->syncWithinPlan($user, $validated['permissions'] ?? [], $request->user());

        return redirect()->route('users.index')->with('success', 'Los permisos quedaron guardados.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(User $user): array
    {
        $role = $user->roles->first();

        return [
            'id' => $user->id,
            'name' => $user->full_name,
            'first_name' => $user->name,
            'email' => $user->email,
            'document' => $user->document,
            'phone' => $user->phone,
            'job_title' => $user->job_title,
            'is_active' => (bool) $user->is_active,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'password_change_required' => (bool) $user->password_change_required,
            'has_pin' => $user->pos_pin !== null,
            'role' => $role ? [
                'name' => $role->name,
                'display_name' => $role->display_name ?: $role->name,
                'color' => $role->color,
            ] : null,
            'stores' => $user->relationLoaded('stores') ? $user->stores->map(fn (Store $store) => $store->name)->values()->all() : [],
        ];
    }

    /**
     * @return list<array{value: string, label: string, color: string}>
     */
    protected function roleOptions(int $companyId): array
    {
        return Role::query()
            ->where('company_id', $companyId)
            ->orderBy('display_name')
            ->get()
            ->map(fn (Role $role) => [
                'value' => $role->name,
                'label' => $role->label(),
                'color' => $role->color,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    protected function storeOptions(int $companyId): array
    {
        return Store::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Store $store) => ['value' => $store->id, 'label' => $store->name])
            ->values()
            ->all();
    }

    protected function authorizeCompany(User $user): void
    {
        abort_if(
            ! auth()->user()->isSuperAdmin() && $user->company_id !== TenantContext::activeCompanyId(),
            403,
            'Ese usuario no pertenece a tu empresa.'
        );
    }
}

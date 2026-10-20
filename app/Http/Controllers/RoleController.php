<?php

namespace App\Http\Controllers;

use App\Helpers\PermissionHelper;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\RoleTemplateService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    public function __construct(protected RoleTemplateService $templates) {}

    public function index(): Response
    {
        $companyId = TenantContext::requireCompanyId();

        $roles = Role::query()
            ->where('company_id', $companyId)
            ->withCount('permissions')
            ->orderBy('is_system', 'desc')
            ->orderBy('display_name')
            ->get()
            ->map(fn (Role $role) => $this->present($role));

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Roles/Form', [
            'mode' => 'create',
            'role' => null,
            'rolePermissions' => [],
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $validated = $request->validated();

        $role = Role::create([
            'company_id' => $companyId,
            'name' => $validated['name'],
            'guard_name' => 'web',
            'display_name' => $validated['display_name'],
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'],
            'is_system' => false,
        ]);

        $role->syncPermissions($this->resolvePermissions($validated['permissions'] ?? []));

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('roles.index')->with('success', "El rol «{$role->label()}» quedo creado.");
    }

    public function show(Role $role): Response
    {
        $this->authorizeCompany($role);

        $role->load('permissions');

        return Inertia::render('Roles/Show', [
            'role' => $this->present($role),
            'rolePermissions' => $role->permissions->pluck('name')->values(),
            'users' => $this->usersWithRole($role),
        ]);
    }

    public function edit(Role $role): Response
    {
        $this->authorizeCompany($role);

        $role->load('permissions');

        return Inertia::render('Roles/Form', [
            'mode' => 'edit',
            'role' => $this->present($role),
            'rolePermissions' => $role->permissions->pluck('name')->values(),
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $this->authorizeCompany($role);

        if ($role->is_system) {
            return back()->with('error', 'Los roles del sistema no se editan.');
        }

        $validated = $request->validated();
        $before = $role->permissions()->pluck('name')->all();
        $after = array_values(array_intersect(
            $validated['permissions'] ?? [],
            PermissionHelper::assignablePermissions()
        ));

        // El asignador solo pinta lo que el plan incluye: lo que la plantilla tenga fuera del plan
        // no se ve ahi, asi que se conserva tal cual en vez de borrarse.
        $entitlement = TenantContext::company()?->entitlement();

        if ($entitlement) {
            $after = array_values(array_unique([
                ...$entitlement->filter($after),
                ...array_filter($before, fn (string $name) => ! $entitlement->allows($name)),
            ]));
        }

        $role->update([
            'display_name' => $validated['display_name'],
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'],
        ]);

        $role->syncPermissions($this->resolvePermissions($after));

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $diff = $this->templates->diff($before, $after);

        if ($diff['added'] === [] && $diff['removed'] === []) {
            return redirect()->route('roles.index')->with('success', 'El rol quedo actualizado.');
        }

        // El cambio espera en sesion: el rol ya cambio, pero nadie se altera hasta propagar.
        session([RoleTemplateService::PENDING_SESSION_PREFIX.$role->id => $diff]);

        return redirect()->route('roles.propagate.show', $role)
            ->with('info', 'El rol cambio. Decide a quien le aplicas la diferencia.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->authorizeCompany($role);

        if ($role->is_system) {
            return back()->with('error', 'Los roles del sistema no se eliminan.');
        }

        if ($this->userCount($role) > 0) {
            return back()->with('error', 'No se puede eliminar un rol que todavia tiene usuarios.');
        }

        $role->delete();

        return redirect()->route('roles.index')->with('success', 'El rol quedo eliminado.');
    }

    /**
     * Diferencia pendiente y a quien afectaria, para el dialogo de propagacion.
     */
    public function pending(Role $role): Response
    {
        $this->authorizeCompany($role);

        $diff = session(RoleTemplateService::PENDING_SESSION_PREFIX.$role->id, ['added' => [], 'removed' => []]);

        return Inertia::render('Roles/Propagate', [
            'role' => $this->present($role),
            'diff' => $diff,
            'users' => $this->templates->usersForPropagation($role, $diff['added'], $diff['removed']),
        ]);
    }

    public function propagate(Request $request, Role $role): RedirectResponse
    {
        $this->authorizeCompany($role);

        $validated = $request->validate([
            'user_ids' => ['array'],
            'user_ids.*' => ['integer'],
        ]);

        $diff = session(RoleTemplateService::PENDING_SESSION_PREFIX.$role->id);

        if (! $diff) {
            return back()->with('error', 'No hay ningun cambio pendiente de aplicar.');
        }

        $affected = $this->templates->propagate(
            $role,
            array_map('intval', $validated['user_ids'] ?? []),
            $diff['added'],
            $diff['removed'],
            $request->user(),
        );

        session()->forget(RoleTemplateService::PENDING_SESSION_PREFIX.$role->id);

        return redirect()->route('roles.index')->with(
            'success',
            $affected === 0
                ? 'El rol quedo guardado y no se cambio a nadie.'
                : "Se aplico el cambio a {$affected} ".($affected === 1 ? 'usuario.' : 'usuarios.')
        );
    }

    /**
     * @param  list<string>  $names
     * @return \Illuminate\Database\Eloquent\Collection<int, Permission>
     */
    protected function resolvePermissions(array $names)
    {
        $valid = array_values(array_filter($names, fn ($name) => PermissionHelper::permissionExists($name)));

        return Permission::query()->whereIn('name', $valid)->where('guard_name', 'web')->get();
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'display_name' => $role->label(),
            'description' => $role->description,
            'color' => $role->color,
            'is_system' => (bool) $role->is_system,
            'permissions_count' => $role->permissions_count ?? $role->permissions()->count(),
            'users_count' => $this->userCount($role),
        ];
    }

    protected function userCount(Role $role): int
    {
        return (int) DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->where('model_type', (new User)->getMorphClass())
            ->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function usersWithRole(Role $role): array
    {
        $ids = DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->where('model_type', (new User)->getMorphClass())
            ->pluck('model_id');

        return User::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name', 'last_name', 'email', 'is_active'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->full_name,
                'email' => $user->email,
                'is_active' => (bool) $user->is_active,
            ])
            ->values()
            ->all();
    }

    protected function authorizeCompany(Role $role): void
    {
        abort_if(
            $role->company_id !== null && $role->company_id !== TenantContext::activeCompanyId() && ! auth()->user()->isSuperAdmin(),
            403,
            'Ese rol no pertenece a tu empresa.'
        );
    }
}

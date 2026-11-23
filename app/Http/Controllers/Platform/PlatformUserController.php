<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\SavePlatformUserRequest;
use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plataforma > Usuarios > De plataforma: los super usuarios. No pertenecen a ninguna empresa ni
 * sede; entran a todas y eligen con cual trabajar en el selector. Son el rol global `super_admin`
 * (sin `company_id`), el mismo que revisa `User::isSuperAdmin()`.
 *
 * No se eliminan, se desactivan: firman plantillas, importaciones y cambios de plataforma, y el
 * correo queda tomado por el indice unico aunque la fila se borre.
 */
class PlatformUserController extends Controller
{
    public function __construct(protected UserAccountService $accounts) {}

    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString(),
        ];

        $users = User::query()->superAdmins()
            ->when($filters['search'], fn (Builder $query, string $search) => $query->where(function (Builder $inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('document', 'like', "%{$search}%");
            }))
            ->when(in_array($filters['status'], ['active', 'inactive'], true), fn (Builder $query) => $query->where('is_active', $filters['status'] === 'active'))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $user) => $this->present($user, $request->user()));

        return Inertia::render('Platform/Users/Index', [
            'users' => $users,
            'filters' => $filters,
            'active' => User::query()->superAdmins()->where('is_active', true)->count(),
            'tabCounts' => self::tabCounts(),
        ]);
    }

    public function store(SavePlatformUserRequest $request): RedirectResponse
    {
        [$user, $temporaryPassword] = $this->accounts->createSuperAdmin($request->validated());

        return back()
            ->with('success', "{$user->full_name} ya es super usuario. Debe cambiar la contraseña temporal al entrar.")
            ->with('temporary_password', $temporaryPassword);
    }

    public function update(SavePlatformUserRequest $request, User $user): RedirectResponse
    {
        $this->ensurePlatformUser($user);

        $user->update($request->validated());

        return back()->with('success', "Los datos de {$user->full_name} quedaron guardados.");
    }

    public function toggle(Request $request, User $user): RedirectResponse
    {
        $this->ensurePlatformUser($user);

        // Quien actua esta activo y no se puede desactivar a si mismo: asi siempre queda uno.
        if ($user->is($request->user())) {
            return back()->with('error', 'No puedes desactivar tu propia cuenta.');
        }

        $user->update(['is_active' => ! $user->is_active]);

        return back()->with('success', $user->is_active
            ? "{$user->full_name} puede volver a entrar."
            : "{$user->full_name} quedó desactivado: no puede entrar y su sesión se cierra en la próxima acción.");
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $this->ensurePlatformUser($user);

        if ($user->is($request->user())) {
            return back()->with('error', 'Tu propia contraseña se cambia desde tu perfil.');
        }

        $temporaryPassword = $this->accounts->resetPassword($user);

        return back()
            ->with('success', "La contraseña de {$user->full_name} quedó restablecida. Debe cambiarla al entrar.")
            ->with('temporary_password', $temporaryPassword);
    }

    /**
     * Cuantos hay en cada pestaña del modulo (de plataforma y de empresas).
     *
     * @return array{platform: int, companies: int}
     */
    public static function tabCounts(): array
    {
        return [
            'platform' => User::query()->superAdmins()->count(),
            'companies' => User::query()->whereNotNull('company_id')->count(),
        ];
    }

    /** Un usuario de empresa no se administra por aqui: va por la pestaña De empresas. */
    protected function ensurePlatformUser(User $user): void
    {
        abort_unless($user->isSuperAdmin(), 404);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(User $user, User $viewer): array
    {
        return [
            'id' => $user->id,
            'full_name' => $user->full_name,
            'initials' => $user->initials,
            'name' => $user->name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'document' => $user->document,
            'phone' => $user->phone,
            'job_title' => $user->job_title,
            'is_active' => (bool) $user->is_active,
            'is_me' => $user->is($viewer),
            'password_change_required' => (bool) $user->password_change_required,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\AccessLog;
use App\Services\EffectivePermissionService;
use App\Services\Membership\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function __construct(protected EffectivePermissionService $permissions) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->is_active) {
            auth()->logout();

            return redirect()->route('login')->with('error', 'Tu cuenta esta desactivada. Contacta al administrador.');
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        // `a|b`: basta con uno. Lo usa el buscador de componentes, que sirve al crear y al editar.
        $names = array_map('trim', explode('|', $permission));
        $allowed = collect($names)->contains(fn (string $name) => $user->can($name));

        if ($allowed) {
            return $next($request);
        }

        // La persona tiene el permiso, pero el plan de su empresa no lo incluye: no es un «no
        // tienes permiso», es un «tu plan no lo trae», y se dice donde se cambia.
        $blocked = collect($names)->first(fn (string $name) => $this->permissions->blockedByPlan($user, $name));

        if ($blocked !== null) {
            AccessLog::log(AccessLog::ACTION_PLAN_DENIED, $user->id, [
                'permission_checked' => $permission,
                'permission_blocked' => $blocked,
                'route' => $request->route()?->getName(),
            ]);

            return $this->deny($request, EntitlementService::deniedMessage($blocked), $permission);
        }

        AccessLog::log(AccessLog::ACTION_PERMISSION_DENIED, $user->id, [
            'permission_checked' => $permission,
            'route' => $request->route()?->getName(),
        ]);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(403, "No tienes permiso para: {$permission}");
        }

        return $this->deny($request, 'No tienes permiso para abrir esta pagina.', $permission);
    }

    protected function deny(Request $request, string $message, string $permission): Response
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 403);
        }

        return inertia('Errors/403', [
            'permission' => $permission,
            'message' => $message,
        ])->toResponse($request)->setStatusCode(403);
    }
}

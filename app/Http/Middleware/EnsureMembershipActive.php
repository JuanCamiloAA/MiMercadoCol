<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Una empresa con la membresia suspendida solo puede ir a pagar.
 *
 * Deja pasar Ajustes (pestana Membresia), las rutas de pago, la salida y el cambio de clave;
 * todo lo demas vuelve a Membresia con un aviso. En gracia sigue funcionando todo: el aviso
 * lo pinta `AppLayout`. El super usuario pasa siempre, para poder arreglarla.
 */
class EnsureMembershipActive
{
    /** Rutas que siguen abiertas con la empresa suspendida. */
    public const ALLOWED = ['settings.index', 'settings.membership.', 'logout', 'password.change.'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if (! $user || $user->isSuperAdmin()) {
            return $next($request);
        }

        $company = TenantContext::company($user);

        if (! $company || ! $company->isSuspended() || $this->allowed($request)) {
            return $next($request);
        }

        $message = 'La membresía de la empresa está suspendida. Pagarla reactiva todo lo demás.';

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 402);
        }

        if (! $user->can('settings.membership.view')) {
            return Inertia::render('Errors/Suspended', [
                'company' => $company->name,
            ])->toResponse($request)->setStatusCode(402);
        }

        return redirect()->route('settings.index', ['pestana' => 'membresia'])->with('warning', $message);
    }

    protected function allowed(Request $request): bool
    {
        $name = (string) $request->route()?->getName();

        foreach (self::ALLOWED as $allowed) {
            if ($name === $allowed || (str_ends_with($allowed, '.') && str_starts_with($name, $allowed))) {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace App\Http\Middleware;

use App\Services\Membership\MembershipFeatures;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las funciones del plan, en las rutas del panel.
 *
 * Va en el grupo de rutas de empresa con un mapa de prefijos: una ruta nueva de promociones o de
 * la tienda queda cubierta sin que nadie tenga que acordarse de ponerle el middleware. Lo que no
 * es una ruta (la bodega extra, el precio de promocion, la tienda publica) se revisa donde pasa.
 */
class EnsurePlanFeatures
{
    /** Prefijo del nombre de la ruta => funcion que exige. */
    public const ROUTES = [
        'storefront.editor.' => MembershipFeatures::ONLINE_STORE,
        'storefront.catalog.' => MembershipFeatures::ONLINE_STORE,
        'media.' => MembershipFeatures::ONLINE_STORE,
        'promotions.' => MembershipFeatures::PROMOTIONS,
        'coupons.' => MembershipFeatures::PROMOTIONS,
        'catalog.import.' => MembershipFeatures::CATALOG_IMPORT,
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $feature = self::featureFor((string) $request->route()?->getName());

        if ($feature === null) {
            return $next($request);
        }

        $company = TenantContext::company($request->user('web'));

        if (! $company || $company->hasFeature($feature)) {
            return $next($request);
        }

        $message = self::message($feature);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 403);
        }

        if (! $request->isMethod('GET')) {
            return back()->with('error', $message);
        }

        return inertia('Errors/403', ['message' => $message])->toResponse($request)->setStatusCode(403);
    }

    public static function featureFor(string $routeName): ?string
    {
        foreach (self::ROUTES as $prefix => $feature) {
            if (str_starts_with($routeName, $prefix)) {
                return $feature;
            }
        }

        return null;
    }

    public static function message(string $feature): string
    {
        $label = MembershipFeatures::CATALOGUE[$feature]['label'] ?? $feature;

        return "Tu plan no incluye {$label}. Cámbialo en Ajustes > Membresía.";
    }
}

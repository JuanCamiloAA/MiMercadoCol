<?php

namespace App\Http\Middleware;

use App\Models\Storefront;
use App\Services\Membership\MembershipFeatures;
use App\Support\StorefrontContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * La tienda en linea solo atiende si la empresa puede vender por ahi: activa, con la membresia
 * sin suspender, con la tienda en su plan y con la tienda de la sede encendida. En gracia sigue
 * vendiendo.
 *
 * Se revisa en el servidor para todas las rutas de la tienda, pago incluido: esconder el boton
 * no basta.
 */
class EnsureStorefrontAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        $store = StorefrontContext::store();

        // Sin sede elegida no se escoge una al azar: se vuelve al buscador de la landing.
        if ($store === null) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => 'Elige primero una tienda.'], 409);
            }

            return redirect('/#tiendas');
        }

        $company = $store->company;

        if (! $company || ! $company->is_active || $company->isSuspended() || ! $company->hasFeature(MembershipFeatures::ONLINE_STORE)) {
            return $this->unavailable($request, 'Esta tienda no está disponible por ahora.', $company?->name);
        }

        $online = Storefront::query()->withoutGlobalScopes()->where('store_id', $store->id)->value('is_online');

        // Sin fila de tienda es una sede de antes del editor: atiende como siempre.
        if ($online !== null && ! $online) {
            return $this->unavailable($request, 'Esta tienda no está disponible por ahora.', $company->name);
        }

        return $next($request);
    }

    protected function unavailable(Request $request, string $message, ?string $name = null): Response
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 503);
        }

        if (! $request->isMethod('GET')) {
            return back()->with('error', $message);
        }

        return Inertia::render('Storefront/Unavailable', [
            'message' => $message,
            'companyName' => $name,
        ])->toResponse($request)->setStatusCode(503);
    }
}

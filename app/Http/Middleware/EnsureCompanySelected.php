<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las pantallas de una empresa necesitan una empresa.
 *
 * El super usuario en «Todas las empresas» no tiene ninguna elegida: en vez de mostrarle un
 * modulo vacio o mezclar datos de varias, se le pide que elija, y al elegir vuelve a donde iba.
 * Al resto de usuarios los deja pasar: siempre trabajan en la suya.
 */
class EnsureCompanySelected
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if (! $user || ! $user->isSuperAdmin() || TenantContext::activeCompanyId($user) !== null) {
            return $next($request);
        }

        $message = 'Elige una empresa en la parte superior para continuar.';

        if (! $request->isMethod('GET')) {
            return back()->with('error', $message);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 409);
        }

        return Inertia::render('Platform/ChooseCompany', [
            'destination' => $request->getRequestUri(),
            'companies' => Company::query()
                ->orderBy('name')
                ->get(['id', 'name', 'tax_id', 'city', 'is_active', 'membership_status'])
                ->map(fn (Company $company) => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'tax_id' => $company->tax_id,
                    'city' => $company->city,
                    'is_active' => $company->is_active,
                    'membership_status' => $company->membership_status,
                    'membership_label' => $company->membershipLabel(),
                ])
                ->values(),
        ])->toResponse($request);
    }
}

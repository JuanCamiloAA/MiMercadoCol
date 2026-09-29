<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserBelongsToCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        if (! $user->company_id) {
            auth()->logout();

            return redirect()->route('login')->with('error', 'Tu cuenta no esta asociada a ninguna empresa.');
        }

        $company = Company::query()->find($user->company_id);

        if (! $company) {
            auth()->logout();

            return redirect()->route('login')->with('error', 'Tu empresa ya no existe. Contacta al soporte.');
        }

        if (! $company->is_active) {
            auth()->logout();

            return redirect()->route('login')->with('error', 'La empresa esta desactivada. Contacta al soporte.');
        }

        return $next($request);
    }
}

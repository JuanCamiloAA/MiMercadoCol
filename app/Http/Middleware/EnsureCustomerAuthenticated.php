<?php

namespace App\Http\Middleware;

use App\Support\StorefrontContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cliente con sesion, y de la empresa de la tienda donde esta.
 *
 * Un cliente es de una empresa: con la sesion de La Canasta no se compra en El Vecino. Si llega
 * asi (otra pestaña, un enlace viejo), sale y se le pide entrar con su cuenta de esta tienda.
 */
class EnsureCustomerAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = Auth::guard('customer')->user();

        if (! $customer) {
            return redirect()->guest(route('storefront.login'));
        }

        $company = StorefrontContext::company();

        if (! $company || (int) $customer->company_id !== (int) $company->id) {
            Auth::guard('customer')->logout();

            return redirect()->guest(route('storefront.login'))
                ->with('warning', 'Tu cuenta es de otra tienda. Ingresa o regístrate en esta.');
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\StorefrontContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CustomerAuthController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Storefront/Login', [
            'store' => StorefrontContext::store()?->only(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'document_number' => ['required', 'string', 'max:40'],
            'password' => ['required', 'string'],
            'return_to' => ['nullable', 'string', 'max:500'],
        ], [
            'document_number.required' => 'Escribe tu documento.',
            'password.required' => 'Escribe tu contrasena.',
        ]);

        $key = Str::lower('storefront:'.$validated['document_number'].'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'document_number' => 'Demasiados intentos. Intenta de nuevo en '.RateLimiter::availableIn($key).' segundos.',
            ]);
        }

        $company = StorefrontContext::company();

        $customer = Customer::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company?->id)
            ->where('document_number', $validated['document_number'])
            ->where('is_online_user', true)
            ->where('is_active', true)
            ->first();

        if (! $customer || ! Auth::guard('customer')->attempt([
            'id' => $customer->id,
            'password' => $validated['password'],
        ])) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages([
                'document_number' => 'Los datos no coinciden con ninguna cuenta.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        // Desde el diálogo: se vuelve a la página donde estaba el cliente (o a la que pidió).
        if ($path = $this->returnPath($validated['return_to'] ?? null)) {
            $request->session()->forget('url.intended');

            return redirect()->to($path)->with('success', 'Hola, '.Str::before($customer->name, ' ').'. Ya entraste a tu cuenta.');
        }

        return redirect()->intended(route('storefront.checkout.create'));
    }

    /**
     * A dónde volver después de entrar. Solo una ruta de la tienda en este mismo sitio: del valor
     * que manda el navegador se toman la ruta y la consulta, nunca el dominio, así que no sirve
     * para mandar al cliente a otro lado. Las páginas de ingreso y registro vuelven a la portada.
     */
    protected function returnPath(?string $value): ?string
    {
        $parts = $value ? parse_url($value) : false;
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';
        $home = rtrim((string) parse_url(route('storefront.home'), PHP_URL_PATH), '/');

        // Nada de `//`, barras invertidas ni `..` (tampoco escritos como `%2e`): el navegador los
        // resolveria fuera de la tienda.
        $unsafe = str_contains($path, '//') || str_contains($path, '\\') || stripos($path, '%2e') !== false || preg_match('#(^|/)\.\.?(/|$)#', $path);

        if ($path === '' || $unsafe || ($path !== $home && ! str_starts_with($path, $home.'/'))) {
            return null;
        }

        $auth = array_map(fn (string $name) => rtrim((string) parse_url(route($name), PHP_URL_PATH), '/'), [
            'storefront.login', 'storefront.register', 'storefront.register.activation',
        ]);

        if (in_array(rtrim($path, '/'), $auth, true)) {
            return $home;
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('customer')->logout();
        $request->session()->regenerate();

        return redirect()->route('storefront.home')->with('success', 'Cerraste sesion.');
    }
}

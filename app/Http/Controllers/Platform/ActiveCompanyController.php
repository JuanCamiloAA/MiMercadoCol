<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\SwitchActiveCompanyRequest;
use App\Models\Company;
use App\Support\HomeResolver;
use App\Support\StoreContext;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

/**
 * El selector de empresa del super usuario.
 *
 * Guarda la empresa elegida en sesion y olvida la sede, que era de la empresa anterior. Vuelve
 * a la misma pantalla si es un listado; si la pantalla mostraba un registro de la otra empresa
 * (`/productos/15/editar`), vuelve al listado del modulo: nunca debe quedar un 404 por
 * `CompanyScope`.
 */
class ActiveCompanyController extends Controller
{
    public function store(SwitchActiveCompanyRequest $request): RedirectResponse
    {
        $id = $request->validated('company_id');

        if ($id !== null) {
            session([TenantContext::SESSION_KEY => (int) $id]);
        } else {
            session()->forget(TenantContext::SESSION_KEY);
        }

        session()->forget(StoreContext::SESSION_KEY);
        TenantContext::forgetCompany();

        $name = $id !== null ? Company::query()->whereKey($id)->value('name') : null;

        return redirect()->to($this->destination($request))->with(
            'success',
            $name !== null ? "Ahora trabajas en {$name}." : 'Vista de todas las empresas.',
        );
    }

    /**
     * A donde volver despues de cambiar de empresa.
     */
    protected function destination(Request $request): string
    {
        $fallback = HomeResolver::for($request->user());
        $url = $request->validated('redirect') ?: url()->previous();
        $path = '/'.ltrim((string) parse_url((string) $url, PHP_URL_PATH), '/');
        $query = parse_url((string) $url, PHP_URL_QUERY);

        try {
            $route = Route::getRoutes()->match(Request::create($path, 'GET'));
        } catch (Throwable) {
            return $fallback;
        }

        // Un listado o una pantalla sin registro: se vuelve a la misma.
        if ($route->parameterNames() === []) {
            return $path.($query ? '?'.$query : '');
        }

        // Un registro de la empresa anterior: al listado de su modulo, si lo hay.
        $name = (string) $route->getName();
        $candidates = array_unique([
            Str::beforeLast($name, '.').'.index',
            Str::before($name, '.').'.index',
        ]);

        foreach ($candidates as $candidate) {
            if (Route::has($candidate) && Route::getRoutes()->getByName($candidate)?->parameterNames() === []) {
                return route($candidate);
            }
        }

        return $fallback;
    }
}

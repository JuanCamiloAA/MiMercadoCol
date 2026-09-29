<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Storefront;
use App\Support\StorefrontContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * El enlace compartible de cada tienda: `/tienda/{empresa}/{tienda}`. Fija la sede y entra.
 */
class StorefrontEntryController extends Controller
{
    public function __invoke(string $companySlug, string $storefrontSlug): RedirectResponse|Response
    {
        $company = Company::query()->where('slug', $companySlug)->first();
        $storefront = $company
            ? Storefront::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('slug', $storefrontSlug)->first()
            : null;

        abort_if($storefront === null, 404);

        if (! StorefrontContext::setStore((int) $storefront->store_id)) {
            return Inertia::render('Storefront/Unavailable', [
                'message' => 'Esta tienda no está disponible por ahora.',
                'companyName' => $company->name,
            ])->toResponse(request())->setStatusCode(503);
        }

        return redirect()->route('storefront.home');
    }
}

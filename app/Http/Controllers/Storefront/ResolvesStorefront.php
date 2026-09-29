<?php

namespace App\Http\Controllers\Storefront;

use App\Models\Store;
use App\Models\Storefront;
use App\Services\Storefront\StorefrontManager;
use App\Support\StoreContext;
use App\Support\TenantContext;

/**
 * El sitio sobre el que trabaja el editor.
 *
 * Siempre es el de la sede activa. Cambiar de sede en el selector de la barra superior cambia
 * el borrador que se edita, sin que ninguna ruta tenga que llevar la sede en la URL.
 */
trait ResolvesStorefront
{
    protected function storefront(): Storefront
    {
        return app(StorefrontManager::class)->for($this->activeStore());
    }

    protected function activeStore(): Store
    {
        return Store::query()->findOrFail(StoreContext::requireStoreId());
    }

    /** Ningun recurso de otra empresa se toca desde aqui. */
    protected function assertOwned(?int $storefrontId): void
    {
        abort_if($storefrontId === null, 404);

        $owned = Storefront::query()
            ->withoutGlobalScopes()
            ->whereKey($storefrontId)
            ->where('company_id', TenantContext::requireCompanyId())
            ->exists();

        abort_if(! $owned && ! auth()->user()->isSuperAdmin(), 403, 'Eso no pertenece a tu empresa.');
    }
}

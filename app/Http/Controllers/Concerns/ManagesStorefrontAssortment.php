<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Company;
use App\Models\Store;
use App\Models\Storefront;
use App\Models\User;
use App\Services\Storefront\StorefrontAssortmentService;
use App\Services\WarehouseService;
use App\Support\StoreContext;
use App\Support\StorefrontContext;
use Illuminate\Http\Request;

/**
 * Lo que comparten las tres pantallas de «Tienda en linea»: de que sede se leen existencias y
 * ventas, que sedes se ofrecen en el selector y a que otros modulos puede saltar quien mira.
 */
trait ManagesStorefrontAssortment
{
    protected StorefrontAssortmentService $assortment;

    /**
     * Un servicio nuevo por peticion. Memoriza la bodega de la tienda, las demas y los ajustes
     * de la sede, y el controlador puede vivir mas que una peticion (las pruebas lo reusan):
     * heredar la memoria de la anterior contaria bodegas que ya no son.
     */
    protected function freshAssortment(): StorefrontAssortmentService
    {
        return $this->assortment = app(StorefrontAssortmentService::class);
    }

    /**
     * La sede pedida en `?store=`, o la activa. Solo una de las que el usuario puede ver: una
     * sede de otra empresa (o una que no le asignaron) no existe para el.
     */
    protected function assortmentStore(Request $request): Store
    {
        $stores = StoreContext::availableStores($request->user());
        $requested = $request->query('store');

        if ($requested !== null && $requested !== '') {
            $store = ctype_digit((string) $requested) ? $stores->firstWhere('id', (int) $requested) : null;

            abort_if($store === null, 404, 'Esa sede no existe o no la tienes asignada.');

            return $store;
        }

        $id = StoreContext::requireStoreId($request->user());

        return $stores->firstWhere('id', $id) ?? $stores->first();
    }

    /**
     * Las sedes del selector «Existencias de», cada una con la bodega que respalda su tienda.
     *
     * @return list<array{value: int, label: string, warehouse: string}>
     */
    protected function assortmentStores(Request $request): array
    {
        $warehouses = app(WarehouseService::class);

        return StoreContext::availableStores($request->user())
            ->map(fn (Store $store) => [
                'value' => $store->id,
                'label' => $store->name,
                'warehouse' => $warehouses->onlineSourceFor($store->id)->name,
            ])
            ->values()
            ->all();
    }

    /**
     * Los saltos a otros modulos. Cada uno exige su permiso alla; aqui solo se decide si se
     * pinta el boton.
     *
     * @return array<string, bool>
     */
    protected function assortmentCan(User $user): array
    {
        return [
            'transfer_requests_create' => $user->can('inventory.transfer_requests.create'),
            'purchases_create' => $user->can('purchases.index.create'),
            'purchases_view' => $user->can('purchases.index.view'),
            'transfers_view' => $user->can('inventory.transfers.view'),
            // La promocion se crea desde su listado: hace falta poder verlo y poder crear.
            'promotions_create' => $user->can('promotions.index.view') && $user->can('promotions.index.create'),
            'products_edit' => $user->can('products.index.edit'),
        ];
    }

    /** El enlace publico de la tienda de la sede, solo si hoy se puede comprar en ella. */
    protected function assortmentStorefrontUrl(Store $store): ?string
    {
        if (! StorefrontContext::isSellable($store)) {
            return null;
        }

        $slug = Storefront::query()->withoutGlobalScopes()->where('store_id', $store->id)->value('slug');
        $company = Company::query()->whereKey($store->company_id)->value('slug');

        return $slug && $company ? route('storefront.entry', [$company, $slug]) : null;
    }
}

<?php

namespace App\Support;

use App\Http\Controllers\Storefront\CheckoutController;
use App\Models\Company;
use App\Models\Store;
use App\Models\Storefront;
use App\Services\Membership\Entitlements;
use App\Services\Membership\EntitlementService;
use App\Services\Membership\MembershipFeatures;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * La sede que atiende la tienda en linea, y con ella la empresa.
 *
 * La empresa se deriva SIEMPRE de la sede elegida: nunca «la primera empresa activa». La sede se
 * elige en la landing (el buscador) o con el enlace de cada tienda, y queda en la sesion. Sin
 * sede elegida no hay tienda: se vuelve al buscador.
 */
final class StorefrontContext
{
    public const SESSION_KEY = 'storefront_store_id';

    /**
     * La sede elegida, si sigue activa. Si puede vender o no lo decide
     * `EnsureStorefrontAvailable`, para mostrar «no disponible» en vez de soltar al cliente.
     */
    public static function store(): ?Store
    {
        $id = session(self::SESSION_KEY);

        if (! $id) {
            return null;
        }

        $request = request();
        $cached = $request->attributes->get('storefront.store');

        if ($cached instanceof Store && $cached->id === (int) $id) {
            return $cached;
        }

        $store = Store::query()->withoutGlobalScopes()->with('company')->where('is_active', true)->find((int) $id);
        $request->attributes->set('storefront.store', $store);

        return $store;
    }

    public static function company(): ?Company
    {
        return self::store()?->company;
    }

    public static function requireStore(): Store
    {
        $store = self::store();

        abort_if($store === null, 503, 'La tienda en linea no esta disponible por ahora.');

        return $store;
    }

    /**
     * Las sedes vendibles de la misma empresa, para el selector interno de la tienda.
     *
     * @return Collection<int, Store>
     */
    public static function stores(): Collection
    {
        $store = self::store();

        if (! $store) {
            return collect();
        }

        return self::sellable()->where('stores.company_id', $store->company_id)->orderBy('stores.name')->get();
    }

    /**
     * Elige la sede. Acepta cualquier sede vendible de cualquier empresa. Si la empresa cambia,
     * el cliente de la anterior sale (es cliente de esa empresa, no de esta) y se olvidan su
     * cupon y su zona; el carrito lo separa `CartService` por empresa.
     */
    public static function setStore(int $storeId): bool
    {
        $store = self::sellable()->with('company')->find($storeId);

        if (! $store) {
            return false;
        }

        $previous = self::store();

        if ($previous && (int) $previous->company_id !== (int) $store->company_id) {
            Auth::guard('customer')->logout();
            session()->forget([CheckoutController::COUPON_SESSION_KEY, 'storefront.zone']);
        }

        session([self::SESSION_KEY => $store->id]);
        request()->attributes->set('storefront.store', $store);

        return true;
    }

    public static function forget(): void
    {
        session()->forget(self::SESSION_KEY);
        request()->attributes->remove('storefront.store');
    }

    /**
     * Si una sede puede vender en linea hoy.
     */
    public static function isSellable(Store $store): bool
    {
        return self::sellable()->whereKey($store->id)->exists();
    }

    /**
     * Las sedes que pueden vender en linea: sede activa; empresa activa, sin suspender, con la
     * tienda en su plan; tienda de la sede encendida y publicada. Es la misma regla para la
     * landing, el buscador y el enlace de cada tienda.
     *
     * @return Builder<Store>
     */
    public static function sellable(): Builder
    {
        // Las sin plan entran todas; las demas, si su plan (con sus excepciones) trae la tienda.
        $withStore = app(EntitlementService::class)->companiesAllowing(Entitlements::FEATURE_NODES[MembershipFeatures::ONLINE_STORE]);

        return Store::query()
            ->withoutGlobalScopes()
            ->select('stores.*')
            ->where('stores.is_active', true)
            ->whereIn('stores.company_id', $withStore)
            ->whereHas('company', fn (Builder $query) => $query
                ->where('is_active', true)
                ->whereNotIn('membership_status', [Company::MEMBERSHIP_SUSPENDED, Company::MEMBERSHIP_CANCELLED]))
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from((new Storefront)->getTable())
                ->whereColumn('storefronts.store_id', 'stores.id')
                ->where('storefronts.is_online', true)
                ->whereNotNull('storefronts.published_version_id'));
    }
}

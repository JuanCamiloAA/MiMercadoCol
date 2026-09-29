<?php

namespace App\Services\Storefront;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Service;
use App\Models\ServiceComponent;
use App\Models\Store;
use App\Services\PromotionService;
use App\Services\ServicePricing;
use App\Services\WarehouseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Los servicios de la tienda en linea.
 *
 * Visible y vendible en linea: activo, publicado, sin precio variable y con todos sus articulos
 * activos. Si a alguno le falta existencia en la bodega que respalda la tienda (y no admite
 * negativo), sale «Agotado», igual que un articulo. El precio lo pone `ServicePricing`, el mismo
 * que usan el carrito y el pedido.
 *
 * Un componente que es el articulo completo (cualquier presentacion) alcanza si alguna
 * presentacion alcanza; el cliente escoge cual en la pagina del servicio.
 */
class StorefrontServiceCatalog
{
    /** Tope que se muestra cuando ningun articulo del servicio limita cuantos se pueden pedir. */
    public const UNLIMITED = 999;

    public function __construct(
        protected ServicePricing $pricing,
        protected WarehouseService $warehouses,
    ) {}

    public function query(Store $store): Builder
    {
        return Service::query()
            ->withoutGlobalScopes()
            ->where('services.company_id', $store->company_id)
            ->whereNull('services.deleted_at')
            ->where('services.is_online_visible', true)
            ->where('services.open_price', false)
            ->sellable()
            ->with(['components.product', 'components.family']);
    }

    public function find(Store $store, int $id): ?Service
    {
        return $this->query($store)->find($id);
    }

    /**
     * @param  Collection<int, Service>  $services
     * @return list<array<string, mixed>>
     */
    public function present(Collection $services, Store $store): array
    {
        $stock = $this->stockFor($services, $store);

        return $services->map(fn (Service $service) => $this->presentOne($service, $stock))->values()->all();
    }

    /**
     * Cuantas unidades del servicio alcanzan con la existencia de la bodega de la tienda. Con
     * `$choices`, con las presentaciones elegidas; sin ellas, cada articulo completo con la
     * presentacion que mas tenga.
     *
     * @param  array<int|string, int|string>  $choices
     */
    public function available(Service $service, Store $store, array $choices = []): int
    {
        return $this->availableWith($service, $this->stockFor(collect([$service]), $store), $choices);
    }

    /**
     * @param  array<int, float>  $stock
     * @return array<string, mixed>
     */
    protected function presentOne(Service $service, array $stock): array
    {
        return [
            'kind' => 'service',
            'id' => $service->id,
            'name' => $service->name,
            'brand' => null,
            'unit' => 'unit',
            'sold_by_weight' => false,
            'price' => $this->pricing->priceFor($service, PromotionService::CHANNEL_ONLINE),
            'compare_at' => null,
            'promo_badge' => null,
            'unit_price' => null,
            'image_path' => $service->image_path,
            'thumb_path' => $service->thumb(),
            'category_id' => $service->category_id,
            'is_featured' => (bool) $service->is_featured,
            'available' => $this->availableWith($service, $stock),
            'includes' => $this->includes($service),
        ];
    }

    /**
     * Lo que trae el servicio, tal cual el maestro: el cliente no cambia las cantidades. En un
     * articulo completo escoge la presentacion: cada opcion dice cuanto cambia el precio del
     * servicio (`delta`, ya calculado aqui) y si hay.
     *
     * @param  array<int|string, int|string>  $choices
     * @return list<array<string, mixed>>
     */
    public function includes(Service $service, array $choices = [], ?Store $store = null): array
    {
        $service->loadMissing(['components.product', 'components.family']);
        $chosen = $this->pricing->resolveChoices($service, $choices);
        $options = $this->familyOptions($service);
        $stock = $store ? $this->stockFor(collect([$service]), $store) : [];

        return $service->components
            ->map(function (ServiceComponent $component) use ($chosen, $options, $service, $stock, $store) {
                $product = $chosen[$component->id] ?? $component->product;
                $entry = [
                    'name' => $component->isFamily() ? (string) ($component->family?->name ?? $product?->name) : (string) $product?->name,
                    'quantity' => round((float) $component->quantity, 3),
                    'unit' => (string) ($product?->unit ?? 'unit'),
                    // Con la presentacion que lleva: «Aceite de girasol · 900 ml». Lo que muestran el carrito y el pago.
                    'label' => (string) $product?->name,
                    'family' => null,
                ];

                if ($component->isFamily()) {
                    $base = $service->isItemized() && $component->product ? $this->pricing->componentPrice($component->product, PromotionService::CHANNEL_ONLINE) : 0;

                    $entry['family'] = [
                        'id' => (int) $component->product_family_id,
                        'chosen' => $product?->id,
                        'options' => $options->get($component->product_family_id, collect())->map(fn (Product $option) => [
                            'product_id' => $option->id,
                            'label' => $option->variant_label ?? $option->name,
                            'delta' => $service->isItemized() ? (int) round((float) $component->quantity * ($this->pricing->componentPrice($option, PromotionService::CHANNEL_ONLINE) - $base)) : 0,
                            'available' => $store === null || $option->allow_negative_stock || ($stock[$option->id] ?? 0) >= (float) $component->quantity,
                        ])->values()->all(),
                    ];
                }

                return $entry;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, float>  $stock  Disponible por articulo en la bodega de la tienda.
     * @param  array<int|string, int|string>  $choices
     */
    protected function availableWith(Service $service, array $stock, array $choices = []): int
    {
        $limit = self::UNLIMITED;
        $chosen = $this->pricing->resolveChoices($service, $choices);
        $options = $this->familyOptions($service);

        foreach ($service->components as $component) {
            $product = $chosen[$component->id] ?? $component->product;

            if (! $product || $product->allow_negative_stock || (float) $component->quantity <= 0) {
                continue;
            }

            // Sin eleccion, el articulo completo alcanza con la presentacion que mas tenga.
            $available = $component->isFamily() && ! isset($choices[$component->product_family_id])
                ? $options->get($component->product_family_id, collect())->max(fn (Product $option) => $option->allow_negative_stock ? PHP_INT_MAX : ($stock[$option->id] ?? 0)) ?? 0
                : ($stock[$product->id] ?? 0);

            $limit = min($limit, (int) floor(max(0, $available) / (float) $component->quantity + 0.0005));
        }

        return max(0, $limit);
    }

    /**
     * Las presentaciones activas de cada articulo completo del servicio.
     *
     * @return Collection<int, Collection<int, Product>>
     */
    protected function familyOptions(Service $service): Collection
    {
        $familyIds = $service->components->pluck('product_family_id')->filter()->unique();

        if ($familyIds->isEmpty()) {
            return collect();
        }

        return Product::query()->withoutGlobalScopes()
            ->where('company_id', $service->company_id)
            ->whereIn('product_family_id', $familyIds)
            ->where('is_active', true)
            ->orderBy('variant_sort')
            ->get()
            ->groupBy('product_family_id');
    }

    /**
     * @param  Collection<int, Service>  $services
     * @return array<int, float>
     */
    protected function stockFor(Collection $services, Store $store): array
    {
        $familyIds = $services->flatMap(fn (Service $service) => $service->components->pluck('product_family_id'))->filter()->unique();
        $ids = $services->flatMap(fn (Service $service) => $service->components->pluck('product_id'))
            ->merge($familyIds->isEmpty() ? [] : Product::query()->withoutGlobalScopes()->whereIn('product_family_id', $familyIds)->pluck('id'))
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $warehouseId = $this->warehouses->onlineSourceFor($store->id)->id;

        return ProductStock::query()->withoutGlobalScopes()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $ids)
            ->get(['product_id', 'quantity', 'reserved_quantity'])
            ->mapWithKeys(fn (ProductStock $row) => [$row->product_id => round((float) $row->quantity - (float) $row->reserved_quantity, 3)])
            ->all();
    }
}

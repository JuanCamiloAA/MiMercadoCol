<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceComponent;

/**
 * Unica fuente del precio de un servicio, por canal.
 *
 * - Precio cerrado: `online_price ?? price` en linea, `price` en caja. Los articulos valen $0.
 * - Servicio + articulos: el valor del servicio mas cada articulo al precio vigente del canal
 *   (`PromotionService`, promociones de precio si; las de cantidad no: el articulo es parte de un
 *   servicio, no mercancia tomada del estante). Si el maestro le fijo precio al articulo
 *   (`service_components.unit_price`), se cobra ese en los dos canales y sin promociones: es el
 *   precio que el comerciante decidio para ese articulo dentro de ese servicio.
 *
 * La usan la tienda, el carrito, los pedidos, la caja y la cotizacion: si una pantalla muestra y
 * otra cobra, las dos le preguntan aqui.
 */
class ServicePricing
{
    public function __construct(protected PromotionService $promotions) {}

    /** Lo que vale el servicio en si, sin articulos. */
    public function serviceValue(Service $service, string $channel): int
    {
        if ($service->open_price) {
            return 0;
        }

        if ($channel === PromotionService::CHANNEL_ONLINE && $service->online_price !== null) {
            return (int) $service->online_price;
        }

        return (int) $service->price;
    }

    /** Precio de un articulo dentro de un servicio: el vigente del canal, sin promociones de cantidad. */
    public function componentPrice(Product $product, string $channel): int
    {
        return $this->promotions->priceFor($product, (int) $product->company_id, $channel);
    }

    /**
     * Precio de un articulo del maestro: el fijado en el servicio o, si no tiene, el vigente del
     * canal. Un articulo que el cajero agrega sin estar en el maestro no tiene componente y va
     * siempre al vigente.
     */
    public function priceInService(?ServiceComponent $component, Product $product, string $channel): int
    {
        // El articulo completo se cobra a la presentacion que se eligio, sin precio fijo.
        if ($component !== null && $component->unit_price !== null && ! $component->isFamily()) {
            return max(0, (int) $component->unit_price);
        }

        return $this->componentPrice($product, $channel);
    }

    /**
     * Lo que cobra una linea de servicio tal cual el maestro: el valor por la cantidad y cada
     * articulo redondeado sobre su cantidad total, que es como lo arma el pedido.
     */
    public function lineTotal(Service $service, float $quantity, string $channel, array $choices = []): int
    {
        $breakdown = $this->breakdown($service, $channel, $choices);
        $total = (int) round($breakdown['value'] * $quantity);

        foreach ($breakdown['components'] as $component) {
            $total += (int) round($component['quantity'] * $quantity * $component['unit_price']);
        }

        return $total;
    }

    /** El precio del servicio tal cual el maestro (con las presentaciones elegidas), por una unidad. */
    public function priceFor(Service $service, string $channel, array $choices = []): int
    {
        return $this->breakdown($service, $channel, $choices)['total'];
    }

    /**
     * El articulo que lleva cada componente: el del maestro, o en un articulo completo la
     * presentacion elegida (`$choices`: familia => subarticulo). Una eleccion que no es de esa
     * familia, esta inactiva o ya no existe se ignora y queda la del maestro.
     *
     * @param  array<int|string, int|string>  $choices
     * @return array<int, Product|null> Id del componente => articulo.
     */
    public function resolveChoices(Service $service, array $choices = []): array
    {
        $service->loadMissing('components.product');
        $wanted = collect($choices)->mapWithKeys(fn ($productId, $familyId) => [(int) $familyId => (int) $productId])->filter();

        $options = $wanted->isEmpty() ? collect() : Product::query()->withoutGlobalScopes()
            ->where('company_id', $service->company_id)
            ->whereIn('id', $wanted->values())
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $resolved = [];

        foreach ($service->components as $component) {
            /** @var ServiceComponent $component */
            $chosen = $component->isFamily() ? $options->get($wanted->get((int) $component->product_family_id)) : null;

            $resolved[$component->id] = $chosen && (int) $chosen->product_family_id === (int) $component->product_family_id
                ? $chosen
                : $component->product;
        }

        return $resolved;
    }

    /**
     * Las elecciones validas de un servicio, limpias: solo articulos completos, solo
     * presentaciones de su familia.
     *
     * @param  array<int|string, int|string>  $choices
     * @return array<int, int> familia => subarticulo
     */
    public function cleanChoices(Service $service, array $choices): array
    {
        $resolved = $this->resolveChoices($service, $choices);
        $clean = [];

        foreach ($service->components as $component) {
            if ($component->isFamily() && ($resolved[$component->id] ?? null) && $resolved[$component->id]->id !== $component->product_id) {
                $clean[(int) $component->product_family_id] = (int) $resolved[$component->id]->id;
            }
        }

        return $clean;
    }

    /**
     * El precio desglosado: valor del servicio y cada articulo del maestro, por una unidad.
     *
     * @param  array<int|string, int|string>  $choices  En los articulos completos: familia => subarticulo elegido.
     * @return array{value: int, components: list<array{product_id: int, quantity: float, unit_price: int, fixed_price: bool, subtotal: int}>, total: int}
     */
    public function breakdown(Service $service, string $channel, array $choices = []): array
    {
        $service->loadMissing('components.product');
        $value = $this->serviceValue($service, $channel);
        $products = $this->resolveChoices($service, $choices);
        $components = [];
        $total = $value;

        foreach ($service->components as $component) {
            /** @var ServiceComponent $component */
            $product = $products[$component->id] ?? $component->product;
            $unit = $service->isItemized() && $product ? $this->priceInService($component, $product, $channel) : 0;
            $subtotal = (int) round((float) $component->quantity * $unit);

            $components[] = [
                'product_id' => (int) ($product?->id ?? $component->product_id),
                'quantity' => round((float) $component->quantity, 3),
                'unit_price' => $unit,
                'fixed_price' => $service->isItemized() && $component->unit_price !== null,
                'subtotal' => $subtotal,
            ];

            $total += $subtotal;
        }

        return ['value' => $value, 'components' => $components, 'total' => $total];
    }
}

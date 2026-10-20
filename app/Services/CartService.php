<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Service;
use App\Models\Store;
use App\Services\Storefront\StorefrontConfig;
use App\Services\Storefront\StorefrontProducts;
use App\Services\Storefront\StorefrontServiceCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Carrito de la tienda en linea.
 *
 * Vive en base de datos y no en la sesion: asi el cliente vuelve al dia siguiente desde
 * otro dispositivo y encuentra lo mismo que dejo.
 */
class CartService
{
    public const COOKIE = 'canasta_cart';

    /** Token del carrito de cada empresa: `[company_id => token]`. */
    public const TOKENS_KEY = 'storefront.cart_tokens';

    public function __construct(
        protected PromotionService $promotions,
        protected WarehouseService $warehouses,
        protected ServicePricing $servicePricing,
        protected StorefrontServiceCatalog $serviceCatalog,
        protected StorefrontConfig $config,
    ) {}

    /**
     * El carrito de esta empresa para quien compra.
     *
     * Un carrito es de una empresa: nunca se mueve a otra. Quien pasa de La Canasta a El Vecino
     * usa otro carrito, y al volver encuentra el suyo; por eso los tokens se guardan en sesion
     * por empresa. Entre sedes de la misma empresa el carrito si se mueve, como siempre.
     */
    public function current(Request $request, Store $store, ?Customer $customer = null): Cart
    {
        $companyId = (int) $store->company_id;
        $tokens = (array) $request->session()->get(self::TOKENS_KEY, []);

        // Un cliente de otra empresa no deberia llegar aqui (cambiar de empresa cierra su
        // sesion), pero si llega, su carrito no se toca.
        $customer = $customer && (int) $customer->company_id === $companyId ? $customer : null;

        $cart = $this->find($request, $store, $customer);

        if (! $cart) {
            $cart = Cart::create([
                'company_id' => $companyId,
                'store_id' => $store->id,
                'customer_id' => $customer?->id,
                'session_token' => Str::random(40),
                'expires_at' => now()->addDays(30),
            ]);
        }

        // Al entrar, el carrito anonimo pasa a ser del cliente.
        if ($customer && $cart->customer_id === null) {
            $cart->update(['customer_id' => $customer->id]);
        }

        if ($cart->store_id !== $store->id) {
            $cart->update(['store_id' => $store->id]);
        }

        $tokens[$companyId] = $cart->session_token;
        $request->session()->put(self::TOKENS_KEY, $tokens);
        $request->session()->put(self::COOKIE, $cart->session_token);

        return $cart;
    }

    /**
     * El carrito de esta empresa, si ya existe. No crea nada: mirar la tienda no deja filas.
     */
    public function find(Request $request, Store $store, ?Customer $customer = null): ?Cart
    {
        $companyId = (int) $store->company_id;
        $tokens = (array) $request->session()->get(self::TOKENS_KEY, []);
        $candidates = array_filter([$tokens[$companyId] ?? null, $request->cookie(self::COOKIE), $request->session()->get(self::COOKIE)]);
        $customer = $customer && (int) $customer->company_id === $companyId ? $customer : null;

        if ($customer) {
            $cart = Cart::query()->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('customer_id', $customer->id)
                ->latest()
                ->first();

            if ($cart) {
                return $cart;
            }
        }

        foreach ($candidates as $token) {
            $cart = Cart::query()->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('session_token', $token)
                ->first();

            if ($cart) {
                return $cart;
            }
        }

        return null;
    }

    /**
     * Deja la cantidad de un articulo o servicio en el carrito tal cual se pide: absoluta, no
     * sumada. Dos toques rapidos que mandan «3» dejan 3, no 6. Cero lo quita.
     */
    /**
     * @param  array<int, int>  $choices  En un servicio: la presentacion elegida de cada articulo completo.
     */
    public function setQuantity(Cart $cart, Product|Service $item, float $quantity, ?array $choices = null): ?CartItem
    {
        $column = $item instanceof Product ? 'product_id' : 'service_id';
        $line = CartItem::query()->where('cart_id', $cart->id)->where($column, $item->id)->first();

        if ($quantity <= 0) {
            $line?->delete();

            return null;
        }

        if ($line) {
            $line->update(array_filter([
                'quantity' => round($quantity, 3),
                'choices' => $item instanceof Service && $choices !== null ? $choices : null,
            ], fn ($value) => $value !== null));

            return $line;
        }

        return CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $item instanceof Product ? $item->id : null,
            'service_id' => $item instanceof Service ? $item->id : null,
            'quantity' => round($quantity, 3),
            'choices' => $item instanceof Service ? ($choices ?? []) : null,
            'substitution_policy' => 'call',
        ]);
    }

    /**
     * El resumen que viaja con cada pagina de la tienda (prop `cart`).
     *
     * El total sale del mismo calculo que el carrito y el checkout: el navegador nunca suma
     * precios. El avance al domicilio gratis y el minimo salen de lo publicado.
     *
     * @return array<string, mixed>
     */
    public function brief(?Cart $cart, Store $store): array
    {
        $summary = $cart ? $this->summary($cart, $store) : ['items' => [], 'subtotal' => 0, 'count' => 0];
        $minimum = $this->config->minOrderTotal($store);

        return [
            'count' => $summary['count'],
            'lines_count' => count($summary['items']),
            'total' => $summary['subtotal'],
            'lines' => array_map(fn (array $item) => ['key' => $item['key'], 'quantity' => $item['quantity']], $summary['items']),
            'free_delivery' => $this->config->freeDelivery($store, $summary['subtotal']),
            'min_order_total' => $minimum,
            'min_order_missing' => $minimum > 0 ? max(0, $minimum - $summary['subtotal']) : 0,
        ];
    }

    /**
     * Un servicio en el carrito: un renglon con `service_id` y cantidad. Sus articulos no se
     * guardan aqui: se toman del maestro al crear el pedido.
     */
    public function addService(Cart $cart, Service $service, float $quantity): CartItem
    {
        $item = CartItem::query()->where('cart_id', $cart->id)->where('service_id', $service->id)->first();

        if ($item) {
            $item->update(['quantity' => round($item->quantity + $quantity, 3)]);

            return $item;
        }

        return CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => null,
            'service_id' => $service->id,
            'quantity' => round($quantity, 3),
            'substitution_policy' => 'call',
        ]);
    }

    public function add(Cart $cart, Product $product, float $quantity, string $policy = 'call'): CartItem
    {
        $item = CartItem::query()->where('cart_id', $cart->id)->where('product_id', $product->id)->first();

        if ($item) {
            $item->update([
                'quantity' => round($item->quantity + $quantity, 3),
                'substitution_policy' => $policy,
            ]);

            return $item;
        }

        return CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => round($quantity, 3),
            'substitution_policy' => $policy,
        ]);
    }

    public function updateQuantity(CartItem $item, float $quantity): void
    {
        if ($quantity <= 0) {
            $item->delete();

            return;
        }

        $item->update(['quantity' => round($quantity, 3)]);
    }

    /**
     * Contenido del carrito con precios y disponibilidad de la sede.
     *
     * Una linea que la tienda ya no le mostraria al cliente —se agoto en la bodega de la tienda,
     * se despublico, quedo sin precio— llega con `unavailable` y su motivo: no suma al subtotal,
     * no entra a las promociones y el carrito no deja pagar mientras este (`has_unavailable`).
     *
     * @return array{items: list<array<string, mixed>>, subtotal: int, savings: int, count: float, has_unavailable: bool}
     */
    public function summary(Cart $cart, Store $store): array
    {
        $cart->load(['items.product.family.images', 'items.service.components.product']);

        $present = $cart->items->filter(fn (CartItem $item) => $item->product !== null)->values();
        $services = $cart->items->filter(fn (CartItem $item) => $item->product_id === null && $item->service !== null && ! $item->service->trashed())->values();

        // Se resuelve una vez y no por renglon: es la misma bodega para todo el carrito, y lo
        // disponible de todas sus lineas sale en una sola consulta.
        $warehouse = $this->warehouses->onlineSourceFor($store->id);
        $stocks = ProductStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->whereIn('product_id', $present->pluck('product_id'))
            ->get(['product_id', 'quantity', 'reserved_quantity'])
            ->keyBy('product_id');
        $availableOf = fn (CartItem $item): float => isset($stocks[$item->product_id])
            ? round((float) $stocks[$item->product_id]->quantity - (float) $stocks[$item->product_id]->reserved_quantity, 3)
            : 0.0;
        $reasons = $present->mapWithKeys(fn (CartItem $item) => [$item->id => $this->unavailableReason($item->product, $availableOf($item))]);
        $sellable = $present->filter(fn (CartItem $item) => $reasons[$item->id] === null)->values();

        // El carrito cobra lo mismo que la venta: el precio sale del motor de promociones, no
        // del precio de lista, para que el total no cambie al confirmar el pedido. Lo que ya no
        // se puede vender no entra: no debe completar un 3x2.
        $promoted = $this->promotions->apply(
            $sellable->map(fn (CartItem $item) => [
                'product_id' => (int) $item->product_id,
                'quantity' => (float) $item->quantity,
            ])->all(),
            $store->company_id,
            PromotionService::CHANNEL_ONLINE,
        );
        $lines = $sellable->values()->mapWithKeys(fn (CartItem $item, int $index) => [$item->id => $promoted['lines'][$index] ?? null]);

        $items = [];
        $subtotal = 0;
        $savings = 0;
        $count = 0.0;
        $unavailable = false;

        foreach ($present as $item) {
            $line = $lines[$item->id] ?? null;
            $reason = $reasons[$item->id];
            $price = $line ? (int) $line['unit_price'] : $item->product->storePrice();
            $discount = $line ? (int) ($line['discount'] ?? 0) : 0;
            $listed = $item->product->storePrice();
            $lineTotal = (int) round($item->quantity * $price) - $discount;

            $image = StorefrontProducts::productImage($item->product);

            $items[] = [
                'id' => $item->id,
                'key' => 'p:'.$item->product_id,
                'kind' => 'product',
                'image' => $image['url'],
                'href' => StorefrontProducts::productHref($item->product),
                'step' => $item->product->sold_by_weight ? 0.5 : 1,
                'product_id' => $item->product_id,
                'service_id' => null,
                'category_id' => $item->product->category_id,
                'name' => $item->product->name,
                'brand' => $item->product->brand,
                'unit' => $item->product->unit,
                'sold_by_weight' => (bool) $item->product->sold_by_weight,
                'image_path' => $item->product->thumb(),
                'price' => $price,
                'compare_at' => $price < $listed ? $listed : null,
                'discount' => $discount,
                'quantity' => round((float) $item->quantity, 3),
                'line_total' => $lineTotal,
                'substitution_policy' => $item->substitution_policy,
                'available' => $availableOf($item),
                'unavailable' => $reason !== null,
                'unavailable_reason' => $reason,
            ];

            if ($reason !== null) {
                $unavailable = true;

                continue;
            }

            $subtotal += $lineTotal;
            $savings += (int) round($item->quantity * max(0, $listed - $price)) + $discount;
            $count += (float) $item->quantity;
        }

        // Los servicios, al precio en linea de `ServicePricing`: el mismo que cobra el pedido.
        foreach ($services as $item) {
            $service = $item->service;
            $published = $service->is_active && $service->is_online_visible && ! $service->open_price && $service->isSellable();
            $choices = (array) ($item->choices ?? []);
            $available = $published ? $this->serviceCatalog->available($service, $store, $choices) : 0;
            $reason = ! $published ? 'unpublished' : ($available < 1 ? 'out_of_stock' : null);
            $lineTotal = $this->servicePricing->lineTotal($service, (float) $item->quantity, PromotionService::CHANNEL_ONLINE, $choices);

            $image = StorefrontProducts::image($service->thumb() ?: $service->image_path, null);

            $items[] = [
                'id' => $item->id,
                'key' => 's:'.$service->id,
                'kind' => 'service',
                'image' => $image['url'],
                'href' => '/tienda/servicio/'.$service->id,
                'step' => 1,
                'product_id' => null,
                'service_id' => $service->id,
                'category_id' => $service->category_id,
                'name' => $service->name,
                'brand' => null,
                'unit' => 'unit',
                'sold_by_weight' => false,
                'image_path' => $service->thumb(),
                'price' => $this->servicePricing->priceFor($service, PromotionService::CHANNEL_ONLINE, $choices),
                'choices' => $choices,
                'compare_at' => null,
                'discount' => 0,
                'quantity' => round((float) $item->quantity, 3),
                'line_total' => $lineTotal,
                'substitution_policy' => $item->substitution_policy,
                'available' => $available,
                'unavailable' => $reason !== null,
                'unavailable_reason' => $reason,
                'includes' => $this->serviceCatalog->includes($service, $choices),
            ];

            if ($reason !== null) {
                $unavailable = true;

                continue;
            }

            $subtotal += $lineTotal;
            $count += (float) $item->quantity;
        }

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'savings' => $savings,
            'promotions' => $promoted['applied'],
            'count' => round($count, 3),
            'has_unavailable' => $unavailable,
        ];
    }

    /**
     * Por que la tienda ya no le venderia esta linea: `unpublished` (inactivo, despublicado, sin
     * precio o con su articulo principal oculto) u `out_of_stock` (sin disponible en la bodega
     * de la tienda y sin permiso de vender en negativo). La misma regla de `StorefrontProducts::query()`.
     */
    public function unavailableReason(Product $product, float $available): ?string
    {
        $family = $product->product_family_id ? $product->family : null;

        if (! $product->is_active || ! $product->is_online_visible || $product->storePrice() <= 0
            || ($product->product_family_id && (! $family || ! $family->is_active || ! $family->is_online_visible))) {
            return 'unpublished';
        }

        return ! $product->allow_negative_stock && $available <= 0 ? 'out_of_stock' : null;
    }

    public function clear(Cart $cart): void
    {
        CartItem::query()->where('cart_id', $cart->id)->delete();
    }
}

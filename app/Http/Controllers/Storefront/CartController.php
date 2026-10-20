<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\SetCartQuantityRequest;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\StorefrontBlock;
use App\Services\CartService;
use App\Services\ServicePricing;
use App\Services\StockService;
use App\Services\Storefront\StorefrontConfig;
use App\Services\Storefront\StorefrontRenderer;
use App\Services\Storefront\StorefrontServiceCatalog;
use App\Services\WarehouseService;
use App\Support\StorefrontContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CartController extends Controller
{
    public function __construct(
        protected CartService $carts,
        protected StorefrontConfig $config,
        protected StorefrontServiceCatalog $services,
        protected StockService $stock,
        protected WarehouseService $warehouses,
        protected StorefrontRenderer $renderer,
        protected ServicePricing $pricing,
    ) {}

    public function index(Request $request): Response
    {
        $store = StorefrontContext::requireStore();
        $customer = auth('customer')->user();
        // Mirar el carrito no crea uno.
        $cart = $this->carts->find($request, $store, $customer);
        $summary = $cart ? $this->carts->summary($cart, $store) : ['items' => [], 'subtotal' => 0, 'savings' => 0, 'count' => 0, 'has_unavailable' => false];
        $settings = $this->config->settings($store);
        $minimum = $this->config->minOrderTotal($store);

        return Inertia::render('Storefront/Cart', [
            'store' => ['id' => $store->id, 'name' => $store->name],
            'items' => $summary['items'],
            'summary' => [
                'subtotal' => $summary['subtotal'],
                'savings' => $summary['savings'] ?? 0,
                'delivery_enabled' => (bool) ($settings['delivery']['enabled'] ?? true),
                'delivery_fee' => $this->config->deliveryFee($store, $summary['subtotal']),
                'free_delivery' => $this->config->freeDelivery($store, $summary['subtotal']),
                'min_order_total' => $minimum,
                'min_order_missing' => $minimum > 0 ? max(0, $minimum - $summary['subtotal']) : 0,
                'delivery_note' => $settings['texts']['delivery_note'] ?? null,
                // Una linea que se agoto o se despublico: hay que quitarla para poder pagar.
                'has_unavailable' => $summary['has_unavailable'],
            ],
            'isLoggedIn' => auth('customer')->check(),
            // La venta cruzada: los bloques que la sede puso debajo del resumen.
            'blocks' => $this->renderer->published($store, StorefrontBlock::PAGE_CART, ['customer' => $customer])['blocks'],
        ]);
    }

    /**
     * Las lineas del carrito para el cajon lateral, con sus precios del servidor.
     */
    public function detail(Request $request): JsonResponse
    {
        $store = StorefrontContext::requireStore();
        $cart = $this->carts->find($request, $store, auth('customer')->user());
        $summary = $cart ? $this->carts->summary($cart, $store) : ['items' => [], 'subtotal' => 0];

        return response()->json([
            'items' => collect($summary['items'])->map(fn (array $item) => collect($item)->only([
                'id', 'key', 'kind', 'product_id', 'service_id', 'name', 'brand', 'image', 'href', 'price', 'compare_at',
                'quantity', 'line_total', 'step', 'sold_by_weight', 'unit',
            ])->all())->values(),
            'subtotal' => $summary['subtotal'],
        ]);
    }

    /**
     * «Agregar» y el contador de la tarjeta: deja la cantidad pedida, sin recargar la pagina.
     *
     * Es absoluta e idempotente: el navegador agrupa los toques y manda cuantos quiere, no
     * cuantos suma. Si no alcanza la existencia de la bodega que respalda la tienda, no cambia
     * nada y dice cuanto hay; el navegador vuelve a lo que diga el resumen.
     */
    public function quantity(SetCartQuantityRequest $request): JsonResponse
    {
        $store = StorefrontContext::requireStore();
        $validated = $request->validated();
        $customer = auth('customer')->user();
        $quantity = round((float) $validated['quantity'], 3);
        $choices = null;

        if (! empty($validated['service_id'])) {
            // Se vende tal cual el maestro, y se pide por unidades enteras.
            $item = $this->services->find($store, (int) $validated['service_id']);

            if (! $item) {
                return response()->json(['message' => 'Ese servicio ya no está disponible.'], 404);
            }

            $quantity = (float) (int) round($quantity);
            // Las presentaciones elegidas, limpias: una que no es de su articulo se ignora.
            $choices = array_key_exists('choices', $validated) ? $this->pricing->cleanChoices($item, (array) ($validated['choices'] ?? [])) : null;
            $available = (float) $this->services->available($item, $store, $choices ?? []);
            $limited = true;
        } else {
            $item = Product::query()
                ->withoutGlobalScopes()
                ->where('company_id', $store->company_id)
                ->where('is_online_visible', true)
                ->where('is_active', true)
                ->whereRaw('(CASE WHEN online_price > 0 THEN online_price ELSE price END) > 0')
                ->find($validated['product_id']);

            if (! $item) {
                return response()->json(['message' => 'Ese artículo ya no está disponible.'], 404);
            }

            // Lo que se vende por unidad va en unidades enteras.
            if (! $item->sold_by_weight) {
                $quantity = (float) (int) round($quantity);
            }

            $limited = ! $item->allow_negative_stock;
            $available = $limited ? $this->stock->available($item, $this->warehouses->onlineSourceFor($store->id)) : INF;
        }

        $cart = $quantity > 0 ? $this->carts->current($request, $store, $customer) : $this->carts->find($request, $store, $customer);

        if ($limited && $quantity > $available) {
            return response()->json([
                'error' => $available <= 0
                    ? "{$item->name} está agotado en esta sede."
                    : 'Solo quedan '.rtrim(rtrim(number_format($available, 2, ',', '.'), '0'), ',')." de {$item->name}.",
                'available' => max(0, $available),
                'cart' => $this->carts->brief($cart, $store),
            ], 422);
        }

        if ($cart) {
            $this->carts->setQuantity($cart, $item, $quantity, $choices ?? null);
        }

        return response()->json(['cart' => $this->carts->brief($cart, $store)]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $store = StorefrontContext::requireStore();

        $validated = $request->validate([
            // Un renglon es un articulo o un servicio, nunca los dos.
            'product_id' => ['nullable', 'integer', 'required_without:service_id', 'prohibits:service_id'],
            'service_id' => ['nullable', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'substitution_policy' => ['nullable', Rule::in(['replace', 'call', 'remove'])],
        ], [
            'product_id.required_without' => 'Elige el articulo.',
            'product_id.prohibits' => 'Elige un articulo o un servicio, no los dos.',
            'quantity.gt' => 'La cantidad debe ser mayor que cero.',
        ]);

        if (! empty($validated['service_id'])) {
            // Se vende tal cual el maestro: el cliente no ajusta sus articulos.
            $service = $this->services->find($store, (int) $validated['service_id']);

            abort_if($service === null, 404);

            if ($this->services->available($service, $store) < 1) {
                return back()->with('error', "{$service->name} está agotado en esta sede.");
            }

            $quantity = max(1, (int) round((float) $validated['quantity']));
            $cart = $this->carts->current($request, $store, auth('customer')->user());
            $this->carts->addService($cart, $service, $quantity);

            return back()->with('success', "{$service->name} quedo en el carrito.");
        }

        $product = Product::query()
            ->withoutGlobalScopes()
            ->where('company_id', $store->company_id)
            ->where('is_online_visible', true)
            ->where('is_active', true)
            ->findOrFail($validated['product_id']);

        // La misma regla de la tienda: sin disponible en la bodega que la respalda no se vende.
        $reason = $this->carts->unavailableReason($product, $this->stock->available($product, $this->warehouses->onlineSourceFor($store->id)));

        if ($reason !== null) {
            $message = $reason === 'out_of_stock' ? 'Este artículo se agotó.' : 'Ese artículo ya no está disponible.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'errors' => ['product_id' => [$message]]], 422);
            }

            return back()->with('error', $message);
        }

        $cart = $this->carts->current($request, $store, auth('customer')->user());

        $this->carts->add($cart, $product, (float) $validated['quantity'], $validated['substitution_policy'] ?? 'call');

        return back()->with('success', "{$product->name} quedo en el carrito.");
    }

    public function update(Request $request, CartItem $item): RedirectResponse
    {
        $store = StorefrontContext::requireStore();
        $cart = $this->carts->current($request, $store, auth('customer')->user());

        abort_if($item->cart_id !== $cart->id, 403, 'Ese articulo no esta en tu carrito.');

        $validated = $request->validate([
            'quantity' => ['required', 'numeric', 'min:0'],
            'substitution_policy' => ['nullable', Rule::in(['replace', 'call', 'remove'])],
        ]);

        if (isset($validated['substitution_policy'])) {
            $item->update(['substitution_policy' => $validated['substitution_policy']]);
        }

        $this->carts->updateQuantity($item, (float) $validated['quantity']);

        return back();
    }

    public function destroy(Request $request, CartItem $item): RedirectResponse
    {
        $store = StorefrontContext::requireStore();
        $cart = $this->carts->current($request, $store, auth('customer')->user());

        abort_if($item->cart_id !== $cart->id, 403, 'Ese articulo no esta en tu carrito.');

        $item->delete();

        return back()->with('success', 'Articulo quitado del carrito.');
    }
}

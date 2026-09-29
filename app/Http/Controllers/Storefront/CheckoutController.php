<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Store;
use App\Services\CartService;
use App\Services\CouponService;
use App\Services\CreditService;
use App\Services\OrderService;
use App\Services\StoreCreditService;
use App\Services\Storefront\StorefrontConfig;
use App\Support\StorefrontContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CheckoutController extends Controller
{
    /** Donde vive el cupon mientras el cliente termina de llenar el formulario. */
    public const COUPON_SESSION_KEY = 'storefront.coupon';

    public function __construct(
        protected CartService $carts,
        protected OrderService $orders,
        protected CreditService $credit,
        protected CouponService $coupons,
        protected StorefrontConfig $config,
        protected StoreCreditService $storeCredits,
    ) {}

    public function create(Request $request): Response|RedirectResponse
    {
        $store = StorefrontContext::requireStore();
        $customer = auth('customer')->user();
        $cart = $this->carts->current($request, $store, $customer);
        $summary = $this->carts->summary($cart, $store);

        if ($summary['items'] === []) {
            return redirect()->route('storefront.cart.index')->with('warning', 'Tu carrito esta vacio.');
        }

        $settings = $this->config->settings($store);
        $zones = $this->config->zones($store);
        $slots = $this->config->slots($store);
        $methods = $this->config->paymentMethods($store);

        $zoneIndex = $request->session()->get('storefront.zone');
        $deliveryFee = $this->config->deliveryFee($store, $summary['subtotal'], is_int($zoneIndex) ? $zoneIndex : null);
        $coupon = $this->currentCoupon($request, $store, $summary, $deliveryFee);
        $total = max(0, $summary['subtotal'] + ($coupon['free_delivery'] ? 0 : $deliveryFee) - $coupon['discount']);

        $minimum = (int) ($settings['delivery']['min_order_total'] ?? 0);

        return Inertia::render('Storefront/Checkout', [
            'store' => ['id' => $store->id, 'name' => $store->name],
            'items' => $summary['items'],
            'subtotal' => $summary['subtotal'],
            'deliveryFee' => $coupon['free_delivery'] ? 0 : $deliveryFee,
            'discount' => $coupon['discount'],
            'total' => $total,
            'cartCount' => $summary['count'],
            'delivery' => [
                'enabled' => (bool) ($settings['delivery']['enabled'] ?? true),
                'eta_minutes' => (int) ($settings['delivery']['eta_minutes'] ?? 0),
                'min_order_total' => $minimum,
                'free_from' => (int) ($settings['delivery']['free_from'] ?? 0),
                'note' => $settings['texts']['delivery_note'] ?? null,
                'below_minimum' => $minimum > 0 && $summary['subtotal'] < $minimum,
            ],
            'zones' => $zones,
            'zone' => is_int($zoneIndex) ? $zoneIndex : null,
            'slots' => $slots,
            'methods' => collect($methods)
                ->map(fn (string $method) => ['value' => $method, 'label' => Order::PAYMENT_LABELS[$method]])
                ->values()
                ->all(),
            'coupon' => $coupon['code'] === null ? null : [
                'code' => $coupon['code'],
                'discount' => $coupon['discount'],
                'free_delivery' => $coupon['free_delivery'],
            ],
            'customer' => [
                'name' => $customer->name,
                'phone' => $customer->phone,
                'address' => $customer->address,
                'neighborhood' => $customer->neighborhood,
                'delivery_notes' => $customer->delivery_notes,
            ],
            // Saldo a favor por devoluciones: se puede usar como parte del pago.
            'storeCredit' => $this->storeCredits->available($customer),
            'credit' => [
                'enabled' => (bool) $customer->credit_enabled,
                'blocked' => (bool) $customer->credit_blocked,
                'blocked_reason' => $customer->blocked_reason,
                'limit' => $customer->credit_limit,
                'balance' => $this->credit->currentBalance($customer),
                'available' => $this->credit->availableCredit($customer),
                'term_days' => $customer->credit_term_days,
                'rejection' => $customer->credit_enabled ? $this->credit->rejectionReason($customer, $total) : null,
            ],
        ]);
    }

    /** Elegir la zona cambia la tarifa, asi que se recalcula en el servidor. */
    public function zone(Request $request): RedirectResponse
    {
        $store = StorefrontContext::requireStore();

        $validated = $request->validate([
            'zone' => ['required', 'integer', 'min:0', 'max:'.max(0, count($this->config->zones($store)) - 1)],
        ], [
            'zone.required' => 'Elige tu zona de entrega.',
            'zone.max' => 'Esa zona ya no esta disponible.',
        ]);

        $request->session()->put('storefront.zone', (int) $validated['zone']);

        return back();
    }

    public function applyCoupon(Request $request): RedirectResponse
    {
        $store = StorefrontContext::requireStore();
        $customer = auth('customer')->user();
        $cart = $this->carts->current($request, $store, $customer);
        $summary = $this->carts->summary($cart, $store);

        $validated = $request->validate(
            ['coupon' => ['required', 'string', 'max:40']],
            ['coupon.required' => 'Escribe el código del cupón.']
        );

        $storefront = $this->config->storefront($store);

        if (! $storefront) {
            return back()->withErrors(['coupon' => 'Esta sede todavía no tiene cupones.']);
        }

        $zoneIndex = $request->session()->get('storefront.zone');
        $deliveryFee = $this->config->deliveryFee($store, $summary['subtotal'], is_int($zoneIndex) ? $zoneIndex : null);

        // `apply` lanza la explicacion exacta de por que no sirve; se muestra tal cual.
        $applied = $this->coupons->apply(
            $validated['coupon'],
            $storefront,
            $this->couponLines($summary),
            $summary['subtotal'],
            $deliveryFee,
            $customer,
        );

        $request->session()->put(self::COUPON_SESSION_KEY, $applied['coupon']->code);

        return back()->with('success', 'Aplicamos el cupón '.$applied['coupon']->code.'.');
    }

    public function removeCoupon(Request $request): RedirectResponse
    {
        $request->session()->forget(self::COUPON_SESSION_KEY);

        return back()->with('success', 'Quitamos el cupón.');
    }

    public function store(Request $request): RedirectResponse
    {
        $store = StorefrontContext::requireStore();
        $customer = auth('customer')->user();
        $cart = $this->carts->current($request, $store, $customer);
        $summary = $this->carts->summary($cart, $store);

        if ($summary['items'] === []) {
            return redirect()->route('storefront.cart.index')->with('warning', 'Tu carrito esta vacio.');
        }

        $settings = $this->config->settings($store);
        $zones = $this->config->zones($store);
        $slots = $this->config->slots($store);
        $methods = $this->config->paymentMethods($store);

        if ($methods === []) {
            return back()->withErrors(['payment_method' => 'Esta sede no tiene medios de pago habilitados.']);
        }

        $validated = $request->validate([
            'delivery_address' => ['required', 'string', 'max:160'],
            'delivery_notes' => ['nullable', 'string', 'max:160'],
            'zone' => [$zones === [] ? 'nullable' : 'required', 'integer', 'min:0'],
            'slot' => [$slots === [] ? 'nullable' : 'required', 'string', 'max:40'],
            'payment_method' => ['required', Rule::in($methods)],
            'use_store_credit' => ['nullable', 'boolean'],
        ], [
            'delivery_address.required' => 'Escribe la direccion de entrega.',
            'zone.required' => 'Elige tu zona de entrega.',
            'slot.required' => 'Elige una franja de entrega.',
            'payment_method.required' => 'Elige como vas a pagar.',
            'payment_method.in' => 'Ese medio de pago no esta disponible en esta sede.',
        ]);

        $minimum = (int) ($settings['delivery']['min_order_total'] ?? 0);

        if ($minimum > 0 && $summary['subtotal'] < $minimum) {
            return back()->withErrors([
                'delivery_address' => 'El pedido mínimo en esta sede es de '.self::money($minimum).'.',
            ]);
        }

        $zoneIndex = isset($validated['zone']) ? (int) $validated['zone'] : null;

        if ($zones !== [] && ! isset($zones[$zoneIndex])) {
            return back()->withErrors(['zone' => 'No llegamos a esa zona.']);
        }

        $slot = $slots === [] ? null : $this->config->findSlot($store, (string) ($validated['slot'] ?? ''));

        if ($slots !== [] && $slot === null) {
            return back()->withErrors(['slot' => 'Esa franja ya no esta disponible.']);
        }

        if ($slot !== null && $slot['full']) {
            return back()->withErrors(['slot' => 'Esa franja se llenó. Elige otra.']);
        }

        $deliveryFee = $this->config->deliveryFee($store, $summary['subtotal'], $zoneIndex);

        // El cupon se vuelve a validar aqui: entre que se aplico y se confirma pudo vencerse,
        // agotarse o dejar de aplicar porque el carrito cambio.
        $coupon = $this->currentCoupon($request, $store, $summary, $deliveryFee);

        if ($coupon['code'] !== null && $coupon['model'] === null) {
            $request->session()->forget(self::COUPON_SESSION_KEY);

            return back()->withErrors(['coupon' => $coupon['error'] ?? 'Ese cupón ya no sirve.']);
        }

        if ($coupon['free_delivery']) {
            $deliveryFee = 0;
        }

        $total = max(0, $summary['subtotal'] + $deliveryFee - $coupon['discount']);

        // Lo que cubre el saldo a favor no se fia: el cupo se mide sobre el resto.
        $useStoreCredit = $request->boolean('use_store_credit');
        $storeCredit = $useStoreCredit ? min($this->storeCredits->available($customer), $total) : 0;

        if ($validated['payment_method'] === Order::PAYMENT_CREDIT && $total - $storeCredit > 0) {
            $reason = $this->credit->rejectionReason($customer, $total - $storeCredit);

            if ($reason !== null) {
                return back()->withErrors(['payment_method' => $reason]);
            }
        }

        $order = $this->orders->create([
            'customer_id' => $customer->id,
            'items' => array_map(fn ($item) => [
                'product_id' => $item['product_id'],
                'service_id' => $item['service_id'] ?? null,
                'choices' => $item['choices'] ?? [],
                'quantity' => $item['quantity'],
                'substitution_policy' => $item['substitution_policy'],
            ], $summary['items']),
            'channel' => Order::CHANNEL_APP,
            'payment_method' => $validated['payment_method'],
            'delivery_address' => $validated['delivery_address'],
            'delivery_notes' => $validated['delivery_notes'] ?? null,
            'delivery_slot_start' => $slot['starts_at'] ?? null,
            'delivery_slot_end' => $slot['ends_at'] ?? null,
            'delivery_fee' => $deliveryFee,
            'discount_total' => $coupon['discount'],
            'use_store_credit' => $useStoreCredit,
        ], $store);

        if ($coupon['model'] instanceof Coupon) {
            $this->coupons->redeem($coupon['model'], $order, $coupon['discount'], $customer);
        }

        $this->carts->clear($cart);
        $request->session()->forget([self::COUPON_SESSION_KEY, 'storefront.zone']);

        return redirect()->route('storefront.orders.show', $order)->with('success', 'Recibimos tu pedido.');
    }

    /**
     * El cupon guardado en sesion, revalidado contra el carrito de este momento.
     *
     * @param  array<string, mixed>  $summary
     * @return array{code: string|null, model: Coupon|null, discount: int, free_delivery: bool, error: string|null}
     */
    protected function currentCoupon(Request $request, Store $store, array $summary, int $deliveryFee): array
    {
        $code = $request->session()->get(self::COUPON_SESSION_KEY);
        $empty = ['code' => null, 'model' => null, 'discount' => 0, 'free_delivery' => false, 'error' => null];

        if (! is_string($code) || $code === '') {
            return $empty;
        }

        $storefront = $this->config->storefront($store);

        if (! $storefront) {
            return $empty;
        }

        try {
            $applied = $this->coupons->apply(
                $code,
                $storefront,
                $this->couponLines($summary),
                $summary['subtotal'],
                $deliveryFee,
                auth('customer')->user(),
            );
        } catch (ValidationException $exception) {
            return array_merge($empty, [
                'code' => $code,
                'error' => $exception->validator->errors()->first('coupon'),
            ]);
        }

        return [
            'code' => $applied['coupon']->code,
            'model' => $applied['coupon'],
            'discount' => $applied['discount'],
            'free_delivery' => $applied['free_delivery'],
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return list<array{product_id: int, line_total: int}>
     */
    protected function couponLines(array $summary): array
    {
        return array_map(fn (array $item) => [
            'product_id' => (int) ($item['product_id'] ?? 0),
            'category_id' => $item['category_id'] ?? null,
            'line_total' => (int) $item['line_total'],
        ], $summary['items']);
    }

    protected static function money(int $amount): string
    {
        return '$ '.number_format($amount, 0, ',', '.');
    }
}

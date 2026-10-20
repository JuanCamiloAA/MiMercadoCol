<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Services\CartService;
use App\Services\CouponService;
use App\Services\CreditService;
use App\Services\OrderService;
use App\Services\Payments\OnlineOrderPayments;
use App\Services\Payments\PaymentContext;
use App\Services\Payments\PaymentMethodAvailability;
use App\Services\StoreCreditService;
use App\Services\Storefront\StorefrontConfig;
use App\Support\StorefrontContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        protected PaymentMethodAvailability $paymentAvailability,
        protected OnlineOrderPayments $onlinePayments,
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

        // Algo se agoto (o se despublico) entre el carrito y el pago: se vuelve al carrito a quitarlo.
        if ($summary['has_unavailable']) {
            return redirect()->route('storefront.cart.index')->with('warning', 'Uno de los artículos se agotó. Quítalo del carrito para seguir.');
        }

        $settings = $this->config->settings($store);
        $zones = $this->config->zones($store);
        $slots = $this->config->slots($store);

        $zoneIndex = $request->session()->get('storefront.zone');
        $deliveryFee = $this->config->deliveryFee($store, $summary['subtotal'], is_int($zoneIndex) ? $zoneIndex : null);
        $coupon = $this->currentCoupon($request, $store, $summary, $deliveryFee);
        $total = max(0, $summary['subtotal'] + ($coupon['free_delivery'] ? 0 : $deliveryFee) - $coupon['discount']);
        $storeCredit = $this->storeCredits->available($customer);

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
            // Los medios que valen en esta sede, por este monto y para este cliente, agrupados. Si usa
            // su saldo a favor, el medio paga menos: puede valer uno que con el total no valia.
            'paymentGroups' => $this->paymentAvailability->groupedForCheckout($this->onlineMethods($store, $customer, $total)),
            'paymentGroupsWithStoreCredit' => $storeCredit > 0
                ? $this->paymentAvailability->groupedForCheckout($this->onlineMethods($store, $customer, max(0, $total - min($storeCredit, $total))))
                : null,
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
            'storeCredit' => $storeCredit,
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

        // Algo se agoto (o se despublico) entre el carrito y el pago: se vuelve al carrito a quitarlo.
        if ($summary['has_unavailable']) {
            return redirect()->route('storefront.cart.index')->with('warning', 'Uno de los artículos se agotó. Quítalo del carrito para seguir.');
        }

        $settings = $this->config->settings($store);
        $zones = $this->config->zones($store);
        $slots = $this->config->slots($store);

        $validated = $request->validate([
            'delivery_address' => ['required', 'string', 'max:160'],
            'delivery_notes' => ['nullable', 'string', 'max:160'],
            'zone' => [$zones === [] ? 'nullable' : 'required', 'integer', 'min:0'],
            'slot' => [$slots === [] ? 'nullable' : 'required', 'string', 'max:40'],
            // El medio configurado; la clave de siempre (`cash_on_delivery`, `credit`...) tambien vale.
            'payment_method_id' => ['nullable', 'integer'],
            'payment_method' => ['nullable', 'string', 'max:30'],
            'use_store_credit' => ['nullable', 'boolean'],
        ], [
            'delivery_address.required' => 'Escribe la direccion de entrega.',
            'zone.required' => 'Elige tu zona de entrega.',
            'slot.required' => 'Elige una franja de entrega.',
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

        // El medio se revalida aqui, por lo que de verdad se va a pagar con el: la lista que vio
        // el cliente es una sugerencia.
        $method = $this->chosenMethod($validated, $this->onlineMethods($store, $customer, $total - $storeCredit), $store, $customer, $total - $storeCredit);

        if (is_string($method)) {
            return back()->withErrors(['payment_method' => $method]);
        }

        if ($method->kind === PaymentMethod::KIND_CREDIT && $total - $storeCredit > 0) {
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
            'payment_method_id' => $method->id,
            'delivery_address' => $validated['delivery_address'],
            'delivery_notes' => $validated['delivery_notes'] ?? null,
            'delivery_slot_start' => $slot['starts_at'] ?? null,
            'delivery_slot_end' => $slot['ends_at'] ?? null,
            'delivery_fee' => $deliveryFee,
            'discount_total' => $coupon['discount'],
            'use_store_credit' => $useStoreCredit,
        ], $store);

        // Pago en linea: el pedido espera a la pasarela. Si no se puede abrir el cobro, el pedido se
        // cancela solo y el carrito queda como estaba para elegir otro medio.
        $transaction = null;

        if ($order->status === Order::STATUS_PENDING_PAYMENT) {
            try {
                $transaction = $this->onlinePayments->start($order, $customer);
            } catch (ValidationException $exception) {
                return back()->withErrors(['payment_method' => collect($exception->errors())->flatten()->first() ?? 'No pudimos abrir el pago en línea.']);
            }
        }

        if ($coupon['model'] instanceof Coupon) {
            $this->coupons->redeem($coupon['model'], $order, $coupon['discount'], $customer);
        }

        $this->carts->clear($cart);
        $request->session()->forget([self::COUPON_SESSION_KEY, 'storefront.zone']);

        if ($transaction) {
            return redirect()->route('storefront.payments.show', $transaction->reference);
        }

        return redirect()->route('storefront.orders.show', $order)->with('success', 'Recibimos tu pedido.');
    }

    /**
     * Los medios que el cliente puede elegir en la tienda de esta sede, por ese monto. El saldo a
     * favor va aparte (se marca y se descuenta primero) y el cambio por devolucion nunca aparece.
     *
     * @return Collection<int, PaymentMethod>
     */
    protected function onlineMethods(Store $store, Customer $customer, int $amount): Collection
    {
        return $this->paymentAvailability
            ->available($store->company_id, PaymentContext::online($store->id, $amount, $customer, 'delivery'))
            ->reject(fn (PaymentMethod $method) => in_array($method->kind, [PaymentMethod::KIND_STORE_CREDIT, PaymentMethod::KIND_EXCHANGE], true))
            ->values();
    }

    /**
     * El medio elegido, o por que no vale.
     *
     * @param  array<string, mixed>  $validated
     * @param  Collection<int, PaymentMethod>  $available
     */
    protected function chosenMethod(array $validated, Collection $available, Store $store, Customer $customer, int $amount): PaymentMethod|string
    {
        if ($available->isEmpty()) {
            return 'Esta sede no tiene medios de pago habilitados.';
        }

        if (! empty($validated['payment_method_id'])) {
            $method = $available->firstWhere('id', (int) $validated['payment_method_id']);

            if ($method) {
                return $method;
            }

            $configured = PaymentMethod::query()->withoutGlobalScopes()->where('company_id', $store->company_id)->find((int) $validated['payment_method_id']);

            return $configured
                ? ($this->paymentAvailability->reason($configured, PaymentContext::online($store->id, $amount, $customer, 'delivery')) ?? 'Ese medio de pago no esta disponible en esta sede.')
                : 'Ese medio de pago no esta disponible en esta sede.';
        }

        if (! empty($validated['payment_method'])) {
            $kind = Order::kindFor((string) $validated['payment_method']);

            return $available->firstWhere('kind', $kind) ?? 'Ese medio de pago no esta disponible en esta sede.';
        }

        return 'Elige como vas a pagar.';
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

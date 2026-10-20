<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerReturn;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Service;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Payments\PaymentContext;
use App\Services\Payments\PaymentMethodAvailability;
use App\Services\SaleService;
use App\Services\Storefront\StorefrontConfig;
use App\Services\Storefront\StorefrontServiceCatalog;
use App\Support\StoreContext;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function __construct(
        protected OrderService $orders,
        protected StorefrontConfig $config,
        protected PaymentMethodAvailability $paymentAvailability,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();
        $storeId = StoreContext::requireStoreId();

        $filters = [
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString(),
            'channel' => $request->string('channel')->toString(),
        ];

        $orders = Order::query()
            ->where('company_id', $companyId)
            ->where('store_id', $storeId)
            ->with(['customer:id,name,phone', 'courier:id,name,last_name', 'paymentMethod:id,name'])
            ->withCount('items')
            ->when($filters['search'], fn ($query, $search) => $query->where(function ($inner) use ($search) {
                $inner->where('number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$search}%"));
            }))
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->when($filters['channel'], fn ($query, $channel) => $query->where('channel', $channel))
            ->orderByDesc('placed_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Order $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'status' => $order->status,
                'status_label' => $order->statusLabel(),
                'channel' => $order->channel,
                'channel_label' => Order::CHANNEL_LABELS[$order->channel] ?? $order->channel,
                'payment_method' => $order->payment_method,
                'payment_label' => $order->paymentLabel(),
                'payment_status' => $order->payment_status,
                'customer' => $order->customer?->name,
                'customer_phone' => $order->customer?->phone,
                'courier' => $order->courier?->full_name,
                'items_count' => $order->items_count,
                'total' => $order->total,
                'delivery_slot_start' => $order->delivery_slot_start?->toIso8601String(),
                'delivery_slot_end' => $order->delivery_slot_end?->toIso8601String(),
                'placed_at' => $order->placed_at->toIso8601String(),
            ]);

        $counts = Order::query()
            ->where('company_id', $companyId)
            ->where('store_id', $storeId)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as total')
            ->pluck('total', 'status');

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
            'filters' => $filters,
            'statusCounts' => collect(Order::STATUS_LABELS)
                ->map(fn ($label, $status) => [
                    'status' => $status,
                    'label' => $label,
                    'count' => (int) ($counts[$status] ?? 0),
                ])
                ->values(),
            'couriers' => $this->courierOptions($companyId, $storeId),
            'fulfillment' => $this->fulfillmentMetrics($companyId, $storeId),
        ]);
    }

    public function show(Order $order): Response
    {
        $this->authorizeCompany($order);

        $order->load(['items.product:id,name,sku,unit,price', 'items.service:id,name,code', 'customer', 'courier:id,name,last_name', 'histories.user:id,name,last_name', 'store:id,name', 'sale:id,number,total', 'couponRedemption.coupon:id,code']);

        return Inertia::render('Orders/Show', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'status' => $order->status,
                'status_label' => $order->statusLabel(),
                'channel_label' => Order::CHANNEL_LABELS[$order->channel] ?? $order->channel,
                'payment_method' => $order->payment_method,
                'payment_label' => $order->paymentLabel(),
                'payment_status' => $order->payment_status,
                'delivery_address' => $order->delivery_address,
                'delivery_notes' => $order->delivery_notes,
                'delivery_slot_start' => $order->delivery_slot_start?->toIso8601String(),
                'delivery_slot_end' => $order->delivery_slot_end?->toIso8601String(),
                'subtotal' => $order->subtotal,
                'tax_total' => $order->tax_total,
                'delivery_fee' => $order->delivery_fee,
                'discount_total' => $order->discount_total,
                'coupon' => $order->couponRedemption?->coupon?->code,
                'total' => $order->total,
                // Lo que pago (o tiene apartado) el saldo a favor del cliente.
                'store_credit_amount' => $order->store_credit_amount,
                'placed_at' => $order->placed_at->toIso8601String(),
                'delivered_at' => $order->delivered_at?->toIso8601String(),
                'cancel_reason' => $order->cancel_reason,
                'store' => $order->store?->name,
                'sale' => $order->sale ? ['id' => $order->sale->id, 'number' => $order->sale->number, 'total' => $order->sale->total] : null,
                'customer' => [
                    'id' => $order->customer->id,
                    'name' => $order->customer->name,
                    'phone' => $order->customer->phone,
                    'document_number' => $order->customer->document_number,
                    'neighborhood' => $order->customer->neighborhood,
                ],
                'courier' => $order->courier ? ['id' => $order->courier->id, 'name' => $order->courier->full_name] : null,
                // El servicio va seguido de sus articulos: se alistan los articulos, no el servicio.
                'items' => $order->items->map(fn (OrderItem $item) => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'is_service' => $item->isService(),
                    'parent_id' => $item->parent_order_item_id,
                    'service_name' => $item->parent_order_item_id ? $order->items->firstWhere('id', $item->parent_order_item_id)?->service?->name : null,
                    'name' => $item->product?->name ?? $item->service?->name,
                    'sku' => $item->product?->sku ?? $item->service?->code,
                    'unit' => $item->product?->unit ?? 'unit',
                    'quantity' => round((float) $item->quantity, 3),
                    'picked_quantity' => $item->picked_quantity !== null ? round((float) $item->picked_quantity, 3) : null,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                    'substitution_policy' => $item->substitution_policy,
                    'substitution_label' => OrderItem::POLICY_LABELS[$item->substitution_policy] ?? $item->substitution_policy,
                    'pick_note' => $item->pick_note,
                ])->values(),
                'histories' => $order->histories->map(fn ($history) => [
                    'id' => $history->id,
                    'from_status' => $history->from_status,
                    'from_label' => $history->from_status ? (Order::STATUS_LABELS[$history->from_status] ?? $history->from_status) : null,
                    'to_status' => $history->to_status,
                    'to_label' => Order::STATUS_LABELS[$history->to_status] ?? $history->to_status,
                    'user' => $history->user?->full_name,
                    'note' => $history->note,
                    'changed_at' => $history->changed_at->toIso8601String(),
                ])->values(),
                'allowed_transitions' => Order::ALLOWED_TRANSITIONS[$order->status] ?? [],
            ],
            'couriers' => $this->courierOptions($order->company_id, $order->store_id),
            // Con que se cobra en la puerta: los medios de la tienda de esa sede que son plata.
            'collectMethods' => $this->paymentAvailability
                ->collectable((int) $order->company_id)
                ->map(fn (PaymentMethod $method) => $this->paymentAvailability->present($method, PaymentMethod::CHANNEL_ONLINE))
                ->values(),
            'statusLabels' => Order::STATUS_LABELS,
            // Las devoluciones de este pedido, las que pidio el cliente o registro el equipo.
            'returns' => CustomerReturn::query()
                ->where('order_id', $order->id)
                ->orderBy('id')
                ->get(['id', 'number', 'status', 'total', 'resolution', 'created_at'])
                ->map(fn (CustomerReturn $return) => [
                    'id' => $return->id,
                    'number' => $return->number,
                    'status_label' => $return->statusLabel(),
                    'resolution_label' => $return->resolutionLabel(),
                    'total' => $return->total,
                    'created_at' => $return->created_at?->toIso8601String(),
                ])
                ->values(),
            'returnSaleId' => $order->status === Order::STATUS_DELIVERED && $order->sale_id && request()->user()->can('sales.returns.create')
                ? $order->sale_id
                : null,
        ]);
    }

    public function create(): Response
    {
        $companyId = TenantContext::requireCompanyId();
        $storeId = StoreContext::requireStoreId();
        $store = Store::query()->find($storeId);

        return Inertia::render('Orders/Form', [
            'customers' => Customer::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->limit(200)
                ->get()
                ->map(fn (Customer $customer) => [
                    'value' => $customer->id,
                    'label' => "{$customer->name} · {$customer->document_number}",
                    'address' => trim(($customer->address ?? '').' '.($customer->neighborhood ?? '')),
                    'notes' => $customer->delivery_notes,
                ])
                ->values(),
            'products' => Product::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'unit', 'price', 'online_price'])
                ->map(fn (Product $product) => [
                    'value' => $product->id,
                    'label' => $product->name,
                    'sku' => $product->sku,
                    'unit' => $product->unit,
                    'price' => $product->storePrice(),
                ])
                ->values(),
            // Los servicios, tal cual el maestro y al precio en linea (`ServicePricing`), como en la tienda.
            'services' => $store ? collect(app(StorefrontServiceCatalog::class)->present(
                Service::query()->where('company_id', $companyId)->where('is_active', true)->where('open_price', false)
                    ->sellable()->with('components.product')->orderBy('name')->get(),
                $store,
            ))->map(fn (array $service) => [
                'value' => $service['id'],
                'label' => $service['name'],
                'price' => $service['price'],
                'includes' => $service['includes'],
            ])->values() : [],
            // Las franjas y la tarifa son de la sede, no de la empresa: las define el editor
            // de la tienda y el mostrador cobra lo mismo que la tienda en linea.
            'slots' => $store ? $this->config->slots($store) : [],
            'deliveryFee' => $store ? $this->config->deliveryFee($store, 0) : 0,
            'store' => $store?->only(['id', 'name']),
            // Los medios de la tienda de la sede, sin la pasarela: un pedido de mostrador se paga al recibir.
            'paymentMethods' => $this->counterMethods($companyId, $storeId)
                ->map(fn (PaymentMethod $method) => ['value' => $method->id, 'label' => $method->name, 'kind' => $method->kind])
                ->values(),
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, PaymentMethod>
     */
    protected function counterMethods(int $companyId, int $storeId)
    {
        return $this->paymentAvailability
            ->available($companyId, PaymentContext::online($storeId))
            ->reject(fn (PaymentMethod $method) => $method->isGateway() || $method->kind === PaymentMethod::KIND_STORE_CREDIT)
            ->values();
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $storeId = StoreContext::requireStoreId();

        $validated = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'delivery_address' => ['required', 'string', 'max:160'],
            'delivery_notes' => ['nullable', 'string', 'max:160'],
            'delivery_slot_start' => ['nullable', 'date'],
            'delivery_slot_end' => ['nullable', 'date', 'after_or_equal:delivery_slot_start'],
            'payment_method_id' => ['required_without:payment_method', 'nullable', 'integer', Rule::in($this->counterMethods($companyId, $storeId)->pluck('id')->all())],
            // La clave de siempre (`cash_on_delivery`...) sigue valiendo para quien no manda el medio.
            'payment_method' => ['required_without:payment_method_id', 'nullable', Rule::in(array_keys(Order::PAYMENT_LABELS))],
            'delivery_fee' => ['required', 'integer', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            // Cada renglon es un articulo o un servicio (tal cual el maestro).
            'items.*.product_id' => ['nullable', 'integer', 'required_without:items.*.service_id', Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'items.*.service_id' => ['nullable', 'integer', Rule::exists('services', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at'))],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.substitution_policy' => ['nullable', Rule::in(array_keys(OrderItem::POLICY_LABELS))],
        ], [
            'customer_id.required' => 'Elige el cliente.',
            'payment_method_id.in' => 'Ese medio de pago no está disponible en esta sede.',
            'payment_method_id.required_without' => 'Elige como se va a pagar.',
            'delivery_address.required' => 'La direccion de entrega es obligatoria.',
            'items.required' => 'El pedido necesita al menos un articulo.',
        ]);

        $store = Store::query()->findOrFail($storeId);

        $order = $this->orders->create(array_merge($validated, ['channel' => Order::CHANNEL_COUNTER]), $store, $request->user());

        return redirect()->route('orders.show', $order)->with('success', "El pedido #{$order->number} quedo creado.");
    }

    public function updateStatus(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeCompany($order);

        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(Order::STATUS_LABELS))],
            'note' => ['nullable', 'string', 'max:160'],
        ], ['status.required' => 'Elige el estado destino.']);

        if ($validated['status'] === Order::STATUS_CANCELLED && ! $request->user()->can('orders.show.cancel')) {
            abort(403, 'No tienes permiso para cancelar pedidos.');
        }

        $this->orders->changeStatus($order, $validated['status'], $request->user(), $validated['note'] ?? null);

        return back()->with('success', 'El estado del pedido quedo actualizado.');
    }

    public function pick(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeCompany($order);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.picked_quantity' => ['required', 'numeric', 'min:0'],
            'items.*.pick_note' => ['nullable', 'string', 'max:120'],
        ], ['items.required' => 'Indica que se alisto.']);

        $this->orders->pick($order, $validated['items'], $request->user());

        return back()->with('success', 'El alistamiento quedo guardado y los totales se recalcularon.');
    }

    public function assignCourier(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeCompany($order);

        $validated = $request->validate([
            'courier_user_id' => ['required', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->where('company_id', $order->company_id))],
        ], ['courier_user_id.required' => 'Elige el domiciliario.']);

        $courier = User::query()->findOrFail($validated['courier_user_id']);

        $this->orders->assignCourier($order, $courier, $request->user());

        return back()->with('success', "Pedido asignado a {$courier->full_name}.");
    }

    public function collect(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeCompany($order);

        $validated = $request->validate([
            // El cobro se puede repartir, igual que en el mostrador: un pago por medio configurado.
            // Cuales valen en la puerta lo decide `PaymentMethodAvailability` en el servicio.
            'payments' => ['required', 'array', 'min:1', 'max:'.SaleService::MAX_PAYMENT_LINES],
            'payments.*.payment_method_id' => [
                'nullable', 'integer', 'distinct',
                Rule::exists('payment_methods', 'id')->where(fn ($query) => $query->where('company_id', $order->company_id)->whereIn('kind', OrderService::COLLECTABLE_METHODS)),
            ],
            'payments.*.method' => ['required_without:payments.*.payment_method_id', 'nullable', 'distinct', Rule::in(OrderService::COLLECTABLE_METHODS)],
            'payments.*.amount' => ['required', 'integer', 'min:1'],
            'payments.*.received_amount' => ['nullable', 'integer', 'min:0'],
            'payments.*.reference' => ['nullable', 'string', 'max:60'],
        ], [
            'payments.required' => 'Elige con que se pago.',
            'payments.*.payment_method_id.distinct' => 'Hay dos pagos con el mismo medio.',
            'payments.*.method.distinct' => 'Hay dos pagos con el mismo medio.',
            'payments.*.payment_method_id.exists' => 'Con ese medio no se cobra en la puerta.',
            'payments.*.amount.min' => 'Un pago en cero no se registra: quitalo del cobro.',
        ]);

        $this->orders->collect($order, $request->user(), $validated['payments']);

        return back()->with('success', 'El cobro quedo registrado.');
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    protected function courierOptions(int $companyId, int $storeId): array
    {
        return User::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereHas('stores', fn ($query) => $query->where('stores.id', $storeId))
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => $user->can('orders.show.collect') || $user->can('orders.show.status'))
            ->map(fn (User $user) => ['value' => $user->id, 'label' => $user->full_name])
            ->values()
            ->all();
    }

    /**
     * Cumplimiento de entrega de los ultimos 30 dias.
     *
     * @return array<string, int|float>
     */
    protected function fulfillmentMetrics(int $companyId, int $storeId): array
    {
        $base = Order::query()
            ->where('company_id', $companyId)
            ->where('store_id', $storeId)
            ->where('placed_at', '>=', now()->subDays(30));

        $total = (int) $base->clone()->count();
        $delivered = (int) $base->clone()->where('status', Order::STATUS_DELIVERED)->count();
        $cancelled = (int) $base->clone()->where('status', Order::STATUS_CANCELLED)->count();

        return [
            'total' => $total,
            'delivered' => $delivered,
            'cancelled' => $cancelled,
            'rate' => $total > 0 ? round(($delivered / $total) * 100, 1) : 0,
        ];
    }

    protected function authorizeCompany(Order $order): void
    {
        abort_if(
            ! auth()->user()->isSuperAdmin() && $order->company_id !== TenantContext::activeCompanyId(),
            403,
            'Ese pedido no pertenece a tu empresa.'
        );
    }
}

<?php

namespace App\Services;

use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Service;
use App\Models\Store;
use App\Models\StoreCreditMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\OrderStatusChanged;
use App\Services\Payments\PaymentContext;
use App\Services\Payments\PaymentMethodAvailability;
use App\Services\Payments\OnlineOrderPayments;
use App\Services\Payments\PaymentMethodCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pedidos y domicilios.
 *
 * El estado solo avanza por los saltos declarados en `Order::ALLOWED_TRANSITIONS`. Al
 * entregar se genera la venta y se descuenta de verdad la mercancia que hasta entonces
 * estaba solo reservada.
 */
class OrderService
{
    public function __construct(
        protected StockService $stock,
        protected SaleService $sales,
        protected CreditService $credit,
        protected NumberSequenceService $numbers,
        protected SaleTotalsCalculator $totals,
        protected PromotionService $promotions,
        protected WarehouseService $warehouses,
        protected ServicePricing $servicePricing,
        protected StoreCreditService $storeCredits,
        protected PaymentMethodCatalog $paymentCatalog,
        protected PaymentMethodAvailability $paymentAvailability,
    ) {}

    /**
     * @param  array{
     *     customer_id: int, items: list<array{product_id: int, quantity: float, substitution_policy?: string}>,
     *     channel?: string, payment_method?: string, delivery_address: string, delivery_notes?: string|null,
     *     delivery_slot_start?: string|null, delivery_slot_end?: string|null, delivery_fee?: int,
     *     discount_total?: int
     * }  $data
     */
    public function create(array $data, Store $store, ?User $user = null): Order
    {
        $items = $data['items'] ?? [];

        if ($items === []) {
            throw ValidationException::withMessages(['items' => ['El pedido no tiene articulos.']]);
        }

        return DB::transaction(function () use ($data, $store, $user, $items) {
            $items = array_values($items);
            $productItems = array_filter($items, fn ($item) => empty($item['service_id']));

            $products = Product::query()
                ->withoutGlobalScopes()
                ->where('company_id', $store->company_id)
                ->whereIn('id', array_map(fn ($item) => (int) $item['product_id'], $productItems))
                ->get()
                ->keyBy('id');

            // El precio sale del motor de promociones, igual que en el carrito. Si aqui se
            // leyera `storePrice()` a secas, el pedido cobraria mas de lo que el cliente vio.
            // Los servicios no entran: su precio lo pone `ServicePricing`, el del carrito.
            $promoted = $this->promotions->apply(
                array_values(array_map(fn ($item) => [
                    'product_id' => (int) $item['product_id'],
                    'quantity' => (float) $item['quantity'],
                ], $productItems)),
                $store->company_id,
                PromotionService::CHANNEL_ONLINE,
            );
            $promotedLines = array_combine(array_keys($productItems), $promoted['lines']) ?: [];

            $lines = [];

            foreach ($items as $index => $item) {
                if (! empty($item['service_id'])) {
                    array_push($lines, ...$this->serviceOrderLines($item, $store, count($lines)));

                    continue;
                }

                $product = $products->get((int) $item['product_id']);

                if (! $product) {
                    throw ValidationException::withMessages(['items' => ['Uno de los articulos del pedido ya no existe.']]);
                }

                $promotedLine = $promotedLines[$index] ?? null;

                $lines[] = [
                    'kind' => 'product',
                    'product' => $product,
                    'quantity' => (float) $item['quantity'],
                    'unit_price' => $promotedLine ? (int) $promotedLine['unit_price'] : $product->storePrice(),
                    'tax_rate' => (float) $product->tax_rate,
                    'discount' => $promotedLine ? (int) ($promotedLine['discount'] ?? 0) : 0,
                    'substitution_policy' => $item['substitution_policy'] ?? OrderItem::POLICY_CALL,
                ];
            }

            $totals = $this->totals->calculate(array_map(fn ($line) => [
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'tax_rate' => $line['tax_rate'],
                'discount' => $line['discount'],
            ], $lines));

            $deliveryFee = max(0, (int) ($data['delivery_fee'] ?? 0));

            // El cupon rebaja el pedido completo, no una linea: nunca por debajo de cero.
            $couponDiscount = min(max(0, (int) ($data['discount_total'] ?? 0)), $totals['total']);

            // La bodega queda escrita en el pedido, no se vuelve a preguntar: si manana cambian
            // cual respalda la tienda, este pedido se sigue alistando de donde se aparto.
            $warehouse = $this->warehouses->onlineSourceFor($store->id);

            // El medio con que el cliente dice que paga: el configurado, o el de fabrica del `kind`
            // para quien llama con la clave de siempre (`cash_on_delivery`, `credit`...).
            $paymentMethod = $this->paymentMethodOf($data, $store->company_id);

            $order = Order::create([
                'company_id' => $store->company_id,
                'store_id' => $store->id,
                'warehouse_id' => $warehouse->id,
                'customer_id' => (int) $data['customer_id'],
                'number' => $this->numbers->nextOrderNumber($store->company_id),
                'channel' => $data['channel'] ?? Order::CHANNEL_APP,
                // Pagado en linea espera a la pasarela antes de entrar a la bandeja.
                'status' => $paymentMethod->kind === PaymentMethod::KIND_GATEWAY_CHECKOUT ? Order::STATUS_PENDING_PAYMENT : Order::STATUS_NEW,
                'payment_method' => Order::paymentKeyFor($paymentMethod->kind),
                'payment_method_id' => $paymentMethod->id,
                'payment_status' => 'pending',
                'delivery_address' => $data['delivery_address'],
                'delivery_notes' => $data['delivery_notes'] ?? null,
                'delivery_slot_start' => $data['delivery_slot_start'] ?? null,
                'delivery_slot_end' => $data['delivery_slot_end'] ?? null,
                'subtotal' => $totals['subtotal'],
                'delivery_fee' => $deliveryFee,
                'discount_total' => $couponDiscount,
                'tax_total' => $totals['tax_total'],
                'total' => $totals['total'] + $deliveryFee - $couponDiscount,
                'placed_at' => now(),
            ]);

            $itemIds = [];

            foreach ($lines as $index => $line) {
                $orderItem = OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $line['product']?->id,
                    'service_id' => ($line['service'] ?? null)?->id,
                    'parent_order_item_id' => isset($line['parent']) ? $itemIds[$line['parent']] : null,
                    'quantity' => $totals['items'][$index]['quantity'],
                    'unit_price' => $line['unit_price'],
                    'line_total' => $totals['items'][$index]['line_total'],
                    'substitution_policy' => $line['substitution_policy'],
                    'service_snapshot' => $line['service_snapshot'] ?? null,
                ]);

                $itemIds[$index] = $orderItem->id;

                // La linea del servicio no se aparta: se apartan sus articulos.
                if (! $line['product']) {
                    continue;
                }

                try {
                    $this->stock->reserve($line['product'], $warehouse, (float) $line['quantity']);
                } catch (ValidationException $exception) {
                    if ($line['kind'] !== 'component') {
                        throw $exception;
                    }

                    throw ValidationException::withMessages([
                        'stock' => ["No hay existencias suficientes de {$line['product']->name} para {$lines[$line['parent']]['service']->name}."],
                    ]);
                }
            }

            // El saldo a favor se aparta al confirmar, como la mercancia: si otra compra lo gasta
            // antes de la entrega, este pedido ya no lo puede contar. Se cobra al entregar.
            if (($data['use_store_credit'] ?? false) && ($customer = Customer::query()->withoutGlobalScopes()->find($order->customer_id))) {
                $held = min($this->storeCredits->available($customer), (int) $order->total);

                if ($held > 0) {
                    $this->storeCredits->consume($customer, $held, StoreCreditMovement::TYPE_ORDER_HOLD, ['order_id' => $order->id], $user, "Pedido #{$order->number}");
                    $order->update(['store_credit_amount' => $held]);
                }
            }

            // Si el saldo a favor lo cubrio todo, no hay nada que pagar en linea.
            if ($order->status === Order::STATUS_PENDING_PAYMENT && (int) $order->total - (int) $order->store_credit_amount <= 0) {
                $order->update(['status' => Order::STATUS_NEW]);
            }

            $this->recordHistory($order, null, $order->status, $user, $order->status === Order::STATUS_PENDING_PAYMENT ? 'Pedido recibido, esperando el pago en línea' : 'Pedido recibido');

            return $order->load('items.product', 'customer');
        });
    }

    /**
     * Las filas de un servicio en el pedido: la del servicio y una hija por articulo, tal cual el
     * maestro por la cantidad pedida. Se vende como dice el maestro; quien alista registra lo que
     * de verdad uso.
     *
     * @param  array<string, mixed>  $item
     * @return list<array<string, mixed>>
     */
    protected function serviceOrderLines(array $item, Store $store, int $offset): array
    {
        $service = Service::query()->withoutGlobalScopes()
            ->where('company_id', $store->company_id)
            ->whereNull('deleted_at')
            ->with('components.product')
            ->find((int) $item['service_id']);

        if (! $service || ! $service->is_active || $service->open_price || ! $service->isSellable()) {
            throw ValidationException::withMessages(['items' => ['Uno de los servicios del pedido ya no está disponible.']]);
        }

        $quantity = (float) $item['quantity'];
        // En los articulos completos, la presentacion que el cliente eligio en la tienda.
        $choices = (array) ($item['choices'] ?? []);
        $chosen = $this->servicePricing->resolveChoices($service, $choices);
        $breakdown = $this->servicePricing->breakdown($service, PromotionService::CHANNEL_ONLINE, $choices);

        $lines = [[
            'kind' => 'service',
            'service' => $service,
            'product' => null,
            'quantity' => $quantity,
            'unit_price' => $breakdown['value'],
            'tax_rate' => (float) $service->tax_rate,
            'discount' => 0,
            'substitution_policy' => OrderItem::POLICY_CALL,
            'service_snapshot' => [
                'pricing_mode' => $service->pricing_mode,
                'master' => $service->components->map(fn ($component) => [
                    'product_id' => (int) $component->product_id,
                    'name' => $component->product?->name,
                    'quantity' => round((float) $component->quantity, 3),
                    'unit_price' => $component->unit_price,
                    'returnable_by_default' => (bool) $component->returnable_by_default,
                ])->values()->all(),
                'swapped' => $service->components
                    ->filter(fn ($component) => ($chosen[$component->id] ?? null) && $chosen[$component->id]->id !== $component->product_id)
                    ->map(fn ($component) => [
                        'master_product_id' => (int) $component->product_id,
                        'product_id' => $chosen[$component->id]->id,
                        'name' => $chosen[$component->id]->name,
                    ])->values()->all(),
            ],
        ]];

        foreach ($service->components->values() as $position => $component) {
            $product = $chosen[$component->id] ?? $component->product;

            $lines[] = [
                'kind' => 'component',
                'parent' => $offset,
                'product' => $product,
                'quantity' => round((float) $component->quantity * $quantity, 3),
                'unit_price' => $breakdown['components'][$position]['unit_price'],
                'tax_rate' => (float) $product->tax_rate,
                'discount' => 0,
                'substitution_policy' => OrderItem::POLICY_CALL,
            ];
        }

        return $lines;
    }

    /**
     * La pasarela aprobo el pago en linea: el pedido entra a la bandeja de siempre, ya pagado.
     * Solo lo llama `OnlineOrderPayments`, con la fila del pedido bloqueada.
     */
    public function confirmOnlinePayment(Order $order, PaymentTransaction $transaction): Order
    {
        if ($order->status !== Order::STATUS_PENDING_PAYMENT) {
            throw ValidationException::withMessages(['status' => ['Ese pedido no esperaba un pago en línea.']]);
        }

        $order->forceFill(['status' => Order::STATUS_NEW, 'payment_status' => 'paid'])->save();
        $this->recordHistory($order, Order::STATUS_PENDING_PAYMENT, Order::STATUS_NEW, null, "Pago en línea aprobado ({$transaction->reference})");

        return $order;
    }

    /**
     * El pago en linea no llego (rechazado, vencido o no se pudo abrir): el pedido se cancela y
     * se libera todo lo apartado, inventario y saldo a favor. No pasa por `ALLOWED_TRANSITIONS`:
     * un pedido esperando el pago no lo mueve nadie a mano.
     */
    public function cancelUnpaid(Order $order, string $reason): Order
    {
        if ($order->status !== Order::STATUS_PENDING_PAYMENT) {
            return $order;
        }

        return DB::transaction(function () use ($order, $reason) {
            $this->releaseReservations($order);
            $this->releaseStoreCredit($order, (int) $order->store_credit_amount, null, "Pedido #{$order->number}: {$reason}");

            // El pedido nunca se pago: el cupon no se gasto. Un cupon de un solo uso queda libre
            // para el pedido que haga despues con otro medio.
            CouponRedemption::query()->where('order_id', $order->id)->delete();

            $order->forceFill(['status' => Order::STATUS_CANCELLED, 'cancel_reason' => $reason])->save();
            $this->recordHistory($order, Order::STATUS_PENDING_PAYMENT, Order::STATUS_CANCELLED, null, $reason);

            // El correo sale despues de confirmar: llega desde un webhook dentro de una transaccion.
            DB::afterCommit(fn () => $this->notifyCustomer($order->fresh()));

            return $order;
        });
    }

    public function changeStatus(Order $order, string $status, User $user, ?string $note = null): Order
    {
        if ($order->status === $status) {
            return $order;
        }

        if (! $order->canTransitionTo($status)) {
            throw ValidationException::withMessages([
                'status' => ["No se puede pasar de «{$order->statusLabel()}» a «".(Order::STATUS_LABELS[$status] ?? $status).'».'],
            ]);
        }

        return DB::transaction(function () use ($order, $status, $user, $note) {
            $from = $order->status;

            if ($status === Order::STATUS_DELIVERED) {
                $this->deliver($order, $user);
            }

            if ($status === Order::STATUS_CANCELLED) {
                $this->releaseReservations($order);
                $this->releaseStoreCredit($order, (int) $order->store_credit_amount, $user, "Pedido #{$order->number} cancelado");
                $order->cancel_reason = $note;
            }

            if ($status === Order::STATUS_RETURNED) {
                $this->restoreDeliveredStock($order, $user);
            }

            $order->status = $status;
            $order->save();

            $this->recordHistory($order, $from, $status, $user, $note);
            $this->notifyCustomer($order->refresh());

            return $order;
        });
    }

    /**
     * Alistamiento: cantidades recogidas y notas de reemplazo, con los totales al dia.
     *
     * @param  list<array{id: int, picked_quantity: float, pick_note?: string|null}>  $picks
     */
    public function pick(Order $order, array $picks, User $user): Order
    {
        return DB::transaction(function () use ($order, $picks, $user) {
            $order->load('items.product');
            $byId = $order->items->keyBy('id');

            foreach ($picks as $pick) {
                $item = $byId->get((int) $pick['id']);

                // La fila del servicio no se alista: no tiene existencia. Se alistan sus articulos.
                if (! $item || $item->isService()) {
                    continue;
                }

                $picked = max(0, (float) $pick['picked_quantity']);

                if ($picked > $item->quantity) {
                    throw ValidationException::withMessages([
                        'items' => ["No se puede alistar mas de lo pedido en {$item->product?->name}."],
                    ]);
                }

                // La reserva sigue al alistamiento: lo que no se recogio vuelve a estar disponible.
                $previous = $item->picked_quantity ?? $item->quantity;

                if ($item->product && $previous !== $picked) {
                    $this->stock->releaseReservation($item->product, $this->warehouseOf($order), $previous - $picked);
                }

                $item->picked_quantity = $picked;
                $item->pick_note = $pick['pick_note'] ?? null;
                $item->line_total = (int) round($picked * $item->unit_price);
                $item->save();
            }

            $this->refreshTotals($order);

            if ($order->status === Order::STATUS_NEW) {
                $order->status = Order::STATUS_PICKING;
                $order->save();
                $this->recordHistory($order, Order::STATUS_NEW, Order::STATUS_PICKING, $user, 'Alistamiento iniciado');
            }

            return $order->refresh()->load('items.product');
        });
    }

    public function assignCourier(Order $order, User $courier, User $actor): Order
    {
        $order->courier_user_id = $courier->id;
        $order->save();

        $this->recordHistory($order, $order->status, $order->status, $actor, "Domiciliario asignado: {$courier->full_name}");

        return $order->refresh();
    }

    /**
     * Cobro contraentrega.
     */
    /** Contraentrega se cobra con plata, no con mas credito. */
    public const COLLECTABLE_METHODS = [
        SalePayment::METHOD_CASH,
        SalePayment::METHOD_CARD,
        SalePayment::METHOD_TRANSFER,
        SalePayment::METHOD_OTHER,
    ];

    /**
     * Cobro contraentrega, repartible entre varios medios.
     *
     * Reemplaza los pagos que se supusieron al entregar: si el pedido era contraentrega en
     * efectivo pero el cliente termino pagando mitad y mitad con transferencia, lo que queda
     * guardado es lo que de verdad paso. No admite credito: una deuda no se paga con otra.
     *
     * @param  list<array<string, mixed>>  $payments
     */
    public function collect(Order $order, User $user, array $payments): Order
    {
        if ($order->payment_status === 'paid') {
            throw ValidationException::withMessages(['payment' => ['Ese pedido ya esta cobrado.']]);
        }

        // Los medios del cobro en la puerta: cualquiera activo que sea plata, con su referencia si
        // la exige. El reparto y el cambio siguen las reglas del mostrador (`normalizePayments`).
        $payments = $this->paymentAvailability->resolve(
            (int) $order->company_id,
            PaymentContext::online((int) $order->store_id, null, $order->customer),
            $payments,
            enforce: false,
        );
        $collectable = $this->paymentAvailability->collectable((int) $order->company_id)->pluck('id')->all();

        foreach ($payments as $payment) {
            // Un `kind` que no es plata lo rechaza `normalizePayments`; aqui, uno de plata apagado.
            if ((int) ($payment['amount'] ?? 0) > 0 && in_array($payment['method'], self::COLLECTABLE_METHODS, true) && ! in_array($payment['payment_method_id'], $collectable, true)) {
                throw ValidationException::withMessages(['payments' => ["«{$payment['payment_method']->name}» está apagado."]]);
            }

            if ((int) ($payment['amount'] ?? 0) > 0 && $payment['payment_method']->requires_reference && trim((string) ($payment['reference'] ?? '')) === '') {
                throw ValidationException::withMessages(['payments' => ['Falta '.mb_strtolower($payment['payment_method']->reference_label ?: 'la referencia')." de «{$payment['payment_method']->name}»."]]);
            }
        }

        $sale = $order->sale_id
            ? Sale::query()->withoutGlobalScopes()->with('payments')->find($order->sale_id)
            : null;

        if (! $sale || $sale->isCancelled()) {
            throw ValidationException::withMessages(['payment' => ['El pedido todavia no tiene una venta que cobrar.']]);
        }

        // Lo que ya pago el saldo a favor no se vuelve a cobrar: se cobra el resto.
        $storeCredit = (int) $sale->payments->where('method', SalePayment::METHOD_STORE_CREDIT)->sum('amount');
        $normalized = $this->sales->normalizePayments($payments, (int) $sale->total - $storeCredit, self::COLLECTABLE_METHODS);

        return DB::transaction(function () use ($order, $user, $sale, $normalized, $storeCredit) {
            $sale->payments()->where('method', '!=', SalePayment::METHOD_STORE_CREDIT)->delete();
            $kept = $storeCredit > 0 ? 1 : 0;

            foreach ($normalized as $payment) {
                SalePayment::create([
                    'sale_id' => $sale->id,
                    'sequence' => $payment['sequence'] + $kept,
                    'payment_method_id' => $payment['payment_method_id'],
                    'method' => $payment['method'],
                    'amount' => $payment['amount'],
                    'received_amount' => $payment['received_amount'],
                    'change_amount' => $payment['change_amount'],
                    'reference' => $payment['reference'],
                ]);
            }

            $sale->paid_total = (int) $sale->total;
            $sale->due_total = 0;
            $sale->status = Sale::STATUS_PAID;
            $sale->save();

            if ($sale->customer) {
                $this->credit->recalculate($sale->customer);
            }

            $order->payment_status = 'paid';
            $order->save();

            $this->recordHistory($order, $order->status, $order->status, $user, 'Cobro registrado');

            return $order->refresh();
        });
    }

    /**
     * Genera la venta del pedido y convierte la reserva en salida real de inventario.
     */
    protected function deliver(Order $order, User $user): void
    {
        $order->load('items.product', 'items.service', 'customer');

        $lines = [];
        $children = $order->items->whereNotNull('parent_order_item_id')->groupBy('parent_order_item_id');

        foreach ($order->items->whereNull('parent_order_item_id') as $item) {
            if ($item->isService()) {
                // La venta nace con lo que de verdad se alisto y al precio del pedido: el cliente
                // paga lo que vio al confirmar. Lo alistado de menos queda como ajuste.
                $components = [];

                foreach ($children->get($item->id) ?? [] as $child) {
                    $picked = (float) ($child->picked_quantity ?? $child->quantity);

                    if ($child->product) {
                        $this->stock->releaseReservation($child->product, $this->warehouseOf($order), $picked);
                    }

                    if ($picked > 0 && $child->product) {
                        $components[] = ['product_id' => $child->product_id, 'quantity' => $picked, 'unit_price' => $child->unit_price];
                    }
                }

                $lines[] = [
                    'service_id' => $item->service_id,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => $item->unit_price,
                    'price_locked' => true,
                    'components' => $components,
                ];

                continue;
            }

            $quantity = $item->picked_quantity ?? $item->quantity;

            if ($quantity <= 0 || ! $item->product) {
                continue;
            }

            $lines[] = [
                'product_id' => $item->product_id,
                'quantity' => (float) $quantity,
                'unit_price' => $item->unit_price,
            ];

            // Se libera la reserva justo antes de descontar, para no restar dos veces.
            $this->stock->releaseReservation($item->product, $this->warehouseOf($order), (float) $quantity);
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['items' => ['El pedido no tiene nada alistado para entregar.']]);
        }

        // El domicilio se cobra, asi que va como una linea mas de la venta. Sin ella esa plata
        // no aparecia en ningun informe ni en el arqueo de la caja.
        if ($order->delivery_fee > 0) {
            $lines[] = [
                'description' => 'Domicilio',
                'quantity' => 1,
                'unit_price' => (int) $order->delivery_fee,
                'tax_rate' => 0,
            ];
        }

        $store = $order->store;
        $creditPayment = $order->payment_method === Order::PAYMENT_CREDIT;

        // El cobro se calcula desde las lineas alistadas, que no siempre son las pedidas.
        $amount = 0;

        foreach ($lines as $line) {
            $amount += (int) round($line['quantity'] * $line['unit_price']);

            foreach ($line['components'] ?? [] as $component) {
                $amount += (int) round($component['quantity'] * $component['unit_price']);
            }
        }

        // El cupon que el cliente uso al pedir se respeta al entregar: si no bajara aqui, el
        // domiciliario cobraria el precio sin descuento.
        $couponDiscount = min((int) $order->discount_total, $amount);
        $amount -= $couponDiscount;

        // El saldo a favor apartado paga primero. Si se alisto de menos, lo que sobra vuelve.
        $storeCredit = min((int) $order->store_credit_amount, $amount);
        $this->releaseStoreCredit($order, (int) $order->store_credit_amount - $storeCredit, $user, "Pedido #{$order->number}: se entrego por menos");
        $rest = $amount - $storeCredit;

        $payments = [];

        if ($storeCredit > 0) {
            $payments[] = ['method' => SalePayment::METHOD_STORE_CREDIT, 'amount' => $storeCredit];
        }

        if ($rest > 0) {
            $payments[] = $this->deliveryPayment($order, $creditPayment, $rest);
        }

        // Pagado en linea y alistado por menos: el domiciliario no cobra nada y lo que sobra de lo
        // pagado vuelve al cliente como saldo a favor, con su movimiento y su nota.
        $overpaid = $this->onlineOverpaid($order, $rest);

        $sale = $this->sales->create([
            'items' => $lines,
            'coupon_discount' => $couponDiscount,
            'payments' => $payments,
            // Ya se desconto al confirmar el pedido: la venta no lo vuelve a cobrar.
            'store_credit_held' => $storeCredit > 0,
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'channel' => Sale::CHANNEL_ONLINE,
            'note' => "Pedido #{$order->number}",
            // Lo que cambio en un servicio lo decidio quien alisto, no un cajero.
            'service_changes_authorized' => true,
            // El medio se valido al tomar el pedido: entregarlo de noche no lo vuelve a examinar.
            'skip_payment_availability' => true,
        ], $user, $store, null, $this->warehouseOf($order));

        $order->sale_id = $sale->id;
        $order->delivered_at = now();

        if ($overpaid > 0 && $order->customer && ! $order->customer->isGenericConsumer()) {
            $this->storeCredits->grant(
                $order->customer,
                $overpaid,
                StoreCreditMovement::TYPE_ONLINE_OVERPAID,
                ['order_id' => $order->id, 'sale_id' => $sale->id],
                $user,
                "Pedido #{$order->number}: se entregó por menos de lo pagado en línea",
            );
        }

        if ($storeCredit > 0) {
            StoreCreditMovement::query()->withoutGlobalScopes()
                ->where('order_id', $order->id)
                ->where('type', StoreCreditMovement::TYPE_ORDER_HOLD)
                ->whereNull('sale_id')
                ->update(['sale_id' => $sale->id]);
        }

        if (! $creditPayment && $order->payment_method !== Order::PAYMENT_CASH_ON_DELIVERY) {
            $order->payment_status = 'paid';
        }

        if ($creditPayment) {
            $order->payment_status = 'pending';
        }

        // Todo lo cubrio el saldo a favor: no queda nada que cobrar al entregar.
        if ($rest === 0) {
            $order->payment_status = 'paid';
        }

        $order->save();
    }

    /**
     * El renglon de pago de la venta de un pedido: el medio configurado del pedido; los de antes,
     * el de fabrica de su clave. A credito siempre es credito.
     *
     * @return array<string, mixed>
     */
    protected function deliveryPayment(Order $order, bool $creditPayment, int $amount): array
    {
        if ($creditPayment) {
            return ['method' => SalePayment::METHOD_CREDIT, 'amount' => $amount];
        }

        $method = $order->payment_method_id
            ? $this->paymentCatalog->find((int) $order->company_id, (int) $order->payment_method_id)
            : null;

        // Pagado en linea: el renglon de la pasarela, con su transaccion.
        if ($method && $method->isGateway()) {
            $transaction = OnlineOrderPayments::transactionOf($order);

            if (! $transaction || $transaction->status !== PaymentTransaction::STATUS_APPROVED) {
                throw ValidationException::withMessages(['payment' => ['El pago en línea de este pedido no está aprobado.']]);
            }

            return ['payment_method_id' => $method->id, 'method' => $method->kind, 'amount' => $amount, 'payment_transaction_id' => $transaction->id];
        }

        if ($method && $method->kind !== PaymentMethod::KIND_CREDIT) {
            return ['payment_method_id' => $method->id, 'method' => $method->kind, 'amount' => $amount];
        }

        return ['method' => $this->paymentMethodFor($order), 'amount' => $amount];
    }

    /**
     * Cuanto de lo pagado en linea no se va a cobrar porque se alisto de menos.
     */
    protected function onlineOverpaid(Order $order, int $charged): int
    {
        if ($order->payment_method !== Order::PAYMENT_GATEWAY) {
            return 0;
        }

        $transaction = OnlineOrderPayments::transactionOf($order);

        if (! $transaction || $transaction->status !== PaymentTransaction::STATUS_APPROVED) {
            return 0;
        }

        if ($charged > (int) $transaction->amount) {
            throw ValidationException::withMessages(['items' => ['Lo alistado vale más de lo que se pagó en línea.']]);
        }

        return (int) $transaction->amount - $charged;
    }

    protected function paymentMethodFor(Order $order): string
    {
        return match ($order->payment_method) {
            Order::PAYMENT_CARD => SalePayment::METHOD_CARD,
            Order::PAYMENT_TRANSFER => SalePayment::METHOD_TRANSFER,
            Order::PAYMENT_OTHER => SalePayment::METHOD_OTHER,
            default => SalePayment::METHOD_CASH,
        };
    }

    /**
     * El medio de pago de un pedido nuevo.
     *
     * @param  array<string, mixed>  $data
     */
    protected function paymentMethodOf(array $data, int $companyId): PaymentMethod
    {
        if (! empty($data['payment_method_id'])) {
            $method = $this->paymentCatalog->find($companyId, (int) $data['payment_method_id']);

            if (! $method || $method->kind === PaymentMethod::KIND_EXCHANGE) {
                throw ValidationException::withMessages(['payment_method' => ['Ese medio de pago no existe.']]);
            }

            return $method;
        }

        $kind = Order::kindFor((string) ($data['payment_method'] ?? Order::PAYMENT_CASH_ON_DELIVERY));

        return $this->paymentCatalog->defaultFor($companyId, $kind, PaymentMethod::CHANNEL_ONLINE);
    }

    protected function releaseReservations(Order $order): void
    {
        $order->loadMissing('items.product');

        foreach ($order->items as $item) {
            if (! $item->product) {
                continue;
            }

            $quantity = $item->picked_quantity ?? $item->quantity;
            $this->stock->releaseReservation($item->product, $this->warehouseOf($order), (float) $quantity);
        }
    }

    protected function restoreDeliveredStock(Order $order, User $user): void
    {
        if (! $order->sale_id) {
            return;
        }

        $sale = Sale::query()->withoutGlobalScopes()->find($order->sale_id);

        if ($sale && ! $sale->isCancelled()) {
            $this->sales->cancel($sale, $user, "Devolucion del pedido #{$order->number}");
        }
    }

    protected function refreshTotals(Order $order): void
    {
        $order->loadMissing(['items.product', 'items.service']);

        $lines = $order->items
            ->map(fn (OrderItem $item) => [
                'quantity' => (float) ($item->picked_quantity ?? $item->quantity),
                'unit_price' => $item->unit_price,
                'tax_rate' => (float) ($item->product?->tax_rate ?? $item->service?->tax_rate ?? 0),
                'discount' => 0,
            ])
            ->all();

        $totals = $this->totals->calculate($lines);

        $order->fill([
            'subtotal' => $totals['subtotal'],
            'tax_total' => $totals['tax_total'],
            'total' => $totals['total'] + $order->delivery_fee,
        ])->save();
    }

    /**
     * Le avisa al cliente por correo, solo en los estados que le importan.
     *
     * El aviso nunca puede tumbar el cambio de estado: si el correo falla, queda en el log.
     */
    protected function notifyCustomer(Order $order): void
    {
        if (! in_array($order->status, OrderStatusChanged::NOTIFIABLE, true)) {
            return;
        }

        $order->loadMissing(['customer', 'store', 'courier']);

        if (! $order->customer || ! filter_var($order->customer->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        try {
            $order->customer->notify(new OrderStatusChanged($order));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    protected function recordHistory(Order $order, ?string $from, string $to, ?User $user, ?string $note): void
    {
        OrderStatusHistory::create([
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $user?->id,
            'note' => $note,
            'changed_at' => now(),
        ]);
    }

    /**
     * La bodega del pedido.
     *
     * Se guarda al crearlo y no se vuelve a resolver: lo apartado esta en ese estante. Si la
     * empresa cambia cual bodega respalda la tienda, los pedidos ya tomados se siguen
     * alistando y despachando de donde se apartaron. El respaldo es para pedidos de antes de
     * que existieran las bodegas.
     */
    protected function warehouseOf(Order $order): Warehouse
    {
        $warehouse = $order->warehouse_id
            ? Warehouse::query()->withoutGlobalScopes()->find($order->warehouse_id)
            : null;

        return $warehouse ?? $this->warehouses->onlineSourceFor($order->store_id);
    }

    /** Devuelve al saldo a favor lo apartado para el pedido que ya no se va a cobrar. */
    protected function releaseStoreCredit(Order $order, int $amount, ?User $user, string $note): void
    {
        if ($amount <= 0) {
            return;
        }

        $customer = Customer::query()->withoutGlobalScopes()->find($order->customer_id);

        if (! $customer) {
            return;
        }

        $this->storeCredits->grant($customer, $amount, StoreCreditMovement::TYPE_ORDER_RELEASE, ['order_id' => $order->id], $user, $note);
        $order->store_credit_amount = max(0, (int) $order->store_credit_amount - $amount);
        $order->save();
    }
}

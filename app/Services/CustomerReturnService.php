<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnItem;
use App\Models\Order;
use App\Models\RegisterSession;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\StoreCreditMovement;
use App\Models\User;
use App\Notifications\CustomerReturnUpdated;
use App\Support\Uploads;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Devoluciones de venta. Unico camino que las crea, las mueve de estado y las resuelve.
 *
 * - En caja (`registerAtPos`, `exchangeAtPos`) nace resuelta en el mismo acto.
 * - En la tienda en linea (`requestOnline`) el cliente la solicita; despues se aprueba, se recibe
 *   (ahi se decide si cada articulo vuelve al inventario o va a averia) y se resuelve.
 * - Desde el panel (`createFromPanel`) se registra aprobada o ya recibida.
 *
 * El inventario se mueve al resolver, con la decision que se tomo al recibir: asi un rechazo
 * despues de revisar no tiene nada que deshacer. La plata sale por una venta en negativo
 * (`SaleService::refund`), como siempre; esta clase le dice por que medios.
 */
class CustomerReturnService
{
    public function __construct(
        protected SaleService $sales,
        protected StoreCreditService $credits,
        protected NumberSequenceService $numbers,
        protected SettingsService $settings,
        protected StockService $stock,
        protected RegisterSessionService $sessions,
    ) {}

    /**
     * Cuanto queda por devolver de cada linea: lo vendido, menos lo ya devuelto, menos lo que
     * apartan las devoluciones abiertas.
     *
     * @return array<int, float>
     */
    public function returnableQuantities(Sale $sale, ?int $ignoreReturnId = null): array
    {
        $sale->loadMissing('items');
        $refunded = $this->sales->refundedQuantities($sale);

        $held = CustomerReturnItem::query()
            ->join('customer_returns', 'customer_returns.id', '=', 'customer_return_items.customer_return_id')
            ->where('customer_returns.sale_id', $sale->id)
            ->whereIn('customer_returns.status', CustomerReturn::OPEN_STATUSES)
            ->when($ignoreReturnId, fn ($query, $id) => $query->where('customer_returns.id', '!=', $id))
            ->groupBy('customer_return_items.sale_item_id')
            ->selectRaw('customer_return_items.sale_item_id, SUM(customer_return_items.quantity) as quantity')
            ->pluck('quantity', 'sale_item_id');

        $available = [];

        foreach ($sale->items as $item) {
            $available[$item->id] = max(0, round((float) $item->quantity - ($refunded[$item->id] ?? 0) - (float) ($held[$item->id] ?? 0), 3));
        }

        return $available;
    }

    /**
     * El plazo: desde la entrega del pedido si la venta viene de uno, si no desde la venta.
     *
     * @return array{since: CarbonImmutable, days: int, max: int, within: bool, until: CarbonImmutable}
     */
    public function window(Sale $sale): array
    {
        $order = Order::query()->withoutGlobalScopes()->where('sale_id', $sale->id)->first();
        $since = CarbonImmutable::parse($order?->delivered_at ?? $sale->sold_at)->startOfDay();
        $max = max(1, (int) $this->settings->get($sale->company_id, 'returns.max_days'));
        $days = (int) $since->diffInDays(CarbonImmutable::now()->startOfDay());

        return ['since' => $since, 'days' => $days, 'max' => $max, 'within' => $days <= $max, 'until' => $since->addDays($max)];
    }

    /**
     * La venta tal como la ve quien va a devolver: lo que queda de cada linea, el plazo y el
     * cliente con su saldo. Lo usan la caja y el panel.
     *
     * @return array<string, mixed>
     */
    public function saleForReturn(Sale $sale): array
    {
        $sale->load(['items.product', 'customer', 'payments']);
        $refunded = $this->sales->refundedQuantities($sale);
        $available = $this->returnableQuantities($sale);
        $window = $this->window($sale);
        $order = Order::query()->withoutGlobalScopes()->where('sale_id', $sale->id)->first(['id', 'number']);

        $present = fn (SaleItem $item) => [
            'id' => $item->id,
            'description' => $item->description,
            'product_id' => $item->product_id,
            'sold_by_weight' => (bool) $item->product?->sold_by_weight,
            'quantity' => round((float) $item->quantity, 3),
            'refunded' => round($refunded[$item->id] ?? 0, 3),
            // Lo que otra devolucion abierta (una solicitud en linea) ya aparto.
            'held' => max(0, round((float) $item->quantity - ($refunded[$item->id] ?? 0) - ($available[$item->id] ?? 0), 3)),
            'available' => $available[$item->id] ?? 0,
            'unit_price' => $item->unit_price,
            'line_total' => $item->line_total,
        ];

        $children = $sale->items->whereNotNull('parent_sale_item_id')->groupBy('parent_sale_item_id');

        return [
            'id' => $sale->id,
            'number' => $sale->number,
            'total' => $sale->total,
            'due_total' => $sale->due_total,
            'status' => $sale->status,
            'status_label' => $sale->statusLabel(),
            'channel' => $sale->channel,
            'sold_at' => $sale->sold_at->toIso8601String(),
            'order' => $order ? ['id' => $order->id, 'number' => $order->number] : null,
            'customer' => $sale->customer ? [
                'id' => $sale->customer->id,
                'name' => $sale->customer->name,
                'store_credit' => $this->credits->available($sale->customer),
                'generic' => $sale->customer->isGenericConsumer(),
            ] : null,
            'window' => ['days' => $window['days'], 'max' => $window['max'], 'within' => $window['within'], 'until' => $window['until']->toDateString()],
            'payments' => $sale->payments->sortBy('sequence')->values()->map(fn (SalePayment $payment) => [
                'method' => $payment->method,
                'label' => $payment->methodLabel(),
                'amount' => $payment->netAmount(),
            ])->all(),
            'items' => $sale->items->whereNull('parent_sale_item_id')->map(fn (SaleItem $item) => [
                ...$present($item),
                'is_service' => $item->service_id !== null,
                'pricing_mode' => $item->service_snapshot['pricing_mode'] ?? null,
                'components' => ($children->get($item->id) ?? collect())->map(fn (SaleItem $component) => [
                    ...$present($component),
                    'returnable_by_default' => (bool) ($component->returnable_by_default ?? true),
                    'adjusted' => $component->component_change !== null,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    // ---------------------------------------------------------------- caja

    /**
     * Devolucion en caja: se recibe y se resuelve en el mismo acto. Tambien documenta un rechazo.
     *
     * @param  array{items: list<array<string, mixed>>, resolution?: string, refund_method?: string, reason: string, customer_id?: int|null}  $data
     */
    public function registerAtPos(Sale $sale, array $data, User $user, Store $store, ?RegisterSession $session, ?User $windowApprover = null): CustomerReturn
    {
        $this->assertReturnable($sale);
        [$outOfWindow, $approver] = $this->checkWindow($sale, $user, $windowApprover);

        $resolution = $data['resolution'] ?? CustomerReturn::RESOLUTION_REFUND;
        $rejected = $resolution === 'rejected';

        if (! $rejected && ! in_array($resolution, [CustomerReturn::RESOLUTION_REFUND, CustomerReturn::RESOLUTION_STORE_CREDIT], true)) {
            throw ValidationException::withMessages(['resolution' => ['Elige cómo se le responde al cliente.']]);
        }

        $built = $this->buildLines($sale, $data['items'] ?? [], withDisposition: ! $rejected, allowDescriptionLines: true);
        $customer = $this->customerFor($sale, $data['customer_id'] ?? null, $resolution === CustomerReturn::RESOLUTION_STORE_CREDIT);
        $method = $data['refund_method'] ?? SalePayment::METHOD_CASH;

        if ($resolution === CustomerReturn::RESOLUTION_REFUND) {
            $this->assertRefundMethod($method, $session);
        }

        $reason = trim((string) ($data['reason'] ?? ''));

        return DB::transaction(function () use ($sale, $store, $user, $session, $built, $customer, $method, $resolution, $rejected, $reason, $outOfWindow, $approver) {
            $return = $this->newReturn($sale, $store, $user, CustomerReturn::ORIGIN_POS, $rejected ? CustomerReturn::STATUS_REJECTED : CustomerReturn::STATUS_RESOLVED, [
                'customer_id' => $customer?->id ?? $sale->customer_id,
                'reason' => $reason !== '' ? mb_substr($reason, 0, 160) : null,
                'out_of_window' => $outOfWindow,
                'window_approved_by_user_id' => $approver?->id,
                'received_at' => $rejected ? null : now(),
                'received_by_user_id' => $rejected ? null : $user->id,
                'rejected_at' => $rejected ? now() : null,
                'rejected_by_user_id' => $rejected ? $user->id : null,
                'reject_reason' => $rejected ? mb_substr($reason, 0, 255) : null,
            ]);

            $this->persistLines($return, $built);

            if ($rejected) {
                return $return->refresh()->load('items');
            }

            return $this->settle($return, $resolution, $method, $user, $session, $customer);
        });
    }

    /**
     * Cambio en caja: la devolucion y la venta nueva en una sola transaccion. El valor devuelto
     * paga la venta nueva (`exchange`); si el cliente se lleva menos, lo que sobra se le devuelve
     * en efectivo o queda como saldo a favor.
     *
     * @param  array{items: list<array<string, mixed>>, reason?: string, leftover_method?: string}  $returnData
     * @param  array<string, mixed>  $saleData  Lo mismo que recibe `SaleService::create`, con un pago `exchange`.
     * @return array{return: CustomerReturn, sale: Sale}
     */
    public function exchangeAtPos(Sale $sale, array $returnData, array $saleData, User $user, Store $store, RegisterSession $session, ?User $windowApprover = null): array
    {
        $this->assertReturnable($sale);
        [$outOfWindow, $approver] = $this->checkWindow($sale, $user, $windowApprover);

        $built = $this->buildLines($sale, $returnData['items'] ?? [], withDisposition: true, allowDescriptionLines: false);
        $total = array_sum(array_column($built, 'line_total'));
        // Lo que se debia de esa venta a credito se perdona primero, como en toda devolucion.
        $relief = min($total, (int) $sale->due_total);
        $value = $total - $relief;

        $applied = (int) collect($saleData['payments'] ?? [])->where('method', SalePayment::METHOD_EXCHANGE)->sum('amount');

        if ($applied <= 0) {
            throw ValidationException::withMessages(['payments' => ['El cambio no aplicó nada de lo devuelto a la compra nueva.']]);
        }

        if ($applied > $value) {
            throw ValidationException::withMessages(['payments' => ['Lo aplicado del cambio supera lo que vale la devolución.']]);
        }

        $leftover = $value - $applied;
        $leftoverMethod = $returnData['leftover_method'] ?? SalePayment::METHOD_CASH;

        if (! in_array($leftoverMethod, [SalePayment::METHOD_CASH, SalePayment::METHOD_STORE_CREDIT], true)) {
            throw ValidationException::withMessages(['leftover_method' => ['Lo que sobra del cambio se devuelve en efectivo o como saldo a favor.']]);
        }

        $customerId = $saleData['customer_id'] ?? $sale->customer_id;
        $customer = $leftover > 0 && $leftoverMethod === SalePayment::METHOD_STORE_CREDIT
            ? $this->customerFor($sale, $customerId, true)
            : ($customerId ? Customer::query()->withoutGlobalScopes()->where('company_id', $sale->company_id)->find($customerId) : null);

        return DB::transaction(function () use ($sale, $store, $user, $session, $built, $saleData, $returnData, $applied, $leftover, $leftoverMethod, $customer, $outOfWindow, $approver) {
            $return = $this->newReturn($sale, $store, $user, CustomerReturn::ORIGIN_POS, CustomerReturn::STATUS_RESOLVED, [
                'customer_id' => $customer?->id ?? $sale->customer_id,
                'reason' => ($reason = trim((string) ($returnData['reason'] ?? ''))) !== '' ? mb_substr($reason, 0, 160) : null,
                'out_of_window' => $outOfWindow,
                'window_approved_by_user_id' => $approver?->id,
                'received_at' => now(),
                'received_by_user_id' => $user->id,
            ]);

            $this->persistLines($return, $built);

            // La mercancia devuelta entra antes de que salga la nueva: puede ser la misma.
            $return = $this->settle($return, CustomerReturn::RESOLUTION_EXCHANGE, $leftoverMethod, $user, $session, $customer, [
                'applied' => $applied,
                'leftover' => $leftover,
            ]);

            $newSale = $this->sales->create([
                ...$saleData,
                'customer_id' => $saleData['customer_id'] ?? $return->customer_id,
                'exchange_limit' => $applied,
                'note' => trim(($saleData['note'] ?? '').' Cambio por la devolución '.$return->number),
            ], $user, $store, $session);

            $return->update(['exchange_sale_id' => $newSale->id]);

            return ['return' => $return->refresh()->load('items'), 'sale' => $newSale];
        });
    }

    // ---------------------------------------------------------------- tienda en linea

    /**
     * El cliente pide la devolucion de un pedido entregado, dentro del plazo.
     *
     * @param  array{items: list<array<string, mixed>>, note?: string|null}  $data
     * @param  list<UploadedFile>  $photos
     */
    public function requestOnline(Order $order, Customer $customer, array $data, array $photos = []): CustomerReturn
    {
        if ((int) $order->customer_id !== (int) $customer->id) {
            throw ValidationException::withMessages(['order' => ['Ese pedido no es tuyo.']]);
        }

        if ($order->status !== Order::STATUS_DELIVERED || ! $order->sale_id) {
            throw ValidationException::withMessages(['order' => ['Solo se puede devolver un pedido entregado.']]);
        }

        $sale = Sale::query()->withoutGlobalScopes()->findOrFail($order->sale_id);
        $this->assertReturnable($sale);

        $window = $this->window($sale);

        if (! $window['within']) {
            throw ValidationException::withMessages(['order' => ['El plazo para devolver este pedido venció el '.$window['until']->format('d/m/Y').'.']]);
        }

        if (count($photos) > CustomerReturn::MAX_PHOTOS) {
            throw ValidationException::withMessages(['photos' => ['Puedes adjuntar hasta '.CustomerReturn::MAX_PHOTOS.' fotos.']]);
        }

        // El cliente devuelve servicios enteros: sus articulos van en proporcion, solos.
        $built = $this->buildLines($sale, $data['items'] ?? [], withDisposition: false, allowDescriptionLines: false, autoComponents: true);
        $store = Store::query()->withoutGlobalScopes()->findOrFail($order->store_id);

        $return = DB::transaction(function () use ($sale, $order, $store, $customer, $built, $data, $photos) {
            $return = $this->newReturn($sale, $store, null, CustomerReturn::ORIGIN_STOREFRONT, CustomerReturn::STATUS_REQUESTED, [
                'order_id' => $order->id,
                'customer_id' => $customer->id,
                'customer_note' => ($note = trim((string) ($data['note'] ?? ''))) !== '' ? mb_substr($note, 0, 2000) : null,
                'requested_at' => now(),
            ]);

            $this->persistLines($return, $built);

            $paths = [];

            foreach ($photos as $photo) {
                $paths[] = $photo->storeAs(
                    "customer-returns/{$sale->company_id}",
                    Str::uuid().'.'.($photo->guessExtension() ?: 'jpg'),
                    Uploads::privateDiskName(),
                );
            }

            if ($paths !== []) {
                $return->update(['photos' => $paths]);
            }

            return $return;
        });

        $this->notify($return);

        return $return->refresh()->load('items');
    }

    public function cancelByCustomer(CustomerReturn $return, Customer $customer): CustomerReturn
    {
        if ((int) $return->customer_id !== (int) $customer->id) {
            throw ValidationException::withMessages(['return' => ['Esa devolución no es tuya.']]);
        }

        return DB::transaction(function () use ($return) {
            $locked = $this->lock($return);

            if (! $locked->customerCanCancel()) {
                throw ValidationException::withMessages(['status' => ['Esa devolución ya no se puede retirar: la mercancía ya la recibimos.']]);
            }

            $locked->update(['status' => CustomerReturn::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by_customer' => true]);

            return $locked;
        });
    }

    // ---------------------------------------------------------------- panel

    /**
     * Registrar desde el panel una devolucion de cualquier venta: aprobada (por recibir) o, si la
     * mercancia ya esta en la sede, recibida con la decision de cada articulo.
     *
     * @param  array{items: list<array<string, mixed>>, received?: bool, reason?: string|null}  $data
     */
    public function createFromPanel(Sale $sale, array $data, User $user): CustomerReturn
    {
        $this->assertReturnable($sale);
        [$outOfWindow] = $this->checkWindow($sale, $user, null);

        $received = (bool) ($data['received'] ?? false);
        $built = $this->buildLines($sale, $data['items'] ?? [], withDisposition: $received, allowDescriptionLines: true);
        $order = Order::query()->withoutGlobalScopes()->where('sale_id', $sale->id)->first();
        $store = Store::query()->withoutGlobalScopes()->findOrFail($order?->store_id ?? $sale->store_id);

        return DB::transaction(function () use ($sale, $order, $store, $user, $built, $received, $data, $outOfWindow) {
            $return = $this->newReturn($sale, $store, $user, CustomerReturn::ORIGIN_PANEL, $received ? CustomerReturn::STATUS_RECEIVED : CustomerReturn::STATUS_APPROVED, [
                'order_id' => $order?->id,
                'customer_id' => $sale->customer_id,
                'reason' => ($reason = trim((string) ($data['reason'] ?? ''))) !== '' ? mb_substr($reason, 0, 160) : null,
                'out_of_window' => $outOfWindow,
                'window_approved_by_user_id' => $outOfWindow ? $user->id : null,
                'approved_at' => now(),
                'approved_by_user_id' => $user->id,
                'received_at' => $received ? now() : null,
                'received_by_user_id' => $received ? $user->id : null,
            ]);

            $this->persistLines($return, $built);

            return $return->refresh()->load('items');
        });
    }

    public function approve(CustomerReturn $return, User $user): CustomerReturn
    {
        $return = DB::transaction(function () use ($return, $user) {
            $locked = $this->lock($return);
            $this->expectStatus($locked, [CustomerReturn::STATUS_REQUESTED], 'Solo se aprueba una solicitud.');

            $locked->update(['status' => CustomerReturn::STATUS_APPROVED, 'approved_at' => now(), 'approved_by_user_id' => $user->id]);

            return $locked;
        });

        $this->notify($return);

        return $return;
    }

    /**
     * Rechazar: no procede. Antes de resolverse no se ha movido ni inventario ni plata, asi que
     * no hay nada que deshacer; lo que el cliente trajo se le entrega de vuelta.
     */
    public function reject(CustomerReturn $return, User $user, string $reason): CustomerReturn
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Escribe por qué no procede: el cliente lo va a leer.']]);
        }

        $return = DB::transaction(function () use ($return, $user, $reason) {
            $locked = $this->lock($return);
            $this->expectStatus($locked, CustomerReturn::OPEN_STATUSES, 'Esa devolución ya está cerrada.');

            $locked->update([
                'status' => CustomerReturn::STATUS_REJECTED,
                'rejected_at' => now(),
                'rejected_by_user_id' => $user->id,
                'reject_reason' => mb_substr($reason, 0, 255),
            ]);

            return $locked;
        });

        $this->notify($return);

        return $return;
    }

    /**
     * Recibir: la mercancia llego a la sede. Por cada renglon se dice cuanto llego (nunca mas de
     * lo pedido; cero lo saca) y a donde va. El inventario se mueve al resolver.
     *
     * @param  list<array{id: int, quantity: float, disposition: string}>  $lines
     */
    public function receive(CustomerReturn $return, array $lines, User $user): CustomerReturn
    {
        return DB::transaction(function () use ($return, $lines, $user) {
            $locked = $this->lock($return);
            $this->expectStatus($locked, [CustomerReturn::STATUS_REQUESTED, CustomerReturn::STATUS_APPROVED], 'Esa devolución ya se recibió o está cerrada.');
            $locked->load('items.saleItem');

            $incoming = collect($lines)->keyBy(fn ($line) => (int) ($line['id'] ?? 0));
            $shares = [];

            foreach ($locked->items->whereNull('parent_item_id') as $item) {
                $line = $incoming->get($item->id);
                $quantity = round((float) ($line['quantity'] ?? $item->quantity), 3);

                if ($quantity < 0 || $quantity - (float) $item->quantity > 0.0005) {
                    throw ValidationException::withMessages(['items' => ["De {$item->description} llegaron más de las pedidas: corrige la cantidad."]]);
                }

                $shares[$item->id] = (float) $item->quantity > 0 ? $quantity / (float) $item->quantity : 0;
                $this->updateReceived($item, $quantity, $item->service_id ? null : ($line['disposition'] ?? null));
            }

            // Los articulos de un servicio llegan en la misma proporcion que el servicio.
            foreach ($locked->items->whereNotNull('parent_item_id') as $item) {
                $line = $incoming->get($item->id);
                $quantity = round((float) $item->quantity * ($shares[$item->parent_item_id] ?? 1), 3);
                $this->updateReceived($item, $quantity, $line['disposition'] ?? null, component: true);
            }

            $locked->load('items');

            if ($locked->items->whereNull('parent_item_id')->isEmpty()) {
                throw ValidationException::withMessages(['items' => ['No llegó nada: si no procede, recházala.']]);
            }

            $locked->update([
                'status' => CustomerReturn::STATUS_RECEIVED,
                'received_at' => now(),
                'received_by_user_id' => $user->id,
                'total' => (int) $locked->items->sum('line_total'),
            ]);

            return $locked->refresh()->load('items');
        });
    }

    /**
     * Resolver una devolucion recibida: el inventario se mueve como se decidio al recibir y al
     * cliente se le devuelve la plata o se le abona saldo a favor.
     */
    public function resolve(CustomerReturn $return, string $resolution, ?string $method, User $user): CustomerReturn
    {
        if (! in_array($resolution, [CustomerReturn::RESOLUTION_REFUND, CustomerReturn::RESOLUTION_STORE_CREDIT], true)) {
            throw ValidationException::withMessages(['resolution' => ['Elige reembolso o saldo a favor. El cambio se hace en caja.']]);
        }

        $session = $this->sessions->openSessionFor($user, (int) $return->store_id);
        $method ??= SalePayment::METHOD_TRANSFER;

        if ($resolution === CustomerReturn::RESOLUTION_REFUND) {
            $this->assertRefundMethod($method, $session);
        }

        $return = DB::transaction(function () use ($return, $resolution, $method, $user, $session) {
            $locked = $this->lock($return);
            $this->expectStatus($locked, [CustomerReturn::STATUS_RECEIVED], 'Solo se resuelve una devolución recibida.');
            $sale = Sale::query()->withoutGlobalScopes()->findOrFail($locked->sale_id);
            $customer = $this->customerFor($sale, $locked->customer_id, $resolution === CustomerReturn::RESOLUTION_STORE_CREDIT);

            return $this->settle($locked, $resolution, $method, $user, $session, $customer);
        });

        $this->notify($return);

        return $return;
    }

    public function cancel(CustomerReturn $return, User $user): CustomerReturn
    {
        return DB::transaction(function () use ($return, $user) {
            $locked = $this->lock($return);
            $this->expectStatus($locked, [CustomerReturn::STATUS_REQUESTED, CustomerReturn::STATUS_APPROVED], 'Solo se anula una devolución que aún no se ha recibido.');

            $locked->update(['status' => CustomerReturn::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by_user_id' => $user->id]);

            return $locked;
        });
    }

    // ---------------------------------------------------------------- piezas

    /**
     * Arma y valida los renglones contra la venta. Un articulo de un servicio se devuelve con su
     * servicio y en proporcion; con `autoComponents` se agregan solos.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    public function buildLines(Sale $sale, array $lines, bool $withDisposition, bool $allowDescriptionLines, bool $autoComponents = false, ?int $ignoreReturnId = null): array
    {
        $sale->loadMissing('items.product');
        $byId = $sale->items->keyBy('id');
        $available = $this->returnableQuantities($sale, $ignoreReturnId);

        $requested = [];

        foreach ($lines as $line) {
            $item = $byId->get((int) ($line['sale_item_id'] ?? 0));
            $quantity = round((float) ($line['quantity'] ?? 0), 3);

            if (! $item) {
                throw ValidationException::withMessages(['items' => ['Uno de los artículos no es de esa venta.']]);
            }

            if ($quantity > 0) {
                $requested[$item->id] = ['item' => $item, 'quantity' => $quantity, 'line' => $line];
            }
        }

        if ($autoComponents) {
            foreach ($requested as $entry) {
                /** @var SaleItem $parent */
                $parent = $entry['item'];

                if (! $parent->service_id) {
                    continue;
                }

                $share = (float) $parent->quantity > 0 ? $entry['quantity'] / (float) $parent->quantity : 0;

                foreach ($sale->items->where('parent_sale_item_id', $parent->id) as $component) {
                    $requested[$component->id] ??= [
                        'item' => $component,
                        'quantity' => round((float) $component->quantity * $share, 3),
                        'line' => ['reason' => $entry['line']['reason'] ?? 'other'],
                    ];
                }
            }
        }

        $built = [];

        foreach ($requested as $entry) {
            /** @var SaleItem $item */
            $item = $entry['item'];
            $quantity = $entry['quantity'];
            $line = $entry['line'];

            if ($quantity <= 0) {
                continue;
            }

            if (! $item->product_id && ! $item->service_id && ! $allowDescriptionLines) {
                throw ValidationException::withMessages(['items' => ["{$item->description} no se devuelve como artículo."]]);
            }

            $left = $available[$item->id] ?? 0;

            if ($quantity - $left > 0.0005) {
                throw ValidationException::withMessages([
                    'items' => ["De {$item->description} solo quedan ".StockService::quantityText($left).' por devolver.'],
                ]);
            }

            if ($item->product && ! $item->product->sold_by_weight && floor($quantity) !== $quantity && ! $item->parent_sale_item_id) {
                throw ValidationException::withMessages(['items' => ["{$item->description} se devuelve en unidades enteras."]]);
            }

            if ($item->parent_sale_item_id) {
                $parent = $requested[$item->parent_sale_item_id] ?? null;

                if (! $parent) {
                    throw ValidationException::withMessages(['items' => ["{$item->description} se devuelve junto con su servicio."]]);
                }

                $share = (float) $parent['item']->quantity > 0 ? $parent['quantity'] / (float) $parent['item']->quantity : 0;

                if ($quantity - round((float) $item->quantity * $share, 3) > 0.0005) {
                    throw ValidationException::withMessages(['items' => ["De {$item->description} se devuelve hasta lo que corresponde a esa cantidad de servicio."]]);
                }
            }

            $reason = (string) ($line['reason'] ?? 'other');

            if (! array_key_exists($reason, CustomerReturnItem::REASONS)) {
                throw ValidationException::withMessages(['items' => ['Elige el motivo de cada artículo.']]);
            }

            $unitNet = (float) $item->quantity > 0 ? $item->line_total / (float) $item->quantity : 0;

            $built[] = [
                'item' => $item,
                'quantity' => $quantity,
                'reason' => $reason,
                'disposition' => $withDisposition ? $this->dispositionFor($item, $line, $reason) : null,
                'unit_price' => (int) round($unitNet),
                'line_total' => (int) round($quantity * $unitNet),
            ];
        }

        if ($built === []) {
            throw ValidationException::withMessages(['items' => ['Elige qué se está devolviendo.']]);
        }

        // El servicio antes que sus articulos, como en la venta.
        usort($built, fn (array $a, array $b) => $a['item']->id <=> $b['item']->id);

        return $built;
    }

    /**
     * Mueve el inventario de lo recibido: vuelve a la bodega de donde salio o, si es averia,
     * entra y se da de baja con el mismo documento (asi la perdida sale en los informes).
     */
    protected function applyStock(CustomerReturn $return, User $user): void
    {
        $sale = Sale::query()->withoutGlobalScopes()->findOrFail($return->sale_id);
        $return->loadMissing('items.product');

        foreach ($return->items as $item) {
            if (! $item->product || (float) $item->quantity <= 0) {
                continue;
            }

            if (! in_array($item->disposition, [CustomerReturnItem::DISPOSITION_RESTOCK, CustomerReturnItem::DISPOSITION_WASTE], true)) {
                continue;
            }

            $warehouse = $this->sales->returnWarehouse($sale, (int) $item->product_id);

            $this->stock->move(
                product: $item->product,
                warehouse: $warehouse,
                type: StockMovement::TYPE_RETURN,
                quantity: (float) $item->quantity,
                userId: $user->id,
                reference: $return,
                note: "Devolución {$return->number} de la venta {$sale->number}",
                unitCost: $item->unit_cost,
            );

            if ($item->disposition === CustomerReturnItem::DISPOSITION_WASTE) {
                $this->stock->move(
                    product: $item->product,
                    warehouse: $warehouse,
                    type: StockMovement::TYPE_WASTE,
                    quantity: -(float) $item->quantity,
                    userId: $user->id,
                    reference: $return,
                    note: "Avería por devolución {$return->number}: {$item->reasonLabel()}",
                    unitCost: $item->unit_cost,
                );
            }

            $item->update(['warehouse_id' => $warehouse->id]);
        }
    }

    /**
     * Mueve el inventario, saca la plata por la venta en negativo y abona el saldo. Deja la
     * devolucion resuelta con cuanto salio por cada lado.
     *
     * @param  array{applied?: int, leftover?: int}  $exchange
     */
    protected function settle(CustomerReturn $return, string $resolution, string $method, User $user, ?RegisterSession $session, ?Customer $customer, array $exchange = []): CustomerReturn
    {
        $this->applyStock($return, $user);

        $sale = Sale::query()->withoutGlobalScopes()->findOrFail($return->sale_id);
        $return->load('items');
        $total = (int) $return->items->sum('line_total');
        $relief = min($total, (int) $sale->due_total);
        $value = $total - $relief;

        $payments = match ($resolution) {
            CustomerReturn::RESOLUTION_REFUND => [['method' => $method, 'amount' => $value]],
            CustomerReturn::RESOLUTION_STORE_CREDIT => [['method' => SalePayment::METHOD_STORE_CREDIT, 'amount' => $value]],
            CustomerReturn::RESOLUTION_EXCHANGE => [
                ['method' => SalePayment::METHOD_EXCHANGE, 'amount' => (int) ($exchange['applied'] ?? 0)],
                ['method' => $method, 'amount' => (int) ($exchange['leftover'] ?? 0)],
            ],
        };

        $store = Store::query()->withoutGlobalScopes()->findOrFail($return->store_id);

        $refund = $this->sales->refund(
            $sale,
            $return->items->map(fn (CustomerReturnItem $item) => [
                'sale_item_id' => $item->sale_item_id,
                'quantity' => (float) $item->quantity,
                'disposition' => $item->disposition,
            ])->all(),
            $user,
            $store,
            $session,
            $method,
            (string) $return->reason,
            [
                'move_stock' => false,
                'payments' => $payments,
                'reference' => $return,
                'note' => "Devolución {$return->number} de la venta {$sale->number}",
            ],
        );

        $credited = 0;

        if ($resolution === CustomerReturn::RESOLUTION_STORE_CREDIT) {
            $credited = $value;
        } elseif ($resolution === CustomerReturn::RESOLUTION_EXCHANGE && $method === SalePayment::METHOD_STORE_CREDIT) {
            $credited = (int) ($exchange['leftover'] ?? 0);
        }

        if ($credited > 0 && $customer) {
            $this->credits->grant($customer, $credited, StoreCreditMovement::TYPE_RETURN_CREDIT, [
                'customer_return_id' => $return->id,
                'sale_id' => $refund->id,
            ], $user, "Devolución {$return->number}");
        }

        $refunded = match ($resolution) {
            CustomerReturn::RESOLUTION_REFUND => $value,
            CustomerReturn::RESOLUTION_EXCHANGE => $method === SalePayment::METHOD_CASH ? (int) ($exchange['leftover'] ?? 0) : 0,
            default => 0,
        };

        $return->update([
            'status' => CustomerReturn::STATUS_RESOLVED,
            'resolution' => $resolution,
            'refund_method' => $refunded > 0 ? $method : null,
            'total' => $total,
            'refunded_total' => $refunded,
            'credited_total' => $credited,
            'exchanged_total' => (int) ($exchange['applied'] ?? 0),
            'debt_relief_total' => $relief,
            'refund_sale_id' => $refund->id,
            'customer_id' => $customer?->id ?? $return->customer_id,
            'resolved_at' => now(),
            'resolved_by_user_id' => $user->id,
        ]);

        return $return->refresh()->load('items');
    }

    /**
     * @param  list<array<string, mixed>>  $built
     */
    protected function persistLines(CustomerReturn $return, array $built): void
    {
        $ids = [];

        foreach ($built as $entry) {
            /** @var SaleItem $item */
            $item = $entry['item'];

            $created = CustomerReturnItem::create([
                'customer_return_id' => $return->id,
                'sale_item_id' => $item->id,
                'parent_item_id' => $item->parent_sale_item_id ? ($ids[$item->parent_sale_item_id] ?? null) : null,
                'product_id' => $item->product_id,
                'service_id' => $item->service_id,
                'description' => mb_substr((string) $item->description, 0, 200),
                'quantity' => $entry['quantity'],
                'unit_price' => $entry['unit_price'],
                'line_total' => $entry['line_total'],
                'tax_rate' => (float) $item->tax_rate,
                'unit_cost' => (int) $item->unit_cost,
                'reason' => $entry['reason'],
                'disposition' => $entry['disposition'],
            ]);

            $ids[$item->id] = $created->id;
        }

        $return->update(['total' => (int) array_sum(array_column($built, 'line_total'))]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function newReturn(Sale $sale, Store $store, ?User $user, string $origin, string $status, array $attributes): CustomerReturn
    {
        // Si la venta viene de un pedido, la devolucion queda colgada tambien del pedido.
        $orderId = array_key_exists('order_id', $attributes)
            ? $attributes['order_id']
            : Order::query()->withoutGlobalScopes()->where('sale_id', $sale->id)->value('id');

        return CustomerReturn::create([
            'company_id' => $sale->company_id,
            'store_id' => $store->id,
            'number' => $this->numbers->nextCustomerReturnNumber($sale->company_id),
            'sale_id' => $sale->id,
            'order_id' => $orderId,
            'channel' => $sale->channel,
            'origin' => $origin,
            'status' => $status,
            'created_by_user_id' => $user?->id,
            'resolved_at' => $status === CustomerReturn::STATUS_RESOLVED ? now() : null,
            'resolved_by_user_id' => $status === CustomerReturn::STATUS_RESOLVED ? $user?->id : null,
            ...$attributes,
        ]);
    }

    protected function updateReceived(CustomerReturnItem $item, float $quantity, ?string $disposition, bool $component = false): void
    {
        if ($quantity <= 0) {
            $item->delete();

            return;
        }

        if ($item->product_id) {
            $allowed = $component
                ? array_keys(CustomerReturnItem::DISPOSITION_LABELS)
                : [CustomerReturnItem::DISPOSITION_RESTOCK, CustomerReturnItem::DISPOSITION_WASTE];

            $disposition ??= CustomerReturnItem::defaultDisposition($item->reason);

            if (! in_array($disposition, $allowed, true)) {
                throw ValidationException::withMessages(['items' => ["Elige a dónde va {$item->description}."]]);
            }
        } else {
            $disposition = null;
        }

        $unit = (float) $item->quantity > 0 ? $item->line_total / (float) $item->quantity : 0;

        $item->update([
            'quantity' => $quantity,
            'line_total' => (int) round($quantity * $unit),
            'disposition' => $disposition,
        ]);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    protected function dispositionFor(SaleItem $item, array $line, string $reason): ?string
    {
        if (! $item->product_id) {
            return null;
        }

        $disposition = $line['disposition'] ?? null;

        // El formulario de siempre mandaba `restock` en los articulos de un servicio.
        if ($disposition === null && array_key_exists('restock', $line) && $line['restock'] !== null) {
            $disposition = filter_var($line['restock'], FILTER_VALIDATE_BOOLEAN)
                ? CustomerReturnItem::defaultDisposition($reason)
                : CustomerReturnItem::DISPOSITION_NONE;
        }

        if ($disposition === null) {
            $disposition = $item->parent_sale_item_id && ! ($item->returnable_by_default ?? true)
                ? CustomerReturnItem::DISPOSITION_NONE
                : CustomerReturnItem::defaultDisposition($reason);
        }

        $allowed = $item->parent_sale_item_id
            ? array_keys(CustomerReturnItem::DISPOSITION_LABELS)
            : [CustomerReturnItem::DISPOSITION_RESTOCK, CustomerReturnItem::DISPOSITION_WASTE];

        if (! in_array($disposition, $allowed, true)) {
            throw ValidationException::withMessages(['items' => ["Elige a dónde va {$item->description}."]]);
        }

        return $disposition;
    }

    protected function assertReturnable(Sale $sale): void
    {
        if ($sale->isCancelled()) {
            throw ValidationException::withMessages(['sale' => ['Esa venta está anulada: no hay nada que devolver.']]);
        }

        if ($sale->status === Sale::STATUS_REFUNDED) {
            throw ValidationException::withMessages(['sale' => ['Esa es una devolución: no se devuelve una devolución.']]);
        }
    }

    /**
     * Fuera de plazo se puede, pero con quien lo autorice: el usuario si tiene el permiso, o el
     * supervisor que puso su PIN.
     *
     * @return array{0: bool, 1: ?User}
     */
    protected function checkWindow(Sale $sale, User $user, ?User $approver): array
    {
        $window = $this->window($sale);

        if ($window['within']) {
            return [false, null];
        }

        if ($user->can('sales.returns.approve')) {
            return [true, $user];
        }

        if ($approver && $approver->can('sales.returns.approve')) {
            return [true, $approver];
        }

        throw ValidationException::withMessages([
            'window' => ["La venta {$sale->number} tiene {$window['days']} días y el plazo para devolver es de {$window['max']}: pide la autorización de un supervisor."],
        ]);
    }

    protected function assertRefundMethod(string $method, ?RegisterSession $session): void
    {
        if (! in_array($method, CustomerReturn::REFUND_METHODS, true)) {
            throw ValidationException::withMessages(['refund_method' => ['Elige con qué medio se devuelve la plata.']]);
        }

        if ($method === SalePayment::METHOD_CASH && ! $session) {
            throw ValidationException::withMessages(['refund_method' => ['Para devolver en efectivo hay que tener una caja abierta en esta sede. Si no, devuelve por transferencia.']]);
        }
    }

    /** El cliente al que se le abona. Para saldo a favor es obligatorio. */
    protected function customerFor(Sale $sale, mixed $customerId, bool $required): ?Customer
    {
        $customer = Customer::query()->withoutGlobalScopes()
            ->where('company_id', $sale->company_id)
            ->find($customerId ?: $sale->customer_id);

        if ($required && ! $customer) {
            throw ValidationException::withMessages(['customer_id' => ['Para dar saldo a favor hay que elegir el cliente.']]);
        }

        // Una venta al consumidor final generico no tiene a quien abonarle: se elige la ficha real.
        if ($required && $customer->isGenericConsumer()) {
            $chosen = $customerId ? Customer::query()->withoutGlobalScopes()->where('company_id', $sale->company_id)->find($customerId) : null;

            if (! $chosen || $chosen->isGenericConsumer()) {
                throw ValidationException::withMessages(['customer_id' => ['Al consumidor final no se le abona saldo a favor: elige la ficha del cliente.']]);
            }

            return $chosen;
        }

        return $customer;
    }

    /**
     * @param  list<string>  $statuses
     */
    protected function expectStatus(CustomerReturn $return, array $statuses, string $message): void
    {
        if (! in_array($return->status, $statuses, true)) {
            throw ValidationException::withMessages(['status' => [$message]]);
        }
    }

    protected function lock(CustomerReturn $return): CustomerReturn
    {
        return CustomerReturn::query()->withoutGlobalScopes()->whereKey($return->id)->lockForUpdate()->firstOrFail();
    }

    /** Al cliente de la tienda en linea se le avisa cada paso. Despues del commit: nunca en falso. */
    protected function notify(CustomerReturn $return): void
    {
        if ($return->channel !== Sale::CHANNEL_ONLINE) {
            return;
        }

        DB::afterCommit(function () use ($return) {
            $return->loadMissing('customer');

            if (! $return->customer || ! filter_var($return->customer->email, FILTER_VALIDATE_EMAIL)) {
                return;
            }

            try {
                $return->customer->notify(new CustomerReturnUpdated($return->refresh()));
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }

    /** Las fotos viven en el disco privado: solo las ve el personal o el cliente dueño. */
    public function photoPath(CustomerReturn $return, int $index): ?string
    {
        $path = ($return->photos ?? [])[$index] ?? null;

        return $path && Uploads::privateDisk()->exists($path) ? $path : null;
    }
}

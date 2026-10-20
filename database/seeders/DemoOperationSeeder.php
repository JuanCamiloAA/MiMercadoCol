<?php

namespace Database\Seeders;

use App\Models\CashMovement;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Register;
use App\Models\RegisterSession;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierReturnSettlement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CreditService;
use App\Services\NumberSequenceService;
use App\Services\OrderService;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Services\Storefront\StorefrontConfig;
use App\Services\SupplierReturnService;
use App\Services\WarehouseService;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\StorefrontShowcase;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Treinta dias de operacion para que el sistema se pueda ver funcionando.
 *
 * Todo pasa por los mismos servicios que usa la aplicacion en produccion, de modo que las
 * cifras del panel, los informes y la cartera cuadran entre si por construccion.
 */
class DemoOperationSeeder extends Seeder
{
    protected Company $company;

    protected Store $mainStore;

    /** @var Collection<int, Store> */
    protected $stores;

    /** @var Collection<int, Product> */
    protected $products;

    /** @var Collection<int, User> */
    protected $cashiers;

    protected User $admin;

    public function __construct(
        protected SaleService $sales,
        protected StockService $stock,
        protected WarehouseService $warehouses,
        protected CreditService $credit,
        protected OrderService $orders,
        protected RegisterSessionService $registerSessions,
        protected NumberSequenceService $numbers,
        protected StorefrontConfig $config,
        protected SupplierReturnService $returns,
    ) {}

    public function run(): void
    {
        $this->company = Company::query()->firstOrFail();
        $this->stores = Store::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->orderBy('id')->get();
        $this->mainStore = $this->stores->first();
        $this->products = Product::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->get();
        $this->admin = User::query()->where('email', 'admin@lacanasta.co')->firstOrFail();
        $this->cashiers = User::query()
            ->whereIn('email', ['cajera@lacanasta.co', 'cajero2@lacanasta.co'])
            ->get();

        $this->seedOpeningStock();
        $this->seedPurchaseOrders();
        $this->seedSalesHistory();
        $this->seedOrders();
        $this->seedCreditPayments();
        $this->markOverdueCustomer();
        $this->seedSupplierReturns();
        $this->seedStorefrontShowcase();
    }

    /**
     * Un deposito en la sede principal (sin banderas: la tienda y la caja siguen en la bodega
     * principal) y los casos de «Tienda en linea»: publicado agotado, agotado que el deposito si
     * tiene, compra en camino y uno sin publicar que se vende en caja.
     */
    protected function seedStorefrontShowcase(): void
    {
        $depot = Warehouse::query()->withoutGlobalScopes()->firstOrCreate(
            ['store_id' => $this->mainStore->id, 'code' => 'DEPOSITO'],
            ['company_id' => $this->company->id, 'name' => 'Depósito', 'type' => Warehouse::TYPE_STORAGE, 'is_active' => true],
        );

        app(StorefrontShowcase::class)->seed($this->mainStore, $this->admin, $depot);
    }

    /**
     * Entrada inicial de mercancia: deja kardex desde el primer dia y existencias con que vender.
     */
    protected function seedOpeningStock(): void
    {
        $date = CarbonImmutable::today()->subDays(31)->setTime(7, 30);

        foreach ($this->products as $index => $product) {
            // Uno de cada nueve articulos se queda sin reposicion, para que las alertas tengan de que hablar.
            if ($index % 9 === 0) {
                continue;
            }

            foreach ($this->stores as $storeIndex => $store) {
                $quantity = max(20, (int) round($product->min_stock * ($storeIndex === 0 ? 5 : 3)));

                $warehouse = $this->warehouses->receivingDefaultFor($store->id);

                DB::transaction(function () use ($product, $warehouse, $quantity, $date) {
                    $this->stock->move(
                        product: $product,
                        warehouse: $warehouse,
                        type: StockMovement::TYPE_PURCHASE,
                        quantity: $quantity,
                        userId: $this->admin->id,
                        note: 'Inventario inicial',
                        unitCost: $product->cost,
                        occurredAt: $date,
                    );

                    // Lo perecedero entra en dos lotes con vencimientos distintos, que es lo
                    // que hace visible el consumo FIFO y la alerta de «por vencer».
                    if ($product->track_lots) {
                        $this->stock->receiveLot(
                            product: $product,
                            warehouse: $warehouse,
                            quantity: round($quantity * 0.4, 3),
                            code: 'L'.$date->format('ymd').'-A',
                            expiresAt: $date->addDays(random_int(34, 42))->toDateString(),
                            unitCost: (int) $product->cost,
                            receivedAt: $date,
                        );

                        $this->stock->receiveLot(
                            product: $product,
                            warehouse: $warehouse,
                            quantity: round($quantity * 0.6, 3),
                            code: 'L'.$date->format('ymd').'-B',
                            expiresAt: $date->addDays(random_int(55, 80))->toDateString(),
                            unitCost: (int) $product->cost,
                            receivedAt: $date->addHours(2),
                        );
                    }
                });
            }
        }
    }

    protected function seedPurchaseOrders(): void
    {
        $suppliers = Supplier::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->get();

        $plans = [
            ['status' => PurchaseOrder::STATUS_RECEIVED, 'days' => 12],
            ['status' => PurchaseOrder::STATUS_RECEIVED, 'days' => 5],
            ['status' => PurchaseOrder::STATUS_SENT, 'days' => 1],
            ['status' => PurchaseOrder::STATUS_DRAFT, 'days' => 0],
        ];

        foreach ($plans as $index => $plan) {
            $supplier = $suppliers[$index % $suppliers->count()];
            $placedAt = CarbonImmutable::today()->subDays($plan['days'])->setTime(9, 0);

            $lines = $this->products
                ->where('supplier_id', $supplier->id)
                ->take(6)
                ->map(fn (Product $product) => [
                    'product' => $product,
                    'quantity' => max(10, $product->min_stock * 2),
                    'unit_cost' => (int) round($product->cost * 0.98),
                ])
                ->values();

            if ($lines->isEmpty()) {
                continue;
            }

            DB::transaction(function () use ($supplier, $plan, $placedAt, $lines) {
                $subtotal = 0;
                $taxTotal = 0;

                foreach ($lines as $line) {
                    $gross = (int) round($line['quantity'] * $line['unit_cost']);
                    $base = (int) round($gross / (1 + $line['product']->tax_rate / 100));
                    $subtotal += $base;
                    $taxTotal += $gross - $base;
                }

                $order = PurchaseOrder::create([
                    'company_id' => $this->company->id,
                    'store_id' => $this->mainStore->id,
                    'warehouse_id' => $this->warehouses->receivingDefaultFor($this->mainStore->id)->id,
                    'supplier_id' => $supplier->id,
                    'code' => $this->numbers->nextPurchaseOrderCode($this->company->id),
                    'status' => $plan['status'],
                    'expected_at' => $placedAt->addDays($supplier->lead_time_days)->toDateString(),
                    'received_at' => $plan['status'] === PurchaseOrder::STATUS_RECEIVED ? $placedAt->addDays(1) : null,
                    'subtotal' => $subtotal,
                    'tax_total' => $taxTotal,
                    'total' => $subtotal + $taxTotal,
                    'user_id' => $this->admin->id,
                    'note' => 'Pedido semanal',
                    'created_at' => $placedAt,
                    'updated_at' => $placedAt,
                ]);

                foreach ($lines as $line) {
                    PurchaseOrderItem::create([
                        'purchase_order_id' => $order->id,
                        'product_id' => $line['product']->id,
                        'quantity' => $line['quantity'],
                        'received_quantity' => $plan['status'] === PurchaseOrder::STATUS_RECEIVED ? $line['quantity'] : 0,
                        'unit_cost' => $line['unit_cost'],
                        'expires_at' => $line['product']->track_lots ? $placedAt->addDays(30)->toDateString() : null,
                        'lot_code' => $line['product']->track_lots ? 'L'.$placedAt->format('ymd') : null,
                    ]);

                    if ($plan['status'] !== PurchaseOrder::STATUS_RECEIVED) {
                        continue;
                    }

                    $this->stock->move(
                        product: $line['product'],
                        warehouse: $this->warehouses->receivingDefaultFor($this->mainStore->id),
                        type: StockMovement::TYPE_PURCHASE,
                        quantity: $line['quantity'],
                        userId: $this->admin->id,
                        reference: $order,
                        note: "Recepcion {$order->code}",
                        unitCost: $line['unit_cost'],
                        occurredAt: $placedAt->addDays(1),
                    );

                    $this->stock->recalculateAverageCost($line['product'], (float) $line['quantity'], (int) $line['unit_cost']);
                }
            });
        }
    }

    /**
     * Dos devoluciones a proveedor: una con orden, despachada y con nota credito parcial (queda
     * pendiente de respuesta), y una suelta en borrador. Por el servicio, como en produccion.
     */
    protected function seedSupplierReturns(): void
    {
        $order = PurchaseOrder::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('status', PurchaseOrder::STATUS_RECEIVED)
            ->with('items.product')
            ->orderBy('id')
            ->first();

        if (! $order) {
            return;
        }

        $warehouse = $this->returns->defaultWarehouseFor($order);
        $available = fn (Product $product) => $this->stock->available($product, $warehouse);

        // Dos renglones con existencia de sobra: uno averiado y uno proximo a vencer.
        $lines = $order->items
            ->filter(fn (PurchaseOrderItem $item) => $item->product && $available($item->product) >= 6)
            ->take(2)
            ->values()
            ->map(fn (PurchaseOrderItem $item, int $index) => [
                'purchase_order_item_id' => $item->id,
                'quantity' => $index === 0 ? 3 : 2,
                'reason' => $index === 0 ? 'damaged' : 'near_expiry',
            ])
            ->all();

        if ($lines !== []) {
            $return = $this->returns->create([
                'company_id' => $this->company->id,
                'store_id' => $order->store_id,
                'supplier_id' => $order->supplier_id,
                'purchase_order_id' => $order->id,
                'warehouse_id' => $warehouse->id,
                'shipping_reference' => 'Servientrega 700123456',
                'note' => 'Llegaron cajas golpeadas y un lote con fecha corta.',
                'items' => $lines,
            ], $this->admin);

            $this->returns->dispatch($return, $this->admin);

            // El proveedor mando nota credito por la mitad; el resto sigue pendiente.
            $this->returns->settle($return->refresh(), [
                'type' => SupplierReturnSettlement::TYPE_CREDIT_NOTE,
                'document_number' => 'NC-'.random_int(1000, 9999),
                'amount' => intdiv($return->total, 2),
                'received_at' => now()->subDay(),
            ], $this->admin);
        }

        // Una suelta en borrador, de otro proveedor, con lo que haya en la bodega de recepcion.
        $supplier = Supplier::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('is_active', true)
            ->where('id', '!=', $order->supplier_id)
            ->orderBy('id')
            ->first();
        $receiving = $this->warehouses->receivingDefaultFor($this->mainStore->id);
        $product = $supplier
            ? $this->products->first(fn (Product $product) => $product->supplier_id === $supplier->id && $this->stock->available($product, $receiving) >= 4)
            : null;

        if ($product) {
            $this->returns->create([
                'company_id' => $this->company->id,
                'store_id' => $this->mainStore->id,
                'supplier_id' => $supplier->id,
                'warehouse_id' => $receiving->id,
                'note' => 'Rotación acordada con el vendedor.',
                'items' => [['product_id' => $product->id, 'quantity' => 2, 'reason' => 'agreement']],
            ], $this->admin);
        }
    }

    protected function seedSalesHistory(): void
    {
        $creditCustomers = Customer::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('credit_enabled', true)
            ->get();

        $walkInCustomers = Customer::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('credit_enabled', false)
            ->get();

        for ($dayOffset = 29; $dayOffset >= 0; $dayOffset--) {
            $day = CarbonImmutable::today()->subDays($dayOffset);
            $isToday = $dayOffset === 0;
            $weekend = in_array($day->dayOfWeek, [0, 6], true);

            foreach ($this->cashiers as $cashierIndex => $cashier) {
                $register = Register::query()
                    ->withoutGlobalScopes()
                    ->where('store_id', $this->mainStore->id)
                    ->orderBy('id')
                    ->skip($cashierIndex)
                    ->first();

                if (! $register) {
                    continue;
                }

                $session = RegisterSession::create([
                    'company_id' => $this->company->id,
                    'store_id' => $this->mainStore->id,
                    'register_id' => $register->id,
                    'user_id' => $cashier->id,
                    'opened_at' => $day->setTime(7, 0),
                    'opening_amount' => (int) $this->mainStore->suggested_cash_base,
                    'opening_breakdown' => ['50000' => 2, '20000' => 3, '10000' => 3, '5000' => 2],
                    'opening_note' => 'Base verificada',
                    'status' => RegisterSession::STATUS_OPEN,
                ]);

                $ticketCount = $weekend ? random_int(5, 8) : random_int(3, 6);

                for ($ticket = 0; $ticket < $ticketCount; $ticket++) {
                    $this->createSale($day, $session, $cashier, $creditCustomers, $walkInCustomers);
                }

                // Una sangria a media tarde en algunos turnos.
                if ($dayOffset % 4 === 0) {
                    CashMovement::create([
                        'register_session_id' => $session->id,
                        'type' => CashMovement::TYPE_OUT,
                        'amount' => 200000,
                        'reason' => 'Traslado a la caja fuerte',
                        'user_id' => $cashier->id,
                        'occurred_at' => $day->setTime(16, 30),
                    ]);
                }

                if ($isToday && $cashierIndex === 0) {
                    // El turno de hoy se queda abierto: asi se ve el estado real de una caja en uso.
                    continue;
                }

                $expected = $this->registerSessions->expectedAmount($session);
                $difference = $dayOffset % 7 === 0 ? -random_int(1, 4) * 500 : 0;

                $session->fill([
                    'closed_at' => $day->setTime(21, 15),
                    'counted_amount' => $expected + $difference,
                    'closing_breakdown' => ['50000' => 4, '20000' => 6, '10000' => 5, '5000' => 4, '2000' => 5],
                    'expected_amount' => $expected,
                    'difference' => $difference,
                    'closing_note' => $difference !== 0 ? 'Faltante por vueltas mal entregadas' : null,
                    'status' => RegisterSession::STATUS_CLOSED,
                ])->save();
            }
        }
    }

    /**
     * @param  Collection<int, Customer>  $creditCustomers
     * @param  Collection<int, Customer>  $walkInCustomers
     */
    protected function createSale(
        CarbonImmutable $day,
        RegisterSession $session,
        User $cashier,
        $creditCustomers,
        $walkInCustomers,
    ): void {
        $hour = $this->realisticHour();
        $soldAt = $day->setTime($hour, random_int(0, 59), random_int(0, 59));

        if ($soldAt->isFuture()) {
            $soldAt = CarbonImmutable::now()->subMinutes(random_int(5, 90));
        }

        $lineCount = random_int(2, 6);
        $picked = $this->products->random(min($lineCount, $this->products->count()));
        $items = [];

        foreach ($picked as $product) {
            $available = $this->stock->available($product, $this->warehouses->posSourceFor($this->mainStore->id));

            if ($available < 2) {
                continue;
            }

            $quantity = $product->sold_by_weight
                ? round(random_int(3, 25) / 10, 3)
                : random_int(1, 3);

            $items[] = [
                'product_id' => $product->id,
                'quantity' => min($quantity, max(1, floor($available))),
            ];
        }

        if ($items === []) {
            return;
        }

        $useCredit = $creditCustomers->isNotEmpty() && random_int(1, 100) <= 12;
        $customer = $useCredit ? $creditCustomers->random() : ($walkInCustomers->isNotEmpty() && random_int(1, 100) <= 35 ? $walkInCustomers->random() : null);

        $totalGuess = 0;

        foreach ($items as $item) {
            $product = $this->products->firstWhere('id', $item['product_id']);
            $totalGuess += (int) round($item['quantity'] * $product->price);
        }

        if ($useCredit && $customer && $this->credit->availableCredit($customer) < $totalGuess) {
            $useCredit = false;
        }

        $method = $useCredit
            ? SalePayment::METHOD_CREDIT
            : $this->randomPaymentMethod();

        $payload = [
            'items' => $items,
            'customer_id' => $customer?->id,
            'channel' => Sale::CHANNEL_POS,
            'sold_at' => $soldAt->toDateTimeString(),
            'payments' => [],
        ];

        try {
            // El total real lo calcula el servicio; aqui solo se anticipa para armar el pago.
            $total = $this->payableTotal($items, $method);

            $payload['payments'] = [[
                'method' => $method,
                'amount' => $total,
                'received_amount' => $method === SalePayment::METHOD_CASH ? $this->roundedCash($total) : null,
            ]];

            $this->sales->create($payload, $useCredit ? $this->admin : $cashier, $this->mainStore, $session);
        } catch (\Throwable) {
            // Un tiquete que no cuadra en los datos de demostracion no debe tumbar el sembrado.
        }
    }

    /**
     * @param  list<array{product_id: int, quantity: float}>  $items
     */
    protected function previewTotal(array $items): int
    {
        $total = 0;

        foreach ($items as $item) {
            $product = $this->products->firstWhere('id', $item['product_id']);
            $total += (int) round($item['quantity'] * $product->price);
        }

        return $total;
    }

    /** El pago en efectivo tiene que cuadrar con el total ya redondeado. */
    protected function payableTotal(array $items, string $method): int
    {
        $total = $this->previewTotal($items);

        if ($method !== SalePayment::METHOD_CASH) {
            return $total;
        }

        return $this->sales->roundedTotal($total, $this->sales->roundingStep($this->company->id));
    }

    protected function roundedCash(int $total): int
    {
        return (int) (ceil($total / 5000) * 5000);
    }

    protected function randomPaymentMethod(): string
    {
        $roll = random_int(1, 100);

        return match (true) {
            $roll <= 62 => SalePayment::METHOD_CASH,
            $roll <= 88 => SalePayment::METHOD_CARD,
            default => SalePayment::METHOD_TRANSFER,
        };
    }

    /** Las horas pico de un supermercado de barrio: media manana y final de la tarde. */
    protected function realisticHour(): int
    {
        $hours = [7, 8, 9, 9, 10, 10, 11, 11, 12, 13, 14, 15, 16, 17, 17, 18, 18, 19, 19, 20];

        return $hours[array_rand($hours)];
    }

    protected function seedOrders(): void
    {
        $customers = Customer::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('is_online_user', true)
            ->get();

        if ($customers->isEmpty()) {
            return;
        }

        $courier = User::query()->where('email', 'domicilios@lacanasta.co')->first();
        $deliveryFee = $this->config->deliveryFee($this->mainStore, 0);

        $plans = [
            ['status' => Order::STATUS_DELIVERED, 'days' => 6],
            ['status' => Order::STATUS_DELIVERED, 'days' => 4],
            ['status' => Order::STATUS_DELIVERED, 'days' => 2],
            ['status' => Order::STATUS_ON_ROUTE, 'days' => 0],
            ['status' => Order::STATUS_ON_ROUTE, 'days' => 0],
            ['status' => Order::STATUS_READY, 'days' => 0],
            ['status' => Order::STATUS_READY, 'days' => 0],
            ['status' => Order::STATUS_PICKING, 'days' => 0],
            ['status' => Order::STATUS_PICKING, 'days' => 0],
            ['status' => Order::STATUS_NEW, 'days' => 0],
            ['status' => Order::STATUS_NEW, 'days' => 0],
            ['status' => Order::STATUS_CANCELLED, 'days' => 3],
        ];

        foreach ($plans as $index => $plan) {
            $customer = $customers[$index % $customers->count()];
            $slotStart = CarbonImmutable::today()->subDays($plan['days'])->setTime(10, 0);

            // Solo se piden articulos que de verdad quedan en la sede tras 30 dias de ventas.
            $candidates = $this->products
                ->where('is_online_visible', true)
                ->filter(fn (Product $product) => $this->stock->available($product, $this->warehouses->onlineSourceFor($this->mainStore->id)) >= 6)
                ->values();

            if ($candidates->isEmpty()) {
                continue;
            }

            $items = $candidates
                ->random(min(4, $candidates->count()))
                ->map(fn (Product $product) => [
                    'product_id' => $product->id,
                    'quantity' => random_int(1, 3),
                ])
                ->values()
                ->all();

            $order = $this->orders->create([
                'customer_id' => $customer->id,
                'items' => $items,
                'channel' => $index % 4 === 0 ? Order::CHANNEL_WHATSAPP : Order::CHANNEL_APP,
                'payment_method' => $index % 3 === 0 ? Order::PAYMENT_TRANSFER : Order::PAYMENT_CASH_ON_DELIVERY,
                'delivery_address' => $customer->address.', '.$customer->neighborhood,
                'delivery_notes' => $customer->delivery_notes,
                'delivery_slot_start' => $slotStart->toDateTimeString(),
                'delivery_slot_end' => $slotStart->addHours(2)->toDateTimeString(),
                'delivery_fee' => $deliveryFee,
            ], $this->mainStore, $this->admin);

            $order->forceFill(['placed_at' => $slotStart->subHours(3)])->save();

            $this->advanceOrder($order, $plan['status'], $courier);
        }
    }

    protected function advanceOrder(Order $order, string $target, ?User $courier): void
    {
        if ($target === Order::STATUS_NEW) {
            return;
        }

        if ($target === Order::STATUS_CANCELLED) {
            $this->orders->changeStatus($order, Order::STATUS_CANCELLED, $this->admin, 'El cliente ya no estaba en casa');

            return;
        }

        $path = [Order::STATUS_PICKING, Order::STATUS_READY, Order::STATUS_ON_ROUTE, Order::STATUS_DELIVERED];

        foreach ($path as $status) {
            if ($status === Order::STATUS_ON_ROUTE && $courier) {
                $this->orders->assignCourier($order->refresh(), $courier, $this->admin);
            }

            if ($status === Order::STATUS_PICKING) {
                $order->loadMissing('items');
                $this->orders->pick(
                    $order,
                    $order->items->map(fn ($item) => ['id' => $item->id, 'picked_quantity' => (float) $item->quantity])->all(),
                    $this->admin,
                );
                $order->refresh();
            }

            if ($order->status !== $status) {
                $order = $this->orders->changeStatus($order->refresh(), $status, $this->admin);
            }

            if ($status === $target) {
                break;
            }
        }

        if ($target === Order::STATUS_DELIVERED && $order->payment_status !== 'paid') {
            $order = $order->refresh();
            $this->orders->collect($order, $this->admin, [
                ['method' => SalePayment::METHOD_CASH, 'amount' => (int) ($order->sale?->total ?? $order->total)],
            ]);
        }
    }

    protected function seedCreditPayments(): void
    {
        $customers = Customer::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('credit_enabled', true)
            ->get();

        foreach ($customers as $index => $customer) {
            $balance = $this->credit->currentBalance($customer);

            if ($balance <= 0 || $index % 2 === 1) {
                continue;
            }

            $this->credit->registerPayment(
                customer: $customer,
                amount: (int) round($balance * 0.4),
                method: CustomerPayment::METHOD_CASH,
                storeId: $this->mainStore->id,
                receivedBy: $this->admin,
                registerSessionId: null,
                note: 'Abono en caja',
                paidAt: CarbonImmutable::now()->subDays(random_int(1, 6)),
            );
        }
    }

    /**
     * Un cliente en mora y otro bloqueado: la cartera necesita los dos casos para verse.
     */
    protected function markOverdueCustomer(): void
    {
        $withDebt = Customer::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('credit_enabled', true)
            ->whereHas('balance', fn ($query) => $query->where('balance', '>', 0))
            ->orderBy('id')
            ->get();

        if ($withDebt->isEmpty()) {
            return;
        }

        $overdue = $withDebt->first();

        Sale::query()
            ->withoutGlobalScopes()
            ->where('customer_id', $overdue->id)
            ->where('status', Sale::STATUS_CREDIT)
            ->update(['due_date' => CarbonImmutable::today()->subDays(22)->toDateString()]);

        $this->credit->recalculate($overdue);

        $blocked = $withDebt->count() > 1 ? $withDebt[1] : null;

        if ($blocked) {
            Sale::query()
                ->withoutGlobalScopes()
                ->where('customer_id', $blocked->id)
                ->where('status', Sale::STATUS_CREDIT)
                ->update(['due_date' => CarbonImmutable::today()->subDays(40)->toDateString()]);

            $this->credit->recalculate($blocked);

            $blocked->forceFill([
                'credit_blocked' => true,
                'blocked_reason' => 'Facturas vencidas hace mas de 15 dias.',
            ])->save();
        }
    }
}

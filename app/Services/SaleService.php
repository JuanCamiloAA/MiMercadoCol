<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnItem;
use App\Models\Employee;
use App\Models\Product;
use App\Models\RegisterSession;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\StoreCreditMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registro de ventas.
 *
 * Una venta es atomica: cabecera, lineas, pagos, descuento de existencias y kardex ocurren
 * en la misma transaccion. Si algo falla, no queda ni media venta ni medio inventario.
 */
class SaleService
{
    /** De la accion mas restrictiva a la menos: quien la autorizo es quien queda en la venta. */
    public const APPROVAL_PRIORITY = ['service_add', 'credit', 'discount', 'service_remove', 'service_increase', 'service_reduce'];

    public function __construct(
        protected SaleTotalsCalculator $totals,
        protected StockService $stock,
        protected CreditService $credit,
        protected NumberSequenceService $numbers,
        protected SettingsService $settings,
        protected PromotionService $promotions,
        protected WarehouseService $warehouses,
        protected ServiceSaleBuilder $serviceLines,
        protected StoreCreditService $storeCredits,
    ) {}

    /**
     * Total que se le cobra en efectivo, redondeado al escalon configurado.
     *
     * En Colombia casi ninguna caja tiene monedas de 50, asi que el total en efectivo se
     * ajusta a la centena mas cercana y la diferencia queda visible en el tiquete.
     */
    public function roundedTotal(int $total, int $step): int
    {
        if ($step <= 1) {
            return $total;
        }

        return (int) (round($total / $step) * $step);
    }

    /** Escalon de redondeo configurado para la empresa. 0 o 1 lo dejan apagado. */
    public function roundingStep(int $companyId): int
    {
        return (int) $this->settings->get($companyId, 'pos.cash_rounding');
    }

    /**
     * @param  array{
     *     items: list<array{product_id: int, quantity: float, unit_price?: int, discount?: int}>,
     *     payments: list<array{method: string, amount: int, received_amount?: int|null, reference?: string|null}>,
     *     customer_id?: int|null, note?: string|null, channel?: string,
     *     due_date?: string|null, sold_at?: string|null
     * }  $data
     */
    /**
     * @param  Warehouse|null  $warehouse  De donde sale la mercancia. Por defecto la que vende
     *                                     en caja; un pedido en linea manda la suya, que es de
     *                                     donde quedo reservada.
     */
    public function create(array $data, User $user, Store $store, ?RegisterSession $session = null, ?Warehouse $warehouse = null): Sale
    {
        $warehouse ??= $this->warehouses->posSourceFor($store->id);

        $lines = $data['items'] ?? [];

        if ($lines === []) {
            throw ValidationException::withMessages(['items' => ['El tiquete no tiene articulos.']]);
        }

        // Las promociones se resuelven en el servidor, no se creen las del navegador: lo que
        // llegue como precio es apenas una propuesta. Aqui queda el precio que de verdad se cobra.
        $channel = $data['channel'] ?? Sale::CHANNEL_POS;

        // Los servicios se arman aparte, desde el maestro fresco: no entran a las promociones
        // (tampoco sus articulos) y cada ajuste de sus articulos se autoriza mas abajo.
        $plain = [];
        $built = [];

        foreach (array_values($lines) as $index => $line) {
            if (! empty($line['service_id'])) {
                $built[$index] = $this->serviceLines->build(
                    $line,
                    $store->company_id,
                    $channel,
                    lockedPrices: (bool) ($line['price_locked'] ?? false),
                );
            } else {
                $plain[$index] = $line;
            }
        }

        // El tope del cajero mide su descuento antes de las promociones, servicios incluidos.
        $manualDiscount = array_sum(array_map(fn ($line) => max(0, (int) ($line['discount'] ?? 0)), $plain))
            + array_sum(array_map(fn (array $service) => $service['manual_discount'], $built));
        $promoted = $this->promotions->apply(array_values($plain), $store->company_id, $channel);
        $promotedPlain = array_combine(array_keys($plain), $promoted['lines']) ?: [];

        $productIds = array_values(array_filter(array_map(
            fn ($line) => isset($line['product_id']) ? (int) $line['product_id'] : null,
            $promotedPlain
        )));

        $products = Product::query()
            ->withoutGlobalScopes()
            ->where('company_id', $store->company_id)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $computedLines = [];
        $serviceComputedIndex = [];

        foreach (array_keys(array_values($lines)) as $index) {
            if (isset($built[$index])) {
                $serviceIndex = count($computedLines);
                $serviceComputedIndex[$index] = $serviceIndex;
                $computedLines[] = ['kind' => 'service', 'product' => null, ...$built[$index]['line']];

                foreach ($built[$index]['components'] as $component) {
                    $computedLines[] = ['kind' => 'component', 'parent' => $serviceIndex, ...$component];
                }

                continue;
            }

            $line = $promotedPlain[$index];
            $productId = isset($line['product_id']) ? (int) $line['product_id'] : null;
            $product = $productId ? $products->get($productId) : null;

            if ($productId && ! $product) {
                throw ValidationException::withMessages(['items' => ['Uno de los articulos del tiquete ya no existe.']]);
            }

            // Linea sin articulo, como el domicilio: cobra pero no sale de ninguna estanteria.
            $description = $product?->name ?? trim((string) ($line['description'] ?? ''));

            if ($description === '') {
                throw ValidationException::withMessages(['items' => ['Una linea del tiquete no tiene articulo ni descripcion.']]);
            }

            $quantity = (float) $line['quantity'];

            if ($quantity <= 0) {
                throw ValidationException::withMessages(['items' => ["La cantidad de {$description} debe ser mayor que cero."]]);
            }

            $computedLines[] = [
                'kind' => $product ? 'product' : 'description',
                'product' => $product,
                'description' => $description,
                'quantity' => $quantity,
                'unit_price' => isset($line['unit_price']) ? (int) $line['unit_price'] : (int) ($product?->price ?? 0),
                'tax_rate' => $product ? (float) $product->tax_rate : (float) ($line['tax_rate'] ?? 0),
                'discount' => max(0, (int) ($line['discount'] ?? 0)),
                'unit_cost' => (int) ($product?->cost ?? 0),
            ];
        }

        // Cada ajuste a los articulos de un servicio se autoriza: con el permiso del cajero o
        // con el PIN de alguien que lo tenga. Un pedido ya alistado llega autorizado.
        $serviceApprovers = [];

        if (empty($data['service_changes_authorized'])) {
            foreach ($built as $index => $service) {
                foreach ($service['changes'] as $action) {
                    if ($user->can("pos.sale.{$action}")) {
                        continue;
                    }

                    $candidate = $this->approverFor($data, $action);

                    if (! $candidate?->can("pos.sale.{$action}")) {
                        throw ValidationException::withMessages([
                            'service' => [ServiceSaleBuilder::ACTION_MESSAGES[$action].' ('.$service['service']->name.')'],
                        ]);
                    }

                    $serviceApprovers[$serviceComputedIndex[$index]][$action] = $candidate;
                }
            }
        }

        // Un cupon de la tienda rebaja el pedido entero. Se reparte entre las lineas de
        // articulo para que el impuesto de cada una baje en la misma proporcion, y se mantiene
        // aparte de `$manualDiscount`: lo que la empresa regalo no lo autoriza el cajero.
        $couponDiscount = max(0, (int) ($data['coupon_discount'] ?? 0));

        if ($couponDiscount > 0) {
            $computedLines = $this->spreadDiscount($computedLines, $couponDiscount);
        }

        $totals = $this->totals->calculate(array_map(fn ($line) => [
            'quantity' => $line['quantity'],
            'unit_price' => $line['unit_price'],
            'tax_rate' => $line['tax_rate'],
            'discount' => $line['discount'],
        ], $computedLines));

        // Solo se redondea el efectivo del mostrador: con tarjeta, a credito o en un pedido en
        // linea se cobra el valor exacto que el cliente ya vio.
        $step = $this->roundingStep($store->company_id);
        $roundable = $channel === Sale::CHANNEL_POS && $this->isCashOnly($data['payments'] ?? []);
        $payable = $roundable ? $this->roundedTotal($totals['total'], $step) : $totals['total'];
        $rounding = $payable - $totals['total'];

        $payments = $this->normalizePayments($data['payments'] ?? [], $payable);
        $creditAmount = $this->creditAmount($payments);
        $storeCreditAmount = $this->methodAmount($payments, SalePayment::METHOD_STORE_CREDIT);
        $exchangeAmount = $this->methodAmount($payments, SalePayment::METHOD_EXCHANGE);

        // El valor de una devolucion solo lo aplica `CustomerReturnService::exchangeAtPos`, que
        // dice cuanto hay disponible. Un cobro normal no puede inventarse un «cambio».
        if ($exchangeAmount > 0 && $exchangeAmount > (int) ($data['exchange_limit'] ?? 0)) {
            throw ValidationException::withMessages(['payments' => ['El cambio por devolución solo se registra desde la devolución.']]);
        }

        $customer = null;

        if (! empty($data['customer_id'])) {
            $customer = Customer::query()
                ->withoutGlobalScopes()
                ->where('company_id', $store->company_id)
                ->find($data['customer_id']);
        }

        $storeCreditHeld = (bool) ($data['store_credit_held'] ?? false);

        if ($storeCreditAmount > 0 && ! $storeCreditHeld) {
            if (! $customer) {
                throw ValidationException::withMessages(['customer_id' => ['Para pagar con saldo a favor hay que elegir el cliente.']]);
            }

            // Aviso temprano; el cobro de verdad vuelve a mirar con la fila bloqueada.
            if ($storeCreditAmount > $this->storeCredits->available($customer)) {
                throw ValidationException::withMessages(['payments' => ["{$customer->name} no tiene saldo a favor suficiente."]]);
            }
        }

        if ($creditAmount > 0) {
            if (! $customer) {
                throw ValidationException::withMessages(['customer_id' => ['Para vender a credito hay que elegir un cliente.']]);
            }

            $creditActor = $user->can('pos.sale.credit') ? $user : $this->approverFor($data, 'credit');
            $this->credit->assertCanTake($customer, $creditAmount, $creditActor ?? $user);
        }

        $approver = $this->approverFor($data, 'discount');
        $authorizedBy = $this->authorizer($data, $serviceApprovers, $creditAmount > 0 && ! $user->can('pos.sale.credit'));
        $sellerId = $this->sellerFor($data, $user, $store, $session);

        // El tope de descuento vigila al cajero, no a la promocion: un 3x2 rebaja un tercio del
        // tiquete y no por eso tiene que firmar un supervisor.
        if ($manualDiscount > 0) {
            $this->assertDiscountAllowed(
                ['total' => $totals['total'], 'discount_total' => $manualDiscount],
                $store->company_id,
                $user,
                $approver,
            );
        }

        // Lo efectivamente pagado: sin el credito, sin el cambio devuelto y sin pasarse del total.
        $paidTotal = min($payable, array_sum(array_map(
            fn ($payment) => $payment['method'] === SalePayment::METHOD_CREDIT
                ? 0
                : $payment['amount'] - (int) ($payment['change_amount'] ?? 0),
            $payments
        )));
        $soldAt = isset($data['sold_at']) ? new \DateTimeImmutable($data['sold_at']) : now();

        return DB::transaction(function () use ($data, $store, $session, $user, $customer, $computedLines, $totals, $payments, $creditAmount, $storeCreditAmount, $paidTotal, $soldAt, $payable, $rounding, $authorizedBy, $sellerId, $warehouse) {
            // Entre la comprobacion de arriba y este punto otra caja pudo haberle fiado al
            // mismo cliente: se vuelve a mirar el cupo con la fila del saldo bloqueada.
            if ($creditAmount > 0 && $customer) {
                $creditActor = $user->can('pos.sale.credit') ? $user : $this->approverFor($data, 'credit');
                $this->credit->assertCanTake($customer, $creditAmount, $creditActor ?? $user, lock: true);
            }

            $prefix = $session?->register?->code ?? 'MOS';
            $number = $this->numbers->nextSaleNumber($store->company_id, $store->id, $prefix);

            $dueDate = null;

            if ($creditAmount > 0 && $customer) {
                $dueDate = isset($data['due_date'])
                    ? CarbonImmutable::parse($data['due_date'])
                    : CarbonImmutable::parse($soldAt)->addDays($customer->credit_term_days);
            }

            $sale = Sale::create([
                'company_id' => $store->company_id,
                'store_id' => $store->id,
                'register_session_id' => $session?->id,
                'customer_id' => $customer?->id,
                'user_id' => $user->id,
                'seller_employee_id' => $sellerId,
                'authorized_by_user_id' => $authorizedBy['sale']?->id,
                'number' => $number,
                'channel' => $data['channel'] ?? Sale::CHANNEL_POS,
                'status' => $creditAmount > 0 ? Sale::STATUS_CREDIT : Sale::STATUS_PAID,
                'subtotal' => $totals['subtotal'],
                'tax_total' => $totals['tax_total'],
                'discount_total' => $totals['discount_total'],
                'rounding_total' => $rounding,
                'total' => $payable,
                'paid_total' => $paidTotal,
                'due_total' => $creditAmount,
                'due_date' => $dueDate,
                'note' => $data['note'] ?? null,
                'sold_at' => $soldAt,
            ]);

            $itemIds = [];

            foreach ($computedLines as $index => $line) {
                /** @var Product|null $product */
                $product = $line['product'];
                $computed = $totals['items'][$index];
                $snapshot = $line['service_snapshot'] ?? null;

                if ($snapshot !== null) {
                    $snapshot['authorized_by_user_id'] = ($authorizedBy['lines'][$index] ?? null)?->id;
                }

                $item = SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product?->id,
                    'service_id' => ($line['service'] ?? null)?->id,
                    // Los articulos de un servicio cuelgan de su linea: la linea va antes que ellos.
                    'parent_sale_item_id' => isset($line['parent']) ? $itemIds[$line['parent']] : null,
                    'description' => $line['description'],
                    'quantity' => $computed['quantity'],
                    'master_quantity' => $line['master_quantity'] ?? null,
                    'component_change' => $line['component_change'] ?? null,
                    'returnable_by_default' => $line['returnable_by_default'] ?? null,
                    'service_snapshot' => $snapshot,
                    'unit_price' => $computed['unit_price'],
                    'tax_rate' => $computed['tax_rate'],
                    'discount' => $computed['discount'],
                    'line_total' => $computed['line_total'],
                    'unit_cost' => (int) ($line['unit_cost'] ?? 0),
                ]);

                $itemIds[$index] = $item->id;

                // La linea del servicio y la del domicilio no salen de ninguna estanteria.
                if (! $product) {
                    continue;
                }

                $this->stock->move(
                    product: $product,
                    warehouse: $warehouse,
                    type: StockMovement::TYPE_SALE,
                    quantity: -$computed['quantity'],
                    userId: $user->id,
                    reference: $sale,
                    note: $line['kind'] === 'component'
                        ? "Servicio {$computedLines[$line['parent']]['description']} — venta {$sale->number}"
                        : "Venta {$sale->number}",
                    unitCost: $product->cost,
                    occurredAt: $soldAt,
                );
            }

            foreach ($payments as $payment) {
                SalePayment::create([
                    'sale_id' => $sale->id,
                    'sequence' => $payment['sequence'],
                    'method' => $payment['method'],
                    'amount' => $payment['amount'],
                    'received_amount' => $payment['received_amount'],
                    'change_amount' => $payment['change_amount'],
                    'reference' => $payment['reference'],
                ]);
            }

            // El saldo se descuenta con la venta: si algo falla, no se gasto.
            if ($storeCreditAmount > 0 && $customer && ! ($data['store_credit_held'] ?? false)) {
                $this->storeCredits->consume(
                    $customer,
                    $storeCreditAmount,
                    StoreCreditMovement::TYPE_SALE_PAYMENT,
                    ['sale_id' => $sale->id, 'order_id' => $data['order_id'] ?? null],
                    $user,
                    "Venta {$sale->number}",
                );
            }

            if ($customer) {
                $this->credit->recalculate($customer);
            }

            return $sale->load(['items', 'payments', 'customer', 'store', 'user']);
        });
    }

    /**
     * Anula una venta cobrada: devuelve las existencias y deja el motivo.
     */
    public function cancel(Sale $sale, User $user, string $reason): Sale
    {
        if ($sale->isCancelled()) {
            throw ValidationException::withMessages(['sale' => ['Esa venta ya esta anulada.']]);
        }

        $this->assertCancellable($sale);

        return DB::transaction(function () use ($sale, $user, $reason) {
            $sale->load(['items.product', 'payments', 'customer']);

            // Lo que se pago con saldo a favor vuelve al saldo: la venta no existio.
            $storeCredit = (int) $sale->payments->where('method', SalePayment::METHOD_STORE_CREDIT)->sum('amount');

            if ($storeCredit > 0 && $sale->customer) {
                $this->storeCredits->grant(
                    $sale->customer,
                    $storeCredit,
                    StoreCreditMovement::TYPE_SALE_REVERSAL,
                    ['sale_id' => $sale->id],
                    $user,
                    "Anulacion de la venta {$sale->number}",
                );
            }

            // Todo lo que salio vuelve, articulos de servicios incluidos: la venta no existio.
            foreach ($sale->items as $item) {
                if (! $item->product) {
                    continue;
                }

                $this->stock->move(
                    product: $item->product,
                    warehouse: $this->returnWarehouse($sale, (int) $item->product_id),
                    type: StockMovement::TYPE_RETURN,
                    quantity: (float) $item->quantity,
                    userId: $user->id,
                    reference: $sale,
                    note: "Anulacion de la venta {$sale->number}",
                    unitCost: $item->unit_cost,
                );
            }

            $sale->fill([
                'status' => Sale::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $user->id,
                'cancel_reason' => $reason,
                'due_total' => 0,
            ])->save();

            if ($sale->customer) {
                $this->credit->recalculate($sale->customer);
            }

            return $sale->refresh();
        });
    }

    /**
     * Devolucion de una venta ya cobrada.
     *
     * Se registra como una venta nueva con cantidades y valores en negativo, fechada el dia
     * en que el cliente devolvio. Asi el informe del dia original no cambia hacia atras y la
     * caja del turno refleja la plata que salio de la gaveta.
     *
     * Cada linea puede traer `disposition` (restock, waste o none) en lugar de `restock`. Con
     * `options` la usa `CustomerReturnService`:
     * - `move_stock` (true): false cuando el inventario ya lo movio la devolucion al recibir.
     * - `payments`: como sale la plata, en positivo y por medio; deben sumar lo que no se
     *   perdona de cartera. Sin esto, todo sale por `$method`.
     * - `reference`: el documento de los movimientos del kardex (por defecto, esta venta).
     * - `note`: la nota de la venta en negativo.
     *
     * @param  list<array{sale_item_id: int, quantity: float, restock?: bool|null, disposition?: string|null}>  $lines
     * @param  array{move_stock?: bool, payments?: list<array{method: string, amount: int}>, reference?: Model|null, note?: string|null}  $options
     */
    public function refund(
        Sale $original,
        array $lines,
        User $user,
        Store $store,
        ?RegisterSession $session,
        string $method,
        string $reason,
        array $options = [],
    ): Sale {
        if ($original->status === Sale::STATUS_REFUNDED) {
            throw ValidationException::withMessages(['sale' => ['Esa es una devolucion: no se devuelve una devolucion.']]);
        }

        if ($original->isCancelled()) {
            throw ValidationException::withMessages(['sale' => ['Esa venta esta anulada: no hay nada que devolver.']]);
        }

        $original->load('items.product');
        $byId = $original->items->keyBy('id');
        $refunded = $this->refundedQuantities($original);

        $requested = [];

        foreach ($lines as $line) {
            $item = $byId->get((int) ($line['sale_item_id'] ?? 0));
            $quantity = round((float) ($line['quantity'] ?? 0), 3);

            if ($item && $quantity > 0) {
                $requested[$item->id] = [
                    'item' => $item,
                    'quantity' => $quantity,
                    'restock' => $line['restock'] ?? null,
                    'disposition' => $line['disposition'] ?? null,
                ];
            }
        }

        $selected = [];

        foreach ($requested as $entry) {
            /** @var SaleItem $item */
            $item = $entry['item'];
            $quantity = $entry['quantity'];

            // Los topes se miden por linea original: un articulo suelto y el mismo dentro de un
            // servicio son dos lineas distintas.
            $available = round((float) $item->quantity - ($refunded[$item->id] ?? 0), 3);

            if ($quantity - $available > 0.0005) {
                throw ValidationException::withMessages([
                    'items' => ["De {$item->description} solo quedan ".rtrim(rtrim(number_format($available, 3, ',', '.'), '0'), ',').' por devolver.'],
                ]);
            }

            $restocked = true;

            if ($item->parent_sale_item_id) {
                // Un articulo de un servicio se devuelve con su servicio, y en proporcion a lo que
                // se devuelve de el: dos de cuatro anchetas devuelven la mitad de lo que llevaban.
                $parent = $requested[$item->parent_sale_item_id] ?? null;

                if (! $parent) {
                    throw ValidationException::withMessages([
                        'items' => ["{$item->description} se devuelve junto con su servicio."],
                    ]);
                }

                $parentItem = $parent['item'];
                $share = (float) $parentItem->quantity > 0 ? $parent['quantity'] / (float) $parentItem->quantity : 0;
                $proportional = round((float) $item->quantity * $share, 3);

                if ($quantity - $proportional > 0.0005) {
                    throw ValidationException::withMessages([
                        'items' => ["De {$item->description} se pueden devolver hasta ".rtrim(rtrim(number_format($proportional, 3, ',', '.'), '0'), ',').' con esa cantidad de servicio.'],
                    ]);
                }

                // El cajero decide si vuelve al estante; si no dice nada, lo que decia el maestro.
                $restocked = $entry['restock'] === null ? (bool) ($item->returnable_by_default ?? true) : filter_var($entry['restock'], FILTER_VALIDATE_BOOLEAN);
            } elseif ($item->service_id) {
                $restocked = false;
            }

            // Una devolucion documentada dice a donde fue cada articulo. La averia tambien
            // volvio a la tienda (entra y se da de baja): por eso cuenta como recibida.
            $disposition = $entry['disposition'] ?? null;

            if ($disposition !== null && ! $item->service_id) {
                $restocked = $disposition !== CustomerReturnItem::DISPOSITION_NONE;
            }

            $selected[] = ['item' => $item, 'quantity' => $quantity, 'restocked' => $restocked, 'disposition' => $disposition];
        }

        if ($selected === []) {
            throw ValidationException::withMessages(['items' => ['Elige que se esta devolviendo.']]);
        }

        // La linea del servicio antes que sus articulos, como en la venta.
        usort($selected, fn (array $a, array $b) => $a['item']->id <=> $b['item']->id);

        return DB::transaction(function () use ($original, $selected, $user, $store, $session, $method, $reason, $byId, $options) {
            $prefix = $session?->register?->code ?? 'MOS';
            $number = $this->numbers->nextSaleNumber($store->company_id, $store->id, $prefix);

            $total = 0;
            $base = 0;

            foreach ($selected as $entry) {
                /** @var SaleItem $item */
                $item = $entry['item'];
                $unitNet = $item->quantity > 0 ? $item->line_total / (float) $item->quantity : 0;
                $lineTotal = (int) round($entry['quantity'] * $unitNet);

                $total += $lineTotal;
                $base += Money::baseFromGross($lineTotal, (float) $item->tax_rate);
            }

            // Lo que quedo a credito se perdona primero; solo el resto sale en plata.
            $creditRelief = min($total, (int) $original->due_total);
            $cashBack = $total - $creditRelief;

            $payments = $options['payments'] ?? [['method' => $method, 'amount' => $cashBack]];
            $payments = array_values(array_filter($payments, fn (array $payment) => (int) $payment['amount'] > 0));

            if (array_sum(array_map(fn (array $payment) => (int) $payment['amount'], $payments)) !== $cashBack) {
                throw ValidationException::withMessages(['payments' => ['Lo que sale de la devolucion no cuadra con su valor.']]);
            }

            $refund = Sale::create([
                'company_id' => $store->company_id,
                'store_id' => $store->id,
                'register_session_id' => $session?->id,
                'customer_id' => $original->customer_id,
                'user_id' => $user->id,
                // La devolucion se le resta a quien vendio, no a quien la tramita.
                'seller_employee_id' => $original->seller_employee_id,
                'number' => $number,
                'channel' => $original->channel,
                'status' => Sale::STATUS_REFUNDED,
                'subtotal' => -$base,
                'tax_total' => -($total - $base),
                'discount_total' => 0,
                'total' => -$total,
                'paid_total' => -$cashBack,
                'due_total' => 0,
                'note' => $options['note'] ?? "Devolucion de la venta {$original->number}: {$reason}",
                'sold_at' => now(),
            ]);

            $refundIds = [];

            foreach ($selected as $entry) {
                /** @var SaleItem $item */
                $item = $entry['item'];
                $unitNet = $item->quantity > 0 ? $item->line_total / (float) $item->quantity : 0;

                $line = SaleItem::create([
                    'sale_id' => $refund->id,
                    'product_id' => $item->product_id,
                    'service_id' => $item->service_id,
                    'parent_sale_item_id' => $item->parent_sale_item_id ? ($refundIds[$item->parent_sale_item_id] ?? null) : null,
                    'refunded_from_sale_item_id' => $item->id,
                    'restocked' => $item->product_id ? $entry['restocked'] : null,
                    'description' => $item->description,
                    'quantity' => -$entry['quantity'],
                    'unit_price' => $item->unit_price,
                    'tax_rate' => $item->tax_rate,
                    'discount' => 0,
                    'line_total' => -(int) round($entry['quantity'] * $unitNet),
                    // Lo que no vuelve al estante se consumio igual: su costo no se devuelve.
                    'unit_cost' => $item->product_id && ! $entry['restocked'] ? 0 : $item->unit_cost,
                ]);

                $refundIds[$item->id] = $line->id;

                // Lo que el cliente no trae de vuelta no se mueve: ya salio con la venta. Si la
                // devolucion ya lo movio al recibir, tampoco.
                if ($item->product && $entry['restocked'] && ($options['move_stock'] ?? true)) {
                    $this->stock->move(
                        product: $item->product,
                        warehouse: $this->returnWarehouse($original, (int) $item->product_id),
                        type: StockMovement::TYPE_RETURN,
                        quantity: $entry['quantity'],
                        userId: $user->id,
                        reference: $options['reference'] ?? $refund,
                        note: $item->parent_sale_item_id
                            ? "Devolucion de la venta {$original->number} (servicio {$byId->get($item->parent_sale_item_id)?->description})"
                            : "Devolucion de la venta {$original->number}",
                        unitCost: $item->unit_cost,
                    );
                }
            }

            foreach ($payments as $sequence => $payment) {
                SalePayment::create([
                    'sale_id' => $refund->id,
                    'sequence' => $sequence + 1,
                    'method' => $payment['method'],
                    'amount' => -(int) $payment['amount'],
                ]);
            }

            if ($creditRelief > 0) {
                $original->due_total -= $creditRelief;
                $original->total -= $creditRelief;

                if ($original->due_total <= 0) {
                    $original->due_total = 0;
                    $original->status = Sale::STATUS_PAID;
                }

                $original->save();
            }

            if ($original->customer) {
                $this->credit->recalculate($original->customer);
            }

            return $refund->load(['items', 'payments']);
        });
    }

    /**
     * Cuanto se ha devuelto ya de cada linea de la venta.
     *
     * @return array<int, float>
     */
    public function refundedQuantities(Sale $sale): array
    {
        $sale->loadMissing('items');

        // Cada linea de devolucion dice de que linea sale: se cuenta por ahi, diga lo que diga la
        // nota (la de una devolucion documentada lleva su numero DV).
        $byLine = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', Sale::STATUS_REFUNDED)
            ->whereIn('sale_items.refunded_from_sale_item_id', $sale->items->pluck('id'))
            ->groupBy('sale_items.refunded_from_sale_item_id')
            ->selectRaw('sale_items.refunded_from_sale_item_id as line_id, SUM(ABS(sale_items.quantity)) as quantity')
            ->pluck('quantity', 'line_id')
            ->map(fn ($quantity) => (float) $quantity)
            ->all();

        // Las de antes de que existiera esa columna se cuentan con la regla vieja (articulo y
        // descripcion, por la nota de la venta), solo para lineas sueltas.
        $legacy = [];

        $refunds = Sale::query()
            ->withoutGlobalScopes()
            ->where('company_id', $sale->company_id)
            ->where('status', Sale::STATUS_REFUNDED)
            ->where('note', 'like', "Devolucion de la venta {$sale->number}:%")
            ->with('items')
            ->get();

        foreach ($refunds as $refund) {
            foreach ($refund->items as $item) {
                if (! $item->refunded_from_sale_item_id && ! $item->service_id && ! $item->parent_sale_item_id) {
                    $key = $item->product_id.'|'.$item->description;
                    $legacy[$key] = ($legacy[$key] ?? 0) + abs((float) $item->quantity);
                }
            }
        }

        $result = [];

        foreach ($sale->items as $item) {
            $quantity = $byLine[$item->id] ?? 0;

            if (! $item->service_id && ! $item->parent_sale_item_id) {
                $quantity += $legacy[$item->product_id.'|'.$item->description] ?? 0;
            }

            $result[$item->id] = round($quantity, 3);
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     * @return list<array{method: string, amount: int, received_amount: int|null, change_amount: int|null, reference: string|null}>
     */
    /**
     * Reparte el cobro entre medios y decide cuanto es cambio.
     *
     * Es publico porque el cobro contraentrega de un pedido sigue exactamente las mismas
     * reglas que el del mostrador: la unica diferencia es que alli no se admite credito,
     * y eso se expresa con `$allowed`.
     *
     * @param  list<array<string, mixed>>  $payments
     * @param  list<string>|null  $allowed
     * @return list<array{sequence: int, method: string, amount: int, received_amount: int|null, change_amount: int|null, reference: string|null}>
     */
    public function normalizePayments(array $payments, int $total, ?array $allowed = null): array
    {
        $allowed ??= array_keys(SalePayment::METHOD_LABELS);

        $normalized = [];
        $seen = [];
        $covered = 0;
        $nonCash = 0;
        $cashIndex = null;

        foreach ($payments as $payment) {
            $method = (string) ($payment['method'] ?? '');

            if (! array_key_exists($method, SalePayment::METHOD_LABELS) || ! in_array($method, $allowed, true)) {
                throw ValidationException::withMessages(['payments' => ['Medio de pago no reconocido.']]);
            }

            $amount = (int) ($payment['amount'] ?? 0);

            if ($amount <= 0) {
                continue;
            }

            // Repartir el cobro es combinar medios distintos, no cobrar dos veces por el mismo.
            if (isset($seen[$method])) {
                throw ValidationException::withMessages([
                    'payments' => ['Hay dos pagos con el mismo medio ('.SalePayment::METHOD_LABELS[$method].').'],
                ]);
            }

            $seen[$method] = true;
            $received = isset($payment['received_amount']) ? (int) $payment['received_amount'] : null;

            if ($method === SalePayment::METHOD_CASH) {
                if ($received !== null && $received < $amount) {
                    throw ValidationException::withMessages([
                        'payments' => ['El efectivo recibido es menor que el monto asignado.'],
                    ]);
                }

                // Si el cajero anota que recibio mas, ese es el efectivo que entro a la gaveta.
                $amount = max($amount, $received ?? 0);
                $received = $amount;
                $cashIndex = count($normalized);
            } else {
                $received = null;
                $nonCash += $amount;
            }

            $normalized[] = [
                'sequence' => count($normalized) + 1,
                'method' => $method,
                'amount' => $amount,
                'received_amount' => $received,
                'change_amount' => null,
                'reference' => isset($payment['reference']) ? (string) $payment['reference'] : null,
            ];

            $covered += $amount;
        }

        if ($normalized === []) {
            throw ValidationException::withMessages(['payments' => ['Falta registrar el cobro.']]);
        }

        if (count($normalized) > count(SalePayment::METHOD_LABELS)) {
            throw ValidationException::withMessages(['payments' => ['Un cobro admite como maximo un pago por medio.']]);
        }

        if ($covered < $total) {
            throw ValidationException::withMessages([
                'payments' => ['Faltan '.self::money($total - $covered).' por cobrar.'],
            ]);
        }

        // Tarjeta, transferencia y credito se cobran exactos: de ellos no sale cambio.
        if ($nonCash > $total) {
            throw ValidationException::withMessages([
                'payments' => ['Solo el efectivo puede exceder el total.'],
            ]);
        }

        $change = $covered - $total;

        if ($change > 0 && $cashIndex === null) {
            throw ValidationException::withMessages([
                'payments' => ['Solo el efectivo puede exceder el total.'],
            ]);
        }

        if ($cashIndex !== null) {
            $normalized[$cashIndex]['change_amount'] = $change;
        }

        return $normalized;
    }

    /**
     * El descuento lo aplica quien tenga el permiso, y por encima del tope hace falta que un
     * supervisor lo autorice con su PIN.
     *
     * @param  array{total: int, discount_total: int}  $totals
     */
    /**
     * Quien queda como vendedor de la venta.
     *
     * Si no se dice nada, es el dueño del turno: la caja la abrio alguien y, mientras nadie
     * indique otra cosa, lo que se venda en ella es suyo. Si se indica, tiene que ser una ficha
     * activa de la misma empresa; un id de otra empresa no se ignora en silencio, se rechaza.
     *
     * @param  array<string, mixed>  $data
     */
    protected function sellerFor(array $data, User $user, Store $store, ?RegisterSession $session): ?int
    {
        if (array_key_exists('seller_employee_id', $data) && $data['seller_employee_id'] !== null && $data['seller_employee_id'] !== '') {
            $seller = Employee::query()
                ->withoutGlobalScopes()
                ->where('company_id', $store->company_id)
                ->where('status', Employee::STATUS_ACTIVE)
                ->find((int) $data['seller_employee_id']);

            if (! $seller) {
                throw ValidationException::withMessages([
                    'seller_employee_id' => ['Ese vendedor no existe o ya no esta activo.'],
                ]);
            }

            return $seller->id;
        }

        // Por defecto, quien abrio la caja; si el turno no dice nada, quien esta cobrando.
        return $session?->user?->employee_id ?? $user->employee_id;
    }

    /**
     * Reparte un descuento del pedido entre las lineas de articulo, a prorrata.
     *
     * El sobrante de la division entera cae en la linea mas grande: repartir 1.000 entre tres
     * lineas deja 333, 333 y 334, nunca 999.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    protected function spreadDiscount(array $lines, int $discount): array
    {
        $net = [];

        foreach ($lines as $index => $line) {
            // El cupon va a las lineas cobradas: articulos, servicios y sus articulos detallados. El
            // domicilio no, y los articulos de un servicio cerrado valen cero y quedan fuera solos.
            if (($line['kind'] ?? 'product') === 'description') {
                continue;
            }

            $value = (int) round($line['quantity'] * $line['unit_price']) - (int) $line['discount'];

            if ($value > 0) {
                $net[$index] = $value;
            }
        }

        $base = array_sum($net);

        if ($base <= 0) {
            return $lines;
        }

        $discount = min($discount, $base);
        $assigned = 0;

        foreach ($net as $index => $value) {
            $share = (int) floor($discount * $value / $base);
            $lines[$index]['discount'] += $share;
            $assigned += $share;
        }

        if ($assigned < $discount) {
            $largest = array_search(max($net), $net, true);
            $lines[$largest]['discount'] += $discount - $assigned;
        }

        return $lines;
    }

    /**
     * Quien autoriza una accion en esta venta: el de esa accion; para descuento y credito, tambien
     * la autorizacion de siempre (`approver`), que es una sola por venta.
     *
     * @param  array<string, mixed>  $data
     */
    protected function approverFor(array $data, string $action): ?User
    {
        if (isset($data['approvers'][$action]) && $data['approvers'][$action] instanceof User) {
            return $data['approvers'][$action];
        }

        return in_array($action, ['discount', 'credit'], true) ? ($data['approver'] ?? null) : null;
    }

    /**
     * Quien queda como autorizador de la venta y de cada linea de servicio.
     *
     * Si autorizaron dos personas distintas, en la venta queda la de la accion mas restrictiva
     * (`APPROVAL_PRIORITY`); cada linea de servicio guarda la suya en su foto.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, User>>  $serviceApprovers
     * @return array{sale: ?User, lines: array<int, User>}
     */
    protected function authorizer(array $data, array $serviceApprovers, bool $creditNeeded): array
    {
        $used = [];

        foreach ($serviceApprovers as $actions) {
            foreach ($actions as $action => $approver) {
                $used[$action] ??= $approver;
            }
        }

        if ($creditNeeded && ($credit = $this->approverFor($data, 'credit'))) {
            $used['credit'] = $credit;
        }

        if ($discount = $this->approverFor($data, 'discount')) {
            $used['discount'] ??= $discount;
        }

        $sale = null;

        foreach (self::APPROVAL_PRIORITY as $action) {
            if (isset($used[$action])) {
                $sale = $used[$action];
                break;
            }
        }

        $lines = [];

        foreach ($serviceApprovers as $lineIndex => $actions) {
            foreach (self::APPROVAL_PRIORITY as $action) {
                if (isset($actions[$action])) {
                    $lines[$lineIndex] = $actions[$action];
                    break;
                }
            }
        }

        return ['sale' => $sale ?? ($data['approver'] ?? null), 'lines' => $lines];
    }

    protected function assertDiscountAllowed(array $totals, int $companyId, User $user, ?User $approver): void
    {
        $granted = $user->can('pos.sale.discount') || (bool) $approver?->can('pos.sale.discount');

        if (! $granted) {
            throw ValidationException::withMessages(['discount' => ['No tienes permiso para aplicar descuentos.']]);
        }

        $gross = $totals['total'] + $totals['discount_total'];

        if ($gross <= 0) {
            return;
        }

        $maxPercent = (float) $this->settings->get($companyId, 'pos.allow_discount_percent');
        $percent = ($totals['discount_total'] / $gross) * 100;

        // Medio punto de tolerancia: el redondeo por linea no puede tumbar una venta valida.
        if ($percent <= $maxPercent + 0.5) {
            return;
        }

        if (! $approver?->can('pos.sale.discount')) {
            throw ValidationException::withMessages([
                'discount' => ['Ese descuento supera el '.rtrim(rtrim(number_format($maxPercent, 1, ',', '.'), '0'), ',').' % permitido: pide autorizacion de un supervisor.'],
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     */
    protected function isCashOnly(array $payments): bool
    {
        if ($payments === []) {
            return false;
        }

        foreach ($payments as $payment) {
            if (($payment['method'] ?? null) !== SalePayment::METHOD_CASH) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{method: string, amount: int}>  $payments
     */
    protected function creditAmount(array $payments): int
    {
        return array_sum(array_map(
            fn ($payment) => $payment['method'] === SalePayment::METHOD_CREDIT ? $payment['amount'] : 0,
            $payments
        ));
    }

    protected static function money(int $amount): string
    {
        return '$ '.number_format($amount, 0, ',', '.');
    }

    /**
     * La bodega a la que vuelve lo devuelto.
     *
     * Es la del movimiento que lo saco, no la que hoy vende en caja: si entre la venta y la
     * devolucion cambiaron cual bodega atiende la caja, devolver a la nueva sumaria mercancia
     * en un estante donde nunca estuvo y la dejaria faltando en el otro.
     */
    /** La bodega de donde salio el articulo en esa venta: ahi vuelve. */
    public function returnWarehouse(Sale $sale, int $productId): Warehouse
    {
        $warehouseId = StockMovement::query()
            ->withoutGlobalScopes()
            ->where('reference_type', $sale->getMorphClass())
            ->where('reference_id', $sale->id)
            ->where('product_id', $productId)
            ->where('type', StockMovement::TYPE_SALE)
            ->value('warehouse_id');

        $warehouse = $warehouseId
            ? Warehouse::query()->withoutGlobalScopes()->find($warehouseId)
            : null;

        return $warehouse ?? $this->warehouses->posSourceFor($sale->store_id);
    }

    /**
     * Una venta con devoluciones, o que es ella misma una devolucion o un cambio, no se anula:
     * el inventario y la plata ya se movieron por otro documento. Se registra una devolucion.
     */
    public function assertCancellable(Sale $sale): void
    {
        if ($sale->status === Sale::STATUS_REFUNDED) {
            throw ValidationException::withMessages(['sale' => ['Una devolucion no se anula.']]);
        }

        $hasRefunds = SaleItem::query()
            ->whereIn('refunded_from_sale_item_id', SaleItem::query()->where('sale_id', $sale->id)->select('id'))
            ->exists();

        $hasReturns = CustomerReturn::query()->withoutGlobalScopes()
            ->where('sale_id', $sale->id)
            ->whereNotIn('status', [CustomerReturn::STATUS_CANCELLED, CustomerReturn::STATUS_REJECTED])
            ->exists();

        if ($hasRefunds || $hasReturns) {
            throw ValidationException::withMessages(['sale' => ["La venta {$sale->number} tiene devoluciones: ya no se anula. Registra una devolución de lo que falte."]]);
        }

        if (SalePayment::query()->where('sale_id', $sale->id)->where('method', SalePayment::METHOD_EXCHANGE)->exists()) {
            throw ValidationException::withMessages(['sale' => ["La venta {$sale->number} es un cambio: no se anula. Registra una devolución."]]);
        }
    }

    /**
     * @param  list<array{method: string, amount: int}>  $payments
     */
    protected function methodAmount(array $payments, string $method): int
    {
        return array_sum(array_map(fn ($payment) => $payment['method'] === $method ? (int) $payment['amount'] : 0, $payments));
    }
}

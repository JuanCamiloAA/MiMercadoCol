<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesTransferWarehouses;
use App\Http\Requests\SupplierReturns\CancelSupplierReturnRequest;
use App\Http\Requests\SupplierReturns\RejectSupplierReturnRequest;
use App\Http\Requests\SupplierReturns\StoreReplacementOrderRequest;
use App\Http\Requests\SupplierReturns\StoreSupplierReturnRequest;
use App\Http\Requests\SupplierReturns\StoreSupplierReturnSettlementRequest;
use App\Http\Requests\SupplierReturns\UpdateSupplierReturnRequest;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnItem;
use App\Models\SupplierReturnSettlement;
use App\Models\Warehouse;
use App\Services\SettingsService;
use App\Services\SupplierReturnCatalog;
use App\Services\SupplierReturnService;
use App\Services\WarehouseService;
use App\Support\StoreContext;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Devoluciones a proveedor: con orden de compra o sueltas.
 *
 * Todo lo que mueve inventario o plata pasa por `SupplierReturnService`. Aqui solo se arma lo
 * que ve cada pantalla y se revisa que quien actua llegue a la sede de la bodega.
 */
class SupplierReturnController extends Controller
{
    use HandlesTransferWarehouses;

    public function __construct(
        protected SupplierReturnService $returns,
        protected SupplierReturnCatalog $catalog,
        protected WarehouseService $warehouses,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();
        $filters = $this->filters($request);

        $returns = $this->filtered($companyId, $filters)
            ->with(['supplier:id,name', 'store:id,name', 'warehouse:id,name', 'purchaseOrder:id,code', 'items:id,supplier_return_id,quantity,rejected_quantity'])
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (SupplierReturn $return) => $this->row($return));

        $pending = SupplierReturn::query()
            ->where('company_id', $companyId)
            ->where('status', SupplierReturn::STATUS_DISPATCHED)
            ->selectRaw('COUNT(*) as pending_count, COALESCE(SUM(GREATEST(total - rejected_total - settled_total, 0)), 0) as pending_value')
            ->first();

        return Inertia::render('SupplierReturns/Index', [
            'returns' => $returns,
            'filters' => $filters,
            'cards' => [
                'pending_count' => (int) ($pending->pending_count ?? 0),
                'pending_value' => (int) ($pending->pending_value ?? 0),
                'drafts' => SupplierReturn::query()->where('company_id', $companyId)->where('status', SupplierReturn::STATUS_DRAFT)->count(),
                'month_value' => (int) SupplierReturn::query()
                    ->where('company_id', $companyId)
                    ->whereIn('status', [SupplierReturn::STATUS_DISPATCHED, SupplierReturn::STATUS_CLOSED])
                    ->whereBetween('dispatched_at', [now()->startOfMonth(), now()->endOfMonth()])
                    ->sum(DB::raw('total - rejected_total')),
            ],
            'statuses' => $this->options(SupplierReturn::STATUS_LABELS),
            'reasons' => SupplierReturnItem::reasonOptions(),
            'kinds' => [
                ['value' => 'order', 'label' => 'Con orden de compra'],
                ['value' => 'loose', 'label' => 'Sueltas'],
            ],
            'suppliers' => Supplier::query()->where('company_id', $companyId)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Supplier $supplier) => ['value' => $supplier->id, 'label' => $supplier->name])->values(),
            'stores' => Store::query()->where('company_id', $companyId)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Store $store) => ['value' => $store->id, 'label' => $store->name])->values(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $companyId = TenantContext::requireCompanyId();

        $rows = $this->filtered($companyId, $this->filters($request))
            ->with(['supplier:id,name', 'store:id,name', 'warehouse:id,name', 'purchaseOrder:id,code', 'items:id,supplier_return_id,quantity,rejected_quantity'])
            ->orderByDesc('id')
            ->get();

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Numero', 'Fecha', 'Despachada', 'Estado', 'Proveedor', 'Orden', 'Sede', 'Bodega', 'Unidades', 'Valor', 'Rechazado', 'Respondido', 'Pendiente a favor'], ';');

            foreach ($rows as $return) {
                fputcsv($handle, [
                    $return->number,
                    $return->created_at?->format('Y-m-d H:i'),
                    $return->dispatched_at?->format('Y-m-d H:i'),
                    $return->statusLabel(),
                    $return->supplier?->name,
                    $return->purchaseOrder?->code,
                    $return->store?->name,
                    $return->warehouse?->name,
                    round((float) $return->items->sum('quantity'), 3),
                    $return->total,
                    $return->rejected_total,
                    $return->settled_total,
                    $return->pendingTotal(),
                ], ';');
            }

            fclose($handle);
        }, 'devoluciones-proveedor-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** El buscador de la devolucion suelta, con escaner. */
    public function products(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:60'],
            'barcode' => ['nullable', 'string', 'max:60'],
            'supplier' => ['nullable', 'integer'],
            'ids' => ['nullable', 'array', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        $products = $this->catalog->search(
            TenantContext::requireCompanyId(),
            StoreContext::requireStoreId(),
            ($validated['supplier'] ?? null) ?: null,
            $validated['search'] ?? null,
            $validated['barcode'] ?? null,
            array_map('intval', $validated['ids'] ?? []),
        );

        return response()->json(['products' => $products, 'found' => $products !== []]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $order = null;

        if ($request->filled('orden')) {
            $order = PurchaseOrder::query()->where('company_id', $companyId)->with('items')->find($request->integer('orden'));

            if (! $order || ! $order->canReturn()) {
                return redirect()->route('supplier_returns.create')
                    ->with('error', 'Esa orden no tiene nada por devolver: solo se devuelve de órdenes recibidas, y lo que ya se devolvió no cuenta.');
            }
        }

        return Inertia::render('SupplierReturns/Form', $this->formProps($companyId, $order, null));
    }

    public function store(StoreSupplierReturnRequest $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $orderId = $request->validated('purchase_order_id');
        $storeId = $orderId
            ? (int) PurchaseOrder::query()->where('company_id', $companyId)->whereKey($orderId)->value('store_id')
            : StoreContext::requireStoreId();

        $this->guardAccess($this->warehouseOf($companyId, (int) $request->validated('warehouse_id')), 'devolver desde');

        $dispatch = $request->boolean('dispatch_now');

        if ($dispatch) {
            abort_unless($request->user()->can('purchases.returns.dispatch'), 403, 'No tienes permiso para despachar devoluciones.');
        }

        // Guardar y despachar es un solo acto: si no alcanza, no queda un borrador que nadie pidio.
        $return = DB::transaction(function () use ($request, $companyId, $storeId, $dispatch) {
            $return = $this->returns->create($request->payload($companyId, $storeId), $request->user());

            return $dispatch ? $this->returns->dispatch($return, $request->user()) : $return;
        });

        return redirect()->route('supplier_returns.show', $return)->with(
            'success',
            $dispatch
                ? "La devolución {$return->number} salió de la bodega. Queda pendiente de la respuesta del proveedor."
                : "La devolución {$return->number} quedó en borrador. No ha salido nada todavía."
        );
    }

    public function show(SupplierReturn $supplierReturn): Response
    {
        $this->authorizeCompany($supplierReturn);

        $supplierReturn->load([
            'items.product', 'items.lot', 'supplier', 'store:id,name', 'warehouse:id,name,store_id', 'purchaseOrder:id,code,status',
            'settlements.user:id,name,last_name', 'settlements.purchaseOrder:id,code,status', 'replacementOrders.items',
            'creator:id,name,last_name', 'editor:id,name,last_name', 'dispatcher:id,name,last_name', 'canceller:id,name,last_name',
        ]);

        $replaced = [];

        foreach ($supplierReturn->replacementOrders->where('status', '!=', PurchaseOrder::STATUS_CANCELLED) as $order) {
            foreach ($order->items as $item) {
                $replaced[$item->product_id] = ($replaced[$item->product_id] ?? 0) + (float) $item->quantity;
            }
        }

        return Inertia::render('SupplierReturns/Show', [
            'supplierReturn' => [
                ...$this->row($supplierReturn),
                'supplier' => [
                    'id' => $supplierReturn->supplier?->id,
                    'name' => $supplierReturn->supplier?->name,
                    'contact_name' => $supplierReturn->supplier?->contact_name,
                    'phone' => $supplierReturn->supplier?->phone,
                ],
                'order' => $supplierReturn->purchaseOrder ? ['id' => $supplierReturn->purchaseOrder->id, 'code' => $supplierReturn->purchaseOrder->code] : null,
                'note' => $supplierReturn->note,
                'shipping_reference' => $supplierReturn->shipping_reference,
                'subtotal' => $supplierReturn->subtotal,
                'tax_total' => $supplierReturn->tax_total,
                'rejected_total' => $supplierReturn->rejected_total,
                'settled_total' => $supplierReturn->settled_total,
                'created_by' => $supplierReturn->creator?->full_name,
                'dispatched_by' => $supplierReturn->dispatcher?->full_name,
                'cancel_reason' => $supplierReturn->cancel_reason,
            ],
            'items' => $supplierReturn->items->map(fn (SupplierReturnItem $item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'name' => $item->product?->name,
                'sku' => $item->product?->sku,
                'unit' => $item->product?->unit,
                'sold_by_weight' => (bool) $item->product?->sold_by_weight,
                'reason' => $item->reason,
                'reason_label' => $item->reasonLabel(),
                'note' => $item->note,
                'quantity' => round((float) $item->quantity, 3),
                'rejected_quantity' => round((float) $item->rejected_quantity, 3),
                'net_quantity' => $item->netQuantity(),
                // Lo que todavia se puede pedir en reposicion de este renglon.
                'replaceable' => round(max(0, $item->netQuantity() - ($replaced[$item->product_id] ?? 0)), 3),
                'unit_cost' => $item->unit_cost,
                'line_total' => $item->line_total,
                'chosen_lot' => $item->lot?->code,
                'lots' => collect($item->lots ?? [])->map(fn (array $lot) => [
                    'code' => $lot['code'] ?? null,
                    'expires_at' => $lot['expires_at'] ?? null,
                    'quantity' => round((float) $lot['quantity'], 3),
                ])->values(),
            ])->values(),
            'settlements' => $supplierReturn->settlements->map(fn (SupplierReturnSettlement $settlement) => [
                'id' => $settlement->id,
                'type' => $settlement->type,
                'type_label' => $settlement->typeLabel(),
                'document_number' => $settlement->document_number,
                'amount' => $settlement->amount,
                'received_at' => $settlement->received_at?->toIso8601String(),
                'payment_method' => $settlement->payment_method ? (SupplierReturnSettlement::METHOD_LABELS[$settlement->payment_method] ?? $settlement->payment_method) : null,
                'note' => $settlement->note,
                'user' => $settlement->user?->full_name,
                'order' => $settlement->purchaseOrder ? [
                    'id' => $settlement->purchaseOrder->id,
                    'code' => $settlement->purchaseOrder->code,
                    'status_label' => $settlement->purchaseOrder->statusLabel(),
                ] : null,
            ])->values(),
            'timeline' => $this->timeline($supplierReturn),
            'paymentMethods' => $this->options(SupplierReturnSettlement::METHOD_LABELS),
            // La reposicion tiene su propio boton: crea una orden de compra.
            'settlementTypes' => $this->options(array_intersect_key(SupplierReturnSettlement::TYPE_LABELS, array_flip([
                SupplierReturnSettlement::TYPE_CREDIT_NOTE,
                SupplierReturnSettlement::TYPE_REFUND,
            ]))),
            'can' => [
                'act_here' => $this->reaches($supplierReturn->warehouse),
            ],
        ]);
    }

    public function edit(SupplierReturn $supplierReturn): Response|RedirectResponse
    {
        $this->authorizeCompany($supplierReturn);

        if (! $supplierReturn->isDraft()) {
            return redirect()->route('supplier_returns.show', $supplierReturn)->with('error', 'Solo se corrige una devolución en borrador.');
        }

        $supplierReturn->load(['items.product', 'purchaseOrder.items']);

        return Inertia::render('SupplierReturns/Form', $this->formProps(
            $supplierReturn->company_id,
            $supplierReturn->purchaseOrder,
            $supplierReturn,
        ));
    }

    public function update(UpdateSupplierReturnRequest $request, SupplierReturn $supplierReturn): RedirectResponse
    {
        $this->authorizeCompany($supplierReturn);
        $this->guardAccess($this->warehouseOf($supplierReturn->company_id, (int) $request->validated('warehouse_id')), 'devolver desde');

        $this->returns->update($supplierReturn, $request->payload($supplierReturn->company_id, $supplierReturn->store_id), $request->user());

        return redirect()->route('supplier_returns.show', $supplierReturn)->with('success', 'El borrador quedó corregido.');
    }

    public function dispatch(Request $request, SupplierReturn $supplierReturn): RedirectResponse
    {
        $this->authorizeCompany($supplierReturn);
        $supplierReturn->load('warehouse.store');
        $this->guardAccess($supplierReturn->warehouse, 'devolver desde');

        $this->returns->dispatch($supplierReturn, $request->user());

        return back()->with('success', "La mercancía salió de {$supplierReturn->warehouse?->name}. La devolución queda pendiente de la respuesta del proveedor.");
    }

    public function reject(RejectSupplierReturnRequest $request, SupplierReturn $supplierReturn): RedirectResponse
    {
        $this->authorizeCompany($supplierReturn);
        $supplierReturn->load('warehouse.store');
        $this->guardAccess($supplierReturn->warehouse, 'recibir en');

        $this->returns->reject($supplierReturn, $request->quantities(), $request->user(), $request->validated('note'));

        return back()->with('success', "El rechazo quedó registrado y la mercancía volvió a {$supplierReturn->warehouse?->name}.");
    }

    public function settle(StoreSupplierReturnSettlementRequest $request, SupplierReturn $supplierReturn): RedirectResponse
    {
        $this->authorizeCompany($supplierReturn);

        $settlement = $this->returns->settle($supplierReturn, $request->validated(), $request->user());

        return back()->with('success', $settlement->type === SupplierReturnSettlement::TYPE_REFUND
            ? 'El reembolso quedó registrado.'
            : "La nota crédito {$settlement->document_number} quedó registrada.");
    }

    public function replacement(StoreReplacementOrderRequest $request, SupplierReturn $supplierReturn): RedirectResponse
    {
        $this->authorizeCompany($supplierReturn);

        $order = $this->returns->createReplacementOrder($supplierReturn, $request->quantities(), $request->user(), $request->validated('note'));
        $message = "Quedó la orden de reposición {$order->code}. Se recibe con el botón de siempre cuando llegue la mercancía.";

        return $request->user()->can('purchases.index.view')
            ? redirect()->route('purchases.show', $order)->with('success', $message)
            : back()->with('success', $message);
    }

    public function cancel(CancelSupplierReturnRequest $request, SupplierReturn $supplierReturn): RedirectResponse
    {
        $this->authorizeCompany($supplierReturn);

        $this->returns->cancel($supplierReturn, $request->user(), $request->validated('reason'));

        return back()->with('success', 'La devolución quedó anulada. No se movió inventario.');
    }

    /** El documento que viaja con la mercancia: para el proveedor o el transportador. */
    public function document(SupplierReturn $supplierReturn): HttpResponse
    {
        $this->authorizeCompany($supplierReturn);

        $supplierReturn->load(['items.product', 'items.lot', 'supplier', 'store', 'warehouse', 'purchaseOrder:id,code', 'dispatcher', 'creator']);
        $company = TenantContext::company();

        return Pdf::loadView('pdf.supplier-return', [
            'return' => $supplierReturn,
            'company' => $company,
            'brandName' => $company ? app(SettingsService::class)->get($company->id, 'brand.display_name') : config('app.name'),
        ])->setPaper('letter')->stream("devolucion-{$supplierReturn->number}.pdf");
    }

    /**
     * @return array<string, mixed>
     */
    protected function formProps(int $companyId, ?PurchaseOrder $order, ?SupplierReturn $editing): array
    {
        $storeId = $order?->store_id ?? $editing?->store_id ?? StoreContext::requireStoreId();
        $store = Store::query()->find($storeId);
        $received = $order ? $this->returns->receivingWarehouses($order) : [];

        $warehouses = $this->warehouses->forStore($storeId)
            ->map(fn (Warehouse $warehouse) => [
                'value' => $warehouse->id,
                'label' => $warehouse->name,
                'received_units' => $received[$warehouse->id] ?? null,
            ])
            ->values();

        $defaultWarehouse = $editing?->warehouse_id
            ?? ($order ? $this->returns->defaultWarehouseFor($order)->id : $this->warehouses->receivingDefaultFor($storeId)->id);

        return [
            'mode' => $order ? 'order' : 'loose',
            'supplierReturn' => $editing ? [
                'id' => $editing->id,
                'number' => $editing->number,
                'supplier_id' => $editing->supplier_id,
                'warehouse_id' => $editing->warehouse_id,
                'note' => $editing->note,
                'shipping_reference' => $editing->shipping_reference,
            ] : null,
            'order' => $order ? [
                'id' => $order->id,
                'code' => $order->code,
                'status_label' => $order->statusLabel(),
                'supplier' => ['id' => $order->supplier_id, 'name' => $order->supplier?->name],
            ] : null,
            'store' => ['id' => $storeId, 'name' => $store?->name],
            'suppliers' => Supplier::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Supplier $supplier) => ['value' => $supplier->id, 'label' => $supplier->name])->values(),
            'warehouses' => $warehouses,
            'warehouseId' => $defaultWarehouse,
            'lines' => $order
                ? $this->catalog->orderLines($order, $editing)
                : ($editing ? $this->catalog->looseLines($editing) : []),
            'reasons' => SupplierReturnItem::reasonOptions(),
            // Para pasar de suelta a «con orden» sin volver al listado.
            'returnableOrders' => $order || $editing ? [] : $this->returnableOrders($companyId, $storeId),
        ];
    }

    /**
     * Las ordenes recibidas de la sede a las que les queda algo por devolver.
     *
     * @return list<array<string, mixed>>
     */
    protected function returnableOrders(int $companyId, int $storeId): array
    {
        return PurchaseOrder::query()
            ->where('company_id', $companyId)
            ->where('store_id', $storeId)
            ->whereIn('status', [PurchaseOrder::STATUS_PARTIAL, PurchaseOrder::STATUS_RECEIVED])
            ->whereHas('items', fn ($query) => $query->whereColumn('received_quantity', '>', 'returned_quantity'))
            ->with('supplier:id,name')
            ->orderByDesc('id')
            ->limit(60)
            ->get()
            ->map(fn (PurchaseOrder $order) => [
                'id' => $order->id,
                'code' => $order->code,
                'supplier_id' => $order->supplier_id,
                'supplier' => $order->supplier?->name,
                'status_label' => $order->statusLabel(),
                'received_at' => ($order->received_at ?? $order->updated_at)?->toIso8601String(),
                'total' => $order->total,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(SupplierReturn $return): array
    {
        return [
            'id' => $return->id,
            'number' => $return->number,
            'status' => $return->status,
            'status_label' => $return->statusLabel(),
            'supplier_name' => $return->supplier?->name,
            'order_code' => $return->purchaseOrder?->code,
            'order_id' => $return->purchase_order_id,
            'store' => $return->store?->name,
            'warehouse' => $return->warehouse?->name,
            'units' => round((float) $return->items->sum('quantity'), 3),
            'net_units' => round((float) $return->items->sum(fn (SupplierReturnItem $item) => $item->netQuantity()), 3),
            'total' => $return->total,
            'pending_total' => $return->pendingTotal(),
            'created_at' => $return->created_at?->toIso8601String(),
            'dispatched_at' => $return->dispatched_at?->toIso8601String(),
            'closed_at' => $return->closed_at?->toIso8601String(),
            'cancelled_at' => $return->cancelled_at?->toIso8601String(),
        ];
    }

    /**
     * Lo que le paso a la devolucion, en orden.
     *
     * @return list<array{at: string, label: string, detail: ?string, user: ?string, tone: string}>
     */
    protected function timeline(SupplierReturn $return): array
    {
        $events = collect([[
            'at' => $return->created_at, 'label' => 'Creada en borrador', 'detail' => null,
            'user' => $return->creator?->full_name, 'tone' => 'neutral',
        ]]);

        if ($return->edited_at) {
            $events->push(['at' => $return->edited_at, 'label' => 'Borrador corregido', 'detail' => null, 'user' => $return->editor?->full_name, 'tone' => 'neutral']);
        }

        if ($return->dispatched_at) {
            $events->push([
                'at' => $return->dispatched_at, 'label' => 'Despachada',
                'detail' => 'Salió de '.$return->warehouse?->name.($return->shipping_reference ? " · guía {$return->shipping_reference}" : ''),
                'user' => $return->dispatcher?->full_name, 'tone' => 'warn',
            ]);
        }

        // Los rechazos no tienen tabla propia: son las entradas del kardex con esta referencia.
        StockMovement::query()
            ->where('reference_type', $return->getMorphClass())
            ->where('reference_id', $return->id)
            ->where('type', StockMovement::TYPE_SUPPLIER_RETURN)
            ->where('quantity', '>', 0)
            ->with(['user:id,name,last_name', 'product:id,name'])
            ->orderBy('occurred_at')
            ->get()
            ->groupBy(fn (StockMovement $movement) => $movement->occurred_at->format('Y-m-d H:i:s'))
            ->each(fn ($group) => $events->push([
                'at' => $group->first()->occurred_at,
                'label' => 'Rechazo del proveedor',
                'detail' => $group->map(fn (StockMovement $movement) => $movement->product?->name.' × '.rtrim(rtrim(number_format((float) $movement->quantity, 3, ',', '.'), '0'), ','))->implode(', ')
                    .' volvió a '.$return->warehouse?->name,
                'user' => $group->first()->user?->full_name,
                'tone' => 'danger',
            ]));

        foreach ($return->settlements as $settlement) {
            $events->push([
                'at' => $settlement->created_at,
                'label' => $settlement->typeLabel().' registrada',
                'detail' => trim(($settlement->document_number ? "{$settlement->document_number} · " : '').'$ '.number_format($settlement->amount, 0, ',', '.')),
                'user' => $settlement->user?->full_name,
                'tone' => 'accent',
            ]);
        }

        if ($return->closed_at) {
            $events->push(['at' => $return->closed_at, 'label' => 'Cerrada', 'detail' => 'Lo respondido y lo rechazado cubren el total.', 'user' => null, 'tone' => 'accent']);
        }

        if ($return->cancelled_at) {
            $events->push(['at' => $return->cancelled_at, 'label' => 'Anulada', 'detail' => $return->cancel_reason, 'user' => $return->canceller?->full_name, 'tone' => 'danger']);
        }

        return $events
            ->filter(fn (array $event) => $event['at'] !== null)
            ->sortBy(fn (array $event) => $event['at']->getTimestamp())
            ->map(fn (array $event) => [...$event, 'at' => $event['at']->toIso8601String()])
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    protected function filters(Request $request): array
    {
        return [
            'status' => $request->string('status')->toString(),
            'supplier' => $request->string('supplier')->toString(),
            'store' => $request->string('store')->toString(),
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
            'kind' => $request->string('kind')->toString(),
            'reason' => $request->string('reason')->toString(),
        ];
    }

    /**
     * @param  array<string, string>  $filters
     */
    protected function filtered(int $companyId, array $filters): Builder
    {
        return SupplierReturn::query()
            ->where('company_id', $companyId)
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->when($filters['supplier'], fn ($query, $id) => $query->where('supplier_id', $id))
            ->when($filters['store'], fn ($query, $id) => $query->where('store_id', $id))
            ->when($filters['from'], fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['to'], fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->when($filters['kind'] === 'order', fn ($query) => $query->whereNotNull('purchase_order_id'))
            ->when($filters['kind'] === 'loose', fn ($query) => $query->whereNull('purchase_order_id'))
            ->when($filters['reason'], fn ($query, $reason) => $query->whereHas('items', fn ($items) => $items->where('reason', $reason)));
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string}>
     */
    protected function options(array $labels): array
    {
        return collect($labels)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all();
    }

    protected function authorizeCompany(SupplierReturn $return): void
    {
        abort_if(
            ! auth('web')->user()->isSuperAdmin() && $return->company_id !== TenantContext::activeCompanyId(),
            403,
            'Esa devolución no pertenece a tu empresa.'
        );
    }
}

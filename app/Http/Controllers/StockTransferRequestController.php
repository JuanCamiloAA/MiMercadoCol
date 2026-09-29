<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesTransferWarehouses;
use App\Http\Requests\FulfillTransferRequestRequest;
use App\Http\Requests\StoreTransferRequestRequest;
use App\Models\ProductStock;
use App\Models\StockTransfer;
use App\Models\StockTransferRequest;
use App\Models\StockTransferRequestItem;
use App\Models\Warehouse;
use App\Services\InventoryCatalogService;
use App\Services\StockTransferRequestService;
use App\Services\WarehouseService;
use App\Support\StoreContext;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Solicitudes de traslado.
 *
 * Cada lado actua sobre lo suyo: pide quien llega a la sede de la bodega que necesita, y
 * atiende —genera el traslado, rechaza o cierra— quien llega a la sede de la bodega que surte.
 */
class StockTransferRequestController extends Controller
{
    use HandlesTransferWarehouses;

    public function __construct(
        protected StockTransferRequestService $requests,
        protected WarehouseService $warehouses,
        protected InventoryCatalogService $catalog,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();
        $storeIds = $this->reachableStoreIds();

        $filters = [
            'side' => $request->string('side')->toString(),
            'status' => $request->string('status')->toString(),
            'warehouse' => $request->string('warehouse')->toString(),
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
        ];

        $requests = StockTransferRequest::query()
            ->where('company_id', $companyId)
            ->with(['origin:id,name,store_id', 'destination:id,name,store_id', 'items', 'creator:id,name,last_name'])
            // «Me piden»: la bodega que surte es de una sede mia. «Pido»: la que pide lo es.
            ->when($filters['side'] === 'incoming', fn ($query) => $this->whereSideIn($query, 'from_warehouse_id', $storeIds))
            ->when($filters['side'] === 'outgoing', fn ($query) => $this->whereSideIn($query, 'to_warehouse_id', $storeIds))
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->when($filters['warehouse'], fn ($query, $id) => $query->where(
                fn ($inner) => $inner->where('from_warehouse_id', $id)->orWhere('to_warehouse_id', $id)
            ))
            ->when($filters['from'], fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['to'], fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (StockTransferRequest $transferRequest) => [
                'id' => $transferRequest->id,
                'number' => $transferRequest->number,
                'status' => $transferRequest->status,
                'status_label' => $transferRequest->statusLabel(),
                'origin' => $transferRequest->origin?->name,
                'destination' => $transferRequest->destination?->name,
                'is_internal' => (int) $transferRequest->origin?->store_id === (int) $transferRequest->destination?->store_id,
                'items' => $transferRequest->items->count(),
                'units' => round((float) $transferRequest->items->sum('quantity'), 3),
                'sent' => round((float) $transferRequest->items->sum('sent_quantity'), 3),
                'created_by' => $transferRequest->creator?->full_name,
                'created_at' => $transferRequest->created_at?->toIso8601String(),
            ]);

        $open = fn () => StockTransferRequest::query()
            ->where('company_id', $companyId)
            ->whereIn('status', StockTransferRequest::OPEN_STATUSES);

        return Inertia::render('TransferRequests/Index', [
            'requests' => $requests,
            'filters' => $filters,
            'warehouses' => $this->warehouseOptions($this->warehouses->forCompany($companyId)),
            'statuses' => collect(StockTransferRequest::STATUS_LABELS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'counts' => [
                'incoming' => $this->whereSideIn($open(), 'from_warehouse_id', $storeIds)->count(),
                'outgoing' => $this->whereSideIn($open(), 'to_warehouse_id', $storeIds)->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        $companyId = TenantContext::requireCompanyId();
        $storeId = StoreContext::requireStoreId();

        $mine = $this->warehouses->forStore($storeId)->load('store:id,name');
        $all = $this->warehouses->forCompany($companyId);

        // Lo normal es que el piso de venta le pida al deposito de su misma sede.
        $toId = $this->warehouses->posSourceFor($storeId)->id;
        $fromId = $mine->firstWhere(fn (Warehouse $warehouse) => $warehouse->id !== $toId)?->id
            ?? $all->firstWhere(fn (Warehouse $warehouse) => $warehouse->id !== $toId)?->id;

        // El catalogo muestra lo que tiene la bodega que pide: es lo que le falta lo que pide.
        $catalog = $this->catalog->catalog($companyId, $storeId, warehouseId: $toId);

        return Inertia::render('Transfers/Form', [
            'mode' => 'request',
            'warehouses' => $this->warehouseOptions($all),
            'destinations' => $this->warehouseOptions($mine),
            'fromId' => $fromId,
            'toId' => $toId,
            'preload' => null,
            'products' => $catalog['products'],
            'counts' => ['total' => $catalog['total'], 'low_stock' => $catalog['low_stock']],
        ]);
    }

    /** El buscador del formulario, con la existencia de una bodega de la sede activa. */
    public function products(Request $request): JsonResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $storeId = StoreContext::requireStoreId();

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:60'],
            'low_stock' => ['nullable', 'boolean'],
            'barcode' => ['nullable', 'string', 'max:60'],
            'warehouse' => ['nullable', 'integer'],
        ]);

        // Solo cuenta si es de esta sede: el parametro viene del navegador.
        $warehouseId = $this->warehouses->forStore($storeId)
            ->firstWhere('id', (int) ($validated['warehouse'] ?? 0))?->id;

        return response()->json($this->catalog->lookup($companyId, $storeId, $validated, $warehouseId));
    }

    public function store(StoreTransferRequestRequest $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();

        $from = $this->warehouseOf($companyId, (int) $request->validated('from_warehouse_id'));
        $to = $this->warehouseOf($companyId, (int) $request->validated('to_warehouse_id'));

        $this->guardAccess($to, 'pedir para');

        $transferRequest = $this->requests->create(
            $companyId,
            $from,
            $to,
            $request->items(),
            $request->user(),
            $request->validated('note'),
        );

        return redirect()
            ->route('transfer_requests.show', $transferRequest)
            ->with('success', "La solicitud {$transferRequest->number} quedo enviada a {$from->name}.");
    }

    public function show(StockTransferRequest $transferRequest): Response
    {
        $this->authorizeCompany($transferRequest);

        $transferRequest->load([
            'items.product', 'origin.store', 'destination.store', 'creator', 'responder',
            'transfers' => fn ($query) => $query->with('items')->orderBy('id'),
        ]);

        $productIds = $transferRequest->items->pluck('product_id')->all();
        $atOrigin = $this->stockAt((int) $transferRequest->from_warehouse_id, $productIds);
        $atDestination = $this->stockAt((int) $transferRequest->to_warehouse_id, $productIds);

        $reachesOrigin = $this->reaches($transferRequest->origin);

        return Inertia::render('TransferRequests/Show', [
            'request' => [
                'id' => $transferRequest->id,
                'number' => $transferRequest->number,
                'status' => $transferRequest->status,
                'status_label' => $transferRequest->statusLabel(),
                'note' => $transferRequest->note,
                'response_note' => $transferRequest->response_note,
                'is_internal' => (int) $transferRequest->origin?->store_id === (int) $transferRequest->destination?->store_id,
                'origin' => $this->side($transferRequest->origin),
                'destination' => $this->side($transferRequest->destination),
                'created_by' => $transferRequest->creator?->full_name,
                'created_at' => $transferRequest->created_at?->toIso8601String(),
                'responded_by' => $transferRequest->responder?->full_name,
                'responded_at' => $transferRequest->responded_at?->toIso8601String(),
                'cancelled_at' => $transferRequest->cancelled_at?->toIso8601String(),
            ],
            'items' => $transferRequest->items
                ->map(fn (StockTransferRequestItem $item) => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'name' => $item->product?->name,
                    'sku' => $item->product?->sku,
                    'unit' => $item->product?->unit,
                    'quantity' => round((float) $item->quantity, 3),
                    'sent_quantity' => round((float) $item->sent_quantity, 3),
                    'pending' => $item->pendingQuantity(),
                    'origin_available' => $atOrigin[$item->product_id] ?? 0.0,
                    'destination_stock' => $atDestination[$item->product_id] ?? 0.0,
                ])
                ->values(),
            'transfers' => $transferRequest->transfers
                ->map(fn (StockTransfer $transfer) => [
                    'id' => $transfer->id,
                    'number' => $transfer->number,
                    'status' => $transfer->status,
                    'status_label' => $transfer->statusLabel(),
                    'units' => round((float) $transfer->items->sum('quantity'), 3),
                    'value' => $transfer->transitValue(),
                    'created_at' => $transfer->created_at?->toIso8601String(),
                ])
                ->values(),
            'can' => [
                'fulfill' => $transferRequest->isOpen() && $reachesOrigin,
                'reject' => $transferRequest->isPending() && $reachesOrigin,
                'close' => $transferRequest->isPartial() && $reachesOrigin,
                'cancel' => $transferRequest->isPending() && $this->reaches($transferRequest->destination),
            ],
        ]);
    }

    public function fulfill(FulfillTransferRequestRequest $request, StockTransferRequest $transferRequest): RedirectResponse
    {
        $this->authorizeCompany($transferRequest);
        $transferRequest->load(['origin.store']);
        $this->guardAccess($transferRequest->origin, 'atender las solicitudes de');

        $dispatch = $request->boolean('dispatch');

        abort_if($dispatch && ! $request->user()->can('inventory.transfers.dispatch'), 403, 'No tienes permiso para despachar.');

        $transfer = $this->requests->fulfill($transferRequest, $request->quantities(), $request->user(), $dispatch);

        return redirect()
            ->route('transfers.show', $transfer)
            ->with('success', $dispatch
                ? "El traslado {$transfer->number} salio. Queda en camino hasta que lo reciban."
                : "Quedo el traslado {$transfer->number}, listo para despachar.");
    }

    public function reject(Request $request, StockTransferRequest $transferRequest): RedirectResponse
    {
        $this->authorizeCompany($transferRequest);
        $transferRequest->load(['origin.store']);
        $this->guardAccess($transferRequest->origin, 'atender las solicitudes de');

        $validated = $request->validate(
            ['reason' => ['required', 'string', 'max:160']],
            ['reason.required' => 'Di por que se rechaza: quien la pidio necesita saberlo.'],
        );

        $this->requests->reject($transferRequest, $validated['reason'], $request->user());

        return back()->with('success', 'La solicitud quedo rechazada.');
    }

    public function close(Request $request, StockTransferRequest $transferRequest): RedirectResponse
    {
        $this->authorizeCompany($transferRequest);
        $transferRequest->load(['origin.store']);
        $this->guardAccess($transferRequest->origin, 'atender las solicitudes de');

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:160']]);

        $this->requests->close($transferRequest, $validated['reason'] ?? null, $request->user());

        return back()->with('success', 'La solicitud quedo cerrada. Lo que ya salio se sigue recibiendo.');
    }

    public function cancel(Request $request, StockTransferRequest $transferRequest): RedirectResponse
    {
        $this->authorizeCompany($transferRequest);
        $transferRequest->load(['destination.store']);
        $this->guardAccess($transferRequest->destination, 'anular las solicitudes de');

        $this->requests->cancel($transferRequest, $request->user());

        return back()->with('success', 'La solicitud quedo anulada.');
    }

    /**
     * Lo disponible por articulo en una bodega: existencia menos lo apartado para pedidos.
     *
     * @param  list<int>  $productIds
     * @return array<int, float>
     */
    protected function stockAt(int $warehouseId, array $productIds): array
    {
        return ProductStock::query()
            ->withoutGlobalScopes()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'quantity', 'reserved_quantity'])
            ->mapWithKeys(fn (ProductStock $row) => [
                (int) $row->product_id => round((float) $row->quantity - (float) $row->reserved_quantity, 3),
            ])
            ->all();
    }

    /** @return list<int>|null Las sedes del usuario; `null` si llega a todas. */
    protected function reachableStoreIds(): ?array
    {
        $user = auth('web')->user();

        if ($user->isSuperAdmin()) {
            return null;
        }

        return $user->stores()->pluck('stores.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  Builder<StockTransferRequest>  $query
     * @param  list<int>|null  $storeIds
     * @return Builder<StockTransferRequest>
     */
    protected function whereSideIn(Builder $query, string $column, ?array $storeIds): Builder
    {
        if ($storeIds === null) {
            return $query;
        }

        return $query->whereIn($column, Warehouse::query()->withoutGlobalScopes()->whereIn('store_id', $storeIds)->select('id'));
    }

    protected function authorizeCompany(StockTransferRequest $transferRequest): void
    {
        abort_if(
            ! auth('web')->user()->isSuperAdmin() && $transferRequest->company_id !== TenantContext::activeCompanyId(),
            403,
            'Esa solicitud no pertenece a tu empresa.'
        );
    }
}

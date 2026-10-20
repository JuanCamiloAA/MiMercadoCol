<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesTransferWarehouses;
use App\Http\Requests\StoreStockTransferRequest;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\StockTransferRequest;
use App\Services\InventoryCatalogService;
use App\Services\StockTransferService;
use App\Services\WarehouseService;
use App\Support\StoreContext;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Traslados de mercancia entre bodegas.
 *
 * Quien despacha tiene que tener acceso a la sede de origen y quien recibe a la de destino.
 * No es una formalidad: es lo unico que impide que alguien de otra sede mueva mercancia de un
 * estante que no puede ver.
 */
class StockTransferController extends Controller
{
    use HandlesTransferWarehouses;

    public function __construct(
        protected StockTransferService $transfers,
        protected WarehouseService $warehouses,
        protected InventoryCatalogService $catalog,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();

        $filters = [
            'status' => $request->string('status')->toString(),
            'warehouse' => $request->string('warehouse')->toString(),
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
        ];

        $transfers = StockTransfer::query()
            ->where('company_id', $companyId)
            ->with(['origin:id,name,store_id', 'destination:id,name,store_id', 'items', 'request:id,number'])
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->when($filters['warehouse'], fn ($query, $id) => $query->where(
                fn ($inner) => $inner->where('from_warehouse_id', $id)->orWhere('to_warehouse_id', $id)
            ))
            ->when($filters['from'], fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['to'], fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (StockTransfer $transfer) => [
                'id' => $transfer->id,
                'number' => $transfer->number,
                'request_number' => $transfer->request?->number,
                'status' => $transfer->status,
                'status_label' => $transfer->statusLabel(),
                'origin' => $transfer->origin?->name,
                'destination' => $transfer->destination?->name,
                'is_internal' => $transfer->isInternal(),
                'items' => $transfer->items->count(),
                'units' => round((float) $transfer->items->sum('quantity'), 3),
                'value' => $transfer->transitValue(),
                'created_at' => $transfer->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Transfers/Index', [
            'transfers' => $transfers,
            'filters' => $filters,
            'warehouses' => $this->warehouseOptions($this->warehouses->forCompany($companyId)),
            'statuses' => collect(StockTransfer::STATUS_LABELS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'inTransit' => [
                'count' => StockTransfer::query()->where('company_id', $companyId)->where('status', StockTransfer::STATUS_IN_TRANSIT)->count(),
            ],
            'openRequests' => StockTransferRequest::query()
                ->where('company_id', $companyId)
                ->whereIn('status', StockTransferRequest::OPEN_STATUSES)
                ->count(),
        ]);
    }

    public function create(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();
        $storeId = StoreContext::requireStoreId();

        $warehouses = $this->warehouseOptions($this->warehouses->forCompany($companyId));
        $mine = $this->warehouses->forStore($storeId);

        $fromId = $request->integer('from') ?: ($mine->first()?->id ?? null);
        $catalog = $this->catalog->catalog($companyId, $storeId, warehouseId: $fromId);

        return Inertia::render('Transfers/Form', [
            'warehouses' => $warehouses,
            'fromId' => $fromId,
            'toId' => $mine->skip(1)->first()?->id,
            // Un articulo precargado cuando se llega desde Existencias.
            'preload' => $request->integer('product') > 0
                ? $this->catalog->rowsFor($companyId, $storeId, [$request->integer('product')], $fromId)
                : [],
            'products' => $catalog['products'],
            'counts' => ['total' => $catalog['total'], 'low_stock' => $catalog['low_stock']],
        ]);
    }

    public function store(StoreStockTransferRequest $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();

        $from = $this->warehouseOf($companyId, (int) $request->validated('from_warehouse_id'));
        $to = $this->warehouseOf($companyId, (int) $request->validated('to_warehouse_id'));

        $this->guardAccess($from, 'despachar desde');

        $direct = $request->boolean('direct');

        if ($direct) {
            $this->authorizeAction($request, 'inventory.transfers.direct');
        } elseif ($request->boolean('dispatch')) {
            $this->authorizeAction($request, 'inventory.transfers.dispatch');
        }

        /*
         * Guardar y despachar es un solo acto.
         *
         * Si al despachar resulta que no hay suficiente, no puede quedar un traslado que nadie
         * pidio: el que lo armo vio un error y se fue creyendo que no paso nada. O sale entero,
         * o no queda rastro.
         */
        $transfer = DB::transaction(function () use ($request, $companyId, $from, $to, $direct) {
            $transfer = $this->transfers->create(
                $companyId,
                $from,
                $to,
                $request->items(),
                $request->user(),
                $request->validated('note'),
            );

            // Despachar deja la mercancia en camino hasta que la bodega de destino la reciba,
            // sea de esta sede o de otra. Solo el traslado directo cierra los dos actos de una.
            if ($direct) {
                $this->transfers->dispatchAndReceive($transfer, $request->user());
            } elseif ($request->boolean('dispatch')) {
                $this->transfers->dispatch($transfer, $request->user());
            }

            return $transfer;
        });

        return redirect()
            ->route('transfers.show', $transfer)
            ->with('success', "El traslado {$transfer->number} quedo registrado.");
    }

    /**
     * Arma los traslados de reposicion del deposito al piso de venta.
     *
     * Quedan por despachar a proposito: la maquina propone que subir, pero quien decide cuanto
     * cabe en el estante es el que lo esta mirando.
     */
    public function replenish(Request $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $storeId = StoreContext::requireStoreId();

        $target = $this->warehouses->posSourceFor($storeId);
        $plan = $this->transfers->replenishmentFor($companyId, $storeId, $target);

        if ($plan === []) {
            return back()->with('success', 'No hay nada que reponer: el piso de venta esta surtido.');
        }

        $created = [];

        foreach ($plan as $group) {
            $created[] = $this->transfers->create(
                $companyId,
                $group['warehouse'],
                $target,
                array_map(
                    fn (array $line) => ['product_id' => $line['product_id'], 'quantity' => $line['quantity']],
                    $group['lines'],
                ),
                $request->user(),
                "Reposicion de {$target->name}",
            );
        }

        if (count($created) === 1) {
            return redirect()
                ->route('transfers.show', $created[0])
                ->with('success', 'Ahi esta lo que hay que subir al piso de venta. Revisalo antes de despacharlo.');
        }

        return redirect()
            ->route('transfers.index')
            ->with('success', 'Quedaron '.count($created).' traslados de reposicion por despachar, uno por bodega de origen.');
    }

    public function show(StockTransfer $transfer): Response
    {
        $this->authorizeCompany($transfer);

        $transfer->load(['items.product', 'origin.store', 'destination.store', 'creator', 'dispatcher', 'receiver', 'request:id,number']);

        return Inertia::render('Transfers/Show', [
            'transfer' => [
                'id' => $transfer->id,
                'number' => $transfer->number,
                'status' => $transfer->status,
                'status_label' => $transfer->statusLabel(),
                'note' => $transfer->note,
                'is_internal' => $transfer->isInternal(),
                'request' => $transfer->request?->only(['id', 'number']),
                'origin' => $this->side($transfer->origin),
                'destination' => $this->side($transfer->destination),
                'created_by' => $transfer->creator?->full_name,
                'created_at' => $transfer->created_at?->toIso8601String(),
                'dispatched_by' => $transfer->dispatcher?->full_name,
                'dispatched_at' => $transfer->dispatched_at?->toIso8601String(),
                'received_by' => $transfer->receiver?->full_name,
                'received_at' => $transfer->received_at?->toIso8601String(),
                'value' => $transfer->transitValue(),
            ],
            'items' => $transfer->items
                ->map(fn (StockTransferItem $item) => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'name' => $item->product?->name,
                    'sku' => $item->product?->sku,
                    'unit' => $item->product?->unit,
                    'quantity' => round((float) $item->quantity, 3),
                    'received_quantity' => round((float) $item->received_quantity, 3),
                    'missing' => $item->missingQuantity(),
                    'unit_cost' => $item->unit_cost,
                ])
                ->values(),
            'can' => [
                'dispatch' => $transfer->isDraft() && $this->reaches($transfer->origin),
                'direct' => $transfer->isDraft() && $transfer->isInternal() && $this->reaches($transfer->origin),
                'receive' => $transfer->isInTransit() && $this->reaches($transfer->destination),
                'cancel' => $transfer->isDraft(),
            ],
        ]);
    }

    public function dispatch(Request $request, StockTransfer $transfer): RedirectResponse
    {
        $this->authorizeCompany($transfer);
        $transfer->load(['origin', 'destination']);
        $this->guardAccess($transfer->origin, 'despachar desde');

        $this->transfers->dispatch($transfer, $request->user());

        return back()->with('success', "La mercancia salio. Queda en camino hasta que la reciban en {$transfer->destination?->name}.");
    }

    /** Despacha y recibe en el mismo acto. Solo dentro de una sede, y con su propio permiso. */
    public function direct(Request $request, StockTransfer $transfer): RedirectResponse
    {
        $this->authorizeCompany($transfer);
        $transfer->load(['origin', 'destination']);
        $this->guardAccess($transfer->origin, 'despachar desde');

        $this->transfers->dispatchAndReceive($transfer, $request->user());

        return back()->with('success', 'La mercancia quedo trasladada.');
    }

    public function receive(Request $request, StockTransfer $transfer): RedirectResponse
    {
        $this->authorizeCompany($transfer);
        $transfer->load(['origin', 'destination', 'items']);
        $this->guardAccess($transfer->destination, 'recibir en');

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.received_quantity' => ['required', 'numeric', 'min:0'],
        ], ['items.required' => 'Indica que llego.']);

        $ids = $transfer->items->pluck('id')->all();
        $received = [];

        foreach ($validated['items'] as $entry) {
            if (in_array((int) $entry['id'], $ids, true)) {
                $received[(int) $entry['id']] = (float) $entry['received_quantity'];
            }
        }

        $this->transfers->receive($transfer, $received, $request->user());

        return back()->with('success', 'El traslado quedo recibido.');
    }

    public function cancel(Request $request, StockTransfer $transfer): RedirectResponse
    {
        $this->authorizeCompany($transfer);

        $this->transfers->cancel($transfer, $request->user());

        return back()->with('success', 'El traslado quedo anulado.');
    }

    protected function authorizeAction(Request $request, string $permission): void
    {
        abort_if(! $request->user()->can($permission), 403, 'No tienes ese permiso.');
    }

    protected function authorizeCompany(StockTransfer $transfer): void
    {
        abort_if(
            ! auth('web')->user()->isSuperAdmin() && $transfer->company_id !== TenantContext::activeCompanyId(),
            403,
            'Ese traslado no pertenece a tu empresa.'
        );
    }
}

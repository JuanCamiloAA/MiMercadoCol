<?php

namespace App\Http\Controllers;

use App\Models\PaymentTerm;
use App\Models\ProductFamily;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\Warehouse;
use App\Services\NumberSequenceService;
use App\Services\PaymentTermService;
use App\Services\PurchaseCatalogService;
use App\Services\PurchaseOrderSplitter;
use App\Services\PurchaseSuggestionService;
use App\Services\StockService;
use App\Services\SupplierReturnService;
use App\Services\WarehouseService;
use App\Support\StoreContext;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function __construct(
        protected StockService $stock,
        protected WarehouseService $warehouses,
        protected NumberSequenceService $numbers,
        protected PurchaseCatalogService $catalog,
        protected PurchaseSuggestionService $suggestionService,
        protected PurchaseOrderSplitter $splitter,
        protected PaymentTermService $terms,
        protected SupplierReturnService $supplierReturns,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();

        $filters = [
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString(),
            'supplier' => $request->string('supplier')->toString(),
        ];

        $orders = PurchaseOrder::query()
            ->where('company_id', $companyId)
            ->with(['supplier:id,name', 'store:id,name', 'user:id,name,last_name', 'paymentTerm:id,name'])
            ->withCount('items')
            ->when($filters['search'], fn ($query, $search) => $query->where('code', 'like', "%{$search}%"))
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->when($filters['supplier'], fn ($query, $supplier) => $query->where('supplier_id', $supplier))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (PurchaseOrder $order) => [
                'id' => $order->id,
                'code' => $order->code,
                'status' => $order->status,
                'status_label' => $order->statusLabel(),
                'supplier' => $order->supplier?->name,
                'store' => $order->store?->name,
                'user' => $order->user?->full_name,
                'expected_at' => $order->expected_at?->toDateString(),
                'received_at' => $order->received_at?->toIso8601String(),
                'payment_term' => $order->paymentTerm?->name,
                'due_at' => $order->due_at?->toIso8601String(),
                'is_overdue' => $order->isOverdue(),
                'items_count' => $order->items_count,
                'total' => $order->total,
                'created_at' => $order->created_at->toIso8601String(),
            ]);

        return Inertia::render('Purchases/Index', [
            'orders' => $orders,
            'filters' => $filters,
            'suppliers' => $this->supplierOptions($companyId),
            'metrics' => [
                'draft' => (int) PurchaseOrder::query()->where('company_id', $companyId)->where('status', PurchaseOrder::STATUS_DRAFT)->count(),
                'sent' => (int) PurchaseOrder::query()->where('company_id', $companyId)->where('status', PurchaseOrder::STATUS_SENT)->count(),
                'received_month' => (int) PurchaseOrder::query()
                    ->where('company_id', $companyId)
                    ->where('status', PurchaseOrder::STATUS_RECEIVED)
                    ->where('received_at', '>=', now()->startOfMonth())
                    ->sum('total'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();
        $storeId = StoreContext::requireStoreId();

        // Precarga (desde «Tienda en linea»): la bodega donde se va a recibir y los articulos.
        // Una bodega de otra sede del usuario es pasarse a esa sede, como en el selector.
        $warehouse = $this->warehouses->forCompany($companyId)->firstWhere('id', $request->integer('warehouse'));

        if ($warehouse && (int) $warehouse->store_id !== $storeId && StoreContext::setActiveStore((int) $warehouse->store_id)) {
            $storeId = (int) $warehouse->store_id;
        }

        $warehouse = $warehouse && (int) $warehouse->store_id === $storeId ? $warehouse : null;
        $preloadIds = collect((array) $request->query('products', []))
            ->filter(fn ($id) => ctype_digit((string) $id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->take(100)
            ->values()
            ->all();

        $suppliers = $this->supplierOptions($companyId);
        $preload = $this->catalog->rowsFor($companyId, $storeId, $preloadIds, null);
        // Con precarga, el proveedor principal del primer articulo: los demas se resaltan, no se
        // filtran, y la orden se puede separar despues.
        $preloadSupplier = collect($preload[0]['suppliers'] ?? [])->firstWhere('is_primary', true)['id'] ?? null;
        // Sin proveedores no hay a quien pedirle: la pantalla lo dice en vez de dejar armar
        // una orden que despues no se puede guardar.
        $supplierId = $request->integer('supplier')
            ?: (collect($suppliers)->contains('value', $preloadSupplier) ? $preloadSupplier : null)
            ?: ($suppliers[0]['value'] ?? null);

        $catalog = $this->catalog->catalog($companyId, $storeId, $supplierId);

        return Inertia::render('Purchases/Create', [
            'suppliers' => $suppliers,
            'supplierId' => $supplierId,
            'preload' => $supplierId !== null && $preloadIds !== [] ? $this->catalog->rowsFor($companyId, $storeId, $preloadIds, $supplierId) : [],
            'warehouse' => $warehouse ? ['id' => $warehouse->id, 'name' => $warehouse->name] : null,
            'products' => $catalog['products'],
            'counts' => [
                'total' => $catalog['total'],
                'low_stock' => $catalog['low_stock'],
            ],
            'store' => StoreContext::activeStore()?->only(['id', 'name']),
            // El plazo sale del proveedor y se puede cambiar antes de guardar.
            'paymentTerms' => $this->terms->options($companyId),
            'defaultPaymentTermId' => $this->terms->defaultFor($companyId, PaymentTermService::TYPE_SUPPLIERS)?->id,
        ]);
    }

    /**
     * El catalogo de la sede, para el buscador y el escaner.
     *
     * Devuelve **todo**, no solo lo del proveedor: el proveedor resalta y ordena, no filtra.
     */
    public function products(Request $request): JsonResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $storeId = StoreContext::requireStoreId();

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:60'],
            'supplier' => ['nullable', 'integer'],
            'low_stock' => ['nullable', 'boolean'],
            'barcode' => ['nullable', 'string', 'max:60'],
        ]);

        $supplierId = ($validated['supplier'] ?? null) ?: null;

        // Un escaneo es una pregunta distinta: o esta ese codigo exacto, o no esta.
        if (! empty($validated['barcode'])) {
            $product = $this->catalog->byBarcode($companyId, $storeId, $validated['barcode'], $supplierId);

            return response()->json([
                'products' => $product ? [$product] : [],
                'found' => $product !== null,
            ]);
        }

        $catalog = $this->catalog->catalog(
            $companyId,
            $storeId,
            $supplierId,
            ($validated['search'] ?? null) ?: null,
            (bool) ($validated['low_stock'] ?? false),
        );

        return response()->json([
            'products' => $catalog['products'],
            'total' => $catalog['total'],
            'low_stock' => $catalog['low_stock'],
            'found' => true,
        ]);
    }

    /** La matriz de compra de un articulo con subarticulos (valor 1 en filas, valor 2 en columnas). */
    public function familyMatrix(Request $request, ProductFamily $family): JsonResponse
    {
        abort_if($family->company_id !== TenantContext::requireCompanyId(), 404);

        return response()->json($this->catalog->familyMatrix($family, StoreContext::requireStoreId(), $request->integer('supplier') ?: null));
    }

    /** Los que estan bajo minimo, para el boton que los agrega todos de una. */
    public function suggestions(Request $request): JsonResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $storeId = StoreContext::requireStoreId();

        return response()->json([
            'products' => $this->catalog->lowStock($companyId, $storeId, $request->integer('supplier') ?: null),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $storeId = StoreContext::requireStoreId();

        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'expected_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:160'],
            'payment_term_id' => ['nullable', 'integer'],
            'status' => ['required', Rule::in([PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_SENT])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('products', 'id')->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            // Del cliente solo se aceptan estos dos: lo demas lo recalcula el servidor.
            'items.*.quantity' => ['required', 'numeric', 'gte:1'],
            'items.*.unit_cost' => ['required', 'integer', 'min:0'],
            'items.*.origin' => ['nullable', Rule::in(PurchaseOrderItem::ORIGINS)],
            // Separar al crear: se arma todo de una pasada y se parte al guardar.
            'split' => ['nullable', 'boolean'],
            // Donde se espera recibirla, si no es la de recibo por defecto (la que respalda la
            // tienda, cuando se compra lo que alli se agoto). Solo una bodega activa de la sede.
            'warehouse_id' => [
                'nullable', 'integer',
                Rule::in($this->warehouses->forStore($storeId)->pluck('id')->all()),
            ],
        ], [
            'warehouse_id.in' => 'Esa bodega no es de esta sede o esta inactiva.',
            'supplier_id.required' => 'Elige el proveedor.',
            'items.required' => 'La orden debe tener al menos un articulo.',
            'items.*.product_id.distinct' => 'Un articulo no puede ir dos veces en la misma orden.',
            'items.*.product_id.exists' => 'Uno de los articulos no existe, esta inactivo o no es de tu empresa.',
            'items.*.quantity.gte' => 'Las cantidades deben ser de al menos uno.',
        ]);

        // Sin plazo elegido, el del proveedor; sin el, el de proveedores por defecto.
        $term = array_key_exists('payment_term_id', $validated) && $validated['payment_term_id'] !== null
            ? $this->terms->resolve($companyId, $validated['payment_term_id'])
            : $this->termFor($companyId, (int) $validated['supplier_id']);

        $order = DB::transaction(function () use ($validated, $companyId, $storeId, $request, $term) {
            $supplierId = (int) $validated['supplier_id'];
            $ids = array_map(fn ($item) => (int) $item['product_id'], $validated['items']);

            $products = $this->catalog->productsFor($companyId, $ids);
            $stock = $this->suggestionService->stockByProduct($companyId, $storeId, $ids);

            $origins = [];

            $order = PurchaseOrder::create([
                'company_id' => $companyId,
                'store_id' => $storeId,
                // Donde se espera recibirla. Se puede cambiar al descargar el camion.
                'warehouse_id' => ! empty($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : $this->warehouses->receivingDefaultFor($storeId)->id,
                'supplier_id' => $supplierId,
                'payment_term_id' => $term?->id,
                'code' => $this->numbers->nextPurchaseOrderCode($companyId),
                'status' => $validated['status'],
                'expected_at' => $validated['expected_at'] ?? null,
                'subtotal' => 0,
                'tax_total' => 0,
                'total' => 0,
                'suggested_total' => 0,
                'source' => PurchaseOrder::SOURCE_MANUAL,
                'user_id' => $request->user()->id,
                'note' => $validated['note'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                $product = $products->get((int) $item['product_id']);
                $available = (float) ($stock[$product->id] ?? 0);

                // El proveedor del renglon lo decide el servidor: el de la orden si lo surte,
                // y si no, el principal del articulo. El cliente no lo manda.
                $links = $product->suppliers;
                $ownLink = $links->firstWhere('supplier_id', $supplierId);
                $rowSupplier = $ownLink
                    ? $supplierId
                    : ($links->firstWhere('is_primary', true)?->supplier_id ?? $links->first()?->supplier_id);

                $origin = $item['origin'] ?? PurchaseOrderItem::ORIGIN_MANUAL;
                $origins[] = $origin;

                PurchaseOrderItem::create([
                    'purchase_order_id' => $order->id,
                    'product_id' => $product->id,
                    'supplier_id' => $rowSupplier,
                    'is_off_supplier' => $rowSupplier !== null && (int) $rowSupplier !== $supplierId,
                    'quantity' => $item['quantity'],
                    // Foto del momento: dentro de tres meses explica por que se pidio esto.
                    'suggested_quantity' => $this->suggestionService->suggestFor($product, $available),
                    'stock_at_order' => $available,
                    'min_at_order' => (float) $product->min_stock,
                    'origin' => $origin,
                    'received_quantity' => 0,
                    'unit_cost' => $item['unit_cost'],
                ]);
            }

            $order->update(['source' => $this->sourceFor($origins)]);
            $this->splitter->recalculate($order->refresh());

            return $order;
        });

        // Se separa despues de crear, no antes: primero queda constancia de lo que se pidio.
        if (! empty($validated['split']) && $order->items()->where('is_off_supplier', true)->exists()) {
            $created = $this->splitter->split($order);

            return redirect()->route('purchases.show', $order)->with(
                'success',
                "La orden {$order->code} quedo creada y se separo en ".(count($created) + 1).' ordenes, una por proveedor.'
            );
        }

        return redirect()->route('purchases.show', $order)->with('success', "La orden {$order->code} quedo creada.");
    }

    /**
     * Separa la orden en una por proveedor.
     *
     * Lo que hace viable mezclar: el comprador recorre la tienda una vez, arma todo lo que
     * falta sin pensar en quien se lo surte, y al final la orden se parte sola.
     */
    public function split(PurchaseOrder $order): RedirectResponse
    {
        $this->authorizeCompany($order);

        $created = $this->splitter->split($order);

        return back()->with(
            'success',
            count($created) === 1
                ? 'Se separo en dos ordenes: '.$order->code.' y '.$created[0]->code.'.'
                : 'Se separo en '.(count($created) + 1).' ordenes, una por proveedor.'
        );
    }

    /**
     * Guarda a como quedo el articulo con ese proveedor.
     *
     * El proveedor del renglon manda sobre el de la orden: en una orden mezclada cada renglon
     * puede ser de otro, y anotarle el costo al proveedor equivocado emporcaria la proxima
     * sugerencia de precio.
     *
     * Si es la primera vez que ese proveedor lo surte, la fila se crea: comprarle algo nuevo a
     * alguien es justamente como empieza una relacion con un proveedor.
     */
    protected function rememberCost(PurchaseOrderItem $item, PurchaseOrder $order): void
    {
        $supplierId = $item->supplier_id ?? $order->supplier_id;

        if (! $supplierId) {
            return;
        }

        ProductSupplier::query()->withoutGlobalScopes()->updateOrCreate(
            ['product_id' => $item->product_id, 'supplier_id' => $supplierId],
            [
                'company_id' => $order->company_id,
                'last_cost' => (int) $item->unit_cost,
                'last_purchased_at' => now(),
            ],
        );
    }

    /**
     * De donde salio la orden, mirando de donde salio cada renglon.
     *
     * @param  list<string>  $origins
     */
    protected function sourceFor(array $origins): string
    {
        $fromSuggestion = in_array(PurchaseOrderItem::ORIGIN_SUGGESTION, $origins, true);
        $fromOther = collect($origins)->contains(fn (string $origin) => $origin !== PurchaseOrderItem::ORIGIN_SUGGESTION);

        return match (true) {
            $fromSuggestion && $fromOther => PurchaseOrder::SOURCE_MIXED,
            $fromSuggestion => PurchaseOrder::SOURCE_SUGGESTED,
            default => PurchaseOrder::SOURCE_MANUAL,
        };
    }

    public function show(PurchaseOrder $order): Response
    {
        $this->authorizeCompany($order);

        $order->load([
            'supplier', 'store:id,name', 'user:id,name,last_name', 'paymentTerm:id,name,days',
            'items.product:id,name,sku,unit,tax_rate,product_family_id,variant_label,variant_sort', 'items.product.family:id,name', 'items.supplier:id,name',
            'parent:id,code', 'children:id,parent_order_id,code,supplier_id,total', 'children.supplier:id,name',
            'returns.items:id,supplier_return_id,quantity,rejected_quantity', 'supplierReturn:id,number',
        ]);

        $receivedValue = (int) $order->items->sum(fn (PurchaseOrderItem $item) => (int) round($item->received_quantity * $item->unit_cost));
        $returnedValue = (int) $order->items->sum(fn (PurchaseOrderItem $item) => (int) round($item->returned_quantity * $item->unit_cost));

        return Inertia::render('Purchases/Show', [
            'paymentTerms' => $this->terms->options($order->company_id, $order->payment_term_id),
            'order' => [
                'id' => $order->id,
                'code' => $order->code,
                'status' => $order->status,
                'status_label' => $order->statusLabel(),
                'supplier' => [
                    'id' => $order->supplier->id,
                    'name' => $order->supplier->name,
                    'contact_name' => $order->supplier->contact_name,
                    'phone' => $order->supplier->phone,
                ],
                'store' => $order->store?->name,
                'user' => $order->user?->full_name,
                'expected_at' => $order->expected_at?->toDateString(),
                'received_at' => $order->received_at?->toIso8601String(),
                'payment_term' => $order->paymentTerm ? ['id' => $order->paymentTerm->id, 'name' => $order->paymentTerm->name, 'days' => $order->paymentTerm->days] : null,
                'due_at' => $order->due_at?->toIso8601String(),
                'is_overdue' => $order->isOverdue(),
                'subtotal' => $order->subtotal,
                'tax_total' => $order->tax_total,
                'total' => $order->total,
                'note' => $order->note,
                'source_label' => PurchaseOrder::SOURCE_LABELS[$order->source] ?? null,
                'can_receive' => $order->canReceive(),
                'can_return' => $order->canReturn(),
                // Recibido, devuelto y neto al costo de la orden: la devolucion no toca lo recibido.
                'received_value' => $receivedValue,
                'returned_value' => $returnedValue,
                'net_value' => $receivedValue - $returnedValue,
                'returns' => $order->returns->sortByDesc('id')->map(fn (SupplierReturn $return) => [
                    'id' => $return->id,
                    'number' => $return->number,
                    'status' => $return->status,
                    'status_label' => $return->statusLabel(),
                    'at' => ($return->dispatched_at ?? $return->created_at)?->toIso8601String(),
                    'units' => round((float) $return->items->sum('quantity'), 3),
                    'total' => $return->total,
                ])->values(),
                // En una orden de reposicion: la devolucion que la origino.
                'replacement_of' => $order->supplierReturn ? ['id' => $order->supplierReturn->id, 'number' => $order->supplierReturn->number] : null,
                'pending_quantity' => round($order->pendingQuantity(), 3),
                // Cada remesa que entro, con su fecha: es lo que explica un pedido que llego
                // por partes.
                'receipts' => StockMovement::query()
                    ->where('reference_type', PurchaseOrder::class)
                    ->where('reference_id', $order->id)
                    ->where('type', StockMovement::TYPE_PURCHASE)
                    ->with('user:id,name,last_name')
                    ->orderBy('occurred_at')
                    ->get()
                    ->groupBy(fn (StockMovement $movement) => $movement->occurred_at->format('Y-m-d H:i'))
                    ->map(fn ($group, $at) => [
                        'at' => $group->first()->occurred_at->toIso8601String(),
                        'units' => round((float) $group->sum('quantity'), 3),
                        'lines' => $group->count(),
                        'user' => $group->first()->user?->full_name,
                    ])
                    ->values(),
                // Cuantos renglones no son del proveedor de la orden: lo que se puede separar.
                'off_supplier_count' => $order->items->where('is_off_supplier', true)->count(),
                'parent' => $order->parent ? ['id' => $order->parent->id, 'code' => $order->parent->code] : null,
                'children' => $order->children->map(fn (PurchaseOrder $child) => [
                    'id' => $child->id,
                    'code' => $child->code,
                    'supplier' => $child->supplier?->name,
                    'total' => $child->total,
                ])->values(),
                'created_at' => $order->created_at->toIso8601String(),
                'items' => $order->items->map(fn (PurchaseOrderItem $item) => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'name' => $item->product?->name,
                    'sku' => $item->product?->sku,
                    'unit' => $item->product?->unit,
                    // Los renglones de un mismo articulo principal se muestran juntos.
                    'family_id' => $item->product?->product_family_id,
                    'family_name' => $item->product?->family?->name,
                    'variant_label' => $item->product?->variant_label,
                    'variant_sort' => (int) ($item->product?->variant_sort ?? 0),
                    'quantity' => round((float) $item->quantity, 3),
                    'received_quantity' => round((float) $item->received_quantity, 3),
                    'returned_quantity' => round((float) $item->returned_quantity, 3),
                    'pending_quantity' => round($item->pendingQuantity(), 3),
                    'unit_cost' => $item->unit_cost,
                    'line_total' => (int) round($item->quantity * $item->unit_cost),
                    'supplier' => $item->supplier?->name,
                    'is_off_supplier' => (bool) $item->is_off_supplier,
                    'suggested_quantity' => round((float) $item->suggested_quantity, 3),
                    'stock_at_order' => round((float) $item->stock_at_order, 3),
                    'min_at_order' => round((float) $item->min_at_order, 3),
                    'expires_at' => $item->expires_at?->toDateString(),
                    'lot_code' => $item->lot_code,
                ])->values(),
            ],
        ]);
    }

    /**
     * Recepcion de mercancia: suma existencias, escribe kardex y recalcula el costo promedio.
     */
    public function receive(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $this->authorizeCompany($order);

        if ($order->status === PurchaseOrder::STATUS_RECEIVED) {
            return back()->with('error', 'Esa orden ya se recibio completa.');
        }

        if ($order->status === PurchaseOrder::STATUS_CANCELLED) {
            return back()->with('error', 'Esa orden esta anulada.');
        }

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            // Lo que llega **ahora**, no el acumulado: un pedido llega por partes y cada
            // remesa trae su cantidad, su lote y su vencimiento.
            'items.*.received_quantity' => ['required', 'numeric', 'min:0'],
            'items.*.expires_at' => ['nullable', 'date'],
            'items.*.lot_code' => ['nullable', 'string', 'max:40'],
            // La remesa puede entrar a otra bodega: llega el camion y se descarga donde quepa.
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('store_id', $order->store_id))],
        ], [
            'items.required' => 'Indica que se recibio.',
            'warehouse_id.exists' => 'Esa bodega no es de la sede de la orden.',
        ]);

        if (collect($validated['items'])->sum('received_quantity') <= 0) {
            return back()->withErrors(['items' => 'No se recibio nada: indica al menos una cantidad.']);
        }

        $warehouse = $this->receivingWarehouse($order, $validated['warehouse_id'] ?? null);

        DB::transaction(function () use ($order, $validated, $request, $warehouse) {
            $order->load('items.product');
            $byId = $order->items->keyBy('id');

            foreach ($validated['items'] as $entry) {
                $item = $byId->get((int) $entry['id']);

                if (! $item || ! $item->product) {
                    continue;
                }

                // Lo que llega en esta remesa. Se suma a lo que ya habia llegado antes.
                $delta = (float) $entry['received_quantity'];

                if ($delta <= 0) {
                    continue;
                }

                $changes = ['received_quantity' => (float) $item->received_quantity + $delta];

                // El lote solo se pisa si esta remesa trae uno: la anterior llego con el suyo
                // y borrarlo dejaria la orden sin rastro de con que lote entro lo primero.
                if (! empty($entry['lot_code'])) {
                    $changes['lot_code'] = $entry['lot_code'];
                }

                if (! empty($entry['expires_at'])) {
                    $changes['expires_at'] = $entry['expires_at'];
                }

                $item->update($changes);

                $this->stock->move(
                    product: $item->product,
                    warehouse: $warehouse,
                    type: StockMovement::TYPE_PURCHASE,
                    quantity: $delta,
                    userId: $request->user()->id,
                    reference: $order,
                    note: "Recepcion {$order->code}",
                    unitCost: $item->unit_cost,
                );

                $this->stock->receiveLot(
                    product: $item->product,
                    warehouse: $warehouse,
                    quantity: $delta,
                    code: $item->lot_code,
                    expiresAt: $entry['expires_at'] ?? null,
                    unitCost: (int) $item->unit_cost,
                    // Asi una devolucion con esta orden encuentra justo el lote que entro con ella.
                    purchaseOrderItemId: $item->id,
                );

                $this->stock->recalculateAverageCost($item->product, $delta, (int) $item->unit_cost);

                // A como lo dejo de verdad este proveedor. Se guarda al recibir y no al pedir:
                // lo que se pidio es una intencion, lo que llego es lo que costo.
                $this->rememberCost($item, $order);
            }

            /*
             * Completa o en parte.
             *
             * Un pedido de 120 del que llegaron 100 no esta recibido: quedan 20 que pueden
             * llegar manana, en otro lote. Cerrarlo aqui obligaria a inventar una orden nueva
             * por lo que falta, y el pedido original mentiria sobre lo que de verdad entro.
             */
            $order->load('items');
            $complete = $order->pendingQuantity() <= 0;

            $order->update([
                'status' => $complete ? PurchaseOrder::STATUS_RECEIVED : PurchaseOrder::STATUS_PARTIAL,
                // La fecha de recepcion es la del cierre: mientras falte, la orden sigue viva.
                'received_at' => $complete ? now() : null,
            ]);

            // Con todo adentro empieza a correr el plazo del proveedor.
            $order->syncDueAt();
        });

        $order->refresh()->load('items');

        if ($order->status === PurchaseOrder::STATUS_PARTIAL) {
            return back()->with(
                'success',
                'Se recibio lo que llego y el costo promedio se recalculo. Faltan '
                    .rtrim(rtrim(number_format($order->pendingQuantity(), 3, ',', '.'), '0'), ',')
                    .' unidades: la orden queda abierta para cuando lleguen.'
            );
        }

        return back()->with('success', 'La mercancia quedo recibida completa y el costo promedio se recalculo.');
    }

    /**
     * Da por cerrada una orden a la que ya no le va a llegar el resto.
     *
     * A veces el proveedor no manda lo que falta y punto. Sin esto la orden quedaria «recibida
     * en parte» para siempre, ensuciando el listado de lo que de verdad esta pendiente.
     *
     * No mueve inventario: solo deja de esperar. Lo que llego ya entro cuando llego.
     */
    public function close(PurchaseOrder $order): RedirectResponse
    {
        $this->authorizeCompany($order);

        if ($order->status !== PurchaseOrder::STATUS_PARTIAL) {
            return back()->withErrors(['status' => 'Solo se cierran las ordenes recibidas en parte.']);
        }

        $order->update([
            'status' => PurchaseOrder::STATUS_RECEIVED,
            'received_at' => now(),
        ]);
        $order->syncDueAt();

        return back()->with('success', "La orden {$order->code} quedo cerrada con lo que llego.");
    }

    public function cancel(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $this->authorizeCompany($order);

        if ($order->status === PurchaseOrder::STATUS_RECEIVED) {
            return back()->with('error', 'Una orden recibida no se puede anular.');
        }

        // Anular una a la que ya le entro mercancia dejaria el inventario contando algo que la
        // orden dice que nunca paso. Se cierra con lo que llego, que es lo que de verdad hubo.
        if ($order->status === PurchaseOrder::STATUS_PARTIAL) {
            return back()->withErrors([
                'status' => 'A esa orden ya le entro mercancia. Ciérrala con lo que llegó en vez de anularla.',
            ]);
        }

        DB::transaction(function () use ($order) {
            $order->update(['status' => PurchaseOrder::STATUS_CANCELLED]);

            // Una reposicion anulada deja de contar como respuesta del proveedor.
            $this->supplierReturns->replacementCancelled($order);
        });

        return back()->with('success', 'La orden quedo anulada.');
    }

    public function send(PurchaseOrder $order): RedirectResponse
    {
        $this->authorizeCompany($order);

        if ($order->status !== PurchaseOrder::STATUS_DRAFT) {
            return back()->with('error', 'Solo se envian las ordenes en borrador.');
        }

        $order->update(['status' => PurchaseOrder::STATUS_SENT]);

        return back()->with('success', 'La orden quedo enviada al proveedor.');
    }

    /**
     * Articulos bajo el minimo, listos para armar la orden con un clic.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * @return list<array{value: int, label: string}>
     */
    protected function supplierOptions(int $companyId): array
    {
        return Supplier::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Supplier $supplier) => ['value' => $supplier->id, 'label' => $supplier->name, 'payment_term_id' => $supplier->payment_term_id])
            ->values()
            ->all();
    }

    /**
     * Cambiar el plazo de una orden. Si ya entro todo, el vencimiento se recalcula.
     */
    public function updateTerm(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $this->authorizeCompany($order);

        if ($order->status === PurchaseOrder::STATUS_CANCELLED) {
            return back()->with('error', 'Una orden anulada no cambia de plazo.');
        }

        $validated = $request->validate(['payment_term_id' => ['nullable', 'integer']]);
        $term = $this->terms->resolve($order->company_id, $validated['payment_term_id'] ?? null, $order->payment_term_id);

        $order->update(['payment_term_id' => $term?->id]);
        $order->syncDueAt();

        return back()->with('success', $term ? "La orden quedó con plazo {$term->name}." : 'La orden quedó sin plazo.');
    }

    /** El plazo que da el proveedor, o el de proveedores por defecto. */
    protected function termFor(int $companyId, int $supplierId): ?PaymentTerm
    {
        $supplierTerm = Supplier::query()->whereKey($supplierId)->value('payment_term_id');

        return ($supplierTerm ? $this->terms->find($companyId, $supplierTerm) : null)
            ?? $this->terms->defaultFor($companyId, PaymentTermService::TYPE_SUPPLIERS);
    }

    protected function authorizeCompany(PurchaseOrder $order): void
    {
        abort_if(
            ! auth()->user()->isSuperAdmin() && $order->company_id !== TenantContext::activeCompanyId(),
            403,
            'Esa orden no pertenece a tu empresa.'
        );
    }

    /**
     * La bodega donde entra esta remesa.
     *
     * Manda lo que diga la recepcion; si no dice nada, la que la orden anoto al crearse; y si
     * la orden es de antes de que existieran las bodegas, la de recepcion de su sede.
     */
    protected function receivingWarehouse(PurchaseOrder $order, ?int $chosen): Warehouse
    {
        $id = $chosen ?: $order->warehouse_id;

        $warehouse = $id
            ? Warehouse::query()->withoutGlobalScopes()->where('store_id', $order->store_id)->find($id)
            : null;

        return $warehouse ?? $this->warehouses->receivingDefaultFor($order->store_id);
    }
}

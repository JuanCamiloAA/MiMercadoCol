<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Warehouse;
use App\Services\StockService;
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

class InventoryController extends Controller
{
    public function __construct(
        protected StockService $stock,
        protected WarehouseService $warehouses,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();
        $storeId = StoreContext::requireStoreId();

        $filters = [
            'search' => $request->string('search')->toString(),
            'category' => $request->string('category')->toString(),
            'state' => $request->string('state')->toString(),
            'warehouse' => $request->string('warehouse')->toString(),
            // Los subarticulos de un articulo principal: cada uno sigue siendo una referencia.
            'family' => $request->string('family')->toString(),
        ];

        $warehouses = $this->warehouses->forStore($storeId);
        // Vacio significa «todas las de la sede», que es la vista util cuando alguien quiere
        // saber cuanto hay, sin importar en que estante esta.
        $warehouseId = $warehouses->firstWhere('id', (int) $filters['warehouse'])?->id;

        // Una fila por articulo, sumando sus bodegas (o la elegida). Sin agrupar, una sede con
        // dos bodegas mostraria el mismo articulo dos veces y ninguna de las dos lineas diria
        // cuanto hay de verdad en la sede.
        $stocks = ProductStock::query()
            ->join('products', 'products.id', '=', 'product_stocks.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('product_stocks.company_id', $companyId)
            ->where('product_stocks.store_id', $storeId)
            ->when($warehouseId, fn ($query, $id) => $query->where('product_stocks.warehouse_id', $id))
            ->whereNull('products.deleted_at')
            ->when($filters['search'], fn ($query, $search) => $query->where(function ($inner) use ($search) {
                $inner->where('products.name', 'like', "%{$search}%")
                    ->orWhere('products.sku', 'like', "%{$search}%")
                    ->orWhere('products.barcode', 'like', "%{$search}%");
            }))
            ->when($filters['category'], fn ($query, $category) => $query->where('products.category_id', $category))
            ->when($filters['family'], fn ($query, $family) => $query->where('products.product_family_id', $family))
            ->groupBy(
                'products.id', 'products.name', 'products.sku', 'products.unit',
                'products.min_stock', 'products.cost', 'products.price', 'categories.name',
            )
            ->when($filters['state'] === 'low', fn ($query) => $query->havingRaw('SUM(product_stocks.quantity) <= products.min_stock'))
            ->when($filters['state'] === 'out', fn ($query) => $query->havingRaw('SUM(product_stocks.quantity) <= 0'))
            ->when($filters['state'] === 'over', fn ($query) => $query->havingRaw('SUM(product_stocks.quantity) > products.min_stock * 4'))
            ->orderByRaw('(SUM(product_stocks.quantity) - products.min_stock) ASC')
            ->select([
                'products.id as product_id',
                'products.name',
                'products.sku',
                'products.unit',
                'products.min_stock',
                'products.cost',
                'products.price',
                'categories.name as category_name',
                DB::raw('SUM(product_stocks.quantity) as quantity'),
                DB::raw('SUM(product_stocks.reserved_quantity) as reserved_quantity'),
                DB::raw('MIN(product_stocks.location) as location'),
                DB::raw('COUNT(DISTINCT product_stocks.warehouse_id) as warehouse_count'),
            ])
            ->paginate(25)
            ->withQueryString()
            ->through(fn ($row) => [
                'id' => (int) $row->product_id,
                'product_id' => (int) $row->product_id,
                'name' => $row->name,
                'sku' => $row->sku,
                'unit' => $row->unit,
                'category' => $row->category_name,
                'quantity' => round((float) $row->quantity, 3),
                'reserved_quantity' => round((float) $row->reserved_quantity, 3),
                'min_stock' => (int) $row->min_stock,
                'suggested_order' => max(0, (int) ceil($row->min_stock * 2 - $row->quantity)),
                'location' => $row->location,
                'warehouse_count' => (int) $row->warehouse_count,
                'value' => (int) round($row->quantity * $row->cost),
            ]);

        return Inertia::render('Inventory/Index', [
            'stocks' => $stocks,
            'filters' => $filters,
            'families' => ProductFamily::query()
                ->where('company_id', $companyId)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (ProductFamily $family) => ['value' => $family->id, 'label' => $family->name])
                ->values(),
            'categories' => Category::query()
                ->where('company_id', $companyId)
                ->orderBy('name')
                ->get()
                ->map(fn (Category $category) => ['value' => $category->id, 'label' => $category->name])
                ->values(),
            'stores' => Store::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Store $store) => ['value' => $store->id, 'label' => $store->name])
                ->values(),
            'warehouses' => $warehouses
                ->map(fn (Warehouse $warehouse) => [
                    'value' => $warehouse->id,
                    'label' => $warehouse->name,
                    'is_pos_source' => $warehouse->is_pos_source,
                    'is_online_source' => $warehouse->is_online_source,
                    'is_receiving_default' => $warehouse->is_receiving_default,
                ])
                ->values(),
            'metrics' => $this->metrics($companyId, $storeId, $warehouseId),
            'recentMovements' => $this->recentMovements($companyId, $storeId, $warehouseId),
            'expiringLots' => $this->stock->expiringLots($companyId, $storeId, 20, $warehouseId)
                ->map(fn ($lot) => [
                    'id' => $lot->id,
                    'product' => $lot->product?->name,
                    'product_id' => $lot->product_id,
                    'sku' => $lot->product?->sku,
                    'unit' => $lot->product?->unit,
                    'code' => $lot->code,
                    'warehouse' => $lot->warehouse?->name,
                    'quantity' => round((float) $lot->quantity, 3),
                    'expires_at' => $lot->expires_at?->toDateString(),
                    'days_left' => $lot->daysToExpiry(),
                ])
                ->values(),
        ]);
    }

    public function adjust(Request $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $storeId = StoreContext::requireStoreId();

        $validated = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('store_id', $storeId))],
            'type' => ['required', Rule::in([StockMovement::TYPE_ADJUSTMENT, StockMovement::TYPE_WASTE])],
            'quantity' => ['required', 'numeric', 'not_in:0'],
            'note' => ['required', 'string', 'max:160'],
        ], [
            'product_id.required' => 'Elige el articulo.',
            'warehouse_id.required' => 'Elige la bodega.',
            'warehouse_id.exists' => 'Esa bodega no es de esta sede.',
            'quantity.required' => 'Indica la cantidad.',
            'quantity.not_in' => 'La cantidad no puede ser cero.',
            'note.required' => 'Explica el motivo del ajuste.',
        ]);

        $product = Product::query()->where('company_id', $companyId)->findOrFail($validated['product_id']);

        // Una averia siempre resta: no tiene sentido registrar una averia positiva.
        $quantity = $validated['type'] === StockMovement::TYPE_WASTE
            ? -abs((float) $validated['quantity'])
            : (float) $validated['quantity'];

        $warehouse = Warehouse::query()->withoutGlobalScopes()->findOrFail($validated['warehouse_id']);

        DB::transaction(fn () => $this->stock->move(
            product: $product,
            warehouse: $warehouse,
            type: $validated['type'],
            quantity: $quantity,
            userId: $request->user()->id,
            note: $validated['note'],
            unitCost: $product->cost,
        ));

        return back()->with('success', 'El movimiento quedo registrado.');
    }

    /**
     * Donde esta un articulo, bodega por bodega.
     *
     * Alimenta el panel de «ver en otras sedes». Es de solo lectura y es lo que evita el
     * telefonazo de siempre: antes de pedirle al proveedor, mirar si lo que falta esta en la
     * sede de al lado. Quien no tenga permiso de mirar otras sedes solo ve las suyas.
     */
    public function stock(Request $request, Product $product): JsonResponse
    {
        $companyId = TenantContext::requireCompanyId();

        abort_if(
            ! $request->user()->isSuperAdmin() && $product->company_id !== $companyId,
            403,
            'Ese articulo no pertenece a tu empresa.'
        );

        $user = $request->user();
        $everywhere = $user->isSuperAdmin() || $user->can('inventory.index.view_other_stores');
        $mine = $user->stores()->pluck('stores.id')->all();

        $warehouses = $this->warehouses->forCompany($product->company_id)
            ->filter(fn (Warehouse $warehouse) => $everywhere || in_array((int) $warehouse->store_id, $mine, true));

        $stock = ProductStock::query()
            ->withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->whereIn('warehouse_id', $warehouses->pluck('id'))
            ->get()
            ->keyBy('warehouse_id');

        $rows = $warehouses
            ->map(function (Warehouse $warehouse) use ($stock, $mine, $user) {
                $row = $stock->get($warehouse->id);
                $quantity = round((float) ($row->quantity ?? 0), 3);
                $reserved = round((float) ($row->reserved_quantity ?? 0), 3);

                return [
                    'warehouse_id' => $warehouse->id,
                    'warehouse' => $warehouse->name,
                    'store_id' => (int) $warehouse->store_id,
                    'store' => $warehouse->store?->name,
                    'quantity' => $quantity,
                    'reserved' => $reserved,
                    'available' => round($quantity - $reserved, 3),
                    'location' => $row->location ?? null,
                    'is_pos_source' => $warehouse->is_pos_source,
                    'is_online_source' => $warehouse->is_online_source,
                    // Solo se puede pedir traslado hacia una bodega a la que se llega.
                    'is_mine' => $user->isSuperAdmin() || in_array((int) $warehouse->store_id, $mine, true),
                ];
            })
            ->values();

        return response()->json([
            'product' => ['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'unit' => $product->unit],
            'rows' => $rows,
            'total' => round((float) $rows->sum('quantity'), 3),
            'sees_all' => $everywhere,
        ]);
    }

    public function movements(Request $request, Product $product): Response
    {
        abort_if(
            ! $request->user()->isSuperAdmin() && $product->company_id !== TenantContext::activeCompanyId(),
            403,
            'Ese articulo no pertenece a tu empresa.'
        );

        $storeId = $request->string('store')->toString();
        $warehouseFilter = $request->string('warehouse')->toString();

        $movements = StockMovement::query()
            ->where('product_id', $product->id)
            ->when($storeId, fn ($query, $store) => $query->where('store_id', $store))
            ->when($warehouseFilter, fn ($query, $id) => $query->where('warehouse_id', $id))
            ->with(['store:id,name', 'warehouse:id,name', 'user:id,name,last_name'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();
        $links = StockMovement::referenceLinks($movements->getCollection());

        $movements = $movements
            ->through(fn (StockMovement $movement) => [
                'id' => $movement->id,
                'type' => $movement->type,
                'type_label' => $movement->typeLabel(),
                'quantity' => round((float) $movement->quantity, 3),
                'balance_after' => round((float) $movement->balance_after, 3),
                'unit_cost' => $movement->unit_cost,
                'store' => $movement->store?->name,
                'warehouse' => $movement->warehouse?->name,
                'user' => $movement->user?->full_name,
                'note' => $movement->note,
                'reference' => $links[$movement->id] ?? null,
                'occurred_at' => $movement->occurred_at->toIso8601String(),
            ]);

        return Inertia::render('Inventory/Movements', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'unit' => $product->unit,
                'min_stock' => $product->min_stock,
            ],
            'movements' => $movements,
            'filters' => ['store' => $storeId, 'warehouse' => $warehouseFilter],
            // El saldo de cada renglon es el de su bodega, no el de la sede: por eso la
            // pantalla deja filtrar, o la columna «saldo» se lee como una montana rusa.
            'warehouses' => $this->warehouses->forCompany($product->company_id)
                ->map(fn (Warehouse $warehouse) => ['value' => $warehouse->id, 'label' => $warehouse->fullName()])
                ->values(),
            'stores' => Store::query()
                ->where('company_id', $product->company_id)
                ->orderBy('name')
                ->get()
                ->map(fn (Store $store) => ['value' => $store->id, 'label' => $store->name])
                ->values(),
        ]);
    }

    /**
     * @return array<string, int|float>
     */
    protected function metrics(int $companyId, int $storeId, ?int $warehouseId = null): array
    {
        $base = ProductStock::query()
            ->join('products', 'products.id', '=', 'product_stocks.product_id')
            ->where('product_stocks.company_id', $companyId)
            ->where('product_stocks.store_id', $storeId)
            ->when($warehouseId, fn ($query, $id) => $query->where('product_stocks.warehouse_id', $id))
            ->whereNull('products.deleted_at')
            ->where('products.is_active', true);

        // Se cuentan articulos, no filas: con dos bodegas cada articulo tiene dos filas, y
        // contarlas sueltas duplicaria las referencias y marcaria agotado lo que hay atras.
        $byProduct = fn (string $having) => $base->clone()
            ->groupBy('product_stocks.product_id', 'products.min_stock')
            ->havingRaw($having)
            ->selectRaw('product_stocks.product_id')
            ->get()
            ->count();

        return [
            'items' => (int) $base->clone()->distinct()->count('product_stocks.product_id'),
            'value' => (int) $base->clone()->selectRaw('COALESCE(SUM(product_stocks.quantity * products.cost), 0) as total')->value('total'),
            'low' => $byProduct('SUM(product_stocks.quantity) <= products.min_stock'),
            'out' => $byProduct('SUM(product_stocks.quantity) <= 0'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function recentMovements(int $companyId, int $storeId, ?int $warehouseId = null): array
    {
        $movements = StockMovement::query()
            ->where('company_id', $companyId)
            ->where('store_id', $storeId)
            ->when($warehouseId, fn ($query, $id) => $query->where('warehouse_id', $id))
            ->with(['product:id,name,sku', 'user:id,name,last_name', 'warehouse:id,name'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(15)
            ->get();
        $links = StockMovement::referenceLinks($movements);

        return $movements
            ->map(fn (StockMovement $movement) => [
                'id' => $movement->id,
                'product' => $movement->product?->name,
                'product_id' => $movement->product_id,
                'sku' => $movement->product?->sku,
                'type' => $movement->type,
                'type_label' => $movement->typeLabel(),
                'warehouse' => $movement->warehouse?->name,
                'quantity' => round((float) $movement->quantity, 3),
                'balance_after' => round((float) $movement->balance_after, 3),
                'user' => $movement->user?->full_name,
                'note' => $movement->note,
                'reference' => $links[$movement->id] ?? null,
                'occurred_at' => $movement->occurred_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}

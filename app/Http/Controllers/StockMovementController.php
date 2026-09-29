<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStockMovementRequest;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\InventoryCatalogService;
use App\Services\StockBatchService;
use App\Services\WarehouseService;
use App\Support\StoreContext;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Entradas y salidas de bodega con varios articulos, con la mecanica del punto de venta.
 *
 * El ajuste de un solo articulo sigue viviendo en `InventoryController`: sirve para corregir
 * un renglon puntual desde la tabla. Esto es para lo otro, que es lo comun en una tienda:
 * llega un conteo de bodega con quince referencias, o se dan de baja los vencidos del dia.
 * Hacerlo de a uno son quince formularios y quince recargas.
 */
class StockMovementController extends Controller
{
    public function __construct(
        protected InventoryCatalogService $catalog,
        protected StockBatchService $batch,
        protected WarehouseService $warehouses,
    ) {}

    public function createEntry(): Response
    {
        return $this->form(StockMovement::DIRECTION_IN);
    }

    public function createExit(): Response
    {
        return $this->form(StockMovement::DIRECTION_OUT);
    }

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

    public function store(StoreStockMovementRequest $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $storeId = StoreContext::requireStoreId();

        $direction = $request->direction();

        $warehouse = Warehouse::query()->withoutGlobalScopes()->findOrFail($request->validated('warehouse_id'));

        $result = $this->batch->register(
            companyId: $companyId,
            warehouse: $warehouse,
            direction: $direction,
            reason: $request->validated('reason'),
            items: $request->items(),
            note: $request->validated('note'),
            userId: $request->user()->id,
        );

        $what = $direction === StockMovement::DIRECTION_IN ? 'entrada' : 'salida';
        $articles = $result['movements'] === 1 ? 'articulo' : 'articulos';

        return redirect()
            ->route('inventory.index')
            ->with('success', "Quedo registrada la {$what} de {$result['movements']} {$articles}.");
    }

    protected function form(string $direction): Response
    {
        $companyId = TenantContext::requireCompanyId();
        $storeId = StoreContext::requireStoreId();

        $warehouses = $this->warehouses->forStore($storeId);

        // Arranca en la bodega que ese canal usaria sola: la entrada donde se reciben las
        // compras, la salida de donde vende la caja. El usuario la confirma o la cambia.
        $flag = $direction === StockMovement::DIRECTION_IN ? Warehouse::FLAG_RECEIVING : Warehouse::FLAG_POS;
        $defaultWarehouse = $warehouses->firstWhere($flag, true) ?? $warehouses->first();

        $catalog = $this->catalog->catalog($companyId, $storeId, warehouseId: $defaultWarehouse?->id);

        return Inertia::render('Inventory/Movement', [
            'direction' => $direction,
            'reasons' => StockMovement::reasonsFor($direction),
            'warehouses' => $warehouses
                ->map(fn (Warehouse $warehouse) => ['value' => $warehouse->id, 'label' => $warehouse->name])
                ->values(),
            'default_warehouse' => $defaultWarehouse?->id,
            'products' => $catalog['products'],
            'counts' => [
                'total' => $catalog['total'],
                'low_stock' => $catalog['low_stock'],
            ],
            'store' => StoreContext::activeStore()?->only(['id', 'name']),
        ]);
    }
}

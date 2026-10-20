<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWarehouseRequest;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Warehouse;
use App\Services\Membership\EntitlementService;
use App\Services\Membership\MembershipFeatures;
use App\Services\WarehouseService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las bodegas de una sede.
 *
 * Aqui se decide lo unico que de verdad cambia el comportamiento del sistema: cual bodega
 * descuenta la caja, cual respalda la tienda en linea y donde entra una compra. El resto (el
 * nombre, el tipo) es para que la gente se entienda.
 */
class WarehouseController extends Controller
{
    public function __construct(protected WarehouseService $warehouses) {}

    public function index(Store $store): Response
    {
        $this->authorizeCompany($store);

        return Inertia::render('Stores/Warehouses', [
            'store' => ['id' => $store->id, 'name' => $store->name, 'code' => $store->code],
            'warehouses' => $this->payload($store),
            'types' => collect(Warehouse::TYPE_LABELS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'flags' => collect(Warehouse::FLAG_LABELS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
        ]);
    }

    public function store(StoreWarehouseRequest $request, Store $store): RedirectResponse
    {
        $this->authorizeCompany($store);

        // La principal nace con la sede; una bodega mas es funcion del plan.
        $company = TenantContext::company();

        if ($company && ! $company->hasFeature(MembershipFeatures::MULTI_WAREHOUSE)) {
            throw ValidationException::withMessages(['name' => EntitlementService::deniedMessage('stores.warehouses.create')]);
        }

        DB::transaction(function () use ($request, $store) {
            $warehouse = Warehouse::query()->withoutGlobalScopes()->create([
                'company_id' => $store->company_id,
                'store_id' => $store->id,
                'name' => $request->validated('name'),
                'code' => strtoupper($request->validated('code')),
                'type' => $request->validated('type'),
                'is_active' => true,
            ]);

            $this->warehouses->assignFlags($warehouse, $request->flags());
        });

        return back()->with('success', 'La bodega quedo creada.');
    }

    public function update(StoreWarehouseRequest $request, Store $store, Warehouse $warehouse): RedirectResponse
    {
        $this->authorizeCompany($store);
        $this->authorizeWarehouse($store, $warehouse);

        DB::transaction(function () use ($request, $warehouse) {
            $warehouse->update([
                'name' => $request->validated('name'),
                'code' => strtoupper($request->validated('code')),
                'type' => $request->validated('type'),
            ]);

            $this->warehouses->assignFlags($warehouse, $request->flags());
        });

        return back()->with('success', 'La bodega quedo actualizada.');
    }

    public function deactivate(Store $store, Warehouse $warehouse): RedirectResponse
    {
        $this->authorizeCompany($store);
        $this->authorizeWarehouse($store, $warehouse);

        if (! $warehouse->is_active) {
            $warehouse->is_active = true;
            $warehouse->save();

            return back()->with('success', 'La bodega quedo activa otra vez.');
        }

        // Nunca se borra: los movimientos que hizo siguen contando la historia del inventario.
        $this->warehouses->deactivate($warehouse);

        return back()->with('success', 'La bodega quedo desactivada.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function payload(Store $store): array
    {
        $warehouses = $this->warehouses->forStore($store->id, onlyActive: false);

        $stock = ProductStock::query()
            ->withoutGlobalScopes()
            ->whereIn('warehouse_id', $warehouses->pluck('id'))
            ->join('products', 'products.id', '=', 'product_stocks.product_id')
            ->groupBy('product_stocks.warehouse_id')
            ->selectRaw('product_stocks.warehouse_id, COUNT(*) as items, COALESCE(SUM(product_stocks.quantity * products.cost), 0) as value')
            ->get()
            ->keyBy('warehouse_id');

        $movements = StockMovement::query()
            ->withoutGlobalScopes()
            ->whereIn('warehouse_id', $warehouses->pluck('id'))
            ->groupBy('warehouse_id')
            ->selectRaw('warehouse_id, COUNT(*) as total')
            ->pluck('total', 'warehouse_id');

        return $warehouses
            ->map(fn (Warehouse $warehouse) => [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'code' => $warehouse->code,
                'type' => $warehouse->type,
                'type_label' => $warehouse->typeLabel(),
                'is_pos_source' => $warehouse->is_pos_source,
                'is_online_source' => $warehouse->is_online_source,
                'is_receiving_default' => $warehouse->is_receiving_default,
                'is_active' => $warehouse->is_active,
                'flags' => $warehouse->flags(),
                'items' => (int) ($stock->get($warehouse->id)->items ?? 0),
                'value' => (int) ($stock->get($warehouse->id)->value ?? 0),
                // Con movimientos ya no es una bodega vacia: se desactiva, no se borra.
                'movements' => (int) ($movements[$warehouse->id] ?? 0),
            ])
            ->values()
            ->all();
    }

    protected function authorizeCompany(Store $store): void
    {
        abort_if(
            ! auth('web')->user()->isSuperAdmin() && $store->company_id !== TenantContext::activeCompanyId(),
            403,
            'Esa sede no pertenece a tu empresa.'
        );
    }

    protected function authorizeWarehouse(Store $store, Warehouse $warehouse): void
    {
        abort_if((int) $warehouse->store_id !== (int) $store->id, 404, 'Esa bodega no es de esta sede.');
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\Services\StoreServiceRequest;
use App\Http\Requests\Services\UpdateServiceRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Service;
use App\Models\ServiceComponent;
use App\Services\PromotionService;
use App\Services\ServiceManager;
use App\Services\ServicePricing;
use App\Services\WarehouseService;
use App\Support\StoreContext;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El maestro de servicios, espejo de Articulos.
 *
 * El precio que se muestra en cada pantalla sale de `ServicePricing`, el mismo que usa la caja:
 * lo que se ve aqui es lo que se cobra.
 */
class ServiceController extends Controller
{
    public function __construct(
        protected ServiceManager $manager,
        protected ServicePricing $pricing,
        protected WarehouseService $warehouses,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();
        $canViewCost = $request->user()->can('services.show.view_cost');
        $filters = $this->filters($request);

        $services = $this->filtered($companyId, $filters)
            ->with(['category:id,name', 'components.product'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Service $service) => $this->row($service, $canViewCost));

        return Inertia::render('Services/Index', [
            'services' => $services,
            'filters' => $filters,
            'categories' => $this->categoryOptions($companyId),
            'options' => $this->filterOptions(),
            'canViewCost' => $canViewCost,
            'counts' => [
                'total' => Service::query()->where('company_id', $companyId)->count(),
                'active' => Service::query()->where('company_id', $companyId)->where('is_active', true)->count(),
                'online' => Service::query()->where('company_id', $companyId)->where('is_online_visible', true)->count(),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $canViewCost = $request->user()->can('services.show.view_cost');

        $services = $this->filtered($companyId, $this->filters($request))
            ->with(['category:id,name', 'components.product'])
            ->orderBy('name')
            ->get();

        // Las mismas columnas de la importacion: el archivo exportado se puede volver a cargar.
        return response()->streamDownload(function () use ($services, $canViewCost) {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['codigo', 'nombre', 'categoria', 'precio', 'precio_en_linea', 'iva', 'modo_precio', 'precio_variable', 'costo', 'activo', 'en_linea', 'articulos', 'codigo_barras'], ';');

            foreach ($services as $service) {
                fputcsv($handle, [
                    $service->code,
                    $service->name,
                    $service->category?->name,
                    $service->price,
                    $service->online_price,
                    (float) $service->tax_rate,
                    $service->isItemized() ? 'detallado' : 'cerrado',
                    $service->open_price ? 'si' : 'no',
                    $canViewCost ? $service->cost : '',
                    $service->is_active ? 'si' : 'no',
                    $service->is_online_visible ? 'si' : 'no',
                    $service->components
                        ->filter(fn (ServiceComponent $component) => $component->product !== null)
                        ->map(fn (ServiceComponent $component) => $component->product->sku.':'
                            .rtrim(rtrim(number_format((float) $component->quantity, 3, '.', ''), '0'), '.').':'
                            .($component->returnable_by_default ? 'si' : 'no')
                            .($service->isItemized() && $component->unit_price !== null ? ':'.$component->unit_price : ''))
                        ->implode(';'),
                    $service->barcode,
                ], ';');
            }

            fclose($handle);
        }, 'servicios-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** El buscador de articulos para armar los componentes. */
    public function products(Request $request): JsonResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:60']]);
        $term = trim((string) ($validated['search'] ?? ''));
        $canViewCost = $request->user()->can('services.show.view_cost');

        $products = Product::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->when($term !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhere('barcode', $term)))
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json([
            'products' => $products->map(fn (Product $product) => $this->componentProduct($product, $canViewCost))->values(),
        ]);
    }

    public function create(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();

        return Inertia::render('Services/Form', [
            'mode' => 'create',
            'service' => null,
            'categories' => $this->categoryOptions($companyId),
            'pricingModes' => $this->options(Service::PRICING_LABELS),
            'taxRates' => array_map(fn ($rate) => ['value' => $rate, 'label' => "{$rate} %"], Product::TAX_RATES),
            'canViewCost' => $request->user()->can('services.show.view_cost'),
            'canEditPrice' => true,
        ]);
    }

    public function store(StoreServiceRequest $request): RedirectResponse
    {
        $service = $this->manager->save(null, $request->serviceData(), TenantContext::requireCompanyId(), $request->file('image'));

        return redirect()->route('services.show', $service)->with('success', "El servicio {$service->name} quedó creado.");
    }

    public function show(Request $request, Service $service): Response
    {
        $this->authorizeCompany($service);

        $service->load(['category:id,name', 'components.product']);
        $canViewCost = $request->user()->can('services.show.view_cost');
        $storeId = StoreContext::activeStoreId();
        $pos = $this->pricing->breakdown($service, PromotionService::CHANNEL_POS);
        $online = $this->pricing->breakdown($service, PromotionService::CHANNEL_ONLINE);

        $posWarehouse = $storeId ? $this->warehouses->posSourceFor($storeId) : null;
        $onlineWarehouse = $storeId ? $this->warehouses->onlineSourceFor($storeId) : null;
        $stocks = ProductStock::query()
            ->whereIn('product_id', $service->components->pluck('product_id'))
            ->whereIn('warehouse_id', array_filter([$posWarehouse?->id, $onlineWarehouse?->id]))
            ->get()
            ->groupBy('product_id');

        $available = fn (int $productId, ?int $warehouseId) => $warehouseId === null ? null : round(
            (float) ($stocks->get($productId)?->firstWhere('warehouse_id', $warehouseId)?->quantity ?? 0)
            - (float) ($stocks->get($productId)?->firstWhere('warehouse_id', $warehouseId)?->reserved_quantity ?? 0),
            3,
        );

        $lines = SaleItem::query()
            ->where('service_id', $service->id)
            ->whereNull('refunded_from_sale_item_id')
            ->whereHas('sale', fn (Builder $sale) => $sale->where('company_id', $service->company_id)->whereIn('status', [Sale::STATUS_PAID, Sale::STATUS_CREDIT]))
            ->with(['sale:id,number,sold_at,channel', 'components:id,parent_sale_item_id,component_change'])
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $sold = SaleItem::query()
            ->where('service_id', $service->id)
            ->whereNull('refunded_from_sale_item_id')
            ->whereHas('sale', fn (Builder $sale) => $sale->where('company_id', $service->company_id)->whereIn('status', [Sale::STATUS_PAID, Sale::STATUS_CREDIT]));

        return Inertia::render('Services/Show', [
            'service' => [
                ...$this->row($service, $canViewCost),
                'description' => $service->description,
                'image_path' => $service->image_path,
                'tax_rate' => (float) $service->tax_rate,
                'is_featured' => (bool) $service->is_featured,
                'online_price' => $service->online_price,
                'price_pos' => $pos['total'],
                'price_online' => $service->open_price ? null : $online['total'],
                'value_pos' => $pos['value'],
                'value_online' => $online['value'],
            ],
            'components' => $service->components->values()->map(function (ServiceComponent $component, int $index) use ($pos, $canViewCost, $available, $posWarehouse, $onlineWarehouse) {
                $product = $component->product;

                return [
                    'id' => $component->id,
                    'product_id' => $component->product_id,
                    // El articulo completo: se nombra por su familia y se dice cual sale por defecto.
                    'name' => $component->isFamily() ? ($component->family?->name ?? $product?->name).' (cualquier presentación)' : ($product?->name ?? 'Artículo eliminado'),
                    'default_label' => $component->isFamily() ? $product?->variant_label : null,
                    'sku' => $product?->sku,
                    'unit' => $product?->unit,
                    'quantity' => round((float) $component->quantity, 3),
                    'returnable_by_default' => (bool) $component->returnable_by_default,
                    'unit_price' => $pos['components'][$index]['unit_price'] ?? 0,
                    'fixed_price' => (bool) ($pos['components'][$index]['fixed_price'] ?? false),
                    'unit_cost' => $canViewCost ? (int) ($product?->cost ?? 0) : null,
                    'is_active' => (bool) ($product?->is_active ?? false),
                    'is_deleted' => (bool) $product?->trashed(),
                    'stock_pos' => $available((int) $component->product_id, $posWarehouse?->id),
                    'stock_online' => $available((int) $component->product_id, $onlineWarehouse?->id),
                ];
            }),
            // Casi siempre la caja y la tienda salen de la misma bodega: entonces va una sola columna.
            'warehouses' => [
                'pos' => $posWarehouse?->name,
                'online' => $onlineWarehouse?->name,
                'shared' => $posWarehouse !== null && $posWarehouse->id === $onlineWarehouse?->id,
            ],
            'sales' => [
                'count' => (int) (clone $sold)->count(),
                // Cuantas veces se vendio distinto del maestro: con un articulo de mas, de menos o quitado.
                'adjusted' => (int) (clone $sold)->where(fn (Builder $query) => $query
                    ->whereHas('components', fn (Builder $components) => $components->whereNotNull('component_change'))
                    ->orWhereJsonLength('service_snapshot->removed', '>', 0))->count(),
                'recent' => $lines->map(fn (SaleItem $line) => [
                    'id' => $line->id,
                    'sale_id' => $line->sale_id,
                    'number' => $line->sale?->number,
                    'sold_at' => $line->sale?->sold_at?->toIso8601String(),
                    'channel' => $line->sale?->channel,
                    'quantity' => round((float) $line->quantity, 3),
                    'line_total' => $line->line_total,
                    'adjusted' => $line->components->contains(fn (SaleItem $component) => $component->component_change !== null)
                        || ! empty($line->service_snapshot['removed'] ?? []),
                ])->values(),
            ],
            'canViewCost' => $canViewCost,
        ]);
    }

    public function edit(Request $request, Service $service): Response
    {
        $this->authorizeCompany($service);

        $service->load('components.product');
        $canViewCost = $request->user()->can('services.show.view_cost');

        return Inertia::render('Services/Form', [
            'mode' => 'edit',
            'service' => [
                'id' => $service->id,
                'code' => $service->code,
                'barcode' => $service->barcode,
                'name' => $service->name,
                'category_id' => $service->category_id,
                'description' => $service->description,
                'image_path' => $service->thumb(),
                'price' => $service->price,
                'online_price' => $service->online_price,
                'cost' => $canViewCost ? $service->cost : null,
                'tax_rate' => (float) $service->tax_rate,
                'pricing_mode' => $service->pricing_mode,
                'open_price' => (bool) $service->open_price,
                'is_active' => (bool) $service->is_active,
                'is_online_visible' => (bool) $service->is_online_visible,
                'is_featured' => (bool) $service->is_featured,
                'sort_order' => $service->sort_order,
                'components' => $service->components->values()->map(fn (ServiceComponent $component) => [
                    ...($component->product ? $this->componentProduct($component->product, $canViewCost) : ['product_id' => $component->product_id, 'name' => 'Artículo eliminado', 'is_active' => false]),
                    'quantity' => round((float) $component->quantity, 3),
                    'returnable_by_default' => (bool) $component->returnable_by_default,
                    // `unit_price` es el vigente del articulo (referencia); `custom_price`, el fijado en el servicio.
                    'custom_price' => $service->isItemized() && ! $component->isFamily() ? $component->unit_price : null,
                    'family' => $component->isFamily(),
                ])->values(),
            ],
            'categories' => $this->categoryOptions($service->company_id),
            'pricingModes' => $this->options(Service::PRICING_LABELS),
            'taxRates' => array_map(fn ($rate) => ['value' => $rate, 'label' => "{$rate} %"], Product::TAX_RATES),
            'canViewCost' => $canViewCost,
            'canEditPrice' => $request->user()->can('services.show.edit_price'),
        ]);
    }

    public function update(UpdateServiceRequest $request, Service $service): RedirectResponse
    {
        $this->authorizeCompany($service);

        $this->manager->save($service, $request->serviceData(), $service->company_id, $request->file('image'));

        return redirect()->route('services.show', $service)->with('success', 'El servicio quedó actualizado.');
    }

    /** Publicar u ocultar en la tienda desde el listado. */
    public function online(Request $request, Service $service): RedirectResponse
    {
        $this->authorizeCompany($service);

        $validated = $request->validate(['value' => ['required', 'boolean']]);

        if ($validated['value'] && $service->open_price) {
            return back()->with('error', 'Un servicio con precio variable no se publica en línea: el precio se digita en la caja.');
        }

        $service->update(['is_online_visible' => (bool) $validated['value']]);

        return back()->with('success', $validated['value'] ? "{$service->name} quedó publicado en la tienda." : "{$service->name} quedó oculto en la tienda.");
    }

    public function duplicate(Service $service): RedirectResponse
    {
        $this->authorizeCompany($service);

        $copy = $this->manager->duplicate($service);

        return redirect()->route('services.edit', $copy)->with('success', "Quedó la copia {$copy->code}, inactiva. Revísala antes de activarla.");
    }

    public function destroy(Service $service): RedirectResponse
    {
        $this->authorizeCompany($service);

        $this->manager->delete($service);

        return redirect()->route('services.index')->with('success', "El servicio {$service->name} quedó eliminado. Las ventas que lo tienen no cambian.");
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(Service $service, bool $canViewCost): array
    {
        $service->loadMissing(['category:id,name', 'components.product']);
        $pos = $this->pricing->breakdown($service, PromotionService::CHANNEL_POS);
        $blocked = $service->blockedComponents();

        $data = [
            'id' => $service->id,
            'code' => $service->code,
            'barcode' => $service->barcode,
            'name' => $service->name,
            'thumb' => $service->thumb(),
            'category' => $service->category ? ['id' => $service->category->id, 'name' => $service->category->name] : null,
            'price' => $service->price,
            'price_total' => $pos['total'],
            'pricing_mode' => $service->pricing_mode,
            'pricing_label' => $service->pricingLabel(),
            'open_price' => (bool) $service->open_price,
            'components_count' => $service->components->count(),
            'is_online_visible' => (bool) $service->is_online_visible,
            'is_active' => (bool) $service->is_active,
            'blocked' => $blocked,
        ];

        if ($canViewCost) {
            $componentsCost = (int) $service->components->sum(fn (ServiceComponent $component) => (int) round((float) $component->quantity * (int) ($component->product?->cost ?? 0)));
            $costTotal = (int) $service->cost + $componentsCost;

            $data['cost'] = (int) $service->cost;
            $data['cost_total'] = $costTotal;
            $data['margin'] = $pos['total'] > 0 ? round((($pos['total'] - $costTotal) / $pos['total']) * 100, 1) : null;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    protected function componentProduct(Product $product, bool $canViewCost): array
    {
        return [
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'unit' => $product->unit,
            'sold_by_weight' => (bool) $product->sold_by_weight,
            'is_active' => (bool) $product->is_active && ! $product->trashed(),
            // Precio vigente en caja, con promociones de precio: el que cobra un servicio detallado.
            'unit_price' => $this->pricing->componentPrice($product, PromotionService::CHANNEL_POS),
            'unit_cost' => $canViewCost ? (int) $product->cost : null,
            // Un subarticulo se puede poner como el articulo completo, en la presentacion que se elija.
            'family_id' => $product->product_family_id,
            'family_name' => $product->product_family_id ? $product->family?->name : null,
            'variant_label' => $product->variant_label,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function filters(Request $request): array
    {
        return [
            'search' => $request->string('search')->toString(),
            'category' => $request->string('category')->toString(),
            'status' => $request->string('status')->toString(),
            'online' => $request->string('online')->toString(),
            'kind' => $request->string('kind')->toString(),
            'mode' => $request->string('mode')->toString(),
        ];
    }

    /**
     * @param  array<string, string>  $filters
     */
    protected function filtered(int $companyId, array $filters): Builder
    {
        return Service::query()
            ->where('company_id', $companyId)
            ->when($filters['search'], fn ($query, $search) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%")))
            ->when($filters['category'], fn ($query, $category) => $query->where('category_id', $category))
            ->when($filters['status'] !== '', fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->when($filters['online'] !== '', fn ($query) => $query->where('is_online_visible', $filters['online'] === 'yes'))
            ->when($filters['kind'] === 'items', fn ($query) => $query->whereHas('components'))
            ->when($filters['kind'] === 'value', fn ($query) => $query->whereDoesntHave('components'))
            ->when($filters['mode'] !== '' && in_array($filters['mode'], [Service::PRICING_BUNDLED, Service::PRICING_ITEMIZED], true), fn ($query) => $query->where('pricing_mode', $filters['mode']));
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    protected function filterOptions(): array
    {
        return [
            'status' => [['value' => 'active', 'label' => 'Activos'], ['value' => 'inactive', 'label' => 'Inactivos']],
            'online' => [['value' => 'yes', 'label' => 'Publicados en línea'], ['value' => 'no', 'label' => 'Ocultos en línea']],
            'kind' => [['value' => 'items', 'label' => 'Con artículos'], ['value' => 'value', 'label' => 'Solo valor']],
            'mode' => $this->options(Service::PRICING_LABELS),
        ];
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    protected function categoryOptions(int $companyId): array
    {
        return Category::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Category $category) => ['value' => $category->id, 'label' => $category->name])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string}>
     */
    protected function options(array $labels): array
    {
        return collect($labels)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all();
    }

    protected function authorizeCompany(Service $service): void
    {
        abort_if(
            ! auth('web')->user()->isSuperAdmin() && $service->company_id !== TenantContext::activeCompanyId(),
            403,
            'Ese servicio no pertenece a tu empresa.'
        );
    }
}

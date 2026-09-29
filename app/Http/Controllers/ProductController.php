<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductImage;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Service;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\ImageService;
use App\Services\ProductFamilyService;
use App\Services\StockService;
use App\Services\WarehouseService;
use App\Support\StoreContext;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller
{
    public function __construct(
        protected StockService $stock,
        protected ImageService $images,
        protected WarehouseService $warehouses,
        protected ProductFamilyService $families,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();
        $storeId = StoreContext::activeStoreId();

        $filters = [
            'search' => $request->string('search')->toString(),
            'category' => $request->string('category')->toString(),
            'supplier' => $request->string('supplier')->toString(),
            'status' => $request->string('status')->toString(),
            'stock' => $request->string('stock')->toString(),
            'type' => $request->string('type')->toString(),
            // Por defecto una familia sale en una sola fila y se despliega para ver cada
            // subarticulo; `ungroup` los muestra sueltos.
            'ungroup' => $request->string('ungroup')->toString() === '1' ? '1' : '',
        ];
        $grouped = $filters['ungroup'] !== '1';

        $withCost = $request->user()->can('products.show.view_cost');

        // Los mismos filtros sirven para la lista y para escoger la fila que representa a cada
        // familia: una familia sale si alguno de sus subarticulos cumple.
        $filter = function ($query) use ($filters, $storeId) {
            $query->where('products.company_id', TenantContext::requireCompanyId())
                ->whereNull('products.deleted_at')
                ->when($filters['search'], fn ($query, $search) => $query->where(function ($inner) use ($search) {
                    $inner->where('products.name', 'like', "%{$search}%")
                        ->orWhere('products.sku', 'like', "%{$search}%")
                        ->orWhere('products.barcode', 'like', "%{$search}%")
                        ->orWhere('products.brand', 'like', "%{$search}%");
                }))
                ->when($filters['category'], fn ($query, $category) => $query->where('products.category_id', $category))
                ->when($filters['supplier'], fn ($query, $supplier) => $query->where('products.supplier_id', $supplier))
                ->when($filters['status'] !== '', fn ($query) => $query->where('products.is_active', $filters['status'] === 'active'))
                ->when($filters['type'] === 'single', fn ($query) => $query->whereNull('products.product_family_id'))
                ->when($filters['type'] === 'family', fn ($query) => $query->whereNotNull('products.product_family_id'))
                // Se compara contra la suma de las bodegas de la sede: un articulo con cero en el
                // piso y doscientos atras no esta agotado, esta mal repuesto.
                ->when($filters['stock'] === 'low', fn ($query) => $query->whereRaw(
                    '(SELECT COALESCE(SUM(quantity), 0) FROM product_stocks WHERE product_stocks.product_id = products.id AND product_stocks.store_id = ?) <= products.min_stock',
                    [$storeId]
                ))
                ->when($filters['stock'] === 'out', fn ($query) => $query->whereRaw(
                    '(SELECT COALESCE(SUM(quantity), 0) FROM product_stocks WHERE product_stocks.product_id = products.id AND product_stocks.store_id = ?) <= 0',
                    [$storeId]
                ));
        };

        $products = Product::query()
            ->tap($filter)
            ->when($grouped, fn ($query) => $query->where(fn ($inner) => $inner
                ->whereNull('products.product_family_id')
                ->orWhereIn('products.id', Product::query()->tap($filter)->whereNotNull('products.product_family_id')->groupBy('products.product_family_id')->selectRaw('MIN(products.id)'))))
            ->with(['category:id,name', 'supplier:id,name'])
            ->withSum(['stocks as stock_quantity' => fn ($query) => $query->where('store_id', $storeId)], 'quantity')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $families = $grouped ? $this->familyRows($products->getCollection(), $storeId, $withCost) : [];

        $products->through(fn (Product $product) => $grouped && $product->product_family_id !== null && isset($families[$product->product_family_id])
            ? $families[$product->product_family_id]
            : $this->present($product, $withCost));

        return Inertia::render('Products/Index', [
            'products' => $products,
            'filters' => $filters,
            'categories' => $this->categoryOptions($companyId),
            'suppliers' => $this->supplierOptions($companyId),
            'canViewCost' => $request->user()->can('products.show.view_cost'),
        ]);
    }

    public function create(): Response
    {
        $companyId = TenantContext::requireCompanyId();

        return Inertia::render('Products/Form', [
            'mode' => 'create',
            'product' => null,
            'categories' => $this->categoryOptions($companyId),
            'suppliers' => $this->supplierOptions($companyId),
            'stores' => $this->storeOptions($companyId),
            'initialStocks' => [],
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $validated = $request->validated();
        $initialStocks = $validated['initial_stocks'] ?? [];
        unset($validated['initial_stocks']);

        $product = DB::transaction(function () use ($validated, $companyId, $initialStocks, $request) {
            $product = Product::create(array_merge($validated, ['company_id' => $companyId]));

            foreach ($initialStocks as $entry) {
                $quantity = (float) ($entry['quantity'] ?? 0);

                if ($quantity <= 0) {
                    continue;
                }

                $this->stock->move(
                    product: $product,
                    warehouse: $this->warehouses->receivingDefaultFor((int) $entry['store_id']),
                    type: StockMovement::TYPE_ADJUSTMENT,
                    quantity: $quantity,
                    userId: $request->user()->id,
                    note: 'Existencia inicial del articulo',
                    unitCost: $product->cost,
                );
            }

            return $product;
        });

        $this->syncImages($request, $product);

        return redirect()->route('products.show', $product)->with('success', 'El articulo quedo creado.');
    }

    public function show(Request $request, Product $product): Response
    {
        $this->authorizeCompany($product);

        $product->load(['category:id,name', 'supplier:id,name,phone,contact_name', 'images', 'family:id,name']);
        $canViewCost = $request->user()->can('products.show.view_cost');

        return Inertia::render('Products/Show', [
            'product' => array_merge($this->present($product, $canViewCost), [
                'description' => $product->description,
                'barcode' => $product->barcode,
                'brand' => $product->brand,
                'wholesale_price' => $product->wholesale_price,
                'online_price' => $product->online_price,
                'track_lots' => (bool) $product->track_lots,
                'allow_negative_stock' => (bool) $product->allow_negative_stock,
                'is_online_visible' => (bool) $product->is_online_visible,
                'is_featured' => (bool) $product->is_featured,
                'supplier' => $product->supplier ? [
                    'id' => $product->supplier->id,
                    'name' => $product->supplier->name,
                    'contact_name' => $product->supplier->contact_name,
                    'phone' => $product->supplier->phone,
                ] : null,
                'images' => $product->images->map(fn ($image) => ['id' => $image->id, 'path' => $image->path, 'thumb_path' => $image->thumb()])->values(),
                'family' => $product->family && ! $product->family->trashed() ? ['id' => $product->family->id, 'name' => $product->family->name, 'label' => $product->variant_label] : null,
            ]),
            'stocks' => $this->stocksByStore($product),
            'salesTrend' => $this->salesTrend($product),
            'movements' => $this->recentMovements($product),
            'canViewCost' => $canViewCost,
        ]);
    }

    public function edit(Product $product): Response
    {
        $this->authorizeCompany($product);

        $product->load('images');

        return Inertia::render('Products/Form', [
            'mode' => 'edit',
            'product' => [
                'id' => $product->id,
                'category_id' => $product->category_id,
                'supplier_id' => $product->supplier_id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'brand' => $product->brand,
                'unit' => $product->unit,
                'sold_by_weight' => (bool) $product->sold_by_weight,
                'cost' => $product->cost,
                'price' => $product->price,
                'wholesale_price' => $product->wholesale_price,
                'online_price' => $product->online_price,
                'tax_rate' => $product->tax_rate,
                'min_stock' => $product->min_stock,
                'track_lots' => (bool) $product->track_lots,
                'allow_negative_stock' => (bool) $product->allow_negative_stock,
                'is_active' => (bool) $product->is_active,
                'is_online_visible' => (bool) $product->is_online_visible,
                'is_featured' => (bool) $product->is_featured,
                'description' => $product->description,
                'family' => $product->product_family_id ? ['id' => $product->product_family_id, 'name' => $product->family?->name, 'label' => $product->variant_label] : null,
            ],
            'categories' => $this->categoryOptions($product->company_id),
            'suppliers' => $this->supplierOptions($product->company_id),
            'stores' => $this->storeOptions($product->company_id),
            'initialStocks' => $this->stocksByStore($product),
            'images' => $product->images->map(fn (ProductImage $image) => ['id' => $image->id, 'path' => $image->path, 'thumb_path' => $image->thumb()])->values(),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $this->authorizeCompany($product);

        $data = $request->validated();

        // Lo comun de un subarticulo lo escribe solo `ProductFamilyService`: el nombre compuesto,
        // la categoria, la marca, el proveedor principal y el IVA se editan en el principal.
        if ($product->product_family_id !== null) {
            $data = array_diff_key($data, array_flip(['name', ...ProductFamilyService::COMMON]));
        }

        $product->update($data);
        $this->syncImages($request, $product);

        return redirect()->route('products.show', $product)->with('success', 'El articulo quedo actualizado.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorizeCompany($product);

        if (SaleItem::query()->where('product_id', $product->id)->exists()) {
            return back()->with('error', 'Ese articulo ya tiene ventas: desactivalo en vez de eliminarlo.');
        }

        // Los articulos se borran suave, asi que la llave foranea no salta sola: sin esta guarda
        // el servicio quedaria con un componente que ya no existe.
        $services = Service::query()
            ->where('company_id', $product->company_id)
            ->where('is_active', true)
            ->whereHas('components', fn ($query) => $query->where('product_id', $product->id))
            ->orderBy('name')
            ->pluck('name');

        if ($services->isNotEmpty()) {
            return back()->with('error', 'Lo usa'.($services->count() === 1 ? ' 1 servicio' : 'n '.$services->count().' servicios').': '
                .$services->take(3)->implode(', ').($services->count() > 3 ? ', …' : '').'. Quítalo de ellos primero.');
        }

        if ($product->product_family_id !== null) {
            $familyId = $product->product_family_id;
            $deleted = $this->families->removeVariant($product);
            $stillThere = ProductFamily::query()->whereKey($familyId)->exists();

            return ($stillThere ? redirect()->route('products.families.show', $familyId) : redirect()->route('products.index'))
                ->with('success', $deleted ? 'El subartículo quedó eliminado.' : 'El subartículo tiene movimientos: quedó desactivado.');
        }

        $product->delete();

        return redirect()->route('products.index')->with('success', 'El articulo quedo eliminado.');
    }

    public function updatePrice(Request $request, Product $product): RedirectResponse
    {
        $this->authorizeCompany($product);

        $validated = $request->validate([
            'price' => ['required', 'integer', 'min:0'],
            'wholesale_price' => ['nullable', 'integer', 'min:0'],
            'online_price' => ['nullable', 'integer', 'min:0'],
        ], [
            'price.required' => 'El precio es obligatorio.',
            'price.min' => 'El precio no puede ser negativo.',
        ]);

        $product->update($validated);

        return back()->with('success', 'El precio quedo actualizado.');
    }

    /**
     * Acciones en lote del listado.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();

        $validated = $request->validate([
            'action' => ['required', Rule::in(['price_percent', 'category', 'deactivate', 'activate'])],
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer'],
            'percent' => ['required_if:action,price_percent', 'nullable', 'numeric', 'between:-90,200'],
            'category_id' => ['required_if:action,category', 'nullable', 'integer', Rule::exists('categories', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
        ], [
            'product_ids.required' => 'Selecciona al menos un articulo.',
            'percent.required_if' => 'Indica el porcentaje de cambio.',
            'category_id.required_if' => 'Elige la categoria destino.',
        ]);

        $products = Product::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $validated['product_ids'])
            ->get();

        if ($products->isEmpty()) {
            return back()->with('error', 'No se encontro ninguno de los articulos seleccionados.');
        }

        DB::transaction(function () use ($products, $validated) {
            // La categoria de un subarticulo es la de su familia: se cambia alla y se propaga.
            if ($validated['action'] === 'category') {
                $families = ProductFamily::query()->whereIn('id', $products->pluck('product_family_id')->filter()->unique())->get();

                foreach ($families as $family) {
                    $family->update(['category_id' => $validated['category_id']]);
                    $this->families->syncCommon($family);
                }

                $products = $products->whereNull('product_family_id');
            }

            foreach ($products as $product) {
                match ($validated['action']) {
                    'price_percent' => $product->update([
                        'price' => max(0, (int) round($product->price * (1 + $validated['percent'] / 100))),
                    ]),
                    'category' => $product->update(['category_id' => $validated['category_id']]),
                    'deactivate' => $product->update(['is_active' => false]),
                    'activate' => $product->update(['is_active' => true]),
                };
            }
        });

        return back()->with('success', $products->count().' articulos actualizados.');
    }

    public function export(Request $request): StreamedResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $canViewCost = $request->user()->can('products.show.view_cost');

        $products = Product::query()
            ->where('company_id', $companyId)
            ->with(['category:id,name', 'supplier:id,name', 'family.attribute1:id,name', 'family.attribute2:id,name', 'value1:id,value', 'value2:id,value'])
            ->orderBy('name')
            ->get();

        return response()->streamDownload(function () use ($products, $canViewCost) {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");

            // Las ultimas cinco son las de la importacion: exportar y volver a importar da lo mismo.
            $header = ['SKU', 'Codigo de barras', 'Nombre', 'Marca', 'Categoria', 'Proveedor', 'Unidad', 'IVA', 'Precio', 'Minimo', 'Activo', 'Articulo principal', 'Atributo 1', 'Valor 1', 'Atributo 2', 'Valor 2'];

            if ($canViewCost) {
                array_splice($header, 8, 0, 'Costo');
            }

            fputcsv($handle, $header, ';');

            foreach ($products as $product) {
                $row = [
                    $product->sku,
                    $product->barcode,
                    $product->name,
                    $product->brand,
                    $product->category?->name,
                    $product->supplier?->name,
                    $product->unit,
                    $product->tax_rate,
                    $product->price,
                    $product->min_stock,
                    $product->is_active ? 'Si' : 'No',
                    $product->family ? ($product->family->code ?: $product->family->name) : null,
                    $product->family?->attribute1?->name,
                    $product->family ? $product->value1?->value : null,
                    $product->family?->attribute2?->name,
                    $product->family ? $product->value2?->value : null,
                ];

                if ($canViewCost) {
                    array_splice($row, 8, 0, $product->cost);
                }

                fputcsv($handle, $row, ';');
            }

            fclose($handle);
        }, 'catalogo-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Etiquetas de precio en PDF, tres por fila.
     */
    public function labels(Request $request)
    {
        $companyId = TenantContext::requireCompanyId();

        $validated = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer'],
        ], ['product_ids.required' => 'Selecciona al menos un articulo.']);

        $products = Product::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $validated['product_ids'])
            ->orderBy('name')
            ->get();

        $pdf = Pdf::loadView('pdf.product-labels', [
            'products' => $products,
            'company' => TenantContext::company(),
        ])->setPaper('letter');

        return $pdf->download('etiquetas-'.now()->format('Y-m-d').'.pdf');
    }

    public function destroyImage(Request $request, Product $product, ProductImage $image): RedirectResponse
    {
        $this->authorizeCompany($product);

        abort_if($image->product_id !== $product->id, 403, 'Esa imagen no es de este articulo.');

        Storage::disk('public')->delete(array_filter([$image->path, $image->thumb_path]));

        if ($product->image_path === $image->path) {
            // Si se borra la portada, la siguiente imagen toma su lugar.
            $next = $product->images()->whereKeyNot($image->id)->first();
            $product->update(['image_path' => $next?->path, 'thumb_path' => $next?->thumb_path]);
        }

        $image->delete();

        return back()->with('success', 'La imagen quedo eliminada.');
    }

    /**
     * Guarda las imagenes que llegaron con el formulario.
     *
     * La primera imagen del articulo queda como portada: es la que se ve en el listado, en
     * el punto de venta y en la tienda en linea.
     */
    protected function syncImages(Request $request, Product $product): void
    {
        $files = $request->file('images', []);

        if (! is_array($files) || $files === []) {
            return;
        }

        $sort = (int) $product->images()->max('sort_order');

        foreach ($files as $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }

            $stored = $this->images->storeProductImage($file, (int) $product->company_id);

            ProductImage::create([
                'product_id' => $product->id,
                'path' => $stored['path'],
                'thumb_path' => $stored['thumb_path'],
                'sort_order' => ++$sort,
            ]);

            if (! $product->image_path) {
                $product->update(['image_path' => $stored['path'], 'thumb_path' => $stored['thumb_path']]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Product $product, bool $withCost): array
    {
        $data = [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'brand' => $product->brand,
            'unit' => $product->unit,
            'sold_by_weight' => (bool) $product->sold_by_weight,
            'price' => $product->price,
            'tax_rate' => (float) $product->tax_rate,
            'min_stock' => $product->min_stock,
            'is_active' => (bool) $product->is_active,
            'is_online_visible' => (bool) $product->is_online_visible,
            'image_path' => $product->image_path,
            'thumb_path' => $product->thumb(),
            'category' => $product->category ? ['id' => $product->category->id, 'name' => $product->category->name] : null,
            'supplier_name' => $product->supplier?->name,
            'family_id' => $product->product_family_id,
            'variant_label' => $product->variant_label,
            'stock_quantity' => round((float) ($product->stock_quantity ?? 0), 3),
        ];

        if ($withCost) {
            $data['cost'] = $product->cost;
            $data['margin'] = $product->margin;
        }

        return $data;
    }

    /**
     * La fila de cada familia de la pagina: cuantos subarticulos, rango de precios, existencia
     * sumada de la sede y los subarticulos para desplegarla.
     *
     * @param  \Illuminate\Support\Collection<int, Product>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function familyRows($rows, ?int $storeId, bool $withCost): array
    {
        $ids = $rows->pluck('product_family_id')->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $families = ProductFamily::query()->whereIn('id', $ids)->with('category:id,name')->get()->keyBy('id');
        $variants = Product::query()
            ->whereIn('product_family_id', $ids)
            ->with(['category:id,name', 'supplier:id,name'])
            ->withSum(['stocks as stock_quantity' => fn ($query) => $query->where('store_id', $storeId)], 'quantity')
            ->orderBy('variant_sort')
            ->get()
            ->groupBy('product_family_id');

        $result = [];

        foreach ($families as $family) {
            $children = $variants->get($family->id, collect());
            $active = $children->where('is_active', true);
            $prices = ($active->isNotEmpty() ? $active : $children)->pluck('price');
            $first = $children->first();

            $result[$family->id] = [
                'kind' => 'family',
                'id' => $family->id,
                'name' => $family->name,
                'sku' => $family->code,
                'brand' => $family->brand,
                'price' => (int) $prices->min(),
                'price_max' => (int) $prices->max(),
                'is_active' => (bool) $family->is_active,
                'is_online_visible' => (bool) $family->is_online_visible,
                'thumb_path' => $first?->thumb(),
                'category' => $family->category ? ['id' => $family->category->id, 'name' => $family->category->name] : null,
                'variants_count' => $children->count(),
                'stock_quantity' => round((float) $children->sum('stock_quantity'), 3),
                'variants' => $children->map(fn (Product $variant) => $this->present($variant, $withCost))->values()->all(),
            ];
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function stocksByStore(Product $product): array
    {
        $stores = Store::query()->where('company_id', $product->company_id)->orderBy('name')->get();
        $stocks = ProductStock::query()->where('product_id', $product->id)->get()->groupBy('store_id');
        $warehouses = $this->warehouses->forCompany($product->company_id)->groupBy('store_id');

        return $stores->map(function (Store $store) use ($stocks, $warehouses) {
            $rows = $stocks->get($store->id, collect())->keyBy('warehouse_id');

            $breakdown = $warehouses->get($store->id, collect())
                ->map(function (Warehouse $warehouse) use ($rows) {
                    $stock = $rows->get($warehouse->id);

                    return [
                        'warehouse_id' => $warehouse->id,
                        'warehouse_name' => $warehouse->name,
                        'quantity' => round((float) ($stock->quantity ?? 0), 3),
                        'reserved_quantity' => round((float) ($stock->reserved_quantity ?? 0), 3),
                        'location' => $stock->location ?? null,
                    ];
                })
                ->values()
                ->all();

            return [
                'store_id' => $store->id,
                'store_name' => $store->name,
                'quantity' => round((float) $rows->sum('quantity'), 3),
                'reserved_quantity' => round((float) $rows->sum('reserved_quantity'), 3),
                'location' => $rows->first()?->location,
                'warehouses' => $breakdown,
            ];
        })->values()->all();
    }

    /**
     * @return list<array{date: string, quantity: float, total: int}>
     */
    protected function salesTrend(Product $product): array
    {
        $from = CarbonImmutable::today()->subDays(13);

        $rows = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sale_items.product_id', $product->id)
            // Lo que salio incluido en un servicio cerrado es consumo, no venta del articulo.
            ->whereRaw(SaleItem::COUNTS_AS_SOLD_SQL)
            ->where('sales.status', '!=', Sale::STATUS_CANCELLED)
            ->where('sales.sold_at', '>=', $from->startOfDay())
            ->groupByRaw('DATE(sales.sold_at)')
            ->selectRaw('DATE(sales.sold_at) as day, SUM(sale_items.quantity) as quantity, SUM(sale_items.line_total) as total')
            ->get()
            ->keyBy('day');

        $series = [];

        for ($offset = 0; $offset < 14; $offset++) {
            $day = $from->addDays($offset);
            $key = $day->toDateString();
            $row = $rows->get($key);

            $series[] = [
                'date' => $key,
                'label' => $day->format('d/m'),
                'quantity' => round((float) ($row->quantity ?? 0), 3),
                'total' => (int) ($row->total ?? 0),
            ];
        }

        return $series;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function recentMovements(Product $product): array
    {
        $movements = StockMovement::query()
            ->where('product_id', $product->id)
            ->with(['store:id,name', 'user:id,name,last_name'])
            ->orderByDesc('occurred_at')
            ->limit(12)
            ->get();
        $links = StockMovement::referenceLinks($movements);

        return $movements
            ->map(fn (StockMovement $movement) => [
                'id' => $movement->id,
                'type' => $movement->type,
                'type_label' => $movement->typeLabel(),
                'quantity' => round((float) $movement->quantity, 3),
                'balance_after' => round((float) $movement->balance_after, 3),
                'store' => $movement->store?->name,
                'user' => $movement->user?->full_name,
                'note' => $movement->note,
                'reference' => $links[$movement->id] ?? null,
                'occurred_at' => $movement->occurred_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    protected function categoryOptions(int $companyId): array
    {
        return Category::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category) => ['value' => $category->id, 'label' => $category->name])
            ->values()
            ->all();
    }

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
            ->map(fn (Supplier $supplier) => ['value' => $supplier->id, 'label' => $supplier->name])
            ->values()
            ->all();
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    protected function storeOptions(int $companyId): array
    {
        return Store::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Store $store) => ['value' => $store->id, 'label' => $store->name])
            ->values()
            ->all();
    }

    protected function authorizeCompany(Product $product): void
    {
        abort_if(
            ! auth()->user()->isSuperAdmin() && $product->company_id !== TenantContext::activeCompanyId(),
            403,
            'Ese articulo no pertenece a tu empresa.'
        );
    }
}

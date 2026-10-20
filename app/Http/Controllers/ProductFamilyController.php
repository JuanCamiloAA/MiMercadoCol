<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductFamilies\ConvertProductToFamilyRequest;
use App\Http\Requests\ProductFamilies\GroupProductsRequest;
use App\Http\Requests\ProductFamilies\ProductFamilyRequest;
use App\Http\Requests\ProductFamilies\StoreProductFamilyRequest;
use App\Http\Requests\ProductFamilies\UpdateProductFamilyRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductFamily;
use App\Models\ProductFamilyImage;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Store;
use App\Models\Supplier;
use App\Services\ProductAttributeCatalog;
use App\Services\Catalog\CatalogImages;
use App\Services\ProductFamilyService;
use App\Support\TenantContext;
use App\Support\Uploads;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Articulos con subarticulos en el panel: la matriz para crear, editar, convertir y agrupar; la
 * ficha con la existencia por sede; separar un subarticulo y deshacer la agrupacion.
 *
 * Todo lo que escribe pasa por `ProductFamilyService`.
 */
class ProductFamilyController extends Controller
{
    public function __construct(
        protected ProductFamilyService $families,
        protected CatalogImages $catalogImages,
        protected ProductAttributeCatalog $attributes,
    ) {}

    public function create(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();

        return $this->form($request, $companyId, 'create', null, []);
    }

    public function store(StoreProductFamilyRequest $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $family = $this->families->create($request->familyData(), $request->user(), $companyId);
        $this->syncImages($request, $family);

        return redirect()->route('products.families.show', $family)->with('success', 'El artículo quedó creado con sus subartículos.');
    }

    public function show(Request $request, ProductFamily $family): Response
    {
        $this->authorizeCompany($family);

        $family->load(['category:id,name', 'supplier:id,name', 'attribute1.values', 'attribute2.values', 'images']);
        $variants = $family->variants()->with(['value1', 'value2'])->get();
        $ids = $variants->pluck('id')->all();
        $canViewCost = $request->user()->can('products.show.view_cost');

        $stores = Store::query()->where('company_id', $family->company_id)->orderBy('name')->get(['id', 'name']);

        // Cuentas por sede, no por fila: se suman las bodegas de cada sede.
        $stock = ProductStock::query()
            ->whereIn('product_id', $ids)
            ->groupBy('product_id', 'store_id')
            ->selectRaw('product_id, store_id, SUM(quantity) as quantity, SUM(reserved_quantity) as reserved')
            ->get()
            ->groupBy('product_id');

        $sold = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereIn('sale_items.product_id', $ids)
            ->whereRaw(SaleItem::COUNTS_AS_SOLD_SQL)
            ->where('sales.status', '!=', Sale::STATUS_CANCELLED)
            ->where('sales.sold_at', '>=', now()->subDays(30))
            ->groupBy('sale_items.product_id')
            ->selectRaw('sale_items.product_id, SUM(sale_items.quantity) as quantity, SUM(sale_items.line_total) as total')
            ->get()
            ->keyBy('product_id');

        return Inertia::render('Products/FamilyShow', [
            'family' => [
                'id' => $family->id,
                'name' => $family->name,
                'code' => $family->code,
                'slug' => $family->slug,
                'brand' => $family->brand,
                'description' => $family->description,
                'tax_rate' => (float) $family->tax_rate,
                'category' => $family->category?->name,
                'supplier' => $family->supplier?->name,
                'is_active' => (bool) $family->is_active,
                'is_online_visible' => (bool) $family->is_online_visible,
                'is_featured' => (bool) $family->is_featured,
                'default_variant_id' => $family->default_variant_id,
                'attribute_1' => $family->attribute1 ? ['id' => $family->attribute1->id, 'name' => $family->attribute1->name] : null,
                'attribute_2' => $family->attribute2 ? ['id' => $family->attribute2->id, 'name' => $family->attribute2->name] : null,
                'images' => $family->images->map(fn (ProductFamilyImage $image) => [
                    'id' => $image->id,
                    'url' => Uploads::url($image->thumb()),
                    'value_id' => $image->attribute_value_id,
                ])->values(),
            ],
            'variants' => $variants->map(fn (Product $variant) => array_merge([
                'id' => $variant->id,
                'name' => $variant->name,
                'label' => $variant->variant_label,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'price' => $variant->price,
                'online_price' => $variant->online_price,
                'min_stock' => $variant->min_stock,
                'is_active' => (bool) $variant->is_active,
                'is_online_visible' => (bool) $variant->is_online_visible,
                'value_1' => $variant->value1 ? ['id' => $variant->value1->id, 'value' => $variant->value1->value] : null,
                'value_2' => $variant->value2 ? ['id' => $variant->value2->id, 'value' => $variant->value2->value] : null,
                'stock' => $stores->mapWithKeys(fn (Store $store) => [
                    $store->id => round((float) ($stock->get($variant->id)?->firstWhere('store_id', $store->id)?->quantity ?? 0), 3),
                ]),
                'sold_30' => round((float) ($sold->get($variant->id)?->quantity ?? 0), 3),
                'sold_30_total' => (int) ($sold->get($variant->id)?->total ?? 0),
            ], $canViewCost ? ['cost' => $variant->cost] : []))->values(),
            'stores' => $stores->map(fn (Store $store) => ['id' => $store->id, 'name' => $store->name])->values(),
            'canViewCost' => $canViewCost,
        ]);
    }

    public function edit(Request $request, ProductFamily $family): Response
    {
        $this->authorizeCompany($family);

        return $this->form($request, (int) $family->company_id, 'edit', $family, $family->variants()->get()->all());
    }

    public function update(UpdateProductFamilyRequest $request, ProductFamily $family): RedirectResponse
    {
        $this->authorizeCompany($family);

        $this->families->update($family, $request->familyData(), $request->user());
        $this->syncImages($request, $family);

        return redirect()->route('products.families.show', $family)->with('success', 'El artículo quedó actualizado.');
    }

    /** La matriz con el articulo individual como primera fila, para volverlo subarticulo. */
    public function convertForm(Request $request, Product $product): Response|RedirectResponse
    {
        $this->authorizeProduct($product);

        if ($product->product_family_id !== null) {
            return redirect()->route('products.families.show', $product->product_family_id);
        }

        return $this->form($request, (int) $product->company_id, 'convert', null, [$product]);
    }

    public function convert(ConvertProductToFamilyRequest $request, Product $product): RedirectResponse
    {
        $this->authorizeProduct($product);

        $family = $this->families->convertToFamily($product, $request->familyData(), $request->user());
        $this->syncImages($request, $family);

        return redirect()->route('products.families.show', $family)->with('success', "{$product->name} ahora es un subartículo: conserva su existencia, su kardex y sus ventas.");
    }

    /** Los articulos elegidos en el listado, cada uno como una fila a la que se le ponen sus valores. */
    public function groupForm(Request $request): Response|RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $ids = collect((array) $request->input('ids', []))->map(fn ($id) => (int) $id)->filter()->unique()->values();

        $products = Product::query()
            ->where('company_id', $companyId)
            ->whereNull('product_family_id')
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get();

        if ($products->count() < 2) {
            return redirect()->route('products.index')->with('error', 'Elige al menos dos artículos individuales para agruparlos.');
        }

        return $this->form($request, $companyId, 'group', null, $products->all());
    }

    public function group(GroupProductsRequest $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $family = $this->families->group($request->familyData(), $request->user(), $companyId);
        $this->syncImages($request, $family);

        return redirect()->route('products.families.show', $family)->with('success', 'Los artículos quedaron agrupados: cada uno conserva su existencia y sus ventas.');
    }

    /** Un subarticulo vuelve a ser individual. */
    public function detach(Product $product): RedirectResponse
    {
        $this->authorizeProduct($product);

        if ($product->product_family_id === null) {
            return back()->with('error', 'Ese artículo no es un subartículo.');
        }

        $familyId = $product->product_family_id;
        $this->families->detach($product);

        $stillThere = ProductFamily::query()->whereKey($familyId)->exists();

        return ($stillThere ? redirect()->route('products.families.show', $familyId) : redirect()->route('products.show', $product))
            ->with('success', "{$product->name} quedó como artículo individual.");
    }

    /** Deshace la agrupacion: todos vuelven a ser individuales. */
    public function ungroup(ProductFamily $family): RedirectResponse
    {
        $this->authorizeCompany($family);

        $this->families->ungroup($family);

        return redirect()->route('products.index')->with('success', 'La agrupación quedó deshecha: cada subartículo es ahora un artículo individual.');
    }

    public function destroyImage(ProductFamily $family, ProductFamilyImage $image): RedirectResponse
    {
        $this->authorizeCompany($family);

        abort_if($image->product_family_id !== $family->id, 403, 'Esa foto no es de este artículo.');

        $this->catalogImages->deleteFiles($image);
        $image->delete();

        return back()->with('success', 'La foto quedó eliminada.');
    }

    /** La foto comun que va primero: la de la tarjeta y la que abre el carrusel. */
    public function coverImage(ProductFamily $family, ProductFamilyImage $image): RedirectResponse
    {
        $this->authorizeCompany($family);

        abort_if($image->product_family_id !== $family->id, 403, 'Esa foto no es de este artículo.');

        $this->catalogImages->makeFirst($family->images(), $image);

        return back()->with('success', 'La portada quedó cambiada.');
    }

    /**
     * La matriz: crear, editar, convertir y agrupar usan la misma pantalla.
     *
     * @param  list<Product>  $members  Las filas que ya existen como articulos.
     */
    protected function form(Request $request, int $companyId, string $mode, ?ProductFamily $family, array $members): Response
    {
        $first = $members[0] ?? null;
        $source = $family ?? $first;
        $canViewCost = $request->user()->can('products.show.view_cost');

        return Inertia::render('Products/FamilyForm', [
            'mode' => $mode,
            'family' => [
                'id' => $family?->id,
                'name' => $family?->name ?? ($mode === 'convert' ? $first?->name : ''),
                'code' => $family?->code,
                'category_id' => $source?->category_id,
                'brand' => $source?->brand,
                'supplier_id' => $source?->supplier_id,
                'tax_rate' => $source ? (float) $source->tax_rate : 19,
                'description' => $source?->description,
                'attribute_1_id' => $family?->attribute_1_id,
                'attribute_2_id' => $family?->attribute_2_id,
                'is_active' => $family ? (bool) $family->is_active : true,
                'is_online_visible' => $family ? (bool) $family->is_online_visible : true,
                'is_featured' => $family ? (bool) $family->is_featured : false,
                'default_variant_id' => $family?->default_variant_id,
            ],
            'rows' => collect($members)->map(fn (Product $product) => array_merge([
                'id' => $product->id,
                'name' => $product->name,
                'value_1_id' => $product->variant_value_1_id,
                'value_2_id' => $product->variant_value_2_id,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'price' => $product->price,
                'online_price' => $product->online_price,
                'wholesale_price' => $product->wholesale_price,
                'content_amount' => $product->content_amount,
                'content_unit' => $product->content_unit,
                'min_stock' => $product->min_stock,
                'is_active' => (bool) $product->is_active,
                'is_online_visible' => (bool) $product->is_online_visible,
                'unit' => $product->unit,
                'sold_by_weight' => (bool) $product->sold_by_weight,
                'track_lots' => (bool) $product->track_lots,
                'allow_negative_stock' => (bool) $product->allow_negative_stock,
                'purchase_pack_size' => $product->purchase_pack_size,
                'description' => $mode === 'edit' ? $product->description : null,
                'has_movements' => $mode === 'edit' && $this->families->hasMovements($product),
            ], $canViewCost ? ['cost' => $product->cost] : []))->values(),
            'attributes' => $this->attributes->catalogFor($companyId, onlyActive: true),
            'images' => $family ? $family->images()->get()->map(fn (ProductFamilyImage $image, int $index) => [
                'id' => $image->id,
                'url' => Uploads::url($image->thumb()),
                'value_id' => $image->attribute_value_id,
                'is_cover' => $index === 0,
            ])->values() : [],
            // Cuantas fotos comunes se le montan (Ajustes > Catalogo).
            'imageLimit' => $this->catalogImages->limit($companyId),
            'categories' => Category::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('sort_order')->orderBy('name')
                ->get()->map(fn (Category $category) => ['value' => $category->id, 'label' => $category->name])->values(),
            'suppliers' => Supplier::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')
                ->get()->map(fn (Supplier $supplier) => ['value' => $supplier->id, 'label' => $supplier->name])->values(),
            'stores' => Store::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')
                ->get()->map(fn (Store $store) => ['value' => $store->id, 'label' => $store->name])->values(),
            'units' => collect(Product::UNIT_LABELS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'contentUnits' => collect(Product::CONTENT_UNITS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'taxRates' => collect(Product::TAX_LABELS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'canManageAttributes' => $request->user()->can('products.attributes.manage'),
            'canViewCost' => $canViewCost,
            'canEditPrice' => $request->user()->can('products.show.edit_price'),
        ]);
    }

    /**
     * Las fotos comunes que llegaron con el formulario, cada una con el valor (un color) que
     * muestra, si lo tiene.
     */
    protected function syncImages(ProductFamilyRequest $request, ProductFamily $family): void
    {
        $files = $request->file('images', []);

        if (! is_array($files) || $files === []) {
            return;
        }

        // Solo un valor de sus propias caracteristicas: la foto de un color de este articulo.
        $allowed = ProductAttributeValue::query()->whereIn('product_attribute_id', array_filter([$family->attribute_1_id, $family->attribute_2_id]))->pluck('id')->all();
        $values = $request->imageValues()->map(fn (?int $id) => in_array($id, $allowed, true) ? $id : null);

        // Cuantas caben ya lo midio el formulario contra el tope de la empresa (`CatalogImages`).
        DB::transaction(fn () => $this->catalogImages->append(
            $family->images(),
            $files,
            (int) $family->company_id,
            'products',
            fn (int $index) => ['attribute_value_id' => $values->get($index)],
        ));
    }

    protected function authorizeCompany(ProductFamily $family): void
    {
        abort_if(
            ! auth()->user()->isSuperAdmin() && $family->company_id !== TenantContext::activeCompanyId(),
            403,
            'Ese artículo no pertenece a tu empresa.'
        );
    }

    protected function authorizeProduct(Product $product): void
    {
        abort_if(
            ! auth()->user()->isSuperAdmin() && $product->company_id !== TenantContext::activeCompanyId(),
            403,
            'Ese artículo no pertenece a tu empresa.'
        );
    }
}

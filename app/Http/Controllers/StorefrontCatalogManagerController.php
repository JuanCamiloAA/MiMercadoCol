<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Service;
use App\Services\ProductFamilyService;
use App\Services\PromotionService;
use App\Services\ServicePricing;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Que se publica en la tienda en linea.
 *
 * Es una pantalla aparte del catalogo porque decidir el surtido de la tienda es una
 * decision comercial, no una edicion de ficha.
 */
class StorefrontCatalogManagerController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();

        $filters = [
            'search' => $request->string('search')->toString(),
            'category' => $request->string('category')->toString(),
            'state' => $request->string('state')->toString(),
            'type' => in_array($request->string('type')->toString(), ['services', 'families'], true) ? $request->string('type')->toString() : 'products',
            // Los subarticulos de un articulo, desde la pestaña de articulos con subarticulos.
            'family' => $request->string('family')->toString(),
        ];

        if ($filters['type'] === 'services') {
            return $this->services($companyId, $filters);
        }

        if ($filters['type'] === 'families') {
            return $this->families($companyId, $filters);
        }

        $products = Product::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->with(['category:id,name', 'family:id,name'])
            ->when($filters['family'], fn ($query, $family) => $query->where('product_family_id', $family))
            ->when($filters['search'], fn ($query, $search) => $query->where(function ($inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%");
            }))
            ->when($filters['category'], fn ($query, $category) => $query->where('category_id', $category))
            ->when($filters['state'] === 'published', fn ($query) => $query->where('is_online_visible', true))
            ->when($filters['state'] === 'hidden', fn ($query) => $query->where('is_online_visible', false))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'category' => $product->category?->name,
                'price' => $product->price,
                'online_price' => $product->online_price,
                'is_online_visible' => (bool) $product->is_online_visible,
                'is_featured' => (bool) $product->is_featured,
                // Un subarticulo oculto es una presentacion que no se ofrece; el articulo sigue publicado.
                'family' => $product->family?->name,
                'variant_label' => $product->variant_label,
            ]);

        return Inertia::render('Storefront/Manage', [
            'products' => $products,
            'filters' => $filters,
            'types' => $this->types(),
            'states' => $this->states(),
            'categories' => Category::query()
                ->where('company_id', $companyId)
                ->orderBy('name')
                ->get()
                ->map(fn (Category $category) => ['value' => $category->id, 'label' => $category->name])
                ->values(),
            'metrics' => [
                'published' => (int) Product::query()->where('company_id', $companyId)->where('is_online_visible', true)->count(),
                'featured' => (int) Product::query()->where('company_id', $companyId)->where('is_featured', true)->count(),
                'total' => (int) Product::query()->where('company_id', $companyId)->where('is_active', true)->count(),
            ],
        ]);
    }

    /**
     * La misma pantalla con los servicios: se publican y destacan igual que un articulo.
     *
     * @param  array<string, string>  $filters
     */
    protected function services(int $companyId, array $filters): Response
    {
        $pricing = app(ServicePricing::class);

        $services = Service::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->with(['category:id,name', 'components.product'])
            ->when($filters['search'], fn ($query, $search) => $query->where(fn ($inner) => $inner->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->when($filters['category'], fn ($query, $category) => $query->where('category_id', $category))
            ->when($filters['state'] === 'published', fn ($query) => $query->where('is_online_visible', true))
            ->when($filters['state'] === 'hidden', fn ($query) => $query->where('is_online_visible', false))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Service $service) => [
                'id' => $service->id,
                'name' => $service->name,
                'sku' => $service->code,
                'category' => $service->category?->name,
                'price' => $service->open_price ? 0 : $pricing->priceFor($service, PromotionService::CHANNEL_POS),
                'online_price' => $service->open_price ? null : $pricing->priceFor($service, PromotionService::CHANNEL_ONLINE),
                'is_online_visible' => (bool) $service->is_online_visible,
                'is_featured' => (bool) $service->is_featured,
                'open_price' => (bool) $service->open_price,
            ]);

        return Inertia::render('Storefront/Manage', [
            'products' => $services,
            'filters' => $filters,
            'types' => $this->types(),
            'states' => $this->states(),
            'categories' => Category::query()
                ->where('company_id', $companyId)
                ->orderBy('name')
                ->get()
                ->map(fn (Category $category) => ['value' => $category->id, 'label' => $category->name])
                ->values(),
            'metrics' => [
                'published' => (int) Service::query()->where('company_id', $companyId)->where('is_online_visible', true)->count(),
                'featured' => (int) Service::query()->where('company_id', $companyId)->where('is_featured', true)->count(),
                'total' => (int) Service::query()->where('company_id', $companyId)->where('is_active', true)->count(),
            ],
        ]);
    }

    /**
     * Los articulos con subarticulos: en la tienda son una sola tarjeta, y esa tarjeta se publica y
     * se destaca aqui. Que presentaciones se ofrecen se decide en la pestaña de articulos.
     *
     * @param  array<string, string>  $filters
     */
    protected function families(int $companyId, array $filters): Response
    {
        $active = fn ($query) => $query->where('is_active', true);

        $families = ProductFamily::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->with('category:id,name')
            ->withCount(['variants as variants_total' => $active, 'variants as variants_published' => fn ($query) => $query->where('is_active', true)->where('is_online_visible', true)])
            ->withMin(['variants as price_from' => $active], 'price')
            ->withMin(['variants as online_from' => $active], 'online_price')
            ->when($filters['search'], fn ($query, $search) => $query->where(fn ($inner) => $inner->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->when($filters['category'], fn ($query, $category) => $query->where('category_id', $category))
            ->when($filters['state'] === 'published', fn ($query) => $query->where('is_online_visible', true))
            ->when($filters['state'] === 'hidden', fn ($query) => $query->where('is_online_visible', false))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ProductFamily $family) => [
                'id' => $family->id,
                'name' => $family->name,
                'sku' => $family->code ?? '',
                'category' => $family->category?->name,
                'price' => (int) $family->price_from,
                'online_price' => $family->online_from !== null ? (int) $family->online_from : null,
                'is_online_visible' => (bool) $family->is_online_visible,
                'is_featured' => (bool) $family->is_featured,
                'variants_total' => (int) $family->variants_total,
                'variants_published' => (int) $family->variants_published,
            ]);

        return Inertia::render('Storefront/Manage', [
            'products' => $families,
            'filters' => $filters,
            'types' => $this->types(),
            'states' => $this->states(),
            'categories' => Category::query()
                ->where('company_id', $companyId)
                ->orderBy('name')
                ->get()
                ->map(fn (Category $category) => ['value' => $category->id, 'label' => $category->name])
                ->values(),
            'metrics' => [
                'published' => (int) ProductFamily::query()->where('company_id', $companyId)->where('is_active', true)->where('is_online_visible', true)->count(),
                'featured' => (int) ProductFamily::query()->where('company_id', $companyId)->where('is_active', true)->where('is_featured', true)->count(),
                'total' => (int) ProductFamily::query()->where('company_id', $companyId)->where('is_active', true)->count(),
            ],
        ]);
    }

    /** @return list<array{value: string, label: string}> */
    protected function types(): array
    {
        return [
            ['value' => 'products', 'label' => 'Artículos'],
            ['value' => 'families', 'label' => 'Con subartículos'],
            ['value' => 'services', 'label' => 'Servicios'],
        ];
    }

    /** @return list<array{value: string, label: string}> */
    protected function states(): array
    {
        return [['value' => 'published', 'label' => 'Publicados'], ['value' => 'hidden', 'label' => 'Ocultos']];
    }

    public function update(Request $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();

        $validated = $request->validate([
            // La peticion dice si son articulos o servicios: los ids de una tabla no sirven en la otra.
            'type' => ['nullable', 'in:product,service,family'],
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer'],
            'field' => ['required', 'in:is_online_visible,is_featured'],
            'value' => ['required', 'boolean'],
        ], ['product_ids.required' => 'Selecciona al menos uno.']);

        if (($validated['type'] ?? 'product') === 'family') {
            app(ProductFamilyService::class)->setFlag($companyId, $validated['product_ids'], $validated['field'], (bool) $validated['value']);

            return back()->with('success', 'La tienda en linea quedo actualizada.');
        }

        if (($validated['type'] ?? 'product') === 'service') {
            $query = DB::table('services')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->whereIn('id', $validated['product_ids']);

            // Un servicio de precio variable no se publica: el precio se digita en la caja.
            if ($validated['field'] === 'is_online_visible' && $validated['value']) {
                $blocked = (clone $query)->where('open_price', true)->count();
                $query->where('open_price', false);
                $query->update([$validated['field'] => true]);

                return back()->with(
                    $blocked > 0 ? 'warning' : 'success',
                    $blocked > 0 ? 'Listo. Los de precio variable no se publican: su precio se digita en la caja.' : 'La tienda en linea quedo actualizada.',
                );
            }

            $query->update([$validated['field'] => $validated['value']]);

            return back()->with('success', 'La tienda en linea quedo actualizada.');
        }

        DB::table('products')
            ->where('company_id', $companyId)
            ->whereIn('id', $validated['product_ids'])
            ->update([$validated['field'] => $validated['value']]);

        return back()->with('success', 'La tienda en linea quedo actualizada.');
    }
}

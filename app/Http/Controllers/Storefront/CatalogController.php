<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Store;
use App\Models\StorefrontBlock;
use App\Services\PromotionService;
use App\Services\Storefront\StorefrontConfig;
use App\Services\Storefront\StorefrontListing;
use App\Services\Storefront\StorefrontProducts;
use App\Services\Storefront\StorefrontRenderer;
use App\Services\Storefront\StorefrontServiceCatalog;
use App\Services\Storefront\StorefrontStructure;
use App\Support\SearchText;
use App\Support\StorefrontContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El catalogo de la tienda en linea: portada, categoria, ofertas, busqueda y fichas.
 *
 * El cascaron (barra superior, encabezado, menu, pie) y el resumen del carrito viajan como
 * props compartidas (`storefront`, `cart`); aqui solo va lo propio de cada pagina.
 */
class CatalogController extends Controller
{
    public function __construct(
        protected StorefrontRenderer $renderer,
        protected StorefrontConfig $config,
        protected StorefrontListing $listing,
        protected StorefrontProducts $products,
        protected StorefrontServiceCatalog $services,
        protected StorefrontStructure $structure,
        protected PromotionService $promotions,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $store = StorefrontContext::requireStore();

        // Los enlaces de antes (`/tienda?category=3`, `/tienda?search=arroz`) siguen sirviendo.
        if ($request->filled('category')) {
            $slug = Category::query()->withoutGlobalScopes()->where('company_id', $store->company_id)->whereKey($request->integer('category'))->value('slug');

            return $slug ? redirect()->route('storefront.category', $slug) : redirect()->route('storefront.search');
        }

        if ($request->filled('search')) {
            return redirect()->route('storefront.search', ['q' => $request->string('search')->toString()]);
        }

        $settings = $this->config->settings($store);
        $blocks = $this->renderer->published($store, StorefrontBlock::PAGE_HOME, $this->context())['blocks'];

        return Inertia::render('Storefront/Home', [
            // La portada la arma el editor. Sin bloques publicados, la tienda no queda en blanco:
            // salen las ofertas y los destacados.
            'blocks' => $blocks,
            'fallback' => $blocks === [] ? [
                'deals' => $this->listing->list($store, ['deals' => true], ['sort' => 'price_asc'], $settings)['items'],
                'featured' => $this->featured($store, $settings),
            ] : null,
        ]);
    }

    public function category(Request $request, string $categorySlug): Response
    {
        $store = StorefrontContext::requireStore();

        $category = Category::query()
            ->withoutGlobalScopes()
            ->where('company_id', $store->company_id)
            ->where('slug', $categorySlug)
            ->where('is_active', true)
            ->firstOrFail();

        $categories = $this->structure->categories($store);
        $row = $categories->firstWhere('id', $category->id);
        $parent = $category->parent_id ? $categories->firstWhere('id', $category->parent_id) : null;
        $ids = [$category->id, ...$categories->where('parent_id', $category->id)->pluck('id')->all()];

        return $this->listingPage($request, $store, 'category', ['category_ids' => $ids], [
            'title' => $category->name,
            'category' => $row ? array_merge($row, [
                'children' => $categories->where('parent_id', $category->id)->values()->all(),
                'parent' => $parent ? ['name' => $parent['name'], 'href' => $parent['href']] : null,
            ]) : null,
        ], ['category_id' => $category->id]);
    }

    public function deals(Request $request): Response
    {
        $store = StorefrontContext::requireStore();
        $label = $this->config->structure($store, 'nav')['deals_label'] ?? 'Ofertas';

        return $this->listingPage($request, $store, 'deals', ['deals' => true], ['title' => $label ?: 'Ofertas']);
    }

    public function search(Request $request): Response
    {
        $store = StorefrontContext::requireStore();
        $query = mb_substr(trim($request->string('q')->toString()), 0, 80);

        return $this->listingPage($request, $store, 'search', ['search' => $query], [
            'title' => $query !== '' ? 'Resultados de «'.$query.'»' : 'Todo el surtido',
            'query' => $query,
        ]);
    }

    /**
     * Lo que sale debajo del buscador mientras se escribe: hasta seis articulos, las categorias
     * que coinciden y, con el campo vacio, las busquedas populares. Sin tildes ni mayusculas, y
     * con lo que suena igual («arros» trae «Arroz»).
     */
    public function suggestions(Request $request): JsonResponse
    {
        $store = StorefrontContext::requireStore();
        $settings = $this->config->settings($store);
        $raw = mb_substr(trim($request->string('q')->toString()), 0, 80);
        $terms = SearchText::terms($raw);

        if ($raw === '' || ! ($settings['search']['suggestions'] ?? true)) {
            return response()->json([
                'products' => [],
                'categories' => [],
                'popular' => array_values($settings['search']['popular_terms'] ?? []),
            ]);
        }

        $query = $this->products->query($store, $settings['catalog']);
        $this->listing->whereSearch($query, $terms, $raw, 'products');

        $first = $terms[0] ?? SearchText::key($raw);
        $rows = $query
            ->orderByRaw('CASE WHEN products.search_name LIKE ? THEN 0 ELSE 1 END', [$first.'%'])
            ->orderByDesc('products.is_featured')
            ->orderBy('products.name')
            // De sobra: las tallas de un mismo articulo salen en una sola sugerencia.
            ->limit(30)
            ->get();

        $categories = $this->structure->categories($store)
            ->filter(function (array $category) use ($terms) {
                $key = SearchText::key($category['name']);

                return $terms !== [] && collect($terms)->every(fn (string $term) => str_contains($key, $term));
            })
            ->take(4)
            ->map(fn (array $category) => ['name' => $category['name'], 'href' => $category['href'], 'icon' => $category['icon']])
            ->values();

        return response()->json([
            'products' => collect($this->products->present($rows, $store, $settings['card'], false))
                ->map(fn (array $product) => [
                    'name' => $product['name'],
                    'brand' => $product['brand'],
                    'content' => $product['content'],
                    'image' => $product['image'],
                    'category_icon' => $product['category_icon'],
                    'price' => $product['price'],
                    'compare_at' => $product['compare_at'],
                    'href' => $product['href'],
                ])
                ->take(6)
                ->values(),
            'categories' => $categories,
            'popular' => [],
        ]);
    }

    /**
     * El panel del menu de categorias: lo más vendido de una categoria (con sus subcategorias),
     * o lo que coincide con lo que se escribe en su buscador. En JSON, para pedirlo al pasar el
     * mouse sin recargar la pagina.
     */
    public function menu(Request $request, int $category): JsonResponse
    {
        $store = StorefrontContext::requireStore();
        $categories = $this->structure->categories($store);

        // Solo una categoria de la tienda de esta sede: el id de otra empresa no existe aqui.
        abort_if($categories->firstWhere('id', $category) === null, 404);

        $settings = $this->config->settings($store);
        $search = mb_substr(trim($request->string('q')->toString()), 0, 80);
        $ids = [$category, ...$categories->where('parent_id', $category)->pluck('id')->all()];
        $result = $this->listing->top($store, $ids, $search, $settings, 8);

        return response()->json([
            'products' => $result['items'],
            'total' => $result['total'],
            'by_sales' => $result['by_sales'],
            'search_href' => $search !== '' ? route('storefront.search', ['q' => $search], false) : null,
        ]);
    }

    public function show(Request $request, Product $product): Response|RedirectResponse
    {
        $store = StorefrontContext::requireStore();

        abort_if((int) $product->company_id !== (int) $store->company_id || ! $product->is_online_visible || ! $product->is_active, 404);

        // Un subarticulo se ve en la pagina de su articulo, con esa presentacion elegida. Un
        // enlace viejo o compartido a la talla 8 sigue llevando a la talla 8.
        if ($product->product_family_id !== null) {
            $family = ProductFamily::query()->withoutGlobalScopes()->whereKey($product->product_family_id)->whereNull('deleted_at')->first();

            if ($family) {
                return redirect()->to('/tienda/articulo/'.$family->slug.'?variante='.$product->id, 301);
            }
        }

        $settings = $this->config->settings($store);
        $row = $this->products->query($store)->where('products.id', $product->id)->first();

        abort_if($row === null, 404);

        $page = $settings['product_page'];
        $presented = $this->products->present([$row], $store, $settings['card'], (bool) ($page['show_unit_price'] ?? true) && (bool) ($settings['catalog']['price_per_unit'] ?? true))[0];
        $categories = $this->structure->categories($store);
        $category = $categories->firstWhere('id', $product->category_id);
        $parent = $category && $category['parent_id'] ? $categories->firstWhere('id', $category['parent_id']) : null;

        return Inertia::render('Storefront/Product', [
            'product' => array_merge($presented, [
                'description' => ($page['show_description'] ?? true) ? $product->description : null,
                'tax_rate' => (float) $product->tax_rate,
            ]),
            'breadcrumbs' => array_values(array_filter([
                $parent ? ['label' => $parent['name'], 'href' => $parent['href']] : null,
                $category ? ['label' => $category['name'], 'href' => $category['href']] : null,
            ])),
            'related' => $this->listing->related($store, $product, $settings),
            'blocks' => $this->renderer->published($store, StorefrontBlock::PAGE_PRODUCT, $this->context())['blocks'],
        ]);
    }

    /**
     * La pagina de un articulo con subarticulos: el principal y, para escoger, sus
     * presentaciones. `?variante=` deja una elegida: es el enlace que se comparte.
     */
    public function family(Request $request, string $familySlug): Response
    {
        $store = StorefrontContext::requireStore();
        $family = $this->findFamily($store, $familySlug);
        $settings = $this->config->settings($store);
        $detail = $this->products->familyDetail($family, $store, $settings);

        abort_if($detail['variants'] === [], 404);

        $wanted = $request->integer('variante');
        $selected = collect($detail['variants'])->firstWhere('id', $wanted) ? $wanted : $detail['default_id'];
        $categories = $this->structure->categories($store);
        $category = $categories->firstWhere('id', $family->category_id);
        $parent = $category && $category['parent_id'] ? $categories->firstWhere('id', $category['parent_id']) : null;
        $representative = Product::query()->withoutGlobalScopes()->find($selected);

        return Inertia::render('Storefront/Family', [
            ...$detail,
            'selected_id' => $selected,
            'show_description' => (bool) ($settings['product_page']['show_description'] ?? true),
            'breadcrumbs' => array_values(array_filter([
                $parent ? ['label' => $parent['name'], 'href' => $parent['href']] : null,
                $category ? ['label' => $category['name'], 'href' => $category['href']] : null,
            ])),
            'related' => $representative ? $this->listing->related($store, $representative, $settings) : [],
            'blocks' => $this->renderer->published($store, StorefrontBlock::PAGE_PRODUCT, $this->context())['blocks'],
        ]);
    }

    /** Las presentaciones de un articulo, para escoger desde la tarjeta sin salir del listado. */
    public function familyOptions(string $familySlug): JsonResponse
    {
        $store = StorefrontContext::requireStore();
        $detail = $this->products->familyDetail($this->findFamily($store, $familySlug), $store, $this->config->settings($store));

        abort_if($detail['variants'] === [], 404);

        return response()->json($detail);
    }

    protected function findFamily(Store $store, string $slug): ProductFamily
    {
        $family = ProductFamily::query()->withoutGlobalScopes()
            ->where('company_id', $store->company_id)
            ->where('slug', $slug)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('is_online_visible', true)
            ->first();

        abort_if($family === null, 404);

        return $family;
    }

    /** El detalle de un servicio: lo que incluye, tal cual el maestro; el cliente no lo ajusta. */
    public function service(Request $request, int $service): Response
    {
        $store = StorefrontContext::requireStore();
        $found = $this->services->find($store, $service);

        abort_if($found === null, 404);

        $found->loadMissing('category:id,name,slug');
        $settings = $this->config->settings($store);

        return Inertia::render('Storefront/Service', [
            'service' => [
                ...$this->products->presentServices(collect([$found]), $store, $settings['card'])[0],
                // Con la existencia de cada presentacion en los articulos completos.
                'includes' => $this->services->includes($found, [], $store),
                'description' => $found->description,
                'category' => $found->category ? ['label' => $found->category->name, 'href' => '/tienda/categoria/'.$found->category->slug] : null,
                'tax_rate' => (float) $found->tax_rate,
                'pricing_mode' => $found->pricing_mode,
            ],
            'blocks' => $this->renderer->published($store, StorefrontBlock::PAGE_PRODUCT, $this->context())['blocks'],
        ]);
    }

    public function switchStore(Request $request): RedirectResponse
    {
        $validated = $request->validate(['store_id' => ['required', 'integer']]);

        if (! StorefrontContext::setStore((int) $validated['store_id'])) {
            return back()->with('error', 'Esa sede no esta disponible.');
        }

        return back()->with('success', 'Cambiaste de sede.');
    }

    /**
     * Categoria, ofertas y busqueda comparten pagina: filtros, orden, contador y resultados.
     *
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $extra
     * @param  array<string, mixed>  $context
     */
    protected function listingPage(Request $request, Store $store, string $mode, array $scope, array $extra, array $context = []): Response
    {
        $settings = $this->config->settings($store);
        $input = [
            'sub' => $request->integer('sub') ?: null,
            'brands' => array_values(array_filter(array_map('strval', (array) $request->input('brands', [])))),
            'attr' => array_values(array_filter(array_map('intval', (array) $request->input('attr', [])))),
            'min' => $request->filled('min') ? $request->integer('min') : null,
            'max' => $request->filled('max') ? $request->integer('max') : null,
            'deals' => $request->boolean('deals'),
            'in_stock' => $request->boolean('in_stock'),
            'sort' => $request->string('sort')->toString() ?: null,
            'page' => max(1, $request->integer('page', 1)),
        ];

        $results = $this->listing->list($store, $scope, $input, $settings);
        $page = $mode === 'category' ? StorefrontBlock::PAGE_CATEGORY : StorefrontBlock::PAGE_SEARCH;

        return Inertia::render('Storefront/Catalog', [
            'mode' => $mode,
            'query' => null,
            'category' => null,
            ...$extra,
            'results' => $results,
            'filters' => array_merge($input, ['sort' => $results['sort']]),
            'sorts' => $this->listing->sortOptions($settings),
            'blocks' => $this->renderer->published($store, $page, $this->context($context))['blocks'],
        ]);
    }

    /**
     * Quien mira, para «vuelve a comprar», y en que categoria esta, para los bloques
     * limitados a unas categorias.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function context(array $extra = []): array
    {
        return array_merge(['customer' => auth('customer')->user()], $extra);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<array<string, mixed>>
     */
    protected function featured(Store $store, array $settings): array
    {
        $rows = $this->products->query($store, $settings['catalog'])
            ->where('products.is_featured', true)
            ->orderBy('products.name')
            ->limit(10)
            ->get();

        return [
            ...$this->products->presentServices($this->services->query($store)->where('is_featured', true)->orderBy('sort_order')->limit(4)->get(), $store, $settings['card']),
            ...$this->products->present($rows, $store, $settings['card'], (bool) ($settings['catalog']['price_per_unit'] ?? true)),
        ];
    }
}

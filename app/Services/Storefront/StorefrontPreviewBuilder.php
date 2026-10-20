<?php

namespace App\Services\Storefront;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontBlock;
use App\Support\StorefrontContext;

/**
 * Lo que necesita la vista previa del editor para pintar una pagina con un retrato cualquiera
 * (el borrador de una sede, o una plantilla sobre el catalogo de una sede).
 *
 * La vista previa usa las paginas reales de la tienda —portada, categoria, producto, carrito,
 * busqueda—, asi que aqui se arman sus mismas props, con el catalogo real de la sede y un
 * carrito de muestra calculado por el servidor.
 */
class StorefrontPreviewBuilder
{
    /** Las paginas que se pueden ver en la vista previa. */
    public const VIEWS = StorefrontBlock::PAGES;

    public function __construct(
        protected StorefrontRenderer $renderer,
        protected StorefrontStructure $structure,
        protected StorefrontListing $listing,
        protected StorefrontProducts $products,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function build(array $snapshot, Store $store, string $view, ?int $categoryId = null, ?int $productId = null, ?string $device = null, ?string $scheme = null): array
    {
        $view = in_array($view, self::VIEWS, true) ? $view : StorefrontBlock::PAGE_HOME;
        $settings = $this->structure->withDefaults($snapshot['settings'] ?? []);

        $rendered = $this->renderer->render(array_merge($snapshot, ['blocks' => []]), $store, draft: true);
        $shell = [
            'theme' => $rendered['theme'],
            'settings' => $rendered['settings'],
            'store' => $rendered['store'],
            'stores' => StorefrontContext::sellable()->where('stores.company_id', $store->company_id)->orderBy('stores.name')->get()
                ->map(fn (Store $item) => ['id' => $item->id, 'name' => $item->name])->values()->all(),
        ];

        $props = ['view' => $view, 'shell' => $shell, 'cart' => null];
        $context = [];

        match ($view) {
            StorefrontBlock::PAGE_CATEGORY => [$props['catalog'], $context] = $this->category($store, $categoryId, $settings),
            StorefrontBlock::PAGE_SEARCH => $props['catalog'] = $this->search($store, $settings),
            StorefrontBlock::PAGE_PRODUCT => $props['product'] = $this->product($store, $productId, $settings),
            StorefrontBlock::PAGE_CART => [$props['cartPage'], $props['cart']] = $this->cart($store, $settings),
            default => null,
        };

        $props['blocks'] = $this->renderer->renderSnapshot($snapshot, $store, $device, $view, $context)['blocks'];
        // El encabezado sobre la portada, con la misma regla que la tienda (los bloques ocultos no cuentan).
        $props['headerOverlay'] = $view === StorefrontBlock::PAGE_HOME
            && StorefrontRenderer::headerOverlay($rendered['settings']['header'] ?? [], array_values(array_filter($props['blocks'], fn (array $block) => $block['is_visible'] ?? true)));
        // El modo que el editor está revisando: manda sobre el de la tienda y sobre el que haya escogido quien edita.
        $props['previewScheme'] = in_array($scheme, ['light', 'dark'], true) ? $scheme : null;

        return $props;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>}
     */
    protected function category(Store $store, ?int $categoryId, array $settings): array
    {
        $categories = $this->structure->categories($store);
        $row = ($categoryId ? $categories->firstWhere('id', $categoryId) : null) ?? $categories->whereNull('parent_id')->first();

        if (! $row) {
            return [null, []];
        }

        $children = $categories->where('parent_id', $row['id'])->values();
        $parent = $row['parent_id'] ? $categories->firstWhere('id', $row['parent_id']) : null;
        $results = $this->listing->list($store, ['category_ids' => [$row['id'], ...$children->pluck('id')->all()]], [], $settings);

        return [[
            'mode' => 'category',
            'title' => $row['name'],
            'query' => null,
            'category' => array_merge($row, [
                'children' => $children->all(),
                'parent' => $parent ? ['name' => $parent['name'], 'href' => $parent['href']] : null,
            ]),
            'results' => $results,
            'filters' => $this->filters($results['sort']),
            'sorts' => $this->listing->sortOptions($settings),
        ], ['category_id' => $row['id']]];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    protected function search(Store $store, array $settings): array
    {
        $results = $this->listing->list($store, ['search' => ''], [], $settings);

        return [
            'mode' => 'search',
            'title' => 'Todo el surtido',
            'query' => '',
            'category' => null,
            'results' => $results,
            'filters' => $this->filters($results['sort']),
            'sorts' => $this->listing->sortOptions($settings),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|null
     */
    protected function product(Store $store, ?int $productId, array $settings): ?array
    {
        $query = $this->products->query($store, $settings['catalog']);
        $row = $productId
            ? (clone $query)->where('products.id', $productId)->first()
            : null;
        $row ??= $query->orderByDesc('products.is_featured')->orderBy('products.name')->first();

        if (! $row) {
            return null;
        }

        $product = Product::query()->withoutGlobalScopes()->find($row->id);
        $page = $settings['product_page'];
        $categories = $this->structure->categories($store);
        $category = $categories->firstWhere('id', $product->category_id);

        return [
            'product' => array_merge(
                $this->products->present([$row], $store, $settings['card'], (bool) ($page['show_unit_price'] ?? true))[0],
                [
                    'description' => ($page['show_description'] ?? true) ? $product->description : null,
                    'tax_rate' => (float) $product->tax_rate,
                    'images' => $this->products->gallery($product),
                ]
            ),
            'breadcrumbs' => $category ? [['label' => $category['name'], 'href' => $category['href']]] : [],
            'related' => $this->listing->related($store, $product, $settings),
        ];
    }

    /**
     * Un carrito de muestra con tres articulos reales de la sede, sumado por el servidor.
     *
     * @param  array<string, mixed>  $settings
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    protected function cart(Store $store, array $settings): array
    {
        $rows = $this->products->query($store, $settings['catalog'])->orderByDesc('products.is_featured')->orderBy('products.name')->limit(3)->get();
        $items = collect($this->products->present($rows, $store, $settings['card']))->map(fn (array $product, int $index) => [
            'id' => $index + 1,
            'key' => $product['key'],
            'kind' => 'product',
            'product_id' => $product['id'],
            'service_id' => null,
            'name' => $product['name'],
            'brand' => $product['brand'],
            'unit' => $product['unit'],
            'sold_by_weight' => $product['sold_by_weight'],
            'image' => $product['image'],
            'href' => $product['href'],
            'step' => $product['step'],
            'price' => $product['price'],
            'compare_at' => $product['compare_at'],
            'quantity' => 1,
            'line_total' => $product['price'],
            'substitution_policy' => 'call',
            'available' => null,
        ])->values();

        $subtotal = (int) $items->sum('line_total');
        $delivery = $settings['delivery'];
        $freeFrom = (int) ($delivery['free_from'] ?? 0);
        $minimum = (int) ($delivery['min_order_total'] ?? 0);
        $enabled = (bool) ($delivery['enabled'] ?? true);
        $free = $enabled && $freeFrom > 0 ? ['from' => $freeFrom, 'missing' => max(0, $freeFrom - $subtotal), 'reached' => $subtotal >= $freeFrom] : null;

        return [[
            'store' => ['id' => $store->id, 'name' => $store->name],
            'items' => $items->all(),
            'summary' => [
                'subtotal' => $subtotal,
                'savings' => (int) $items->sum(fn (array $item) => $item['compare_at'] ? $item['compare_at'] - $item['price'] : 0),
                'delivery_enabled' => $enabled,
                'delivery_fee' => $free && $free['reached'] ? 0 : (int) ($delivery['fee'] ?? 0),
                'free_delivery' => $free,
                'min_order_total' => $minimum,
                'min_order_missing' => $minimum > 0 ? max(0, $minimum - $subtotal) : 0,
                'delivery_note' => $settings['texts']['delivery_note'] ?? null,
            ],
            'isLoggedIn' => false,
        ], [
            'count' => (float) $items->count(),
            'lines_count' => $items->count(),
            'total' => $subtotal,
            'lines' => $items->map(fn (array $item) => ['key' => $item['key'], 'quantity' => 1])->all(),
            'free_delivery' => $free,
            'min_order_total' => $minimum,
            'min_order_missing' => $minimum > 0 ? max(0, $minimum - $subtotal) : 0,
        ]];
    }

    /**
     * Las opciones de «Ver la página de» del editor: las categorias y unos articulos.
     *
     * @return array{categories: list<array{value: int, label: string}>, products: list<array{value: int, label: string}>}
     */
    public function options(Store $store): array
    {
        return [
            'categories' => Category::query()->withoutGlobalScopes()
                ->where('company_id', $store->company_id)->where('is_active', true)
                ->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                ->map(fn (Category $category) => ['value' => $category->id, 'label' => $category->name])->all(),
            'products' => $this->products->query($store)
                ->orderByDesc('products.is_featured')->orderBy('products.name')->limit(40)->get()
                ->map(fn ($row) => ['value' => (int) $row->id, 'label' => $row->name])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function filters(string $sort): array
    {
        return ['sub' => null, 'brands' => [], 'attr' => [], 'min' => null, 'max' => null, 'deals' => false, 'sort' => $sort, 'page' => 1];
    }
}

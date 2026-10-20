<?php

namespace App\Services\Storefront;

use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductFamily;
use App\Models\Service;
use App\Models\Store;
use App\Services\PromotionService;
use App\Services\ServicePricing;
use App\Support\SearchText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Los listados de la tienda: una categoria, las ofertas y la busqueda.
 *
 * El precio que se filtra y se ordena es el **vigente** (con promociones), no `products.price`:
 * ordenar por «menor precio» con el precio de lista pondria arriba lo que no es lo mas barato
 * hoy. Como las promociones se calculan en PHP (`PromotionService::unitPrices`), el listado
 * trae los candidatos del alcance (la categoria, la busqueda), les pone su precio, filtra,
 * ordena y pagina aqui. Se cortan en `MAX_CANDIDATES`: una sola categoria con mas articulos
 * que eso ordenaria solo los primeros por nombre. Es una limitacion conocida y documentada.
 */
class StorefrontListing
{
    public const MAX_CANDIDATES = 2000;

    public function __construct(
        protected StorefrontProducts $products,
        protected PromotionService $promotions,
        protected StorefrontServiceCatalog $services,
        protected ServicePricing $pricing,
        protected StorefrontRenderer $renderer,
    ) {}

    /**
     * @param  array{category_ids?: list<int>, deals?: bool, search?: string}  $scope
     * @param  array<string, mixed>  $input  Lo que pidio el cliente: sub, brands, attr, min, max, deals, sort, page.
     * @param  array<string, mixed>  $settings  Los ajustes publicados de la sede.
     * @return array<string, mixed>
     */
    public function list(Store $store, array $scope, array $input, array $settings): array
    {
        $page = $settings['category_page'] ?? [];
        $allowedFilters = $page['filters'] ?? [];
        $allowedSorts = $page['sorts'] ?? array_keys(StorefrontStructure::SORTS);
        $sort = in_array($input['sort'] ?? null, $allowedSorts, true) ? $input['sort'] : ($page['default_sort'] ?? 'relevance');
        $perPage = max(12, min(60, (int) ($page['page_size'] ?? 24)));
        $search = trim((string) ($scope['search'] ?? ''));
        $terms = SearchText::terms($search);

        $entries = $this->candidates($store, $scope, $terms, $search, $settings);

        // Las facetas se cuentan sobre el alcance, antes de filtrar: el cliente ve cuantas hay de
        // cada marca aunque ya haya elegido una.
        $facets = $this->facets($entries, $store, $scope, $allowedFilters);

        $filtered = $entries->filter(function (array $entry) use ($input, $allowedFilters) {
            if (in_array('subcategories', $allowedFilters, true) && ! empty($input['sub']) && $entry['category_id'] !== (int) $input['sub']) {
                return false;
            }

            $brands = array_filter((array) ($input['brands'] ?? []));

            if (in_array('brand', $allowedFilters, true) && $brands !== [] && ! in_array($entry['brand'], $brands, true)) {
                return false;
            }

            if (in_array('price', $allowedFilters, true)) {
                if (is_numeric($input['min'] ?? null) && $entry['price'] < (int) $input['min']) {
                    return false;
                }

                if (is_numeric($input['max'] ?? null) && (int) $input['max'] > 0 && $entry['price'] > (int) $input['max']) {
                    return false;
                }
            }

            if (in_array('deals', $allowedFilters, true) && ! empty($input['deals']) && ! $entry['deal']) {
                return false;
            }

            // «Talla: 12»: solo los articulos que la tienen disponible.
            if (in_array('attributes', $allowedFilters, true) && ($attributeFilter = $this->attributeFilter((array) ($input['attr'] ?? []))) !== [] && ! $this->matchesAttributes($entry, $attributeFilter)) {
                return false;
            }

            return true;
        });

        $sorted = $this->sort($filtered, $sort, $store, $terms)->values();
        $total = $sorted->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $current = max(1, min($lastPage, (int) ($input['page'] ?? 1)));
        $slice = $sorted->slice(($current - 1) * $perPage, $perPage)->values();

        return [
            'items' => $this->present($slice, $store, $settings),
            'total' => $total,
            'page' => $current,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'sort' => $sort,
            'facets' => $facets,
            'truncated' => $entries->count() >= self::MAX_CANDIDATES,
        ];
    }

    /**
     * Todo lo que entra en el alcance, con su precio vigente ya puesto.
     *
     * @param  array<string, mixed>  $scope
     * @param  list<string>  $terms
     * @param  array<string, mixed>  $settings
     * @return Collection<int, array<string, mixed>>
     */
    protected function candidates(Store $store, array $scope, array $terms, string $search, array $settings): Collection
    {
        $query = $this->products->query($store, $settings['catalog'] ?? []);

        if (! empty($scope['category_ids'])) {
            $query->whereIn('products.category_id', $scope['category_ids']);
        }

        $this->whereSearch($query, $terms, $search, 'products');

        $rows = $query->orderByDesc('products.is_featured')->orderBy('products.name')->limit(self::MAX_CANDIDATES)->get();
        $promos = $this->promotions->unitPrices($rows, $store->company_id, PromotionService::CHANNEL_ONLINE);

        $entries = $rows->map(function ($row) use ($promos) {
            $promo = $promos[(int) $row->id] ?? null;
            $price = (int) ($promo['price'] ?? ((int) $row->online_price > 0 ? $row->online_price : $row->price));

            return [
                'kind' => 'product',
                'id' => (int) $row->id,
                // Los articulos que la entrada representa: uno, o todas las presentaciones de una familia.
                'ids' => [(int) $row->id],
                'family_id' => $row->product_family_id ? (int) $row->product_family_id : null,
                'name' => (string) $row->name,
                'key' => SearchText::key($row->name),
                'brand' => $row->brand ?: null,
                'category_id' => $row->category_id ? (int) $row->category_id : null,
                'price' => $price,
                'deal' => $promo !== null && (int) $promo['compare_at'] > $price,
                'featured' => (bool) $row->is_featured,
                'row' => $row,
                'promo' => $promo,
            ];
        });

        $entries = $this->collapseFamilies($entries, $store);

        // Las ofertas son lo que hoy tiene precio rebajado: sin promocion no hay oferta.
        if (! empty($scope['deals'])) {
            return $entries->filter(fn (array $entry) => $entry['deal'])->values();
        }

        // Los servicios entran como siempre: en su categoria y en la busqueda, si alcanzan para una unidad.
        $services = $this->services->onlyAvailable($this->services->query($store)
            ->when(! empty($scope['category_ids']), fn (Builder $inner) => $inner->whereIn('services.category_id', $scope['category_ids']))
            ->when($search !== '', fn (Builder $inner) => $inner->where(fn (Builder $words) => $words
                ->where('services.name', 'like', '%'.$search.'%')
                ->orWhere('services.description', 'like', '%'.$search.'%')))
            ->limit(60)
            ->get(), $store)
            ->map(fn (Service $service) => [
                'kind' => 'service',
                'id' => $service->id,
                'ids' => [],
                'family_id' => null,
                'name' => (string) $service->name,
                'key' => SearchText::key($service->name),
                'brand' => null,
                'category_id' => $service->category_id ? (int) $service->category_id : null,
                'price' => $this->pricing->priceFor($service, PromotionService::CHANNEL_ONLINE),
                'deal' => false,
                'featured' => (bool) $service->is_featured,
                'row' => $service,
                'promo' => null,
            ]);

        return $services->concat($entries)->values();
    }

    /**
     * Lo más vendido de una categoria (con sus subcategorias), para el panel del menu. Con
     * busqueda, filtra dentro de ella y mantiene el orden por ventas.
     *
     * No pasa por los ordenes que la sede ofrece en la pagina de categoria: el panel siempre
     * muestra lo que mas sale. Sin ventas en la sede, primero lo destacado y lo que hay.
     *
     * @param  list<int>  $categoryIds
     * @param  array<string, mixed>  $settings
     * @return array{items: list<array<string, mixed>>, total: int, by_sales: bool}
     */
    public function top(Store $store, array $categoryIds, string $search, array $settings, int $limit): array
    {
        $terms = SearchText::terms($search);
        $entries = $this->candidates($store, ['category_ids' => $categoryIds], $terms, $search, $settings);
        $rank = array_flip($this->renderer->bestSellers($store, 500));
        $sold = fn (array $entry) => $entry['kind'] === 'product' && $this->rankOf($entry, $rank) < 100000;

        $sorted = $entries->sortBy(fn (array $entry) => [
            $entry['kind'] === 'product' ? $this->rankOf($entry, $rank) : 100000,
            $terms !== [] && str_starts_with($entry['key'], $terms[0]) ? 0 : 1,
            $entry['featured'] ? 0 : 1,
            $entry['name'],
        ])->values();

        return [
            'items' => $this->present($sorted->take($limit)->values(), $store, $settings),
            'total' => $sorted->count(),
            'by_sales' => $sorted->contains($sold),
        ];
    }

    /**
     * Un articulo con subarticulos entra una sola vez a la lista: con el nombre del principal, el
     * menor precio vigente de sus presentaciones (el que la tarjeta dice «Desde») y en oferta si
     * alguna lo esta. Ese precio es el que ordena y filtra. Solo llegan las que tienen disponible:
     * una familia sin ninguna no entra.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    protected function collapseFamilies(Collection $entries, Store $store): Collection
    {
        $familyIds = $entries->pluck('family_id')->filter()->unique()->values();

        if ($familyIds->isEmpty()) {
            return $entries;
        }

        $families = ProductFamily::query()->withoutGlobalScopes()
            ->where('company_id', $store->company_id)
            ->whereIn('id', $familyIds)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('is_online_visible', true)
            ->get()
            ->keyBy('id');

        $collapsed = [];
        $done = [];

        foreach ($entries as $entry) {
            $familyId = $entry['family_id'];

            if ($familyId === null) {
                $collapsed[] = $entry;

                continue;
            }

            if (isset($done[$familyId]) || ! $families->has($familyId)) {
                continue;
            }

            $done[$familyId] = true;
            $group = $entries->where('family_id', $familyId);
            // Todas tienen disponible (`query()` ya dejo afuera las que no): la mas barata representa.
            $representative = $group->sortBy('price')->first();
            $family = $families->get($familyId);

            $collapsed[] = array_merge($representative, [
                'ids' => $group->pluck('id')->all(),
                'name' => (string) $family->name,
                'key' => SearchText::key($family->name),
                'brand' => $family->brand ?: $representative['brand'],
                'deal' => $group->contains('deal', true),
                'featured' => (bool) $family->is_featured || $group->contains('featured', true),
                'variants' => $group->map(fn (array $variant) => [
                    'v1' => $variant['row']->variant_value_1_id ? (int) $variant['row']->variant_value_1_id : null,
                    'v2' => $variant['row']->variant_value_2_id ? (int) $variant['row']->variant_value_2_id : null,
                ])->values()->all(),
            ]);
        }

        return collect($collapsed);
    }

    /** @param  array<string, mixed>  $entry  @param  array<int, int>  $rank */
    protected function rankOf(array $entry, array $rank): int
    {
        return (int) collect($entry['ids'] ?? [$entry['id']])->map(fn (int $id) => $rank[$id] ?? 100000)->min();
    }

    /**
     * Los valores pedidos agrupados por su caracteristica: dentro de una se escoge cualquiera; entre
     * dos, una misma presentacion tiene que cumplir las dos (talla 12 y azul, en la misma).
     *
     * @param  list<int|string>  $ids
     * @return array<int, list<int>>
     */
    protected function attributeFilter(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        return ProductAttributeValue::query()->whereIn('id', $ids)->get(['id', 'product_attribute_id'])
            ->groupBy('product_attribute_id')
            ->map(fn ($values) => $values->pluck('id')->map(fn ($id) => (int) $id)->all())
            ->all();
    }

    /** @param  array<string, mixed>  $entry  @param  array<int, list<int>>  $filter */
    protected function matchesAttributes(array $entry, array $filter): bool
    {
        foreach ($entry['variants'] ?? [] as $variant) {
            $values = array_filter([$variant['v1'], $variant['v2']]);
            $ok = true;

            foreach ($filter as $wanted) {
                if (array_intersect($values, $wanted) === []) {
                    $ok = false;

                    break;
                }
            }

            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /**
     * «Talla: 8, 10, 12…»: por cada caracteristica, sus valores con cuantos articulos los tienen
     * disponibles en el alcance.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    protected function attributeFacets(Collection $entries): array
    {
        $counts = [];

        foreach ($entries as $entry) {
            $seen = [];

            foreach ($entry['variants'] ?? [] as $variant) {
                foreach (array_filter([$variant['v1'], $variant['v2']]) as $valueId) {
                    if (! isset($seen[$valueId])) {
                        $seen[$valueId] = true;
                        $counts[$valueId] = ($counts[$valueId] ?? 0) + 1;
                    }
                }
            }
        }

        if ($counts === []) {
            return [];
        }

        return ProductAttributeValue::query()
            ->with('attribute')
            ->whereIn('id', array_keys($counts))
            ->orderBy('sort_order')
            ->get()
            ->groupBy('product_attribute_id')
            ->map(fn ($values) => [
                'id' => (int) $values->first()->product_attribute_id,
                'name' => $values->first()->attribute?->name,
                'display' => $values->first()->attribute?->display,
                'sort' => (int) ($values->first()->attribute?->sort_order ?? 0),
                'values' => $values->map(fn (ProductAttributeValue $value) => [
                    'id' => $value->id,
                    'value' => $value->value,
                    'color_hex' => $value->color_hex,
                    'count' => $counts[$value->id],
                ])->values()->all(),
            ])
            ->sortBy('sort')
            ->values()
            ->all();
    }

    /**
     * Los ordenes que ofrece la sede, con su nombre.
     *
     * @param  array<string, mixed>  $settings
     * @return list<array{value: string, label: string}>
     */
    public function sortOptions(array $settings): array
    {
        return collect($settings['category_page']['sorts'] ?? [])
            ->filter(fn (string $sort) => isset(StorefrontStructure::SORTS[$sort]))
            ->map(fn (string $sort) => ['value' => $sort, 'label' => StorefrontStructure::SORTS[$sort]])
            ->values()
            ->all();
    }

    /**
     * «También te puede interesar»: de la misma categoria o lo mas vendido, segun la sede.
     *
     * @param  array<string, mixed>  $settings
     * @return list<array<string, mixed>>
     */
    public function related(Store $store, Product $product, array $settings): array
    {
        $page = $settings['product_page'] ?? [];
        $limit = max(2, min(16, (int) ($page['related_limit'] ?? 10)));

        $query = match ($page['related'] ?? 'same_category') {
            'none' => null,
            'best_sellers' => ($ids = $this->renderer->bestSellers($store, $limit + 1)) !== []
                ? $this->products->query($store, $settings['catalog'] ?? [])->whereIn('products.id', $ids)
                : null,
            default => $product->category_id
                ? $this->products->query($store, $settings['catalog'] ?? [])->whereIn('products.category_id', $this->renderer->withChildren($store, (int) $product->category_id))->orderByDesc('products.is_featured')->orderBy('products.name')
                : null,
        };

        if ($query === null) {
            return [];
        }

        // Ni el mismo articulo ni sus otras presentaciones: eso ya se escoge en su pagina.
        $rows = $query->where('products.id', '!=', $product->id)
            ->when($product->product_family_id, fn ($inner, $familyId) => $inner->where(fn ($scope) => $scope->whereNull('products.product_family_id')->orWhere('products.product_family_id', '!=', $familyId)))
            ->limit($limit)
            ->get();

        return $this->products->present($rows, $store, $settings['card'] ?? [], (bool) ($settings['catalog']['price_per_unit'] ?? true));
    }

    /**
     * La busqueda sobre la llave de cada articulo, palabra por palabra. Lo que entro sin llave
     * (por consulta directa) se compara por el nombre tal cual.
     *
     * @param  list<string>  $terms
     */
    public function whereSearch(Builder $query, array $terms, string $raw, string $table): void
    {
        if ($raw === '') {
            return;
        }

        $query->where(function (Builder $outer) use ($terms, $raw, $table) {
            $outer->where(function (Builder $keyed) use ($terms, $table) {
                foreach ($terms === [] ? ['__'] : $terms as $term) {
                    $keyed->where($table.'.search_name', 'like', '%'.$term.'%');
                }
            })->orWhere(fn (Builder $plain) => $plain
                ->whereNull($table.'.search_name')
                ->where($table.'.name', 'like', '%'.$raw.'%'));
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @param  array<string, mixed>  $scope
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    protected function facets(Collection $entries, Store $store, array $scope, array $allowed): array
    {
        $facets = ['subcategories' => [], 'brands' => [], 'price' => null, 'attributes' => []];

        if (in_array('attributes', $allowed, true)) {
            $facets['attributes'] = $this->attributeFacets($entries);
        }

        if (in_array('subcategories', $allowed, true) && ! empty($scope['category_ids'])) {
            $counts = $entries->countBy('category_id');
            $parent = $scope['category_ids'][0];

            $facets['subcategories'] = app(StorefrontStructure::class)->categories($store)
                ->where('parent_id', $parent)
                ->map(fn (array $category) => [
                    'id' => $category['id'],
                    'name' => $category['name'],
                    'href' => $category['href'],
                    'count' => (int) ($counts[$category['id']] ?? 0),
                ])
                ->filter(fn (array $category) => $category['count'] > 0)
                ->values()
                ->all();
        }

        if (in_array('brand', $allowed, true)) {
            $facets['brands'] = $entries->whereNotNull('brand')->countBy('brand')
                ->sortDesc()
                ->take(24)
                ->map(fn (int $count, string $brand) => ['value' => $brand, 'count' => $count])
                ->sortBy('value')
                ->values()
                ->all();
        }

        if (in_array('price', $allowed, true) && $entries->isNotEmpty()) {
            $facets['price'] = ['min' => (int) $entries->min('price'), 'max' => (int) $entries->max('price')];
        }

        return $facets;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @param  list<string>  $terms
     * @return Collection<int, array<string, mixed>>
     */
    protected function sort(Collection $entries, string $sort, Store $store, array $terms): Collection
    {
        return match ($sort) {
            'price_asc' => $entries->sortBy([['price', 'asc'], ['name', 'asc']]),
            'price_desc' => $entries->sortBy([['price', 'desc'], ['name', 'asc']]),
            'name' => $entries->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE),
            'best_sellers' => (function () use ($entries, $store) {
                $rank = array_flip($this->renderer->bestSellers($store, 500));

                return $entries->sortBy(fn (array $entry) => [$entry['kind'] === 'product' ? $this->rankOf($entry, $rank) : 100001, $entry['name']]);
            })(),
            // Relevancia: en una busqueda, lo que empieza por la palabra va primero; despues lo
            // destacado y el nombre.
            default => $entries->sortBy(fn (array $entry) => [
                $terms !== [] && str_starts_with($entry['key'], $terms[0]) ? 0 : 1,
                $entry['featured'] ? 0 : 1,
                $entry['name'],
            ]),
        };
    }

    /**
     * La ficha de cada uno, en el orden de la pagina.
     *
     * @param  Collection<int, array<string, mixed>>  $slice
     * @param  array<string, mixed>  $settings
     * @return list<array<string, mixed>>
     */
    protected function present(Collection $slice, Store $store, array $settings): array
    {
        $card = $settings['card'] ?? [];
        $perUnit = (bool) ($settings['catalog']['price_per_unit'] ?? true);

        $products = $slice->where('kind', 'product');
        $promos = $products->filter(fn (array $entry) => $entry['promo'] !== null)->mapWithKeys(fn (array $entry) => [$entry['id'] => $entry['promo']])->all();
        // Por llave y no por id: la ficha de un articulo con subarticulos lleva el id de la familia,
        // y la entrada, el de la presentacion que la representa.
        $presented = collect($this->products->present($products->pluck('row'), $store, $card, $perUnit, $promos))->keyBy('key');
        $services = collect($this->products->presentServices($slice->where('kind', 'service')->pluck('row'), $store, $card))->keyBy('id');

        return $slice->map(fn (array $entry) => match (true) {
            $entry['kind'] !== 'product' => $services->get($entry['id']),
            ! empty($entry['family_id']) => $presented->get('f:'.$entry['family_id']),
            default => $presented->get('p:'.$entry['id']),
        })
            ->filter()
            ->values()
            ->all();
    }
}

<?php

namespace App\Services\Storefront;

use App\Models\Customer;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionTarget;
use App\Models\SaleItem;
use App\Models\Store;
use App\Models\Storefront;
use App\Models\StorefrontBlock;
use App\Models\StorefrontTemplate;
use App\Services\PromotionService;
use App\Storefront\Blocks\BlockData;
use App\Storefront\Targets;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Convierte un retrato del sitio en lo que pinta la tienda.
 *
 * Es el unico camino: la tienda publica lee el retrato publicado y la vista previa lee el
 * borrador, pero ambas pasan por aqui. Si fueran dos resolvedores, la vista previa mentiria.
 *
 * Una sede que sigue la plantilla se pinta con el diseño publicado de la plantilla y todo lo
 * suyo (marca, domicilio, pagos, contacto): `StorefrontTemplate::compose`.
 */
class StorefrontRenderer
{
    /** Cuanto se guarda el retrato publicado ya resuelto. */
    public const CACHE_MINUTES = 10;

    /** @var array<int, MediaAsset> */
    protected array $media = [];

    public function __construct(
        protected StorefrontManager $manager,
        protected PromotionService $promotions,
        protected StorefrontProducts $products,
        protected StorefrontStructure $structure,
        protected StorefrontServiceCatalog $services,
    ) {}

    /**
     * Una pagina de la tienda que ve el cliente: tema, estructura global y los bloques de esa
     * pagina.
     *
     * @param  array{category_id?: int|null, customer?: Customer|null}  $context
     * @return array<string, mixed>
     */
    public function published(Store $store, string $page = StorefrontBlock::PAGE_HOME, array $context = []): array
    {
        $storefront = Storefront::query()->withoutGlobalScopes()->with('template')->where('store_id', $store->id)->first();

        if (! $storefront || ! $storefront->is_online || ! $storefront->published_version_id) {
            return $this->empty();
        }

        $rendered = Cache::remember(
            StorefrontPublisher::cacheKey($store->id, $page),
            now()->addMinutes(self::CACHE_MINUTES),
            fn () => $this->render($this->snapshotFor($storefront, draft: false), $store, page: $page)
        );

        return $this->personalize($rendered, $store, $context);
    }

    /**
     * El cascaron publicado de la sede: tema, estructura global y datos de la sede, sin bloques.
     * Lo pintan todas las paginas de la tienda.
     *
     * @return array<string, mixed>
     */
    public function shell(Store $store): array
    {
        $storefront = Storefront::query()->withoutGlobalScopes()->with('template')->where('store_id', $store->id)->first();

        if (! $storefront || ! $storefront->published_version_id) {
            return ['theme' => [], 'settings' => [], 'store' => null];
        }

        return Cache::remember(
            StorefrontPublisher::cacheKey($store->id, StorefrontPublisher::SHELL),
            now()->addMinutes(self::CACHE_MINUTES),
            fn () => collect($this->render(array_merge($this->snapshotFor($storefront, draft: false), ['blocks' => []]), $store))->except('blocks')->all()
        );
    }

    /**
     * El cascaron del borrador, para la vista previa.
     *
     * @param  array<string, mixed>|null  $snapshot  Uno ya armado (el de la plantilla).
     * @return array<string, mixed>
     */
    public function draftShell(Store $store, ?array $snapshot = null): array
    {
        $snapshot ??= $this->snapshotFor($this->manager->for($store), draft: true);

        return collect($this->render(array_merge($snapshot, ['blocks' => []]), $store, draft: true))->except('blocks')->all();
    }

    /**
     * El borrador, para la vista previa.
     *
     * @param  array{category_id?: int|null, customer?: Customer|null}  $context
     * @return array<string, mixed>
     */
    public function draft(Store $store, ?string $device = null, string $page = StorefrontBlock::PAGE_HOME, array $context = []): array
    {
        return $this->renderSnapshot($this->snapshotFor($this->manager->for($store), draft: true), $store, $device, $page, $context);
    }

    /**
     * Un retrato cualquiera pintado como borrador: la vista previa de la sede o de la plantilla.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array{category_id?: int|null, customer?: Customer|null}  $context
     * @return array<string, mixed>
     */
    public function renderSnapshot(array $snapshot, Store $store, ?string $device = null, string $page = StorefrontBlock::PAGE_HOME, array $context = []): array
    {
        return $this->personalize($this->render($snapshot, $store, $device, draft: true, page: $page), $store, $context, draft: true);
    }

    /**
     * La plantilla vista con el catalogo de una sede, para el editor de plantillas.
     *
     * @param  array<string, mixed>  $design
     * @return array<string, mixed>
     */
    public function template(array $design, Store $store, ?string $device = null, string $page = StorefrontBlock::PAGE_HOME, array $context = []): array
    {
        return $this->renderSnapshot($this->templateSnapshot($design, $store), $store, $device, $page, $context);
    }

    /**
     * El diseño de una plantilla sobre lo de una sede (marca, domicilio, pagos).
     *
     * @param  array<string, mixed>  $design
     * @return array<string, mixed>
     */
    public function templateSnapshot(array $design, Store $store): array
    {
        $snapshot = StorefrontTemplate::compose($this->manager->snapshot($this->manager->for($store)), $design);
        $snapshot['platform_media'] = true;

        return $snapshot;
    }

    /**
     * El retrato que manda en la sede: el propio, o el de la plantilla con lo de la sede.
     *
     * @return array<string, mixed>
     */
    public function snapshotFor(Storefront $storefront, bool $draft): array
    {
        $base = $draft ? $this->manager->snapshot($storefront) : ($storefront->publishedVersion?->snapshot ?? []);

        if ($storefront->follows_template && $storefront->template?->published) {
            $composed = StorefrontTemplate::compose($base, $storefront->template->published);
            // Los medios de la plantilla son de la plataforma: solo aqui se pueden pintar.
            $composed['platform_media'] = true;

            return $composed;
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function render(array $snapshot, Store $store, ?string $device = null, bool $draft = false, string $page = StorefrontBlock::PAGE_HOME): array
    {
        $now = CarbonImmutable::now();
        $store->loadMissing('company');
        $structure = $this->structure->resolve($snapshot['settings'] ?? [], $store, $snapshot['theme'] ?? []);

        $rows = collect($snapshot['blocks'] ?? [])
            // Un retrato de antes de las paginas es todo portada.
            ->filter(fn (array $block) => ($block['page'] ?? StorefrontBlock::PAGE_HOME) === $page)
            ->filter(fn (array $block) => $draft || $this->isLive($block, $now))
            ->filter(fn (array $block) => $device === null || in_array($block['devices'] ?? 'all', ['all', $device], true))
            ->sortBy('sort_order')
            ->values();

        $this->loadMedia($rows, $store, (bool) ($snapshot['platform_media'] ?? false));

        $links = [
            'slugs' => $this->structure->categories($store)->pluck('slug', 'id')->all(),
            'whatsapp' => Targets::whatsappNumber($structure['footer']['contact']['whatsapp'] ?? null) ?? Targets::whatsappNumber($store->phone),
        ];

        $blocks = $rows->map(fn (array $block) => [
            'uuid' => $block['uuid'] ?? '',
            'type' => $block['type'],
            'page' => $block['page'] ?? StorefrontBlock::PAGE_HOME,
            'devices' => $block['devices'] ?? 'all',
            'is_visible' => (bool) ($block['is_visible'] ?? true),
            'scheduled' => ! $this->isLive($block, $now),
            'data' => $this->withHrefs(
                $this->resolve($block['type'], $block['payload'] ?? [], $store, $structure, $draft),
                $links
            ),
        ])->all();

        return [
            'theme' => $snapshot['theme'] ?? [],
            'blocks' => $blocks,
            'settings' => $structure,
            'store' => $this->storePayload($store, $snapshot['settings'] ?? []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function empty(): array
    {
        return ['theme' => [], 'blocks' => [], 'settings' => [], 'store' => null];
    }

    /**
     * Lo que depende de quien mira y no se guarda en cache: los bloques limitados a unas
     * categorias, «vuelve a comprar» y las ofertas relampago que ya vencieron.
     *
     * @param  array<string, mixed>  $rendered
     * @param  array{category_id?: int|null, customer?: Customer|null}  $context
     * @return array<string, mixed>
     */
    protected function personalize(array $rendered, Store $store, array $context, bool $draft = false): array
    {
        $categoryId = $context['category_id'] ?? null;
        $lineage = $categoryId ? $this->lineage($store, (int) $categoryId) : [];
        $now = CarbonImmutable::now();

        $rendered['blocks'] = collect($rendered['blocks'])
            ->filter(function (array $block) use ($lineage, $categoryId) {
                $only = $block['data']['only_categories'] ?? [];

                // Fuera de la pagina de categoria la condicion no aplica.
                return $only === [] || $categoryId === null || array_intersect($only, $lineage) !== [];
            })
            ->map(function (array $block) use ($store, $context, $draft, $now) {
                if ($block['type'] === 'product_grid' && ($block['data']['source'] ?? null) === 'buy_again') {
                    $block['data']['products'] = $this->buyAgain($store, $context['customer'] ?? null, $block['data']);
                    $block['data']['placeholder'] = $draft && $block['data']['products'] === [];
                }

                if ($block['type'] === 'deal_countdown' && ! $draft) {
                    $ends = $block['data']['promotion']['ends_at'] ?? null;

                    if ($ends === null || CarbonImmutable::parse($ends)->lte($now)) {
                        $block['data']['products'] = [];
                        $block['data']['promotion'] = null;
                    }
                }

                return $block;
            })
            // Lo que el cliente no puede ver se cae aqui, y no en el `.tsx`: «vuelve a comprar»
            // sin compras y la oferta relampago vencida no dejan un hueco.
            ->filter(fn (array $block) => $draft || ! $this->isBlank($block))
            ->values()
            ->all();

        return $rendered;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    protected function isBlank(array $block): bool
    {
        return match ($block['type']) {
            'deal_countdown' => ($block['data']['promotion'] ?? null) === null || ($block['data']['products'] ?? []) === [],
            'product_grid' => ($block['data']['source'] ?? null) === 'buy_again' && ($block['data']['products'] ?? []) === [],
            'hero', 'promo_carousel' => ($block['data']['slides'] ?? []) === [],
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $block
     */
    protected function isLive(array $block, CarbonImmutable $now): bool
    {
        if (! ($block['is_visible'] ?? true)) {
            return false;
        }

        $from = ($block['visible_from'] ?? null) ? CarbonImmutable::parse($block['visible_from']) : null;
        $to = ($block['visible_to'] ?? null) ? CarbonImmutable::parse($block['visible_to']) : null;

        if ($from && $from->gt($now)) {
            return false;
        }

        return ! ($to && $to->lt($now));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $structure
     * @return array<string, mixed>
     */
    protected function resolve(string $type, array $payload, Store $store, array $structure, bool $draft): array
    {
        $card = $structure['card'] ?? [];
        $perUnit = (bool) ($structure['catalog']['price_per_unit'] ?? true);

        return match ($type) {
            'hero' => array_merge($payload, [
                'slides' => $this->slides($payload['slides'] ?? [], $draft),
                'side' => collect($payload['side'] ?? [])->map(fn (array $side) => array_merge($side, [
                    'media' => $this->mediaPayload($side['media_id'] ?? null),
                ]))->values()->all(),
            ]),
            'promo_carousel' => array_merge($payload, ['slides' => $this->slides($payload['slides'] ?? [], $draft)]),
            'banner_grid' => array_merge($payload, [
                'tiles' => collect($payload['tiles'] ?? [])->map(fn (array $tile) => array_merge($tile, [
                    'media' => $this->mediaPayload($tile['media_id'] ?? null),
                    'media_mobile' => $this->mediaPayload($tile['media_mobile_id'] ?? null),
                ]))->values()->all(),
            ]),
            'categories' => array_merge($payload, ['items' => $this->categories($payload, $store)]),
            'product_grid' => array_merge($payload, [
                'products' => ($payload['source'] ?? null) === 'buy_again' ? [] : $this->products($payload, $store, $card, $perUnit && (bool) ($payload['show_price_per_unit'] ?? true)),
                'see_all_href' => $this->seeAll($payload, $store),
                'layout' => $payload['layout'] ?? 'carousel',
            ]),
            'deal_countdown' => array_merge($payload, $this->dealCountdown($payload, $store, $card, $perUnit)),
            'store_info' => array_merge($payload, ['store' => $this->storePayload($store, [])]),
            default => $payload,
        };
    }

    /**
     * Las laminas vigentes con sus imagenes. En la vista previa se ven todas, marcadas.
     *
     * @param  list<array<string, mixed>>  $slides
     * @return list<array<string, mixed>>
     */
    protected function slides(array $slides, bool $draft): array
    {
        return collect($slides)
            ->map(fn (array $slide) => array_merge($slide, [
                'is_live' => BlockData::itemIsLive($slide),
                'media' => $this->mediaPayload($slide['media_id'] ?? null)
                    ?? (! empty($slide['legacy_image']) ? ['url' => '/storage/'.$slide['legacy_image'], 'srcset' => null, 'alt' => $slide['title'] ?? '', 'focal_x' => 0.5, 'focal_y' => 0.5, 'variants' => []] : null),
                'media_mobile' => $this->mediaPayload($slide['media_mobile_id'] ?? null),
            ]))
            ->filter(fn (array $slide) => $draft || ($slide['is_live'] && $slide['media'] !== null))
            ->values()
            ->all();
    }

    /**
     * Carga de una vez todas las imagenes de la pagina.
     *
     * Solo las de la empresa de la sede, y las de plataforma cuando el diseño es de la
     * plantilla. Un id de otra empresa no se pinta aunque alguien lo haya puesto a mano.
     *
     * @param  Collection<int, array<string, mixed>>  $blocks
     */
    protected function loadMedia(Collection $blocks, Store $store, bool $platform): void
    {
        $ids = [];

        foreach ($blocks as $block) {
            array_walk_recursive($block['payload'], function ($value, $key) use (&$ids) {
                if (BlockData::isMediaKey($key) && is_numeric($value)) {
                    $ids[] = (int) $value;
                }
            });
        }

        $this->media = $ids === [] ? [] : MediaAsset::query()
            ->withoutGlobalScopes()
            ->whereIn('id', array_unique($ids))
            ->where(fn ($query) => $query
                ->where('company_id', $store->company_id)
                ->when($platform, fn ($inner) => $inner->orWhereNull('company_id')))
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function mediaPayload(mixed $id): ?array
    {
        if (! is_numeric($id) || ! isset($this->media[(int) $id])) {
            return null;
        }

        return $this->media[(int) $id]->toPayload();
    }

    /**
     * Le pone su direccion a cada destino que haya en los datos del bloque.
     *
     * @param  array<string, mixed>  $data
     * @param  array{slugs: array<int, string>, whatsapp: string|null}  $links
     * @return array<string, mixed>
     */
    protected function withHrefs(array $data, array $links): array
    {
        foreach ($data as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            if (isset($value['kind']) && array_key_exists('value', $value) && in_array($value['kind'], Targets::KINDS, true)) {
                $data[$key]['href'] = Targets::href($value, $links['slugs'], $links['whatsapp']);

                continue;
            }

            $data[$key] = $this->withHrefs($value, $links);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    protected function categories(array $payload, Store $store): array
    {
        $limit = max(2, min(24, (int) ($payload['limit'] ?? 12)));
        $all = $this->structure->categories($store)->keyBy('id');
        $source = $payload['source'] ?? 'all';

        $ids = match ($source) {
            'manual' => array_map('intval', $payload['category_ids'] ?? []),
            'top' => $this->topCategories($store, $limit),
            default => $all->whereNull('parent_id')->keys()->all(),
        };

        return collect($ids)
            ->map(fn (int $id) => $all->get($id))
            ->filter()
            ->take($limit)
            ->map(fn (array $category) => [
                'category_id' => $category['id'],
                'label' => $category['name'],
                'icon' => $category['icon'],
                'image' => $category['image'],
                'href' => $category['href'],
            ])
            ->values()
            ->all();
    }

    /**
     * Las categorias que mas se vendieron en la sede en los ultimos treinta dias; si todavia no
     * hay ventas, las principales en su orden.
     *
     * @return list<int>
     */
    protected function topCategories(Store $store, int $limit): array
    {
        $ids = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->where('sales.store_id', $store->id)
            ->where('sales.status', '!=', 'cancelled')
            ->where('sales.sold_at', '>=', now()->subDays(30))
            ->whereRaw(SaleItem::COUNTS_AS_SOLD_SQL)
            ->selectRaw('COALESCE(categories.parent_id, categories.id) as category_id')
            ->groupByRaw('COALESCE(categories.parent_id, categories.id)')
            ->orderByRaw('SUM(sale_items.line_total) DESC')
            ->limit($limit)
            ->pluck('category_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($ids !== []) {
            return $ids;
        }

        return $this->structure->categories($store)->whereNull('parent_id')->pluck('id')->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $card
     * @return list<array<string, mixed>>
     */
    protected function products(array $payload, Store $store, array $card, bool $perUnit): array
    {
        $limit = max(2, min(24, (int) ($payload['limit'] ?? 8)));
        $source = (string) ($payload['source'] ?? 'featured');
        $query = $this->products->query($store);
        $ids = [];

        match ($source) {
            'featured' => $query->where('products.is_featured', true),
            'category' => $query->whereIn('products.category_id', $this->withChildren($store, (int) ($payload['value'] ?? 0))),
            'new_arrivals' => $query->reorder()->orderByDesc('products.created_at'),
            'manual' => $ids = array_map('intval', $payload['product_ids'] ?? []),
            'promotion' => $ids = $this->promotionProducts((int) ($payload['promotion_id'] ?? 0), $store),
            'best_sellers' => $ids = $this->bestSellers($store, $limit),
            default => null,
        };

        if (in_array($source, ['manual', 'promotion', 'best_sellers'], true)) {
            if ($ids === []) {
                return [];
            }

            $query->whereIn('products.id', $ids);
        }

        if ($source !== 'new_arrivals') {
            $query->orderBy('products.name');
        }

        $rows = $query->limit(in_array($source, ['on_sale', 'promotion'], true) ? 200 : $limit)->get();
        $promos = $this->promotions->unitPrices($rows, $store->company_id, PromotionService::CHANNEL_ONLINE);

        if (in_array($source, ['on_sale', 'promotion'], true)) {
            $rows = $rows->filter(fn ($row) => isset($promos[(int) $row->id]))->take($limit);
        }

        if (in_array($source, ['manual', 'best_sellers'], true)) {
            $order = array_flip($ids);
            $rows = $rows->sortBy(fn ($row) => $order[(int) $row->id] ?? 999)->take($limit);
        }

        $products = $this->products->present($rows, $store, $card, $perUnit, $promos);

        // Los destacados y los de una categoria incluyen servicios; los que se eligen por id son
        // solo articulos.
        $services = in_array($source, ['featured', 'category'], true)
            ? $this->products->presentServices(
                $this->services->query($store)
                    ->when($source === 'featured', fn ($query) => $query->where('is_featured', true))
                    ->when($source === 'category', fn ($query) => $query->whereIn('category_id', $this->withChildren($store, (int) ($payload['value'] ?? 0))))
                    ->orderBy('sort_order')
                    ->limit($limit)
                    ->get(),
                $store,
                $card,
            )
            : [];

        return array_slice([...$services, ...$products], 0, $limit);
    }

    /**
     * Lo que ya compro este cliente en esta empresa, lo mas reciente primero. Sin sesion o sin
     * compras, nada: el bloque no se pinta.
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    public function buyAgain(Store $store, ?Customer $customer, array $data, array $card = []): array
    {
        if (! $customer || (int) $customer->company_id !== (int) $store->company_id) {
            return [];
        }

        $limit = max(2, min(24, (int) ($data['limit'] ?? 8)));

        $ids = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.company_id', $store->company_id)
            ->where('sales.customer_id', $customer->id)
            ->where('sales.status', '!=', 'cancelled')
            ->whereNotNull('sale_items.product_id')
            ->whereRaw(SaleItem::COUNTS_AS_SOLD_SQL)
            ->groupBy('sale_items.product_id')
            ->orderByRaw('MAX(sales.sold_at) DESC')
            ->limit($limit)
            ->pluck('sale_items.product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($ids === []) {
            return [];
        }

        $rows = $this->products->query($store)->whereIn('products.id', $ids)->get();
        $order = array_flip($ids);
        $rows = $rows->sortBy(fn ($row) => $order[(int) $row->id] ?? 999)->values();
        $structure = app(StorefrontConfig::class)->settings($store);

        return $this->products->present($rows, $store, $card ?: ($structure['card'] ?? []), (bool) ($data['show_price_per_unit'] ?? true));
    }

    /**
     * La oferta relampago: la promocion, cuando vence y sus articulos.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $card
     * @return array<string, mixed>
     */
    protected function dealCountdown(array $payload, Store $store, array $card, bool $perUnit): array
    {
        $now = now();
        $query = Promotion::query()
            ->withoutGlobalScopes()
            ->where('company_id', $store->company_id)
            ->where('is_active', true)
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', $now)
            ->where(fn ($inner) => $inner->whereNull('starts_at')->orWhere('starts_at', '<=', $now));

        $promotion = ($payload['source'] ?? 'next_ending') === 'promotion'
            ? $query->find((int) ($payload['promotion_id'] ?? 0))
            : $query->orderBy('ends_at')->get()->first(fn (Promotion $candidate) => $this->promotionProducts($candidate->id, $store) !== []);

        if (! $promotion) {
            return ['promotion' => null, 'products' => []];
        }

        $ids = $this->promotionProducts($promotion->id, $store);
        $limit = max(2, min(16, (int) ($payload['limit'] ?? 8)));
        $rows = $ids === [] ? collect() : $this->products->query($store)->whereIn('products.id', $ids)->orderBy('products.name')->limit(200)->get();
        $promos = $this->promotions->unitPrices($rows, $store->company_id, PromotionService::CHANNEL_ONLINE);
        $rows = $rows->filter(fn ($row) => ($promos[(int) $row->id]['promotion_id'] ?? null) === $promotion->id)->take($limit);

        return [
            'promotion' => [
                'id' => $promotion->id,
                'name' => $promotion->name,
                'ends_at' => $promotion->ends_at?->toIso8601String(),
            ],
            'products' => $this->products->present($rows, $store, $card, $perUnit, $promos),
            'see_all_href' => '/tienda/ofertas',
        ];
    }

    /**
     * El «Ver todo» de un estante: el que se eligio, o el que se deduce de su regla.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function seeAll(array $payload, Store $store): ?string
    {
        if (! empty($payload['see_all_target'])) {
            return null;
        }

        return match ($payload['source'] ?? null) {
            'on_sale', 'promotion' => '/tienda/ofertas',
            'category' => ($slug = $this->structure->categories($store)->firstWhere('id', (int) ($payload['value'] ?? 0))['slug'] ?? null)
                ? '/tienda/categoria/'.$slug
                : null,
            default => null,
        };
    }

    /**
     * Una categoria con sus subcategorias.
     *
     * @return list<int>
     */
    public function withChildren(Store $store, int $categoryId): array
    {
        if ($categoryId <= 0) {
            return [0];
        }

        return [$categoryId, ...$this->structure->categories($store)->where('parent_id', $categoryId)->pluck('id')->all()];
    }

    /**
     * La categoria y su madre, para saber si un bloque limitado a una aplica.
     *
     * @return list<int>
     */
    protected function lineage(Store $store, int $categoryId): array
    {
        $category = $this->structure->categories($store)->firstWhere('id', $categoryId);

        return array_values(array_filter([$categoryId, $category['parent_id'] ?? null]));
    }

    /**
     * @return list<int>
     */
    public function promotionProducts(int $promotionId, Store $store): array
    {
        $promotion = Promotion::query()
            ->withoutGlobalScopes()
            ->where('company_id', $store->company_id)
            ->with('targets')
            ->find($promotionId);

        if (! $promotion) {
            return [];
        }

        $ids = $promotion->targets->where('target_type', PromotionTarget::TYPE_PRODUCT)->pluck('target_id')->all();
        $categories = $promotion->targets->where('target_type', PromotionTarget::TYPE_CATEGORY)->pluck('target_id');

        if ($categories->isNotEmpty()) {
            $ids = array_merge($ids, Product::query()
                ->withoutGlobalScopes()
                ->where('company_id', $store->company_id)
                ->whereIn('category_id', $categories)
                ->pluck('id')
                ->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Lo que mas se ha vendido en la sede en los ultimos treinta dias.
     *
     * @return list<int>
     */
    public function bestSellers(Store $store, int $limit): array
    {
        return SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.store_id', $store->id)
            ->where('sales.status', '!=', 'cancelled')
            ->where('sales.sold_at', '>=', now()->subDays(30))
            ->whereNotNull('sale_items.product_id')
            ->whereRaw(SaleItem::COUNTS_AS_SOLD_SQL)
            ->groupBy('sale_items.product_id')
            ->orderByRaw('SUM(sale_items.quantity) DESC')
            ->limit($limit)
            ->pluck('sale_items.product_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    protected function storePayload(Store $store, array $settings): array
    {
        return [
            'id' => $store->id,
            'name' => $store->name,
            'address' => $store->address,
            'phone' => $store->phone,
            'opens_at' => substr((string) $store->opens_at, 0, 5),
            'closes_at' => substr((string) $store->closes_at, 0, 5),
        ];
    }
}

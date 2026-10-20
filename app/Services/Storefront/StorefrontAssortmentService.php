<?php

namespace App\Services\Storefront;

use App\Models\Category;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Service;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\StorefrontSuggestionDismissal;
use App\Models\Warehouse;
use App\Services\PromotionService;
use App\Services\ServicePricing;
use App\Services\SettingsService;
use App\Services\WarehouseService;
use App\Support\SearchText;
use App\Support\Uploads;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El surtido de la tienda en linea visto desde el panel: que ve el cliente, que esta publicado
 * pero oculto y que conviene publicar.
 *
 * Es el unico que calcula el estado de un articulo frente a la tienda para el panel, y lo hace
 * con la misma expresion de disponible que la tienda (`StorefrontProducts::AVAILABLE_SQL`) sobre
 * la misma bodega (`onlineSourceFor`). Se une **una** bodega, asi que hay una fila por articulo:
 * ningun conteo de aqui suma filas de varias bodegas.
 *
 * Todo sale de una consulta derivada (`catalog`) con los agregados ya calculados; los filtros y
 * los conteos de las vistas rapidas se escriben sobre sus columnas, asi dicen lo mismo.
 */
class StorefrontAssortmentService
{
    public const STATUS_NOT_PUBLISHED = 'not_published';

    public const STATUS_FAMILY_HIDDEN = 'family_hidden';

    public const STATUS_NO_PRICE = 'no_price';

    public const STATUS_OUT_OF_STOCK = 'out_of_stock';

    public const STATUS_VISIBLE = 'visible';

    /** En el orden en que se evaluan: el primero que aplica gana. */
    public const STATUS_LABELS = [
        self::STATUS_NOT_PUBLISHED => 'No publicado',
        self::STATUS_FAMILY_HIDDEN => 'Oculto: artículo principal sin publicar',
        self::STATUS_NO_PRICE => 'Publicado sin precio',
        self::STATUS_OUT_OF_STOCK => 'Publicado sin stock',
        self::STATUS_VISIBLE => 'Visible',
    ];

    public const VIEW_LABELS = [
        'all' => 'Todos',
        'visible' => 'Visibles al cliente',
        'out_of_stock' => 'Publicados sin stock',
        'stock_unpublished' => 'Con stock sin publicar',
        'unpublished' => 'Sin publicar',
        'low_stock' => 'Bajo mínimo',
        'expiring' => 'Lote por vencer',
        'promo' => 'Con promoción',
        'incomplete' => 'Ficha incompleta',
        'featured' => 'Destacados',
        'new' => 'Nuevos (30 días)',
    ];

    public const SORT_LABELS = [
        'name' => 'Nombre',
        'sold_pos_desc' => 'Más vendidos en caja',
        'sold_online_desc' => 'Más vendidos en línea',
        'available_desc' => 'Más disponible',
        'available_asc' => 'Menos disponible',
        'price_asc' => 'Precio tienda: menor a mayor',
        'price_desc' => 'Precio tienda: mayor a menor',
        'recent' => 'Creados recientemente',
        'out_since' => 'Más tiempo sin stock',
    ];

    public const SHEET_LABELS = [
        'complete' => 'Completa',
        'incomplete' => 'Incompleta (foto, descripción o precio)',
        'no_image' => 'Sin foto',
        'no_description' => 'Sin descripción',
    ];

    public const STOCK_LABELS = [
        'all' => 'Todas',
        'in' => 'Con stock',
        'out' => 'Sin stock',
        'low' => 'Bajo mínimo',
    ];

    public const REPLENISHMENT_LABELS = [
        'any' => 'Con reposición en camino',
        'purchase' => 'Compra en camino',
        'transfer' => 'Traslado en camino',
        'transferable' => 'Se puede trasladar',
        'none' => 'Sin reposición prevista',
    ];

    public const GROUP_LABELS = [
        StorefrontSuggestionDismissal::GROUP_POS_SELLERS => 'Se venden en caja y no están en línea',
        StorefrontSuggestionDismissal::GROUP_EXPIRING => 'Lote por vencer',
        StorefrontSuggestionDismissal::GROUP_OVERSTOCK => 'Inventario alto y poca rotación',
        StorefrontSuggestionDismissal::GROUP_INCOMPLETE => 'Completa la ficha antes de publicar',
    ];

    /** Cuantos publica de una vez «Publicar los N más vendidos». */
    public const TOP_SELLERS = 10;

    /** Ventana de ventas que mira el modulo. */
    public const SALES_DAYS = 30;

    public const UNIT_SHORT = [
        Product::UNIT_UNIT => 'und',
        Product::UNIT_KG => 'kg',
        Product::UNIT_G => 'g',
        Product::UNIT_L => 'L',
        Product::UNIT_PACK => 'paq',
    ];

    /** El precio de la tienda: el propio si lo tiene, si no el del mostrador. */
    public const STORE_PRICE_SQL = '(CASE WHEN products.online_price > 0 THEN products.online_price ELSE products.price END)';

    protected const LOW_SQL = '(catalog.available > 0 AND catalog.min_stock > 0 AND catalog.available < catalog.min_stock)';

    /** @var array<string, array<string, mixed>> */
    protected array $contexts = [];

    /** @var array<int, Collection<int, array<string, mixed>>> */
    protected array $trees = [];

    public function __construct(
        protected WarehouseService $warehouses,
        protected PromotionService $promotions,
        protected SettingsService $settings,
        protected StorefrontServiceCatalog $serviceCatalog,
        protected ServicePricing $servicePricing,
    ) {}

    /**
     * Lo que se calcula una vez por sede: la bodega de la tienda, las demas, los umbrales de
     * Ajustes y a que alcanzan las promociones en linea.
     *
     * @return array{warehouse: Warehouse, warehouse_id: int, other_warehouses: Collection<int, Warehouse>, expiring_days: int, overstock_days: int, overstock_factor: float, low_margin: int, reach: array<string, list<int>>}
     */
    public function context(int $companyId, Store $store): array
    {
        return $this->contexts[$companyId.':'.$store->id] ??= (function () use ($companyId, $store) {
            $online = $this->warehouses->onlineSourceFor($store->id);
            $settings = $this->settings->all($companyId);

            return [
                'warehouse' => $online,
                'warehouse_id' => (int) $online->id,
                'other_warehouses' => $this->warehouses->forStore($store->id)->where('id', '!=', $online->id)->values(),
                'expiring_days' => max(0, (int) $settings['storefront_catalog.expiring_days']),
                'overstock_days' => max(1, (int) $settings['storefront_catalog.overstock_days']),
                'overstock_factor' => max(1.0, (float) $settings['storefront_catalog.overstock_min_factor']),
                'low_margin' => max(0, (int) $settings['storefront_catalog.low_margin_percent']),
                'reach' => $this->promotions->reach($companyId, PromotionService::CHANNEL_ONLINE),
            ];
        })();
    }

    /**
     * Los articulos activos de la empresa con su estado frente a la tienda de la sede, en una
     * consulta derivada (`catalog`) sin N+1. Con `$replenishment` trae ademas si hay compra o
     * traslado en camino hacia la bodega de la tienda (solo lo pide el apartado sin stock).
     */
    public function baseQuery(int $companyId, Store $store, bool $replenishment = false): Builder
    {
        $context = $this->context($companyId, $store);
        $warehouseId = $context['warehouse_id'];
        $available = StorefrontProducts::AVAILABLE_SQL;
        $storePrice = self::STORE_PRICE_SQL;

        // Lo vendido en la sede: las devoluciones restan (vienen en negativo) y los articulos de
        // un servicio cerrado no cuentan (van a $0). Las anuladas no entran.
        $sold = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.company_id', $companyId)
            ->where('sales.store_id', $store->id)
            ->whereIn('sales.status', [Sale::STATUS_PAID, Sale::STATUS_CREDIT, Sale::STATUS_REFUNDED])
            ->where('sales.sold_at', '>=', now()->subDays(self::SALES_DAYS))
            ->whereNotNull('sale_items.product_id')
            ->whereRaw(SaleItem::COUNTS_AS_SOLD_SQL)
            ->groupBy('sale_items.product_id')
            ->selectRaw(
                'sale_items.product_id, SUM(CASE WHEN sales.channel = ? THEN sale_items.quantity ELSE 0 END) as sold_pos, SUM(CASE WHEN sales.channel = ? THEN sale_items.quantity ELSE 0 END) as sold_online',
                [Sale::CHANNEL_POS, Sale::CHANNEL_ONLINE],
            );

        // Lo que hay en las demas bodegas de la sede; un negativo no le resta a otra bodega.
        $otherIds = $context['other_warehouses']->pluck('id')->map(fn ($id) => (int) $id)->all();
        $other = DB::table('product_stocks')
            ->whereIn('warehouse_id', $otherIds === [] ? [0] : $otherIds)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(GREATEST(quantity - reserved_quantity, 0)) as other_available');

        // El lote con saldo que vence primero en la bodega de la tienda (la regla de `expiringLots`).
        $lots = DB::table('product_lots')
            ->where('company_id', $companyId)
            ->where('warehouse_id', $warehouseId)
            ->where('quantity', '>', 0)
            ->whereNotNull('expires_at')
            ->groupBy('product_id')
            ->selectRaw('product_id, MIN(expires_at) as next_expiry');

        $groupColumns = collect(StorefrontSuggestionDismissal::GROUPS)
            ->map(fn (string $group) => "MAX(CASE WHEN `group` = '{$group}' THEN 1 ELSE 0 END) as d_{$group}")
            ->implode(', ');
        $dismissed = DB::table('storefront_suggestion_dismissals')
            ->where('store_id', $store->id)
            ->groupBy('product_id')
            ->selectRaw("product_id, {$groupColumns}");

        $familyPublished = '(product_families.id IS NOT NULL AND product_families.deleted_at IS NULL AND product_families.is_active = 1 AND product_families.is_online_visible = 1)';
        $base = "({$storePrice} / (1 + COALESCE(products.tax_rate, 0) / 100))";
        $statusSql = 'CASE'
            ." WHEN products.is_online_visible = 0 THEN '".self::STATUS_NOT_PUBLISHED."'"
            ." WHEN products.product_family_id IS NOT NULL AND NOT {$familyPublished} THEN '".self::STATUS_FAMILY_HIDDEN."'"
            ." WHEN {$storePrice} <= 0 THEN '".self::STATUS_NO_PRICE."'"
            ." WHEN products.allow_negative_stock = 0 AND {$available} <= 0 THEN '".self::STATUS_OUT_OF_STOCK."'"
            ." ELSE '".self::STATUS_VISIBLE."' END";

        $inner = DB::table('products')
            ->leftJoin('product_stocks', function ($join) use ($warehouseId) {
                $join->on('product_stocks.product_id', '=', 'products.id')
                    ->where('product_stocks.warehouse_id', '=', $warehouseId);
            })
            ->leftJoin('product_families', 'product_families.id', '=', 'products.product_family_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoinSub($sold, 'sold', 'sold.product_id', '=', 'products.id')
            ->leftJoinSub($other, 'other_stock', 'other_stock.product_id', '=', 'products.id')
            ->leftJoinSub($lots, 'lots', 'lots.product_id', '=', 'products.id')
            ->leftJoinSub($dismissed, 'dismissed', 'dismissed.product_id', '=', 'products.id')
            ->where('products.company_id', $companyId)
            ->whereNull('products.deleted_at')
            ->where('products.is_active', true)
            ->select([
                'products.id', 'products.name', 'products.search_name', 'products.sku', 'products.barcode', 'products.brand',
                'products.category_id', 'categories.name as category_name', 'products.supplier_id',
                'products.product_family_id', 'product_families.name as family_name', 'products.variant_label',
                'products.unit', 'products.sold_by_weight', 'products.image_path', 'products.thumb_path', 'products.image_url',
                'products.price', 'products.online_price', 'products.cost', 'products.tax_rate', 'products.min_stock',
                'products.allow_negative_stock', 'products.is_online_visible', 'products.is_featured', 'products.created_at',
                'product_stocks.out_of_stock_since',
            ])
            ->selectRaw('COALESCE(product_stocks.quantity, 0) as stock_quantity')
            ->selectRaw('COALESCE(product_stocks.reserved_quantity, 0) as reserved_quantity')
            ->selectRaw("{$available} as available")
            ->selectRaw("{$storePrice} as store_price")
            ->selectRaw('COALESCE(sold.sold_pos, 0) as sold_pos_30d')
            ->selectRaw('COALESCE(sold.sold_online, 0) as sold_online_30d')
            ->selectRaw('COALESCE(other_stock.other_available, 0) as other_available')
            ->selectRaw("CASE WHEN COALESCE(products.image_path, '') <> '' OR COALESCE(products.image_url, '') <> '' OR EXISTS (SELECT 1 FROM product_images WHERE product_images.product_id = products.id) THEN 1 ELSE 0 END as has_image")
            ->selectRaw("CASE WHEN COALESCE(TRIM(products.description), '') <> '' THEN 1 ELSE 0 END as has_description")
            ->selectRaw("CASE WHEN {$storePrice} > 0 THEN ROUND(({$base} - COALESCE(products.cost, 0)) / {$base} * 100, 1) ELSE NULL END as margin_pct")
            ->selectRaw('DATEDIFF(lots.next_expiry, ?) as expiring_lot_days', [now()->toDateString()])
            ->selectRaw($this->promotionSql($context['reach']).' as has_active_promotion')
            ->selectRaw("CASE WHEN {$familyPublished} THEN 1 ELSE 0 END as family_published")
            ->selectRaw("{$statusSql} as status_key");

        foreach (StorefrontSuggestionDismissal::GROUPS as $group) {
            $inner->selectRaw("COALESCE(dismissed.d_{$group}, 0) as d_{$group}");
        }

        if ($replenishment) {
            $inner->selectRaw($this->purchaseExistsSql($companyId, $warehouseId).' as has_purchase')
                ->selectRaw($this->transferExistsSql($companyId, $warehouseId).' as has_transfer');
        }

        return DB::query()->fromSub($inner, 'catalog');
    }

    /**
     * Los filtros del panel, limpios. Todo es texto porque viaja por la URL (`useFilters`).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function normalizeFilters(array $input): array
    {
        $text = fn (string $key) => trim((string) ($input[$key] ?? ''));
        $digits = fn (string $key) => preg_match('/^\d+$/', $text($key)) ? $text($key) : '';
        $flag = fn (string $key) => $text($key) === '1' ? '1' : '';
        $pick = fn (string $key, array $allowed) => array_key_exists($text($key), $allowed) ? $text($key) : '';

        $view = $pick('view', self::VIEW_LABELS);
        $stock = $pick('stock', self::STOCK_LABELS);
        $sort = $pick('sort', self::SORT_LABELS);

        return [
            'view' => $view === 'all' ? '' : $view,
            'search' => mb_substr($text('search'), 0, 100),
            'category' => $digits('category'),
            'brand' => mb_substr($text('brand'), 0, 100),
            'supplier' => $digits('supplier'),
            'stock' => $stock === 'all' ? '' : $stock,
            'status' => $pick('status', self::STATUS_LABELS),
            'sheet' => $pick('sheet', self::SHEET_LABELS),
            'price_min' => $digits('price_min'),
            'price_max' => $digits('price_max'),
            'promo' => $flag('promo'),
            'expiring_days' => $digits('expiring_days'),
            'no_online_sales' => $flag('no_online_sales'),
            'price_differs' => $flag('price_differs'),
            'margin_below' => $digits('margin_below'),
            'other_warehouse' => $flag('other_warehouse'),
            'sort' => $sort === 'name' ? '' : $sort,
            'family' => $digits('family'),
        ];
    }

    /**
     * Lo que acota el universo: buscar, categoria (con sus subcategorias), marca, proveedor y
     * articulo principal.
     *
     * @param  array<string, string>  $filters
     */
    public function applyScope(Builder $query, int $companyId, array $filters): Builder
    {
        $raw = trim((string) ($filters['search'] ?? ''));

        if ($raw !== '') {
            $terms = SearchText::terms($raw);

            $query->where(function (Builder $outer) use ($terms, $raw) {
                if ($terms !== []) {
                    $outer->orWhere(function (Builder $keyed) use ($terms) {
                        foreach ($terms as $term) {
                            $keyed->where('catalog.search_name', 'like', '%'.$term.'%');
                        }
                    });
                }

                // El codigo de barras se compara como texto: los ceros de la izquierda cuentan.
                $outer->orWhere(fn (Builder $plain) => $plain->whereNull('catalog.search_name')->where('catalog.name', 'like', '%'.$raw.'%'))
                    ->orWhere('catalog.sku', 'like', '%'.$raw.'%')
                    ->orWhere('catalog.barcode', 'like', '%'.$raw.'%')
                    ->orWhere('catalog.brand', 'like', '%'.$raw.'%');
            });
        }

        if (($filters['category'] ?? '') !== '') {
            $query->whereIn('catalog.category_id', $this->descendants($companyId, (int) $filters['category']));
        }

        if (($filters['brand'] ?? '') !== '') {
            $query->where('catalog.brand', $filters['brand']);
        }

        if (($filters['supplier'] ?? '') !== '') {
            $supplierId = (int) $filters['supplier'];

            // Cualquiera de sus proveedores, no solo el principal.
            $query->where(fn (Builder $inner) => $inner
                ->where('catalog.supplier_id', $supplierId)
                ->orWhereExists(fn (Builder $exists) => $exists->from('product_suppliers')
                    ->whereColumn('product_suppliers.product_id', 'catalog.id')
                    ->where('product_suppliers.supplier_id', $supplierId)));
        }

        if (($filters['family'] ?? '') !== '') {
            $query->where('catalog.product_family_id', (int) $filters['family']);
        }

        return $query;
    }

    /**
     * Los filtros que leen columnas calculadas (existencias, estado, ficha, precio...). La vista
     * rapida va aparte (`applyView`) para que los conteos de los chips la puedan dejar por fuera.
     *
     * @param  array<string, string>  $filters
     */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        match ($filters['stock'] ?? '') {
            'in' => $query->where('catalog.available', '>', 0),
            'out' => $query->where('catalog.available', '<=', 0),
            'low' => $query->whereRaw(self::LOW_SQL),
            default => null,
        };

        if (($filters['status'] ?? '') !== '') {
            $query->where('catalog.status_key', $filters['status']);
        }

        match ($filters['sheet'] ?? '') {
            'complete' => $query->where('catalog.has_image', 1)->where('catalog.has_description', 1)->where('catalog.store_price', '>', 0),
            'incomplete' => $query->whereRaw('(catalog.has_image = 0 OR catalog.has_description = 0 OR catalog.store_price <= 0)'),
            'no_image' => $query->where('catalog.has_image', 0),
            'no_description' => $query->where('catalog.has_description', 0),
            default => null,
        };

        if (($filters['price_min'] ?? '') !== '') {
            $query->where('catalog.store_price', '>=', (int) $filters['price_min']);
        }

        if (($filters['price_max'] ?? '') !== '') {
            $query->where('catalog.store_price', '<=', (int) $filters['price_max']);
        }

        if (($filters['promo'] ?? '') === '1') {
            $query->where('catalog.has_active_promotion', 1);
        }

        if (($filters['expiring_days'] ?? '') !== '') {
            $query->whereNotNull('catalog.expiring_lot_days')->where('catalog.expiring_lot_days', '<=', (int) $filters['expiring_days']);
        }

        if (($filters['no_online_sales'] ?? '') === '1') {
            $query->where('catalog.is_online_visible', 1)->where('catalog.sold_online_30d', '<=', 0);
        }

        if (($filters['price_differs'] ?? '') === '1') {
            $query->where('catalog.online_price', '>', 0)->whereColumn('catalog.online_price', '<>', 'catalog.price');
        }

        if (($filters['margin_below'] ?? '') !== '') {
            $query->whereNotNull('catalog.margin_pct')->where('catalog.margin_pct', '<', (int) $filters['margin_below']);
        }

        if (($filters['other_warehouse'] ?? '') === '1') {
            $query->where('catalog.other_available', '>', 0);
        }

        return $query;
    }

    public function applyView(Builder $query, string $view, int $companyId, Store $store): Builder
    {
        $conditions = $this->viewConditions($this->context($companyId, $store));

        if (isset($conditions[$view])) {
            $query->whereRaw($conditions[$view]);
        }

        return $query;
    }

    public function applySort(Builder $query, string $sort): Builder
    {
        match ($sort) {
            'sold_pos_desc' => $query->orderByDesc('catalog.sold_pos_30d'),
            'sold_online_desc' => $query->orderByDesc('catalog.sold_online_30d'),
            'available_desc' => $query->orderByDesc('catalog.available'),
            'available_asc' => $query->orderBy('catalog.available'),
            'price_asc' => $query->orderBy('catalog.store_price'),
            'price_desc' => $query->orderByDesc('catalog.store_price'),
            'recent' => $query->orderByDesc('catalog.created_at'),
            // Los que llevan mas tiempo sin stock primero; los que tienen existencia al final.
            'out_since' => $query->orderByRaw('catalog.out_of_stock_since IS NULL')->orderBy('catalog.out_of_stock_since'),
            default => null,
        };

        return $query->orderBy('catalog.name')->orderBy('catalog.id');
    }

    /**
     * La pestaña de articulos ya filtrada y ordenada, sin paginar (la usan la tabla y el CSV).
     *
     * @param  array<string, string>  $filters
     */
    public function filtered(int $companyId, Store $store, array $filters, bool $replenishment = false): Builder
    {
        $query = $this->baseQuery($companyId, $store, $replenishment);
        $this->applyScope($query, $companyId, $filters);
        $this->applyFilters($query, $filters);
        $this->applyView($query, $filters['view'] ?? '', $companyId, $store);

        return $this->applySort($query, $filters['sort'] ?? '');
    }

    /**
     * Indicadores del modulo: sobre todos los articulos activos de la sede, sin filtros.
     *
     * @return array<string, int>
     */
    public function metrics(int $companyId, Store $store): array
    {
        return $this->aggregate($this->baseQuery($companyId, $store), $this->context($companyId, $store));
    }

    /**
     * Cuantos hay en cada vista rapida con los demas filtros puestos, para que el numero del chip
     * diga cuantos quedan si lo eligen.
     *
     * @param  array<string, string>  $filters
     * @return list<array{value: string, label: string, count: int, tone: string}>
     */
    public function quickViews(int $companyId, Store $store, array $filters): array
    {
        $query = $this->baseQuery($companyId, $store);
        $this->applyScope($query, $companyId, $filters);
        $this->applyFilters($query, $filters);
        $counts = $this->aggregate($query, $this->context($companyId, $store));

        return collect(self::VIEW_LABELS)->map(fn (string $label, string $value) => [
            'value' => $value,
            'label' => $label,
            'count' => $value === 'all' ? $counts['active'] : ($counts['view_'.$value] ?? 0),
            'tone' => match ($value) {
                'out_of_stock' => 'warn',
                'visible' => 'accent',
                default => 'neutral',
            },
        ])->values()->all();
    }

    /**
     * Estado en tienda de una fila de `baseQuery()`. Es la misma precedencia del `CASE` de SQL;
     * esta existe para quien arma una fila a mano y para las pruebas.
     */
    public function statusOf(object $row): string
    {
        if (! (bool) $row->is_online_visible) {
            return self::STATUS_NOT_PUBLISHED;
        }

        if (! empty($row->product_family_id) && ! (bool) ($row->family_published ?? false)) {
            return self::STATUS_FAMILY_HIDDEN;
        }

        if ((int) $row->store_price <= 0) {
            return self::STATUS_NO_PRICE;
        }

        if (! (bool) $row->allow_negative_stock && (float) $row->available <= 0) {
            return self::STATUS_OUT_OF_STOCK;
        }

        return self::STATUS_VISIBLE;
    }

    /**
     * Las filas de la pagina, listas para la tabla: precio vigente de `PromotionService`, la
     * bodega de la sede que mas tiene, la miniatura resuelta y las marcas secundarias.
     *
     * @param  iterable<object>  $rows
     * @param  array<string, bool>  $can
     * @return list<array<string, mixed>>
     */
    public function presentRows(iterable $rows, int $companyId, Store $store, array $can = []): array
    {
        $rows = collect($rows);

        if ($rows->isEmpty()) {
            return [];
        }

        $context = $this->context($companyId, $store);
        $prices = $this->promotions->unitPrices($rows, $companyId, PromotionService::CHANNEL_ONLINE);
        $tops = $this->topOtherWarehouses($rows->where('other_available', '>', 0)->pluck('id')->all(), $context);
        $gallery = $this->firstImages($rows->filter(fn ($row) => ! $this->thumbOf($row) && (int) $row->has_image === 1)->pluck('id')->all());

        return $rows->map(function (object $row) use ($prices, $tops, $gallery, $context, $can) {
            $status = $this->statusOf($row);
            $available = round((float) $row->available, 3);
            $top = $tops[(int) $row->id] ?? null;

            return [
                'id' => (int) $row->id,
                'name' => $row->name,
                'sku' => (string) $row->sku,
                // Texto siempre: los ceros de la izquierda del codigo de barras son parte de el.
                'barcode' => $row->barcode !== null ? (string) $row->barcode : null,
                'brand' => $row->brand,
                'category' => $row->category_name,
                'family' => $row->family_name,
                'family_id' => $row->product_family_id !== null ? (int) $row->product_family_id : null,
                'variant_label' => $row->variant_label,
                'thumb_url' => Uploads::url($this->thumbOf($row) ?? ($gallery[(int) $row->id] ?? null)),
                'available' => $available,
                'stock_quantity' => round((float) $row->stock_quantity, 3),
                'reserved_quantity' => round((float) $row->reserved_quantity, 3),
                'min_stock' => $row->min_stock !== null ? round((float) $row->min_stock, 3) : null,
                'stock_unit' => self::UNIT_SHORT[$row->unit] ?? 'und',
                'allow_negative_stock' => (bool) $row->allow_negative_stock,
                'sold_pos_30d' => round((float) $row->sold_pos_30d, 3),
                'sold_online_30d' => round((float) $row->sold_online_30d, 3),
                'price' => (int) $row->price,
                'online_price' => (int) $row->online_price > 0 ? (int) $row->online_price : null,
                'current_price' => (int) ($prices[(int) $row->id]['price'] ?? $row->store_price),
                'is_online_visible' => (bool) $row->is_online_visible,
                'is_featured' => (bool) $row->is_featured,
                'status' => $status,
                'status_label' => self::STATUS_LABELS[$status],
                'status_note' => $this->statusNote($status, $row),
                'out_of_stock_since' => $row->out_of_stock_since ? (string) $row->out_of_stock_since : null,
                'other_available' => round((float) $row->other_available, 3),
                'top_other_warehouse' => $top,
                'expiring_lot_days' => $row->expiring_lot_days !== null ? (int) $row->expiring_lot_days : null,
                'flags' => $this->flags($row, $context, $top, $can),
            ];
        })->values()->all();
    }

    /**
     * Las marcas que se pintan debajo del nombre. No cambian el estado.
     *
     * @param  array<string, mixed>  $context
     * @param  array{id: int, name: string, available: float}|null  $top
     * @param  array<string, bool>  $can
     * @return list<array{key: string, label: string, href?: string}>
     */
    public function flags(object $row, array $context, ?array $top = null, array $can = []): array
    {
        $flags = [];
        $available = (float) $row->available;
        $low = $available > 0 && (float) $row->min_stock > 0 && $available < (float) $row->min_stock;
        $edit = ($can['products_edit'] ?? false) ? route('products.edit', $row->id) : null;

        if ($low) {
            $flags[] = ['key' => 'low_stock', 'label' => 'Bajo mínimo'];
        }

        if ($row->expiring_lot_days !== null && (int) $row->expiring_lot_days <= $context['expiring_days']) {
            $flags[] = ['key' => 'expiring', 'label' => $this->expiryLabel((int) $row->expiring_lot_days)];
        }

        foreach ([['has_image', 'no_image', 'Sin foto'], ['has_description', 'no_description', 'Sin descripción']] as [$column, $key, $label]) {
            if ((int) $row->{$column} === 0) {
                $flags[] = array_filter(['key' => 'incomplete', 'label' => $label, 'href' => $edit]);
            }
        }

        if (! (bool) $row->is_online_visible && $available > 0 && (float) $row->sold_pos_30d > 0) {
            $flags[] = ['key' => 'suggested', 'label' => 'Sugerido · se vende en caja'];
        }

        if ((int) $row->has_active_promotion === 1) {
            $flags[] = ['key' => 'promo', 'label' => 'Promoción activa'];
        }

        // La otra bodega solo importa cuando a la de la tienda le falta.
        if ($top !== null && ($available <= 0 || $low)) {
            $flags[] = array_filter([
                'key' => 'other_warehouse',
                'label' => "{$top['name']} de la sede tiene ".self::quantity($top['available']),
                'href' => ($can['transfer_requests_create'] ?? false)
                    ? route('transfer_requests.create', ['to_warehouse' => $context['warehouse_id'], 'from_warehouse' => $top['id'], 'products' => [(int) $row->id]])
                    : null,
            ]);
        }

        return array_values($flags);
    }

    /**
     * Cobertura por categoria raiz (sumando sus subcategorias): visibles, publicados sin stock,
     * con stock sin publicar y el resto, mas cuantas sugerencias tiene.
     *
     * @return list<array<string, mixed>>
     */
    public function categoryCoverage(int $companyId, Store $store, ?int $limit = 5): array
    {
        $context = $this->context($companyId, $store);
        $views = $this->viewConditions($context);
        $suggested = $this->suggestionConditions($context)['any'];

        $rows = $this->baseQuery($companyId, $store)
            ->whereNotNull('catalog.category_id')
            ->groupBy('catalog.category_id')
            ->selectRaw('catalog.category_id, COUNT(*) as active, SUM(catalog.is_online_visible) as published')
            ->selectRaw("SUM(CASE WHEN {$views['visible']} THEN 1 ELSE 0 END) as visible")
            ->selectRaw("SUM(CASE WHEN {$views['out_of_stock']} THEN 1 ELSE 0 END) as out_of_stock")
            ->selectRaw("SUM(CASE WHEN {$views['stock_unpublished']} THEN 1 ELSE 0 END) as stock_unpublished")
            ->selectRaw("SUM(CASE WHEN {$suggested} THEN 1 ELSE 0 END) as suggestions")
            ->get();

        $tree = $this->categoryTree($companyId)->keyBy('id');
        $roots = [];

        foreach ($rows as $row) {
            $node = $tree->get((int) $row->category_id);

            if (! $node) {
                continue;
            }

            $rootId = $node['root_id'];
            $roots[$rootId] ??= ['id' => $rootId, 'name' => $tree->get($rootId)['name'] ?? $node['name'], 'active' => 0, 'published' => 0, 'visible' => 0, 'out_of_stock' => 0, 'stock_unpublished' => 0, 'suggestions' => 0];

            foreach (['active', 'published', 'visible', 'out_of_stock', 'stock_unpublished', 'suggestions'] as $key) {
                $roots[$rootId][$key] += (int) $row->{$key};
            }
        }

        return collect($roots)
            ->map(fn (array $root) => $root + ['rest' => max(0, $root['active'] - $root['visible'] - $root['out_of_stock'] - $root['stock_unpublished'])])
            ->sortBy([['active', 'desc'], ['name', 'asc']])
            ->when($limit !== null, fn (Collection $roots) => $roots->take($limit))
            ->values()
            ->all();
    }

    /**
     * El apartado «Publicados sin stock»: los publicados que la tienda esconde porque la bodega
     * que la respalda no tiene disponible, con su reposicion.
     *
     * @param  array<string, string>  $filters  search, category, replenishment
     */
    public function outOfStock(int $companyId, Store $store, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->outOfStockQuery($companyId, $store, $filters)
            ->orderByRaw('catalog.out_of_stock_since IS NULL')
            ->orderBy('catalog.out_of_stock_since')
            ->orderBy('catalog.name')
            ->orderBy('catalog.id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @param  array<string, string>  $filters */
    public function outOfStockQuery(int $companyId, Store $store, array $filters): Builder
    {
        $query = $this->baseQuery($companyId, $store, replenishment: true)
            ->where('catalog.status_key', self::STATUS_OUT_OF_STOCK);

        $this->applyScope($query, $companyId, array_intersect_key($filters, array_flip(['search', 'category'])));

        $incoming = '(catalog.has_purchase = 1 OR catalog.has_transfer = 1)';

        match ($filters['replenishment'] ?? '') {
            'any' => $query->whereRaw($incoming),
            'purchase' => $query->where('catalog.has_purchase', 1),
            'transfer' => $query->where('catalog.has_transfer', 1),
            'transferable' => $query->whereRaw("NOT {$incoming}")->where('catalog.other_available', '>', 0),
            'none' => $query->whereRaw("NOT {$incoming}")->where('catalog.other_available', '<=', 0),
            default => null,
        };

        return $query;
    }

    /**
     * Indicadores del apartado. Reposicion, transferibles y sin reposicion suman el total: un
     * articulo con algo en camino no se cuenta como transferible aunque otra bodega tenga.
     *
     * @return array<string, int>
     */
    public function outOfStockMetrics(int $companyId, Store $store): array
    {
        $incoming = '(catalog.has_purchase = 1 OR catalog.has_transfer = 1)';

        $row = $this->baseQuery($companyId, $store, replenishment: true)
            ->where('catalog.status_key', self::STATUS_OUT_OF_STOCK)
            ->selectRaw('COUNT(*) as hidden, SUM(catalog.is_featured) as featured')
            ->selectRaw("SUM(CASE WHEN {$incoming} THEN 1 ELSE 0 END) as replenishing")
            ->selectRaw('SUM(CASE WHEN catalog.has_transfer = 1 THEN 1 ELSE 0 END) as replenishing_transfers')
            ->selectRaw('SUM(CASE WHEN catalog.has_transfer = 0 AND catalog.has_purchase = 1 THEN 1 ELSE 0 END) as replenishing_purchases')
            ->selectRaw("SUM(CASE WHEN NOT {$incoming} AND catalog.other_available > 0 THEN 1 ELSE 0 END) as transferable")
            ->selectRaw("SUM(CASE WHEN NOT {$incoming} AND catalog.other_available <= 0 THEN 1 ELSE 0 END) as no_replenishment")
            ->first();

        return collect(['hidden', 'featured', 'replenishing', 'replenishing_purchases', 'replenishing_transfers', 'transferable', 'no_replenishment'])
            ->mapWithKeys(fn (string $key) => [$key => (int) ($row->{$key} ?? 0)])
            ->all();
    }

    /**
     * La reposicion mas proxima de cada articulo hacia la bodega de la tienda: el traslado que ya
     * viene en camino, o si no la compra pendiente que llega primero.
     *
     * @param  list<int>  $productIds
     * @return array<int, array{kind: string, id: int, code: string, expected_at: string|null, quantity: float}>
     */
    public function replenishmentFor(array $productIds, int $companyId, Store $store): array
    {
        if ($productIds === []) {
            return [];
        }

        $warehouseId = $this->context($companyId, $store)['warehouse_id'];
        $result = [];

        $purchases = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.company_id', $companyId)
            ->where('purchase_orders.warehouse_id', $warehouseId)
            ->whereIn('purchase_orders.status', [PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_PARTIAL])
            ->whereIn('purchase_order_items.product_id', $productIds)
            ->whereRaw('purchase_order_items.quantity - purchase_order_items.received_quantity > 0')
            ->orderByRaw('purchase_orders.expected_at IS NULL')
            ->orderBy('purchase_orders.expected_at')
            ->orderBy('purchase_orders.id')
            ->get(['purchase_order_items.product_id', 'purchase_orders.id', 'purchase_orders.code', 'purchase_orders.expected_at', DB::raw('purchase_order_items.quantity - purchase_order_items.received_quantity as pending')]);

        foreach ($purchases as $purchase) {
            $result[(int) $purchase->product_id] ??= [
                'kind' => 'purchase',
                'id' => (int) $purchase->id,
                'code' => (string) $purchase->code,
                'expected_at' => $purchase->expected_at ? substr((string) $purchase->expected_at, 0, 10) : null,
                'quantity' => round((float) $purchase->pending, 3),
            ];
        }

        $transfers = DB::table('stock_transfer_items')
            ->join('stock_transfers', 'stock_transfers.id', '=', 'stock_transfer_items.stock_transfer_id')
            ->where('stock_transfers.company_id', $companyId)
            ->where('stock_transfers.to_warehouse_id', $warehouseId)
            ->where('stock_transfers.status', StockTransfer::STATUS_IN_TRANSIT)
            ->whereIn('stock_transfer_items.product_id', $productIds)
            ->orderBy('stock_transfers.dispatched_at')
            ->orderBy('stock_transfers.id')
            ->get(['stock_transfer_items.product_id', 'stock_transfers.id', 'stock_transfers.number', 'stock_transfer_items.quantity']);

        $seen = [];

        foreach ($transfers as $transfer) {
            $productId = (int) $transfer->product_id;

            // Un traslado despachado ya viene en camino: gana sobre una compra por llegar.
            if (! isset($seen[$productId])) {
                $seen[$productId] = true;
                $result[$productId] = [
                    'kind' => 'transfer',
                    'id' => (int) $transfer->id,
                    'code' => (string) $transfer->number,
                    'expected_at' => null,
                    'quantity' => round((float) $transfer->quantity, 3),
                ];
            }
        }

        return $result;
    }

    /**
     * Las sugerencias de publicar, por grupo: sus primeros `$take` y el total de cada uno.
     *
     * @param  array<string, string>  $filters  search, category
     * @return array{groups: list<array<string, mixed>>, total: int, top_seller_ids: list<int>}
     */
    public function suggestions(int $companyId, Store $store, array $filters = [], int $take = 3): array
    {
        $context = $this->context($companyId, $store);
        $conditions = $this->suggestionConditions($context);
        $scoped = fn () => $this->applyScope($this->baseQuery($companyId, $store), $companyId, array_intersect_key($filters, array_flip(['search', 'category'])));

        $counts = $scoped();
        foreach ($conditions as $key => $sql) {
            $counts->selectRaw("SUM(CASE WHEN {$sql} THEN 1 ELSE 0 END) as c_{$key}");
        }
        $counts = $counts->first();

        $orders = [
            StorefrontSuggestionDismissal::GROUP_POS_SELLERS => fn (Builder $query) => $query->orderByDesc('catalog.sold_pos_30d'),
            StorefrontSuggestionDismissal::GROUP_EXPIRING => fn (Builder $query) => $query->orderBy('catalog.expiring_lot_days'),
            // Sin ventas es cobertura infinita: van primero.
            StorefrontSuggestionDismissal::GROUP_OVERSTOCK => fn (Builder $query) => $query->orderByRaw('catalog.sold_pos_30d <= 0 DESC')
                ->orderByRaw('catalog.available / NULLIF(catalog.sold_pos_30d, 0) DESC')
                ->orderByDesc('catalog.available'),
            StorefrontSuggestionDismissal::GROUP_INCOMPLETE => fn (Builder $query) => $query->orderByDesc('catalog.sold_pos_30d'),
        ];

        $groups = [];
        $topSellers = [];

        foreach (StorefrontSuggestionDismissal::GROUPS as $group) {
            $query = $scoped()->whereRaw($conditions[$group]);
            $orders[$group]($query)->orderBy('catalog.name')->orderBy('catalog.id');
            $rows = (clone $query)->limit($take)->get();

            if ($group === StorefrontSuggestionDismissal::GROUP_POS_SELLERS) {
                $topSellers = (clone $query)->limit(self::TOP_SELLERS)->pluck('catalog.id')->map(fn ($id) => (int) $id)->all();
            }

            $groups[] = [
                'key' => $group,
                'title' => self::GROUP_LABELS[$group],
                'criteria' => $this->groupCriteria($group, $context),
                'count' => (int) ($counts->{'c_'.$group} ?? 0),
                'items' => $this->presentSuggestions($rows, $group, $companyId, $store),
                'view' => $this->groupView($group),
            ];
        }

        return [
            'groups' => $groups,
            'total' => (int) ($counts->c_any ?? 0),
            'top_seller_ids' => $topSellers,
        ];
    }

    /**
     * Tres sugerencias para la columna del catalogo: la primera de cada grupo que vende, en orden.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    public function preview(array $groups, int $limit = 3): array
    {
        $picked = [];
        $seen = [];
        $lists = collect($groups)->where('key', '!=', StorefrontSuggestionDismissal::GROUP_INCOMPLETE)->pluck('items')->all();

        for ($round = 0; count($picked) < $limit && $round < 3; $round++) {
            foreach ($lists as $items) {
                $item = $items[$round] ?? null;

                if ($item && ! isset($seen[$item['id']]) && count($picked) < $limit) {
                    $seen[$item['id']] = true;
                    $picked[] = $item;
                }
            }
        }

        return $picked;
    }

    /**
     * Los descartes de la sede, con quien y cuando.
     *
     * @return list<array<string, mixed>>
     */
    public function dismissals(int $companyId, Store $store): array
    {
        return StorefrontSuggestionDismissal::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('store_id', $store->id)
            ->with(['product' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'name', 'sku']), 'dismissedBy:id,name'])
            ->orderByDesc('dismissed_at')
            ->get()
            ->filter(fn (StorefrontSuggestionDismissal $dismissal) => $dismissal->product !== null)
            ->map(fn (StorefrontSuggestionDismissal $dismissal) => [
                'id' => $dismissal->id,
                'product_id' => $dismissal->product_id,
                'name' => $dismissal->product->name,
                'sku' => (string) $dismissal->product->sku,
                'group' => $dismissal->group,
                'group_label' => self::GROUP_LABELS[$dismissal->group] ?? $dismissal->group,
                'dismissed_at' => $dismissal->dismissed_at?->toDateTimeString(),
                'dismissed_by' => $dismissal->dismissedBy?->name,
            ])
            ->values()
            ->all();
    }

    /**
     * Pestaña «Con subarticulos»: una fila por articulo principal, con lo que suman sus
     * presentaciones en la bodega de la tienda. La tarjeta se oculta solo cuando ninguna
     * presentacion publicada tiene disponible.
     *
     * @param  array<string, string>  $filters
     */
    public function familiesQuery(int $companyId, Store $store, array $filters): Builder
    {
        $variants = $this->baseQuery($companyId, $store, replenishment: true)
            ->whereNotNull('catalog.product_family_id')
            ->groupBy('catalog.product_family_id')
            ->selectRaw('catalog.product_family_id as family_id, COUNT(*) as variants_total, SUM(catalog.is_online_visible) as variants_published')
            ->selectRaw('SUM(CASE WHEN catalog.is_online_visible = 1 AND catalog.store_price > 0 THEN 1 ELSE 0 END) as variants_priced')
            ->selectRaw('SUM(CASE WHEN catalog.is_online_visible = 1 AND catalog.store_price > 0 AND (catalog.allow_negative_stock = 1 OR catalog.available > 0) THEN 1 ELSE 0 END) as variants_available')
            ->selectRaw('SUM(GREATEST(catalog.available, 0)) as available, SUM(catalog.other_available) as other_available')
            ->selectRaw('SUM(catalog.sold_pos_30d) as sold_pos_30d, SUM(catalog.sold_online_30d) as sold_online_30d')
            ->selectRaw('MIN(CASE WHEN catalog.store_price > 0 THEN catalog.store_price END) as price_from, MIN(catalog.price) as counter_from')
            ->selectRaw('MAX(catalog.has_purchase) as has_purchase, MAX(catalog.has_transfer) as has_transfer')
            ->selectRaw('MAX(catalog.has_active_promotion) as has_active_promotion');

        $statusSql = 'CASE'
            ." WHEN product_families.is_online_visible = 0 OR variants.variants_published = 0 THEN '".self::STATUS_NOT_PUBLISHED."'"
            ." WHEN variants.variants_priced = 0 THEN '".self::STATUS_NO_PRICE."'"
            ." WHEN variants.variants_available = 0 THEN '".self::STATUS_OUT_OF_STOCK."'"
            ." ELSE '".self::STATUS_VISIBLE."' END";

        $inner = DB::table('product_families')
            ->joinSub($variants, 'variants', 'variants.family_id', '=', 'product_families.id')
            ->leftJoin('categories', 'categories.id', '=', 'product_families.category_id')
            ->where('product_families.company_id', $companyId)
            ->whereNull('product_families.deleted_at')
            ->where('product_families.is_active', true)
            ->select([
                'product_families.id', 'product_families.name', 'product_families.search_name', 'product_families.code',
                'product_families.brand', 'product_families.category_id', 'categories.name as category_name',
                'product_families.is_online_visible', 'product_families.is_featured', 'product_families.created_at',
                'variants.variants_total', 'variants.variants_published', 'variants.variants_available', 'variants.available',
                'variants.other_available', 'variants.sold_pos_30d', 'variants.sold_online_30d', 'variants.price_from',
                'variants.counter_from', 'variants.has_purchase', 'variants.has_transfer', 'variants.has_active_promotion',
            ])
            ->selectRaw("CASE WHEN COALESCE(TRIM(product_families.description), '') <> '' THEN 1 ELSE 0 END as has_description")
            ->selectRaw('CASE WHEN EXISTS (SELECT 1 FROM product_family_images WHERE product_family_images.product_family_id = product_families.id) THEN 1 ELSE 0 END as has_image')
            ->selectRaw("{$statusSql} as status_key");

        $query = DB::query()->fromSub($inner, 'catalog');
        $raw = trim((string) ($filters['search'] ?? ''));

        if ($raw !== '') {
            $terms = SearchText::terms($raw);
            $query->where(function (Builder $outer) use ($terms, $raw) {
                if ($terms !== []) {
                    $outer->orWhere(function (Builder $keyed) use ($terms) {
                        foreach ($terms as $term) {
                            $keyed->where('catalog.search_name', 'like', '%'.$term.'%');
                        }
                    });
                }

                $outer->orWhere('catalog.name', 'like', '%'.$raw.'%')->orWhere('catalog.code', 'like', '%'.$raw.'%');
            });
        }

        if (($filters['category'] ?? '') !== '') {
            $query->whereIn('catalog.category_id', $this->descendants($companyId, (int) $filters['category']));
        }

        if (($filters['brand'] ?? '') !== '') {
            $query->where('catalog.brand', $filters['brand']);
        }

        if (($filters['status'] ?? '') !== '') {
            $query->where('catalog.status_key', $filters['status']);
        }

        if (($filters['promo'] ?? '') === '1') {
            $query->where('catalog.has_active_promotion', 1);
        }

        $this->applyGroupedView($query, $filters['view'] ?? '');

        return $query;
    }

    /**
     * Las filas de la pestaña «Con subarticulos», con el «desde» vigente de sus presentaciones
     * publicadas (el mismo que pinta la tarjeta de la tienda).
     *
     * @param  iterable<object>  $rows
     * @return list<array<string, mixed>>
     */
    public function presentFamilies(iterable $rows, int $companyId): array
    {
        $rows = collect($rows);

        if ($rows->isEmpty()) {
            return [];
        }

        $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $variants = Product::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('product_family_id', $ids)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('is_online_visible', true)
            ->get(['id', 'product_family_id', 'category_id', 'price', 'online_price']);
        $prices = $this->promotions->unitPrices($variants, $companyId, PromotionService::CHANNEL_ONLINE);
        $from = $variants->groupBy('product_family_id')->map(fn (Collection $group) => $group
            ->map(fn (Product $variant) => (int) ($prices[$variant->id]['price'] ?? $variant->storePrice()))
            ->filter(fn (int $price) => $price > 0)
            ->min());
        $images = DB::table('product_family_images')->whereIn('product_family_id', $ids)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['product_family_id', 'path', 'thumb_path'])
            ->groupBy('product_family_id')
            ->map(fn (Collection $group) => $group->first()->thumb_path ?: $group->first()->path);

        return $rows->map(function (object $row) use ($from, $images) {
            $status = (string) $row->status_key;
            $published = (int) $row->variants_published;

            return [
                'id' => (int) $row->id,
                'name' => $row->name,
                'sku' => (string) ($row->code ?? ''),
                'barcode' => null,
                'brand' => $row->brand,
                'category' => $row->category_name,
                'family' => null,
                'variant_label' => null,
                'thumb_url' => Uploads::url($images->get((int) $row->id)),
                'available' => round((float) $row->available, 3),
                'min_stock' => null,
                'stock_unit' => 'und',
                'sold_pos_30d' => round((float) $row->sold_pos_30d, 3),
                'sold_online_30d' => round((float) $row->sold_online_30d, 3),
                'price' => (int) $row->counter_from,
                'online_price' => null,
                'current_price' => (int) ($from->get((int) $row->id) ?? $row->price_from ?? 0),
                'is_online_visible' => (bool) $row->is_online_visible,
                'is_featured' => (bool) $row->is_featured,
                'status' => $status,
                'status_label' => self::STATUS_LABELS[$status] ?? $status,
                'status_note' => match ($status) {
                    self::STATUS_NOT_PUBLISHED => (bool) $row->is_online_visible && $published === 0 ? 'Ninguna presentación publicada' : 'Sin publicar',
                    self::STATUS_NO_PRICE => 'Oculto: sus presentaciones no tienen precio',
                    self::STATUS_OUT_OF_STOCK => 'Oculto al cliente: ninguna presentación tiene disponible',
                    default => 'Se muestra en la tienda',
                },
                'variants_total' => (int) $row->variants_total,
                'variants_published' => $published,
                'variants_available' => (int) $row->variants_available,
                'other_available' => round((float) $row->other_available, 3),
                'has_purchase' => (bool) $row->has_purchase,
                'has_transfer' => (bool) $row->has_transfer,
                'flags' => array_values(array_filter([
                    (int) $row->has_active_promotion === 1 ? ['key' => 'promo', 'label' => 'Promoción activa'] : null,
                    (int) $row->has_image === 0 ? ['key' => 'incomplete', 'label' => 'Sin foto'] : null,
                    (int) $row->has_description === 0 ? ['key' => 'incomplete', 'label' => 'Sin descripción'] : null,
                ])),
            ];
        })->values()->all();
    }

    /**
     * Pestaña «Servicios». Su disponible depende de sus articulos y no cabe en SQL, asi que se
     * calcula aqui con una sola consulta de existencias (`StorefrontServiceCatalog::availability`).
     *
     * @param  array<string, string>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function services(int $companyId, Store $store, array $filters = []): Collection
    {
        $services = Service::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->with(['category:id,name', 'components.product', 'components.family'])
            ->when(($filters['category'] ?? '') !== '', fn ($query) => $query->whereIn('category_id', $this->descendants($companyId, (int) $filters['category'])))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', '%'.trim($filters['search']).'%')
                ->orWhere('code', 'like', '%'.trim($filters['search']).'%')
                ->orWhere('barcode', 'like', '%'.trim($filters['search']).'%')))
            ->orderBy('name')
            ->get();

        $availability = $this->serviceCatalog->availability($services, $store);
        $sold = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.company_id', $companyId)
            ->where('sales.store_id', $store->id)
            ->whereIn('sales.status', [Sale::STATUS_PAID, Sale::STATUS_CREDIT, Sale::STATUS_REFUNDED])
            ->where('sales.sold_at', '>=', now()->subDays(self::SALES_DAYS))
            ->whereNotNull('sale_items.service_id')
            ->whereNull('sale_items.parent_sale_item_id')
            ->groupBy('sale_items.service_id')
            ->selectRaw('sale_items.service_id, SUM(CASE WHEN sales.channel = ? THEN sale_items.quantity ELSE 0 END) as sold_pos, SUM(CASE WHEN sales.channel = ? THEN sale_items.quantity ELSE 0 END) as sold_online', [Sale::CHANNEL_POS, Sale::CHANNEL_ONLINE])
            ->get()
            ->keyBy('service_id');

        $rows = $services->map(function (Service $service) use ($availability, $sold) {
            // Un articulo inactivo saca al servicio de la tienda (`Service::sellable`).
            $broken = $service->components->contains(fn ($component) => ! $component->product || ! $component->product->is_active);
            $available = $broken ? 0 : (int) ($availability[$service->id] ?? 0);
            $price = $service->open_price ? 0 : $this->servicePricing->priceFor($service, PromotionService::CHANNEL_ONLINE);

            $status = match (true) {
                ! $service->is_online_visible => self::STATUS_NOT_PUBLISHED,
                $service->open_price || $price <= 0 => self::STATUS_NO_PRICE,
                $available < 1 => self::STATUS_OUT_OF_STOCK,
                default => self::STATUS_VISIBLE,
            };

            $short = $service->components
                ->filter(fn ($component) => ! $component->product || ! $component->product->is_active || ! $component->product->allow_negative_stock)
                ->map(fn ($component) => $component->product?->name ?? 'Artículo eliminado')
                ->values();

            return [
                'id' => $service->id,
                'name' => $service->name,
                'sku' => (string) $service->code,
                'barcode' => $service->barcode !== null ? (string) $service->barcode : null,
                'brand' => null,
                'category' => $service->category?->name,
                'category_id' => $service->category_id,
                'family' => null,
                'variant_label' => null,
                'thumb_url' => Uploads::url($service->thumb()),
                'available' => $available >= StorefrontServiceCatalog::UNLIMITED ? null : $available,
                'min_stock' => null,
                'stock_unit' => 'und',
                'sold_pos_30d' => round((float) ($sold[$service->id]->sold_pos ?? 0), 3),
                'sold_online_30d' => round((float) ($sold[$service->id]->sold_online ?? 0), 3),
                'price' => $service->open_price ? 0 : $this->servicePricing->priceFor($service, PromotionService::CHANNEL_POS),
                'online_price' => null,
                'current_price' => $price,
                'open_price' => (bool) $service->open_price,
                'is_online_visible' => (bool) $service->is_online_visible,
                'is_featured' => (bool) $service->is_featured,
                'has_image' => $service->thumb() !== null,
                'has_description' => trim((string) $service->description) !== '',
                'status' => $status,
                'status_label' => $service->open_price ? 'Precio variable' : self::STATUS_LABELS[$status],
                'status_note' => match ($status) {
                    self::STATUS_NO_PRICE => $service->open_price ? 'Se digita en la caja: no se publica' : 'Oculto: fija el precio',
                    self::STATUS_OUT_OF_STOCK => $broken ? 'Un artículo del servicio está inactivo' : 'Oculto al cliente: sus artículos no alcanzan',
                    self::STATUS_VISIBLE => 'Se muestra en la tienda',
                    default => $available >= 1 ? 'Alcanza para venderlo' : 'Sin publicar',
                },
                'components' => $short->all(),
                'flags' => [],
            ];
        });

        if (($filters['status'] ?? '') !== '') {
            $rows = $rows->where('status', $filters['status']);
        }

        $rows = match ($filters['view'] ?? '') {
            'visible' => $rows->where('status', self::STATUS_VISIBLE),
            'out_of_stock' => $rows->where('status', self::STATUS_OUT_OF_STOCK),
            'unpublished' => $rows->where('is_online_visible', false),
            'stock_unpublished' => $rows->filter(fn (array $row) => ! $row['is_online_visible'] && ($row['available'] === null || $row['available'] >= 1)),
            'featured' => $rows->where('is_featured', true),
            'incomplete' => $rows->filter(fn (array $row) => ! $row['has_image'] || ! $row['has_description']),
            default => $rows,
        };

        $sorted = match ($filters['sort'] ?? '') {
            'sold_pos_desc' => $rows->sortByDesc('sold_pos_30d'),
            'sold_online_desc' => $rows->sortByDesc('sold_online_30d'),
            'price_asc' => $rows->sortBy('current_price'),
            'price_desc' => $rows->sortByDesc('current_price'),
            default => $rows,
        };

        return $sorted->values();
    }

    /**
     * Indicadores de las pestañas que se calculan por grupo (familias) o en PHP (servicios),
     * con las mismas claves de `metrics()`.
     *
     * @param  iterable<object|array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public function groupedMetrics(iterable $rows): array
    {
        $metrics = array_fill_keys(['visible', 'out_of_stock', 'stock_unpublished', 'published', 'active', 'featured', 'featured_out_of_stock', 'incomplete', 'out_of_stock_transferable'], 0);

        foreach ($rows as $row) {
            $row = (object) $row;
            $status = $row->status ?? $row->status_key;
            $available = $row->available === null ? INF : (float) $row->available;

            $metrics['active']++;
            $metrics['published'] += (int) (bool) $row->is_online_visible;
            $metrics['visible'] += (int) ($status === self::STATUS_VISIBLE);
            $metrics['out_of_stock'] += (int) ($status === self::STATUS_OUT_OF_STOCK);
            $metrics['stock_unpublished'] += (int) (! $row->is_online_visible && $available > 0);
            $metrics['featured'] += (int) (bool) $row->is_featured;
            $metrics['featured_out_of_stock'] += (int) ((bool) $row->is_featured && $status === self::STATUS_OUT_OF_STOCK);
            $metrics['incomplete'] += (int) (! $row->has_image || ! $row->has_description);
            $metrics['out_of_stock_transferable'] += (int) ($status === self::STATUS_OUT_OF_STOCK && (float) ($row->other_available ?? 0) > 0);
        }

        return $metrics;
    }

    /**
     * El arbol de categorias de la empresa, aplanado en orden de lectura, con profundidad y raiz.
     *
     * @return Collection<int, array{id: int, name: string, parent_id: int|null, depth: int, root_id: int}>
     */
    public function categoryTree(int $companyId): Collection
    {
        return $this->trees[$companyId] ??= (function () use ($companyId) {
            $categories = Category::query()
                ->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'parent_id']);

            $ids = $categories->pluck('id')->all();
            $children = $categories->groupBy(fn (Category $category) => $category->parent_id && in_array($category->parent_id, $ids, true) ? $category->parent_id : 0);
            $flat = collect();
            $seen = [];

            $walk = function (int $parentId, int $depth, ?int $rootId) use (&$walk, $children, $flat, &$seen) {
                foreach ($children->get($parentId, collect()) as $category) {
                    // Un ciclo mal guardado no cuelga la pantalla.
                    if (isset($seen[$category->id])) {
                        continue;
                    }

                    $seen[$category->id] = true;
                    $root = $rootId ?? $category->id;
                    $flat->push(['id' => $category->id, 'name' => $category->name, 'parent_id' => $category->parent_id, 'depth' => $depth, 'root_id' => $root]);
                    $walk($category->id, $depth + 1, $root);
                }
            };

            $walk(0, 0, null);

            return $flat;
        })();
    }

    /**
     * Una categoria con todas sus subcategorias, a cualquier profundidad.
     *
     * @return list<int>
     */
    public function descendants(int $companyId, int $categoryId): array
    {
        $tree = $this->categoryTree($companyId);
        $ids = [$categoryId];
        $frontier = [$categoryId];

        while ($frontier !== []) {
            $frontier = $tree->whereIn('parent_id', $frontier)->pluck('id')->diff($ids)->values()->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    public static function quantity(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', '.'), '0'), ',');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, int>
     */
    protected function aggregate(Builder $query, array $context): array
    {
        $views = $this->viewConditions($context);
        $query->selectRaw('COUNT(*) as active, SUM(catalog.is_online_visible) as published');

        foreach ($views as $key => $sql) {
            $query->selectRaw("SUM(CASE WHEN {$sql} THEN 1 ELSE 0 END) as view_{$key}");
        }

        $query->selectRaw("SUM(CASE WHEN catalog.is_featured = 1 AND catalog.status_key = '".self::STATUS_OUT_OF_STOCK."' THEN 1 ELSE 0 END) as featured_out_of_stock")
            ->selectRaw("SUM(CASE WHEN catalog.status_key = '".self::STATUS_OUT_OF_STOCK."' AND catalog.other_available > 0 THEN 1 ELSE 0 END) as out_of_stock_transferable")
            ->selectRaw("SUM(CASE WHEN catalog.status_key = '".self::STATUS_NO_PRICE."' THEN 1 ELSE 0 END) as no_price")
            ->selectRaw("SUM(CASE WHEN catalog.status_key = '".self::STATUS_FAMILY_HIDDEN."' THEN 1 ELSE 0 END) as family_hidden");

        $row = $query->first();
        $result = [];

        foreach ((array) $row as $key => $value) {
            $result[$key] = (int) $value;
        }

        return $result + [
            'visible' => $result['view_visible'] ?? 0,
            'out_of_stock' => $result['view_out_of_stock'] ?? 0,
            'stock_unpublished' => $result['view_stock_unpublished'] ?? 0,
            'unpublished' => $result['view_unpublished'] ?? 0,
            'featured' => $result['view_featured'] ?? 0,
            'incomplete' => $result['view_incomplete'] ?? 0,
        ];
    }

    /**
     * Cada vista rapida en SQL sobre las columnas de `catalog`. Lo que no es numero sale de
     * constantes de esta clase o de Ajustes ya convertidos a entero.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, string>
     */
    protected function viewConditions(array $context): array
    {
        $since = now()->subDays(self::SALES_DAYS)->format('Y-m-d H:i:s');

        return [
            'visible' => "catalog.status_key = '".self::STATUS_VISIBLE."'",
            'out_of_stock' => "catalog.status_key = '".self::STATUS_OUT_OF_STOCK."'",
            'stock_unpublished' => '(catalog.is_online_visible = 0 AND catalog.available > 0)',
            'unpublished' => 'catalog.is_online_visible = 0',
            'low_stock' => self::LOW_SQL,
            'expiring' => '(catalog.expiring_lot_days IS NOT NULL AND catalog.expiring_lot_days <= '.(int) $context['expiring_days'].')',
            'promo' => 'catalog.has_active_promotion = 1',
            'incomplete' => '(catalog.has_image = 0 OR catalog.has_description = 0)',
            'featured' => 'catalog.is_featured = 1',
            'new' => "catalog.created_at >= '{$since}'",
        ];
    }

    /** Las vistas que tienen sentido sobre una fila agrupada (familias). */
    protected function applyGroupedView(Builder $query, string $view): void
    {
        match ($view) {
            'visible' => $query->where('catalog.status_key', self::STATUS_VISIBLE),
            'out_of_stock' => $query->where('catalog.status_key', self::STATUS_OUT_OF_STOCK),
            'stock_unpublished' => $query->where('catalog.is_online_visible', 0)->where('catalog.available', '>', 0),
            'unpublished' => $query->where('catalog.is_online_visible', 0),
            'promo' => $query->where('catalog.has_active_promotion', 1),
            'incomplete' => $query->whereRaw('(catalog.has_image = 0 OR catalog.has_description = 0)'),
            'featured' => $query->where('catalog.is_featured', 1),
            'new' => $query->where('catalog.created_at', '>=', now()->subDays(self::SALES_DAYS)),
            default => null,
        };
    }

    /**
     * Cada grupo de sugerencias en SQL: candidato (sin publicar y con disponible), su criterio y
     * no descartado en esa sede. `any` es estar en al menos uno.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, string>
     */
    protected function suggestionConditions(array $context): array
    {
        $candidate = '(catalog.is_online_visible = 0 AND catalog.available > 0)';
        $days = (int) $context['overstock_days'];
        $factor = number_format((float) $context['overstock_factor'], 2, '.', '');
        $expiring = (int) $context['expiring_days'];

        $groups = [
            StorefrontSuggestionDismissal::GROUP_POS_SELLERS => 'catalog.sold_pos_30d > 0',
            StorefrontSuggestionDismissal::GROUP_EXPIRING => "(catalog.expiring_lot_days IS NOT NULL AND catalog.expiring_lot_days <= {$expiring})",
            // Cobertura en dias con el ritmo de caja; sin ventas, inventario alto es pasar varias
            // veces el minimo (sin minimo no hay con que medir «alto»).
            StorefrontSuggestionDismissal::GROUP_OVERSTOCK => '((catalog.sold_pos_30d > 0 AND catalog.available * '.self::SALES_DAYS." / catalog.sold_pos_30d > {$days})"
                ." OR (catalog.sold_pos_30d <= 0 AND catalog.min_stock > 0 AND catalog.available >= {$factor} * catalog.min_stock))",
            StorefrontSuggestionDismissal::GROUP_INCOMPLETE => '(catalog.has_image = 0 OR catalog.has_description = 0 OR catalog.store_price <= 0)',
        ];

        $conditions = [];

        foreach ($groups as $group => $sql) {
            $conditions[$group] = "({$candidate} AND {$sql} AND catalog.d_{$group} = 0)";
        }

        $conditions['any'] = '('.implode(' OR ', $conditions).')';

        return $conditions;
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    protected function presentSuggestions(Collection $rows, string $group, int $companyId, Store $store): array
    {
        $prices = $this->promotions->unitPrices($rows, $companyId, PromotionService::CHANNEL_ONLINE);

        return $rows->map(function (object $row) use ($group, $prices) {
            $unit = self::UNIT_SHORT[$row->unit] ?? 'und';
            $available = round((float) $row->available, 3);
            $sold = round((float) $row->sold_pos_30d, 3);
            $coverage = $sold > 0 ? (int) round($available * self::SALES_DAYS / $sold) : null;
            $missing = array_values(array_filter([
                (int) $row->has_image === 0 ? 'image' : null,
                (int) $row->has_description === 0 ? 'description' : null,
                (int) $row->store_price <= 0 ? 'price' : null,
            ]));

            return [
                'id' => (int) $row->id,
                'name' => $row->name,
                'sku' => (string) $row->sku,
                'thumb_url' => Uploads::url($this->thumbOf($row)),
                'group' => $group,
                'reason' => match ($group) {
                    StorefrontSuggestionDismissal::GROUP_POS_SELLERS => self::quantity($sold)." {$unit} vendidos en caja en 30 días",
                    StorefrontSuggestionDismissal::GROUP_EXPIRING => $this->expiryLabel((int) $row->expiring_lot_days),
                    StorefrontSuggestionDismissal::GROUP_OVERSTOCK => $coverage !== null ? "Cobertura de {$coverage} días" : 'Sin ventas en caja en 30 días',
                    default => 'Falta '.$this->missingLabel($missing),
                },
                'available' => $available,
                'stock_unit' => $unit,
                'sold_pos_30d' => $sold,
                'coverage_days' => $coverage,
                'expiring_lot_days' => $row->expiring_lot_days !== null ? (int) $row->expiring_lot_days : null,
                'min_stock' => $row->min_stock !== null ? round((float) $row->min_stock, 3) : null,
                'price' => (int) ($prices[(int) $row->id]['price'] ?? $row->store_price),
                'missing' => $missing,
            ];
        })->values()->all();
    }

    /** @param  list<string>  $missing */
    protected function missingLabel(array $missing): string
    {
        $labels = array_map(fn (string $key) => ['image' => 'foto', 'description' => 'descripción', 'price' => 'precio'][$key], $missing);

        if (count($labels) <= 1) {
            return $labels[0] ?? 'nada';
        }

        $last = array_pop($labels);

        return implode(', ', $labels).' y '.$last;
    }

    /** @param  array<string, mixed>  $context */
    protected function groupCriteria(string $group, array $context): string
    {
        return match ($group) {
            StorefrontSuggestionDismissal::GROUP_POS_SELLERS => 'Vendidos en caja en los últimos 30 días, con disponible en la bodega de la tienda.',
            StorefrontSuggestionDismissal::GROUP_EXPIRING => "Tienen un lote con saldo que vence en {$context['expiring_days']} días o menos.",
            StorefrontSuggestionDismissal::GROUP_OVERSTOCK => "Alcanzan para más de {$context['overstock_days']} días al ritmo de caja, o no se venden y pasan "
                .self::quantity((float) $context['overstock_factor']).' veces el mínimo.',
            default => 'Sin foto, sin descripción o sin precio de tienda. Publicados así venderían menos.',
        };
    }

    /**
     * La vista del catalogo que muestra el grupo completo («Ver los N»).
     *
     * @return array<string, string>
     */
    protected function groupView(string $group): array
    {
        return match ($group) {
            StorefrontSuggestionDismissal::GROUP_POS_SELLERS => ['view' => 'stock_unpublished', 'sort' => 'sold_pos_desc'],
            StorefrontSuggestionDismissal::GROUP_EXPIRING => ['view' => 'expiring', 'status' => self::STATUS_NOT_PUBLISHED, 'stock' => 'in'],
            StorefrontSuggestionDismissal::GROUP_OVERSTOCK => ['view' => 'stock_unpublished', 'sort' => 'available_desc'],
            default => ['view' => 'stock_unpublished', 'sheet' => 'incomplete'],
        };
    }

    protected function statusNote(string $status, object $row): string
    {
        return match ($status) {
            self::STATUS_NOT_PUBLISHED => (float) $row->available > 0 ? 'Tiene existencias para vender' : 'Sin existencias',
            self::STATUS_FAMILY_HIDDEN => 'Publica el artículo principal',
            self::STATUS_NO_PRICE => 'Oculto: fija el precio',
            self::STATUS_OUT_OF_STOCK => 'Oculto al cliente',
            default => (bool) $row->allow_negative_stock && (float) $row->available <= 0 ? 'Se vende sin existencias' : 'Se muestra en la tienda',
        };
    }

    protected function expiryLabel(int $days): string
    {
        return match (true) {
            $days < 0 => 'Lote vencido',
            $days === 0 => 'Lote vence hoy',
            $days === 1 => 'Lote vence mañana',
            default => "Lote vence en {$days} días",
        };
    }

    /** @param  array<string, list<int>>  $reach */
    protected function promotionSql(array $reach): string
    {
        $parts = [];

        foreach (['product_ids' => 'products.id', 'category_ids' => 'products.category_id', 'family_ids' => 'products.product_family_id'] as $key => $column) {
            $ids = array_map('intval', $reach[$key] ?? []);

            if ($ids !== []) {
                $parts[] = $column.' IN ('.implode(',', $ids).')';
            }
        }

        return $parts === [] ? '0' : 'CASE WHEN '.implode(' OR ', $parts).' THEN 1 ELSE 0 END';
    }

    protected function purchaseExistsSql(int $companyId, int $warehouseId): string
    {
        $statuses = "'".PurchaseOrder::STATUS_SENT."', '".PurchaseOrder::STATUS_PARTIAL."'";

        return 'CASE WHEN EXISTS (SELECT 1 FROM purchase_order_items poi JOIN purchase_orders po ON po.id = poi.purchase_order_id'
            ." WHERE poi.product_id = products.id AND po.company_id = {$companyId} AND po.warehouse_id = {$warehouseId}"
            ." AND po.status IN ({$statuses}) AND poi.quantity - poi.received_quantity > 0) THEN 1 ELSE 0 END";
    }

    protected function transferExistsSql(int $companyId, int $warehouseId): string
    {
        return 'CASE WHEN EXISTS (SELECT 1 FROM stock_transfer_items sti JOIN stock_transfers st ON st.id = sti.stock_transfer_id'
            ." WHERE sti.product_id = products.id AND st.company_id = {$companyId} AND st.to_warehouse_id = {$warehouseId}"
            ." AND st.status = '".StockTransfer::STATUS_IN_TRANSIT."') THEN 1 ELSE 0 END";
    }

    /**
     * La bodega de la sede (que no es la de la tienda) con mas disponible de cada articulo.
     *
     * @param  list<int>  $productIds
     * @param  array<string, mixed>  $context
     * @return array<int, array{id: int, name: string, available: float}>
     */
    protected function topOtherWarehouses(array $productIds, array $context): array
    {
        $warehouses = $context['other_warehouses']->keyBy('id');

        if ($productIds === [] || $warehouses->isEmpty()) {
            return [];
        }

        $result = [];

        DB::table('product_stocks')
            ->whereIn('warehouse_id', $warehouses->keys()->all())
            ->whereIn('product_id', $productIds)
            ->whereRaw('quantity - reserved_quantity > 0')
            ->orderByRaw('quantity - reserved_quantity DESC')
            ->get(['product_id', 'warehouse_id', DB::raw('quantity - reserved_quantity as available')])
            ->each(function (object $row) use (&$result, $warehouses) {
                $result[(int) $row->product_id] ??= [
                    'id' => (int) $row->warehouse_id,
                    'name' => (string) $warehouses->get((int) $row->warehouse_id)?->name,
                    'available' => round((float) $row->available, 3),
                ];
            });

        return $result;
    }

    /**
     * La portada de la galeria de los articulos que no tienen foto en la ficha.
     *
     * @param  list<int>  $productIds
     * @return array<int, string>
     */
    protected function firstImages(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::table('product_images')
            ->whereIn('product_id', $productIds)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['product_id', 'path', 'thumb_path'])
            ->groupBy('product_id')
            ->map(fn (Collection $images) => $images->first()->thumb_path ?: $images->first()->path)
            ->all();
    }

    protected function thumbOf(object $row): ?string
    {
        foreach (['thumb_path', 'image_path', 'image_url'] as $column) {
            if (! empty($row->{$column})) {
                return (string) $row->{$column};
            }
        }

        return null;
    }
}

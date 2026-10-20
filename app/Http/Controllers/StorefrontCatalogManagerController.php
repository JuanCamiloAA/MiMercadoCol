<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesStorefrontAssortment;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSuggestionDismissal;
use App\Models\Supplier;
use App\Realtime\ChangeCollector;
use App\Services\ProductFamilyService;
use App\Services\Storefront\StorefrontAssortmentService;
use App\Services\Storefront\StorefrontProducts;
use App\Services\WarehouseService;
use App\Support\StoreContext;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Que se publica en la tienda en linea, y que ve el cliente de verdad.
 *
 * Es una pantalla aparte del catalogo porque decidir el surtido de la tienda es una decision
 * comercial, no una edicion de ficha. Publicado no es visible: lo que no tiene disponible en la
 * bodega de la tienda sigue publicado pero oculto, y aqui se ve asi (`StorefrontAssortmentService`).
 */
class StorefrontCatalogManagerController extends Controller
{
    use ManagesStorefrontAssortment;

    public const TABS = ['products', 'families', 'services'];

    public const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $this->freshAssortment();
        $companyId = TenantContext::requireCompanyId();
        $store = $this->assortmentStore($request);
        $tab = $this->tab($request);
        $filters = $this->assortment->normalizeFilters($request->query());
        $can = $this->assortmentCan($request->user());
        $context = $this->assortment->context($companyId, $store);

        [$rows, $metrics, $quickViews] = match ($tab) {
            'families' => $this->families($companyId, $store, $filters),
            'services' => $this->services($request, $companyId, $store, $filters),
            default => $this->products($companyId, $store, $filters, $can),
        };

        $suggestions = $this->assortment->suggestions($companyId, $store);

        return Inertia::render('Storefront/Manage', [
            'tab' => $tab,
            'stores' => $this->assortmentStores($request),
            'store_id' => $store->id,
            'warehouse' => ['id' => $context['warehouse_id'], 'name' => $context['warehouse']->name],
            'storefront_url' => $this->assortmentStorefrontUrl($store),
            'rows' => $rows,
            'filters' => ['store' => (string) $store->id, 'tab' => $tab === 'products' ? '' : $tab, ...$filters],
            'options' => $this->options($companyId, $quickViews),
            'thresholds' => [
                'expiring_days' => $context['expiring_days'],
                'low_margin' => $context['low_margin'],
            ],
            'metrics' => $metrics,
            'suggestions_preview' => $this->assortment->preview($suggestions['groups']),
            'suggestions_total' => $suggestions['total'],
            'coverage' => $this->assortment->categoryCoverage($companyId, $store),
            'can' => $can,
        ]);
    }

    /**
     * Publicar, ocultar y destacar. Acepta un cambio (`field`/`value`) o varios a la vez
     * (`fields`), que se aplican en una sola transaccion: «Publicar y destacar» no puede quedar
     * a medias.
     */
    public function update(Request $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();

        $validated = $request->validate([
            // La peticion dice si son articulos o servicios: los ids de una tabla no sirven en la otra.
            'type' => ['nullable', 'in:product,service,family'],
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer'],
            'field' => ['required_without:fields', 'nullable', 'in:is_online_visible,is_featured'],
            'value' => ['required_with:field', 'nullable', 'boolean'],
            'fields' => ['required_without:field', 'nullable', 'array'],
            'fields.is_online_visible' => ['sometimes', 'boolean'],
            'fields.is_featured' => ['sometimes', 'boolean'],
            // De que sede avisar si lo que se publica no se va a ver.
            'store_id' => ['nullable', 'integer'],
        ], [
            'product_ids.required' => 'Selecciona al menos uno.',
            'field.required_without' => 'Di que quieres cambiar.',
            'fields.required_without' => 'Di que quieres cambiar.',
        ]);

        $changes = ! empty($validated['field'])
            ? [$validated['field'] => (bool) $validated['value']]
            : array_map('boolval', array_intersect_key((array) ($validated['fields'] ?? []), array_flip(['is_online_visible', 'is_featured'])));

        if ($changes === []) {
            return back()->withErrors(['fields' => 'Di que quieres cambiar.']);
        }

        $ids = array_values(array_unique(array_map('intval', $validated['product_ids'])));
        $type = $validated['type'] ?? 'product';
        $warning = null;

        DB::transaction(function () use ($companyId, $ids, $type, $changes, &$warning) {
            if ($type === 'family') {
                foreach ($changes as $field => $value) {
                    app(ProductFamilyService::class)->setFlag($companyId, $ids, $field, $value);
                }

                return;
            }

            if ($type === 'service') {
                $query = DB::table('services')->where('company_id', $companyId)->whereNull('deleted_at')->whereIn('id', $ids);

                // Un servicio de precio variable no se publica: el precio se digita en la caja.
                if (($changes['is_online_visible'] ?? false) === true && (clone $query)->where('open_price', true)->exists()) {
                    $warning = 'Listo. Los de precio variable no se publican: su precio se digita en la caja.';
                    (clone $query)->where('open_price', true)->update(array_diff_key($changes, ['is_online_visible' => true]) ?: ['updated_at' => now()]);
                    $query->where('open_price', false);
                }

                $query->update($changes + ['updated_at' => now()]);

                return;
            }

            DB::table('products')->where('company_id', $companyId)->whereIn('id', $ids)->update($changes + ['updated_at' => now()]);

            // Publicarlo cierra la discusion: sus sugerencias descartadas dejan de valer.
            if (($changes['is_online_visible'] ?? false) === true) {
                StorefrontSuggestionDismissal::query()->withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->whereIn('product_id', $ids)
                    ->delete();
            }
        });

        // Se escribio con `update` masivo, sin eventos de modelo: se avisa a mano a las pestañas.
        app(ChangeCollector::class)->record($companyId, $type === 'service' ? 'services' : 'products');

        if ($warning === null && $type === 'product' && ($changes['is_online_visible'] ?? false) === true) {
            $warning = $this->hiddenWarning($request, $companyId, $ids);
        }

        return back()->with($warning ? 'warning' : 'success', $warning ?? 'La tienda en línea quedó actualizada.');
    }

    /**
     * El CSV de lo que se ve en pantalla: mismos filtros, sin tope de paginas. Desde el apartado
     * sin stock (`view=out_of_stock`) respeta tambien el filtro de reposicion.
     */
    public function export(Request $request): StreamedResponse
    {
        $assortment = $this->freshAssortment();
        $companyId = TenantContext::requireCompanyId();
        $store = $this->assortmentStore($request);
        $filters = $assortment->normalizeFilters($request->query());

        $query = $filters['view'] === 'out_of_stock'
            ? $assortment->outOfStockQuery($companyId, $store, $filters + ['replenishment' => (string) $request->query('replenishment', '')])
                ->orderByRaw('catalog.out_of_stock_since IS NULL')->orderBy('catalog.out_of_stock_since')->orderBy('catalog.name')->orderBy('catalog.id')
            : $assortment->filtered($companyId, $store, $filters);

        $name = 'tienda-en-linea-'.str($store->name)->slug().($filters['view'] !== '' ? '-'.str_replace('_', '-', $filters['view']) : '').'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query, $assortment, $companyId, $store) {
            $handle = fopen('php://output', 'wb');
            // Sin el BOM, Excel se come los acentos.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'SKU', 'Codigo de barras', 'Nombre', 'Marca', 'Categoria', 'Estado en tienda', 'Disponible', 'Minimo',
                'Vendido caja 30 d', 'Vendido en linea 30 d', 'Precio mostrador', 'Precio tienda', 'Precio vigente',
                'Publicado', 'Destacado', 'Sin stock desde', 'Reposicion',
            ], ';');

            $query->chunk(500, function (Collection $chunk) use ($handle, $assortment, $companyId, $store) {
                $rows = $assortment->presentRows($chunk, $companyId, $store);
                $incoming = $assortment->replenishmentFor(collect($rows)->where('status', StorefrontAssortmentService::STATUS_OUT_OF_STOCK)->pluck('id')->all(), $companyId, $store);

                foreach ($rows as $row) {
                    $next = $incoming[$row['id']] ?? null;

                    fputcsv($handle, [
                        $row['sku'],
                        // La comilla obliga a Excel a leerlo como texto: sin ella se come el cero
                        // de la izquierda y el codigo deja de servir en la caja.
                        $row['barcode'] === null || $row['barcode'] === '' ? '' : "'".$row['barcode'],
                        $row['name'],
                        $row['brand'] ?? '',
                        $row['category'] ?? '',
                        $row['status_label'],
                        $this->decimal($row['available']),
                        $row['min_stock'] === null ? '' : $this->decimal($row['min_stock']),
                        $this->decimal($row['sold_pos_30d']),
                        $this->decimal($row['sold_online_30d']),
                        $row['price'],
                        $row['online_price'] ?? $row['price'],
                        $row['current_price'],
                        $row['is_online_visible'] ? 'Si' : 'No',
                        $row['is_featured'] ? 'Si' : 'No',
                        $row['out_of_stock_since'] ? substr($row['out_of_stock_since'], 0, 10) : '',
                        match ($next['kind'] ?? null) {
                            'purchase' => 'Compra '.$next['code'].($next['expected_at'] ? ' · llega '.$next['expected_at'] : ''),
                            'transfer' => 'Traslado '.$next['code'].' · en camino',
                            default => $row['status'] === StorefrontAssortmentService::STATUS_OUT_OF_STOCK ? 'Ninguna' : '',
                        },
                    ], ';');
                }
            });

            fclose($handle);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<string, string>  $filters
     * @param  array<string, bool>  $can
     * @return array{0: LengthAwarePaginator, 1: array<string, int>, 2: list<array<string, mixed>>}
     */
    protected function products(int $companyId, Store $store, array $filters, array $can): array
    {
        $page = $this->assortment->filtered($companyId, $store, $filters)->paginate(self::PER_PAGE)->withQueryString();
        $page->setCollection(collect($this->assortment->presentRows($page->getCollection(), $companyId, $store, $can)));

        return [$page, $this->assortment->metrics($companyId, $store), $this->assortment->quickViews($companyId, $store, $filters)];
    }

    /**
     * @param  array<string, string>  $filters
     * @return array{0: LengthAwarePaginator, 1: array<string, int>, 2: list<array<string, mixed>>}
     */
    protected function families(int $companyId, Store $store, array $filters): array
    {
        $query = $this->assortment->familiesQuery($companyId, $store, $filters);

        match ($filters['sort']) {
            'sold_pos_desc' => $query->orderByDesc('catalog.sold_pos_30d'),
            'sold_online_desc' => $query->orderByDesc('catalog.sold_online_30d'),
            'available_desc' => $query->orderByDesc('catalog.available'),
            'available_asc' => $query->orderBy('catalog.available'),
            'price_asc' => $query->orderBy('catalog.price_from'),
            'price_desc' => $query->orderByDesc('catalog.price_from'),
            'recent' => $query->orderByDesc('catalog.created_at'),
            default => null,
        };

        $page = $query->orderBy('catalog.name')->orderBy('catalog.id')->paginate(self::PER_PAGE)->withQueryString();
        $page->setCollection(collect($this->assortment->presentFamilies($page->getCollection(), $companyId)));

        $all = $this->assortment->groupedMetrics($this->assortment->familiesQuery($companyId, $store, [])->get());
        $scoped = $this->assortment->groupedMetrics($this->assortment->familiesQuery($companyId, $store, ['view' => ''] + $filters)->get());

        return [$page, $all, $this->groupedViews($scoped)];
    }

    /**
     * @param  array<string, string>  $filters
     * @return array{0: LengthAwarePaginator, 1: array<string, int>, 2: list<array<string, mixed>>}
     */
    protected function services(Request $request, int $companyId, Store $store, array $filters): array
    {
        $all = $this->assortment->services($companyId, $store);
        $rows = $this->assortment->services($companyId, $store, $filters);
        $scoped = $this->assortment->services($companyId, $store, ['view' => ''] + $filters);
        $current = max(1, (int) $request->query('page', 1));

        $page = new LengthAwarePaginator(
            $rows->forPage($current, self::PER_PAGE)->values(),
            $rows->count(),
            self::PER_PAGE,
            $current,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return [$page, $this->assortment->groupedMetrics($all), $this->groupedViews($this->assortment->groupedMetrics($scoped))];
    }

    /**
     * Las vistas rapidas que tienen sentido en familias y servicios, con sus conteos.
     *
     * @param  array<string, int>  $metrics
     * @return list<array<string, mixed>>
     */
    protected function groupedViews(array $metrics): array
    {
        return collect([
            'all' => $metrics['active'],
            'visible' => $metrics['visible'],
            'out_of_stock' => $metrics['out_of_stock'],
            'stock_unpublished' => $metrics['stock_unpublished'],
            'unpublished' => $metrics['active'] - $metrics['published'],
            'incomplete' => $metrics['incomplete'],
            'featured' => $metrics['featured'],
        ])->map(fn (int $count, string $value) => [
            'value' => $value,
            'label' => StorefrontAssortmentService::VIEW_LABELS[$value],
            'count' => $count,
            'tone' => match ($value) {
                'out_of_stock' => 'warn',
                'visible' => 'accent',
                default => 'neutral',
            },
        ])->values()->all();
    }

    /**
     * Todo lo que pintan los filtros sale de aqui: el `.tsx` no tiene listas propias.
     *
     * @param  list<array<string, mixed>>  $quickViews
     * @return array<string, mixed>
     */
    protected function options(int $companyId, array $quickViews): array
    {
        $pairs = fn (array $labels) => collect($labels)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all();

        return [
            'categories' => $this->assortment->categoryTree($companyId)
                ->map(fn (array $node) => ['value' => $node['id'], 'label' => $node['name'], 'depth' => $node['depth']])
                ->values()
                ->all(),
            'brands' => Product::query()->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->whereNotNull('brand')
                ->where('brand', '<>', '')
                ->distinct()
                ->orderBy('brand')
                ->pluck('brand')
                ->map(fn (string $brand) => ['value' => $brand, 'label' => $brand])
                ->values()
                ->all(),
            'suppliers' => Supplier::query()->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Supplier $supplier) => ['value' => $supplier->id, 'label' => $supplier->name])
                ->values()
                ->all(),
            'statuses' => $pairs(StorefrontAssortmentService::STATUS_LABELS),
            'sheet' => $pairs(StorefrontAssortmentService::SHEET_LABELS),
            'sorts' => $pairs(StorefrontAssortmentService::SORT_LABELS),
            'stock' => $pairs(StorefrontAssortmentService::STOCK_LABELS),
            'quick_views' => $quickViews,
        ];
    }

    protected function tab(Request $request): string
    {
        // `type` es como se llamaba antes: un enlace guardado sigue abriendo la pestaña que era.
        $tab = (string) ($request->query('tab') ?? $request->query('type') ?? 'products');

        return in_array($tab, self::TABS, true) ? $tab : 'products';
    }

    /**
     * Publicar algo sin disponible en la bodega de la tienda no lo pone a la vista. Se dice al
     * publicar, para que nadie crea que la tienda no le hizo caso.
     *
     * @param  list<int>  $ids
     */
    protected function hiddenWarning(Request $request, int $companyId, array $ids): ?string
    {
        $storeId = $request->integer('store_id');

        if ($storeId <= 0 || ! StoreContext::availableStores($request->user())->contains('id', $storeId)) {
            return null;
        }

        $warehouseId = app(WarehouseService::class)->onlineSourceFor($storeId)->id;

        $hidden = Product::query()->withoutGlobalScopes()
            ->where('products.company_id', $companyId)
            ->whereIn('products.id', $ids)
            ->where('products.allow_negative_stock', false)
            ->leftJoin('product_stocks', function ($join) use ($warehouseId) {
                $join->on('product_stocks.product_id', '=', 'products.id')->where('product_stocks.warehouse_id', '=', $warehouseId);
            })
            ->whereRaw(StorefrontProducts::AVAILABLE_SQL.' <= 0')
            ->count();

        if ($hidden === 0) {
            return null;
        }

        return $hidden === 1 && count($ids) === 1
            ? 'Quedó publicado, pero no tiene disponible en la bodega de la tienda: el cliente lo verá cuando entre mercancía.'
            : "Quedaron publicados. {$hidden} no tienen disponible en la bodega de la tienda: el cliente los verá cuando entre mercancía.";
    }

    protected function decimal(float|int|null $value): string
    {
        return $value === null ? '' : rtrim(rtrim(number_format((float) $value, 3, ',', ''), '0'), ',');
    }
}

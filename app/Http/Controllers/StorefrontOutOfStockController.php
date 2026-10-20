<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesStorefrontAssortment;
use App\Models\Store;
use App\Services\Storefront\StorefrontAssortmentService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Artículos publicados sin stock»: lo que sigue publicado pero la tienda esconde porque la
 * bodega que la respalda no tiene disponible. Vuelve solo cuando entra mercancia; aqui esta lo
 * necesario para que entre: otras bodegas de la sede, compras y traslados en camino.
 */
class StorefrontOutOfStockController extends Controller
{
    use ManagesStorefrontAssortment;

    public const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $this->freshAssortment();
        $companyId = TenantContext::requireCompanyId();
        $store = $this->assortmentStore($request);
        $can = $this->assortmentCan($request->user());
        $context = $this->assortment->context($companyId, $store);
        $tab = in_array($request->query('tab'), ['families', 'services'], true) ? (string) $request->query('tab') : 'products';
        $replenishment = (string) $request->query('replenishment', '');
        $filters = [
            'search' => mb_substr(trim((string) $request->query('search', '')), 0, 100),
            'category' => ctype_digit((string) $request->query('category', '')) ? (string) $request->query('category') : '',
            'replenishment' => array_key_exists($replenishment, StorefrontAssortmentService::REPLENISHMENT_LABELS) ? $replenishment : '',
        ];

        $rows = match ($tab) {
            'families' => $this->families($companyId, $store, $filters),
            'services' => $this->services($request, $companyId, $store, $filters),
            default => $this->products($companyId, $store, $filters, $can),
        };

        $metrics = $this->assortment->outOfStockMetrics($companyId, $store);

        return Inertia::render('Storefront/OutOfStock', [
            'tab' => $tab,
            'stores' => $this->assortmentStores($request),
            'store_id' => $store->id,
            'warehouse' => ['id' => $context['warehouse_id'], 'name' => $context['warehouse']->name],
            'rows' => $rows,
            'filters' => ['store' => (string) $store->id, 'tab' => $tab === 'products' ? '' : $tab, ...$filters],
            'options' => [
                'categories' => $this->assortment->categoryTree($companyId)
                    ->map(fn (array $node) => ['value' => $node['id'], 'label' => $node['name'], 'depth' => $node['depth']])
                    ->values()
                    ->all(),
                'replenishment' => collect(StorefrontAssortmentService::REPLENISHMENT_LABELS)
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values()
                    ->all(),
            ],
            'metrics' => $metrics,
            'tab_counts' => [
                'products' => $metrics['hidden'],
                'families' => $this->assortment->familiesQuery($companyId, $store, ['view' => 'out_of_stock'])->count(),
                'services' => $this->assortment->services($companyId, $store, ['view' => 'out_of_stock'])->count(),
            ],
            'can' => $can,
        ]);
    }

    /**
     * @param  array<string, string>  $filters
     * @param  array<string, bool>  $can
     */
    protected function products(int $companyId, Store $store, array $filters, array $can): LengthAwarePaginator
    {
        $page = $this->assortment->outOfStock($companyId, $store, $filters, self::PER_PAGE);
        $raw = $page->getCollection()->keyBy('id');
        $rows = $this->assortment->presentRows($page->getCollection(), $companyId, $store, $can);
        $incoming = $this->assortment->replenishmentFor(array_column($rows, 'id'), $companyId, $store);
        $warehouseId = $this->assortment->context($companyId, $store)['warehouse_id'];

        $page->setCollection(collect($rows)->map(function (array $row) use ($raw, $incoming, $can, $warehouseId) {
            $next = $incoming[$row['id']] ?? null;
            $source = $raw->get($row['id']);
            // Lo que ya viene en camino gana: no se pide traslado de algo que esta llegando.
            $kind = $next['kind'] ?? ($row['other_available'] > 0 ? 'transferable' : 'none');
            $top = $row['top_other_warehouse'];

            return $row + [
                'replenishment' => $next === null ? null : $next + [
                    'href' => match ($next['kind']) {
                        'purchase' => $can['purchases_view'] ? route('purchases.show', $next['id']) : null,
                        default => $can['transfers_view'] ? route('transfers.show', $next['id']) : null,
                    },
                ],
                'replenishment_kind' => $kind,
                // Lo que se pidio en otras compras sin llegar todavia (para no pedir dos veces).
                'has_purchase' => (bool) ($source->has_purchase ?? false),
                'has_transfer' => (bool) ($source->has_transfer ?? false),
                'transfer_href' => $kind === 'transferable' && $top !== null && $can['transfer_requests_create']
                    ? route('transfer_requests.create', ['to_warehouse' => $warehouseId, 'from_warehouse' => $top['id'], 'products' => [$row['id']]])
                    : null,
                'purchase_href' => $kind === 'none' && $can['purchases_create']
                    ? route('purchases.create', ['warehouse' => $warehouseId, 'products' => [$row['id']]])
                    : null,
            ];
        }));

        return $page;
    }

    /**
     * Articulos con subarticulos cuya tarjeta desaparecio: ninguna presentacion publicada tiene
     * disponible.
     *
     * @param  array<string, string>  $filters
     */
    protected function families(int $companyId, Store $store, array $filters): LengthAwarePaginator
    {
        $page = $this->assortment->familiesQuery($companyId, $store, ['view' => 'out_of_stock'] + $filters)
            ->orderBy('catalog.name')
            ->orderBy('catalog.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $page->setCollection(collect($this->assortment->presentFamilies($page->getCollection(), $companyId))->map(fn (array $row) => $row + [
            'replenishment_kind' => $row['has_transfer'] ? 'transfer' : ($row['has_purchase'] ? 'purchase' : ($row['other_available'] > 0 ? 'transferable' : 'none')),
        ]));

        return $page;
    }

    /**
     * Servicios publicados cuyos articulos no alcanzan para una unidad.
     *
     * @param  array<string, string>  $filters
     */
    protected function services(Request $request, int $companyId, Store $store, array $filters): LengthAwarePaginator
    {
        $rows = $this->assortment->services($companyId, $store, ['view' => 'out_of_stock'] + $filters);
        $current = max(1, (int) $request->query('page', 1));

        return new LengthAwarePaginator(
            $rows->forPage($current, self::PER_PAGE)->values(),
            $rows->count(),
            self::PER_PAGE,
            $current,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }
}

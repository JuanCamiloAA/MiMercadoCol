<?php

namespace App\Services;

use App\Models\CustomerBalance;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Register;
use App\Models\Sale;
use App\Models\SaleItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cifras del panel de inicio.
 *
 * Todo sale de consultas agregadas sobre la sede activa: nada se recorre en memoria para
 * que el panel siga respondiendo igual con un ano de ventas encima.
 */
class DashboardService
{
    public function __construct(protected RegisterSessionService $registerSessions) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $companyId, int $storeId): array
    {
        $today = CarbonImmutable::today();
        $yesterday = $today->subDay();

        return [
            'metrics' => $this->metrics($companyId, $storeId, $today, $yesterday),
            'sales_by_hour' => $this->salesByHour($companyId, $storeId, $today),
            'registers' => $this->registers($companyId, $storeId),
            'recent_orders' => $this->recentOrders($companyId, $storeId),
            'top_products' => $this->topProducts($companyId, $storeId, $today),
            'stock_alerts' => $this->stockAlerts($companyId, $storeId),
        ];
    }

    /**
     * @return array<string, array{value: int|float, previous: int|float, change: float|null}>
     */
    protected function metrics(int $companyId, int $storeId, CarbonImmutable $today, CarbonImmutable $yesterday): array
    {
        $todayStats = $this->dayStats($companyId, $storeId, $today);
        $yesterdayStats = $this->dayStats($companyId, $storeId, $yesterday);

        $receivable = (int) CustomerBalance::query()
            ->join('customers', 'customers.id', '=', 'customer_balances.customer_id')
            ->where('customers.company_id', $companyId)
            ->sum('customer_balances.balance');

        $overdue = (int) CustomerBalance::query()
            ->join('customers', 'customers.id', '=', 'customer_balances.customer_id')
            ->where('customers.company_id', $companyId)
            ->sum('customer_balances.overdue_balance');

        return [
            'sales_total' => $this->metric($todayStats['total'], $yesterdayStats['total']),
            'tickets' => $this->metric($todayStats['count'], $yesterdayStats['count']),
            'average_ticket' => $this->metric($todayStats['average'], $yesterdayStats['average']),
            'receivable' => $this->metric($receivable, $receivable, $overdue),
        ];
    }

    /**
     * @return array{total: int, count: int, average: int}
     */
    protected function dayStats(int $companyId, int $storeId, CarbonImmutable $day): array
    {
        $row = Sale::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('store_id', $storeId)
            ->whereBetween('sold_at', [$day->startOfDay(), $day->endOfDay()])
            ->where('status', '!=', Sale::STATUS_CANCELLED)
            ->selectRaw('COALESCE(SUM(total), 0) as total, COUNT(*) as count')
            ->first();

        $total = (int) $row->total;
        $count = (int) $row->count;

        return [
            'total' => $total,
            'count' => $count,
            'average' => $count > 0 ? (int) round($total / $count) : 0,
        ];
    }

    /**
     * @return array{value: int|float, previous: int|float, change: float|null, extra: int|null}
     */
    protected function metric(int|float $value, int|float $previous, ?int $extra = null): array
    {
        $change = null;

        if ($previous > 0) {
            $change = round((($value - $previous) / $previous) * 100, 1);
        } elseif ($value > 0) {
            $change = 100.0;
        }

        return [
            'value' => $value,
            'previous' => $previous,
            'change' => $change,
            'extra' => $extra,
        ];
    }

    /**
     * @return list<array{hour: string, total: int}>
     */
    protected function salesByHour(int $companyId, int $storeId, CarbonImmutable $day): array
    {
        $rows = Sale::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('store_id', $storeId)
            ->whereBetween('sold_at', [$day->startOfDay(), $day->endOfDay()])
            ->where('status', '!=', Sale::STATUS_CANCELLED)
            ->groupByRaw('HOUR(sold_at)')
            ->selectRaw('HOUR(sold_at) as hour, COALESCE(SUM(total), 0) as total')
            ->pluck('total', 'hour');

        $series = [];

        for ($hour = 6; $hour <= 22; $hour++) {
            $series[] = [
                'hour' => str_pad((string) $hour, 2, '0', STR_PAD_LEFT).':00',
                'total' => (int) ($rows[$hour] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function registers(int $companyId, int $storeId): array
    {
        return Register::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->with(['openSession.user'])
            ->orderBy('code')
            ->get()
            ->map(function (Register $register) {
                $session = $register->openSession;

                return [
                    'id' => $register->id,
                    'name' => $register->name,
                    'code' => $register->code,
                    'is_open' => $session !== null,
                    'cashier' => $session?->user?->full_name,
                    'opened_at' => $session?->opened_at?->toIso8601String(),
                    'expected_amount' => $session ? $this->registerSessions->expectedAmount($session) : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function recentOrders(int $companyId, int $storeId): array
    {
        return Order::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('store_id', $storeId)
            ->with('customer:id,name')
            ->orderByDesc('placed_at')
            ->limit(5)
            ->get()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'customer' => $order->customer?->name,
                'status' => $order->status,
                'status_label' => $order->statusLabel(),
                'total' => $order->total,
                'placed_at' => $order->placed_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function topProducts(int $companyId, int $storeId, CarbonImmutable $day): array
    {
        return SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.company_id', $companyId)
            ->where('sales.store_id', $storeId)
            ->whereBetween('sales.sold_at', [$day->startOfDay(), $day->endOfDay()])
            ->where('sales.status', '!=', Sale::STATUS_CANCELLED)
            // Los articulos incluidos en un servicio cerrado no son venta.
            ->whereRaw(SaleItem::COUNTS_AS_SOLD_SQL)
            ->groupBy('sale_items.product_id', 'sale_items.description')
            ->selectRaw('sale_items.product_id, sale_items.description, SUM(sale_items.quantity) as quantity, SUM(sale_items.line_total) as total')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'product_id' => (int) $row->product_id,
                'name' => $row->description,
                'quantity' => round((float) $row->quantity, 3),
                'total' => (int) $row->total,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function stockAlerts(int $companyId, int $storeId): array
    {
        // Se agrupa por articulo: lo que esta en el deposito de atras tambien surte el estante,
        // y sin sumarlo el tablero alertaria por algo de lo que hay de sobra en la sede.
        return ProductStock::query()
            ->join('products', 'products.id', '=', 'product_stocks.product_id')
            ->where('product_stocks.company_id', $companyId)
            ->where('product_stocks.store_id', $storeId)
            ->where('products.is_active', true)
            ->whereNull('products.deleted_at')
            ->groupBy('products.id', 'products.name', 'products.sku', 'products.min_stock')
            ->havingRaw('SUM(product_stocks.quantity) <= products.min_stock')
            ->orderByRaw('SUM(product_stocks.quantity) ASC')
            ->limit(8)
            ->get([
                'products.id as product_id',
                'products.name',
                'products.sku',
                'products.min_stock',
                DB::raw('SUM(product_stocks.quantity) as quantity'),
            ])
            ->map(fn ($row) => [
                'product_id' => (int) $row->product_id,
                'name' => $row->name,
                'sku' => $row->sku,
                'quantity' => round((float) $row->quantity, 3),
                'min_stock' => (int) $row->min_stock,
            ])
            ->values()
            ->all();
    }

    public function stockAlertCount(int $companyId, int $storeId): int
    {
        return DB::table('product_stocks')
            ->join('products', 'products.id', '=', 'product_stocks.product_id')
            ->where('product_stocks.company_id', $companyId)
            ->where('product_stocks.store_id', $storeId)
            ->where('products.is_active', true)
            ->whereNull('products.deleted_at')
            ->groupBy('product_stocks.product_id', 'products.min_stock')
            ->havingRaw('SUM(product_stocks.quantity) <= products.min_stock')
            ->select('product_stocks.product_id')
            ->get()
            ->count();
    }

    public function productCount(int $companyId): int
    {
        return Product::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('is_active', true)->count();
    }
}

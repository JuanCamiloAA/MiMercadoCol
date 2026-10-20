<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Informes de ventas, inventario, cartera y empleados.
 *
 * Todo sale de agregados en SQL sobre el mismo rango para que las cifras de un informe
 * cuadren con las del panel y con las de otro informe del mismo dia.
 */
class ReportService
{
    /**
     * @return array<string, mixed>
     */
    public function sales(int $companyId, CarbonImmutable $from, CarbonImmutable $to, ?int $storeId, ?string $channel, bool $groupVariants = true): array
    {
        $base = fn () => Sale::query()
            ->where('sales.company_id', $companyId)
            ->whereBetween('sales.sold_at', [$from->startOfDay(), $to->endOfDay()])
            ->where('sales.status', '!=', Sale::STATUS_CANCELLED)
            ->when($storeId, fn ($query, $store) => $query->where('sales.store_id', $store))
            ->when($channel, fn ($query, $value) => $query->where('sales.channel', $value));

        $totals = $base()
            ->selectRaw('COALESCE(SUM(total), 0) as total, COALESCE(SUM(tax_total), 0) as tax, COALESCE(SUM(discount_total), 0) as discount, COUNT(*) as tickets')
            ->first();

        $margin = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.company_id', $companyId)
            ->whereBetween('sales.sold_at', [$from->startOfDay(), $to->endOfDay()])
            ->where('sales.status', '!=', Sale::STATUS_CANCELLED)
            ->when($storeId, fn ($query, $store) => $query->where('sales.store_id', $store))
            ->when($channel, fn ($query, $value) => $query->where('sales.channel', $value))
            ->selectRaw('COALESCE(SUM(sale_items.line_total), 0) as revenue, COALESCE(SUM(sale_items.unit_cost * sale_items.quantity), 0) as cost')
            ->first();

        $tickets = (int) $totals->tickets;

        return [
            'summary' => [
                'total' => (int) $totals->total,
                'tax' => (int) $totals->tax,
                'discount' => (int) $totals->discount,
                'tickets' => $tickets,
                'average_ticket' => $tickets > 0 ? (int) round($totals->total / $tickets) : 0,
                'cost' => (int) $margin->cost,
                'margin' => (int) ($margin->revenue - $margin->cost),
                'margin_percent' => $margin->revenue > 0 ? round((($margin->revenue - $margin->cost) / $margin->revenue) * 100, 1) : 0,
            ],
            'by_day' => $this->salesByDay($base(), $from, $to),
            'by_category' => $this->salesByCategory($companyId, $from, $to, $storeId, $channel),
            'by_method' => $this->salesByMethod($companyId, $from, $to, $storeId, $channel),
            'by_payment_method' => $this->salesByPaymentMethod($companyId, $from, $to, $storeId, $channel),
            'gateways' => $this->gatewayReconciliation($companyId, $from, $to, $storeId, $channel),
            'by_employee' => $this->salesByEmployee($base()),
            'top_products' => $this->topProducts($companyId, $from, $to, $storeId, $channel, $groupVariants),
            'services' => $this->servicesConsumption($companyId, $from, $to, $storeId, $channel),
        ];
    }

    /**
     * @return list<array{date: string, label: string, total: int, tickets: int}>
     */
    protected function salesByDay($query, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $query
            ->groupByRaw('DATE(sales.sold_at)')
            ->selectRaw('DATE(sales.sold_at) as day, COALESCE(SUM(sales.total), 0) as total, COUNT(*) as tickets')
            ->get()
            ->keyBy('day');

        $series = [];
        $cursor = $from->startOfDay();

        while ($cursor->lte($to)) {
            $key = $cursor->toDateString();
            $row = $rows->get($key);

            $series[] = [
                'date' => $key,
                'label' => $cursor->format('d/m'),
                'total' => (int) ($row->total ?? 0),
                'tickets' => (int) ($row->tickets ?? 0),
            ];

            $cursor = $cursor->addDay();
        }

        return $series;
    }

    /**
     * @return list<array{name: string, total: int, quantity: float}>
     */
    protected function salesByCategory(int $companyId, CarbonImmutable $from, CarbonImmutable $to, ?int $storeId, ?string $channel): array
    {
        return SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            // Un servicio cuenta en su propia categoria; sus articulos detallados, en la suya.
            ->leftJoin('services', 'services.id', '=', 'sale_items.service_id')
            ->leftJoin('categories', 'categories.id', '=', DB::raw('COALESCE(products.category_id, services.category_id)'))
            ->whereRaw(SaleItem::COUNTS_AS_SOLD_SQL)
            ->where('sales.company_id', $companyId)
            ->whereBetween('sales.sold_at', [$from->startOfDay(), $to->endOfDay()])
            ->where('sales.status', '!=', Sale::STATUS_CANCELLED)
            ->when($storeId, fn ($query, $store) => $query->where('sales.store_id', $store))
            ->when($channel, fn ($query, $value) => $query->where('sales.channel', $value))
            ->groupBy('categories.id', 'categories.name')
            ->selectRaw('COALESCE(categories.name, \'Sin categoria\') as name, SUM(sale_items.line_total) as total, SUM(sale_items.quantity) as quantity')
            ->orderByDesc('total')
            ->limit(12)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'total' => (int) $row->total,
                'quantity' => round((float) $row->quantity, 3),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{method: string, label: string, total: int, count: int}>
     */
    protected function salesByMethod(int $companyId, CarbonImmutable $from, CarbonImmutable $to, ?int $storeId, ?string $channel): array
    {
        return SalePayment::query()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.company_id', $companyId)
            ->whereBetween('sales.sold_at', [$from->startOfDay(), $to->endOfDay()])
            ->where('sales.status', '!=', Sale::STATUS_CANCELLED)
            ->when($storeId, fn ($query, $store) => $query->where('sales.store_id', $store))
            ->when($channel, fn ($query, $value) => $query->where('sales.channel', $value))
            ->groupBy('sale_payments.method')
            // Sin restar el cambio, el efectivo del informe no cuadra con la gaveta.
            ->selectRaw('sale_payments.method, SUM('.SalePayment::NET_AMOUNT_SQL.') as total, COUNT(*) as count')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'method' => $row->method,
                'label' => PaymentMethod::KIND_LABELS[$row->method] ?? SalePayment::METHOD_LABELS[$row->method] ?? $row->method,
                'total' => (int) $row->total,
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();
    }

    /**
     * Por medio configurado, con la comision estimada y lo que queda. La comision sale de lo que
     * la empresa anoto en Medios de pago (porcentaje + fijo por cobro) y solo pesa sobre lo que
     * entro: una devolucion no le devuelve la comision a nadie. Es estimada; la real la dice el
     * extracto del banco o de la pasarela.
     *
     * @return list<array{payment_method_id: int|null, method: string, label: string, kind_label: string, total: int, count: int, fee: int, net: int}>
     */
    protected function salesByPaymentMethod(int $companyId, CarbonImmutable $from, CarbonImmutable $to, ?int $storeId, ?string $channel): array
    {
        $net = SalePayment::NET_AMOUNT_SQL;

        return SalePayment::query()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'sale_payments.payment_method_id')
            ->where('sales.company_id', $companyId)
            ->whereBetween('sales.sold_at', [$from->startOfDay(), $to->endOfDay()])
            ->where('sales.status', '!=', Sale::STATUS_CANCELLED)
            ->when($storeId, fn ($query, $store) => $query->where('sales.store_id', $store))
            ->when($channel, fn ($query, $value) => $query->where('sales.channel', $value))
            ->groupBy('sale_payments.method', 'sale_payments.payment_method_id', 'payment_methods.name', 'payment_methods.fee_percent', 'payment_methods.fee_fixed', 'payment_methods.sort_order')
            ->selectRaw("sale_payments.method, sale_payments.payment_method_id, payment_methods.name as method_name,
                COALESCE(payment_methods.fee_percent, 0) as fee_percent, COALESCE(payment_methods.fee_fixed, 0) as fee_fixed,
                SUM({$net}) as total, COUNT(*) as count,
                SUM(CASE WHEN sale_payments.amount > 0 THEN {$net} ELSE 0 END) as charged,
                SUM(CASE WHEN sale_payments.amount > 0 THEN 1 ELSE 0 END) as charges")
            ->orderByRaw('payment_methods.sort_order IS NULL, payment_methods.sort_order')
            ->get()
            ->map(function ($row) {
                $fee = (int) round((int) $row->charged * (float) $row->fee_percent / 100) + (int) $row->fee_fixed * (int) $row->charges;

                return [
                    'payment_method_id' => $row->payment_method_id ? (int) $row->payment_method_id : null,
                    'method' => $row->method,
                    'label' => $row->method_name ?: (PaymentMethod::KIND_LABELS[$row->method] ?? $row->method),
                    'kind_label' => PaymentMethod::KIND_LABELS[$row->method] ?? $row->method,
                    'total' => (int) $row->total,
                    'count' => (int) $row->count,
                    'fee' => $fee,
                    'net' => (int) $row->total - $fee,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Conciliacion de pasarelas: por cuenta y medio, cuanto aprobo la pasarela, cuanto quedo en
     * ventas o pedidos y cuanto falta por conciliar. Lo aprobado que no aparece en una venta ni
     * en un pedido es plata que entro y que el informe de ventas no ve. Los cobros de prueba
     * (sin medio) no cuentan.
     *
     * @return list<array<string, mixed>>
     */
    public function gatewayReconciliation(int $companyId, CarbonImmutable $from, CarbonImmutable $to, ?int $storeId, ?string $channel = null): array
    {
        $approved = "payment_transactions.status = '".PaymentTransaction::STATUS_APPROVED."'";
        $pending = "{$approved} AND payment_transactions.applied_at IS NULL AND payment_transactions.resolution IS NULL";
        $failed = "payment_transactions.status IN ('".PaymentTransaction::STATUS_DECLINED."', '".PaymentTransaction::STATUS_ERROR."')";
        $expired = "payment_transactions.status = '".PaymentTransaction::STATUS_EXPIRED."'";
        // El canal del informe es el de la venta; el de la transaccion es caja o tienda.
        $gatewayChannel = match ($channel) {
            null, '' => null,
            Sale::CHANNEL_POS => PaymentTransaction::CHANNEL_POS,
            default => PaymentTransaction::CHANNEL_ONLINE,
        };

        return PaymentTransaction::query()->withoutGlobalScopes()
            ->join('payment_methods', 'payment_methods.id', '=', 'payment_transactions.payment_method_id')
            ->where('payment_transactions.company_id', $companyId)
            ->whereBetween('payment_transactions.created_at', [$from->startOfDay(), $to->endOfDay()])
            ->when($storeId, fn ($query, $store) => $query->where('payment_transactions.store_id', $store))
            ->when($gatewayChannel, fn ($query, $value) => $query->where('payment_transactions.channel', $value))
            ->groupBy('payment_transactions.provider', 'payment_transactions.environment', 'payment_transactions.payment_method_id', 'payment_methods.name', 'payment_methods.fee_percent', 'payment_methods.fee_fixed')
            ->selectRaw("payment_transactions.provider, payment_transactions.environment, payment_transactions.payment_method_id, payment_methods.name as method_name,
                payment_methods.fee_percent, payment_methods.fee_fixed,
                COUNT(*) as attempts,
                SUM(CASE WHEN {$approved} THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN {$approved} THEN payment_transactions.amount ELSE 0 END) as approved_total,
                SUM(CASE WHEN {$approved} AND payment_transactions.applied_at IS NOT NULL THEN payment_transactions.amount ELSE 0 END) as applied_total,
                SUM(CASE WHEN {$pending} THEN 1 ELSE 0 END) as to_reconcile_count,
                SUM(CASE WHEN {$pending} THEN payment_transactions.amount ELSE 0 END) as to_reconcile_total,
                SUM(CASE WHEN {$failed} THEN 1 ELSE 0 END) as failed_count,
                SUM(CASE WHEN {$expired} THEN 1 ELSE 0 END) as expired_count,
                SUM(payment_transactions.refunded_amount) as refunded_total")
            ->orderBy('payment_transactions.provider')
            ->get()
            ->map(function ($row) {
                $approvedTotal = (int) $row->approved_total;
                $fee = (int) round($approvedTotal * (float) $row->fee_percent / 100) + (int) $row->fee_fixed * (int) $row->approved_count;

                return [
                    'provider' => $row->provider,
                    'environment' => $row->environment,
                    'payment_method_id' => (int) $row->payment_method_id,
                    'label' => $row->method_name,
                    'attempts' => (int) $row->attempts,
                    'approved_count' => (int) $row->approved_count,
                    'approved_total' => $approvedTotal,
                    'applied_total' => (int) $row->applied_total,
                    'to_reconcile_count' => (int) $row->to_reconcile_count,
                    'to_reconcile_total' => (int) $row->to_reconcile_total,
                    'failed_count' => (int) $row->failed_count,
                    'expired_count' => (int) $row->expired_count,
                    'refunded_total' => (int) $row->refunded_total,
                    'fee' => $fee,
                    'net' => $approvedTotal - (int) $row->refunded_total - $fee,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{name: string, total: int, tickets: int}>
     */
    protected function salesByEmployee($query): array
    {
        return $query
            ->leftJoin('users', 'users.id', '=', 'sales.user_id')
            ->groupBy('users.id', 'users.name', 'users.last_name')
            ->selectRaw("COALESCE(CONCAT(users.name, ' ', COALESCE(users.last_name, '')), 'Sin usuario') as name, SUM(sales.total) as total, COUNT(*) as tickets")
            ->orderByDesc('total')
            ->limit(12)
            ->get()
            ->map(fn ($row) => [
                'name' => trim($row->name),
                'total' => (int) $row->total,
                'tickets' => (int) $row->tickets,
            ])
            ->values()
            ->all();
    }

    /**
     * Los mas vendidos. Con `groupVariants` (por defecto) las tallas o presentaciones de un mismo
     * articulo salen en una sola linea con el nombre del principal; sin la opcion, cada una por
     * separado. La plata es la misma de las dos maneras: solo cambia como se agrupa.
     *
     * @return list<array{name: string, total: int, quantity: float, is_service: bool, variants: int}>
     */
    protected function topProducts(int $companyId, CarbonImmutable $from, CarbonImmutable $to, ?int $storeId, ?string $channel, bool $groupVariants = true): array
    {
        $key = $groupVariants
            ? "COALESCE(CONCAT('f', products.product_family_id), CONCAT('d', sale_items.description))"
            : 'sale_items.description';
        $name = $groupVariants ? 'MAX(COALESCE(product_families.name, sale_items.description))' : 'MAX(sale_items.description)';

        return SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('product_families', 'product_families.id', '=', 'products.product_family_id')
            ->where('sales.company_id', $companyId)
            ->whereBetween('sales.sold_at', [$from->startOfDay(), $to->endOfDay()])
            ->where('sales.status', '!=', Sale::STATUS_CANCELLED)
            ->when($storeId, fn ($query, $store) => $query->where('sales.store_id', $store))
            ->when($channel, fn ($query, $value) => $query->where('sales.channel', $value))
            // Los articulos de un servicio cerrado no son «mas vendidos»: van incluidos a $0.
            ->whereRaw(SaleItem::COUNTS_AS_SOLD_SQL)
            ->groupByRaw("{$key}, (sale_items.service_id IS NOT NULL)")
            ->selectRaw("{$name} as name, (sale_items.service_id IS NOT NULL) as is_service, SUM(sale_items.line_total) as total, SUM(sale_items.quantity) as quantity, COUNT(DISTINCT sale_items.product_id) as variants, MAX(products.product_family_id IS NOT NULL) as is_family")
            ->orderByDesc('total')
            ->limit(15)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'is_service' => (bool) $row->is_service,
                'total' => (int) $row->total,
                'quantity' => round((float) $row->quantity, 3),
                'variants' => $groupVariants && $row->is_family ? (int) $row->variants : 0,
            ])
            ->values()
            ->all();
    }

    /**
     * Consumo en servicios: por articulo, cuanto salio del inventario a traves de servicios; por
     * servicio, cuantas veces se vendio, cuantas con ajustes, cuanto dejo y con que margen.
     *
     * @return array{by_product: list<array<string, mixed>>, by_service: list<array<string, mixed>>}
     */
    public function servicesConsumption(int $companyId, CarbonImmutable $from, CarbonImmutable $to, ?int $storeId, ?string $channel): array
    {
        $scope = fn () => SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.company_id', $companyId)
            ->whereBetween('sales.sold_at', [$from->startOfDay(), $to->endOfDay()])
            ->where('sales.status', '!=', Sale::STATUS_CANCELLED)
            ->when($storeId, fn ($query, $store) => $query->where('sales.store_id', $store))
            ->when($channel, fn ($query, $value) => $query->where('sales.channel', $value));

        // Lo que se devolvio y volvio al estante resta consumo; lo que no volvio sigue consumido.
        $byProduct = $scope()
            ->whereNotNull('sale_items.parent_sale_item_id')
            ->whereRaw('COALESCE(sale_items.restocked, 1) = 1')
            ->groupBy('sale_items.product_id', 'sale_items.description')
            ->selectRaw('sale_items.product_id, sale_items.description as name, SUM(sale_items.quantity) as quantity, SUM(sale_items.unit_cost * sale_items.quantity) as cost')
            ->orderByDesc('cost')
            ->limit(20)
            ->get()
            ->map(fn ($row) => [
                'product_id' => (int) $row->product_id,
                'name' => $row->name,
                'quantity' => round((float) $row->quantity, 3),
                'cost' => (int) round((float) $row->cost),
            ])
            ->values()
            ->all();

        $lines = $scope()
            ->whereNotNull('sale_items.service_id')
            ->whereNull('sale_items.parent_sale_item_id')
            ->get(['sale_items.id', 'sale_items.service_id', 'sale_items.description', 'sale_items.quantity', 'sale_items.line_total', 'sale_items.unit_cost', 'sale_items.service_snapshot']);

        $components = SaleItem::query()
            ->whereIn('parent_sale_item_id', $lines->pluck('id'))
            ->groupBy('parent_sale_item_id')
            ->selectRaw('parent_sale_item_id, SUM(line_total) as revenue, SUM(unit_cost * quantity) as cost, MAX(component_change IS NOT NULL) as adjusted')
            ->get()
            ->keyBy('parent_sale_item_id');

        $byService = $lines->groupBy('service_id')->map(function ($group) use ($components) {
            $revenue = 0;
            $cost = 0;
            $times = 0;
            $adjusted = 0;

            foreach ($group as $line) {
                $parts = $components->get($line->id);
                $revenue += (int) $line->line_total + (int) ($parts->revenue ?? 0);
                $cost += (int) round((float) $line->unit_cost * (float) $line->quantity + (float) ($parts->cost ?? 0));

                if ((float) $line->quantity > 0) {
                    $times++;
                    $adjusted += ((bool) ($parts->adjusted ?? false) || ! empty($line->service_snapshot['removed'] ?? [])) ? 1 : 0;
                }
            }

            return [
                'service_id' => (int) $group->first()->service_id,
                'name' => $group->first()->description,
                'quantity' => round((float) $group->sum('quantity'), 3),
                'times' => $times,
                'adjusted' => $adjusted,
                'revenue' => $revenue,
                'margin' => $revenue - $cost,
                'margin_percent' => $revenue > 0 ? round((($revenue - $cost) / $revenue) * 100, 1) : 0,
            ];
        })->sortByDesc('revenue')->values()->all();

        return ['by_product' => $byProduct, 'by_service' => $byService];
    }

    /**
     * @return array<string, mixed>
     */
    public function inventory(int $companyId, ?int $storeId): array
    {
        $base = ProductStock::query()
            ->join('products', 'products.id', '=', 'product_stocks.product_id')
            ->where('product_stocks.company_id', $companyId)
            ->whereNull('products.deleted_at')
            ->where('products.is_active', true)
            ->when($storeId, fn ($query, $store) => $query->where('product_stocks.store_id', $store));

        // Lo que se cuenta son articulos, no filas: con varias bodegas cada articulo tiene una
        // fila por bodega, y contarlas sueltas inflaria las referencias y marcaria bajo minimo
        // lo que en la sede sobra.
        $byProduct = fn (string $having) => $base->clone()
            ->groupBy('product_stocks.product_id', 'products.min_stock')
            ->havingRaw($having)
            ->selectRaw('product_stocks.product_id')
            ->get()
            ->count();

        return [
            'value' => (int) $base->clone()->selectRaw('COALESCE(SUM(product_stocks.quantity * products.cost), 0) as total')->value('total'),
            'retail_value' => (int) $base->clone()->selectRaw('COALESCE(SUM(product_stocks.quantity * products.price), 0) as total')->value('total'),
            'references' => (int) $base->clone()->distinct()->count('product_stocks.product_id'),
            'low' => $byProduct('SUM(product_stocks.quantity) <= products.min_stock'),
            'out' => $byProduct('SUM(product_stocks.quantity) <= 0'),
            'by_category' => DB::table('product_stocks')
                ->join('products', 'products.id', '=', 'product_stocks.product_id')
                ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                ->where('product_stocks.company_id', $companyId)
                ->whereNull('products.deleted_at')
                ->when($storeId, fn ($query, $store) => $query->where('product_stocks.store_id', $store))
                ->groupBy('categories.id', 'categories.name')
                ->selectRaw('COALESCE(categories.name, \'Sin categoria\') as name, SUM(product_stocks.quantity * products.cost) as total')
                ->orderByDesc('total')
                ->limit(12)
                ->get()
                ->map(fn ($row) => ['name' => $row->name, 'total' => (int) $row->total])
                ->values()
                ->all(),
        ];
    }

    public function productCount(int $companyId): int
    {
        return (int) Product::query()->where('company_id', $companyId)->where('is_active', true)->count();
    }
}

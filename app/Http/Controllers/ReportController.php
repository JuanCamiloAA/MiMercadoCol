<?php

namespace App\Http\Controllers;

use App\Models\CustomerBalance;
use App\Models\Employee;
use App\Models\Sale;
use App\Models\Store;
use App\Services\ReportService;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(protected ReportService $reports) {}

    public function index(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();
        [$from, $to, $storeId, $channel] = $this->filters($request);

        return Inertia::render('Reports/Index', [
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'store' => $storeId ? (string) $storeId : '',
                'channel' => $channel ?? '',
                // Por defecto las tallas o presentaciones de un articulo cuentan juntas.
                'separate' => $request->boolean('separate') ? '1' : '',
            ],
            'stores' => Store::query()
                ->where('company_id', $companyId)
                ->orderBy('name')
                ->get()
                ->map(fn (Store $store) => ['value' => $store->id, 'label' => $store->name])
                ->values(),
            'sales' => $this->reports->sales($companyId, $from, $to, $storeId, $channel, ! $request->boolean('separate')),
            'inventory' => $this->reports->inventory($companyId, $storeId),
            'credit' => $this->creditSummary($companyId),
            'employees' => $this->employeeSummary($companyId),
            'canExport' => [
                'sales' => $request->user()->can('reports.sales.export'),
                'inventory' => $request->user()->can('reports.inventory.export'),
                'credit' => $request->user()->can('reports.credit.export'),
            ],
        ]);
    }

    public function exportSales(Request $request): StreamedResponse
    {
        $companyId = TenantContext::requireCompanyId();
        [$from, $to, $storeId, $channel] = $this->filters($request);

        $sales = Sale::query()
            ->where('company_id', $companyId)
            ->whereBetween('sold_at', [$from->startOfDay(), $to->endOfDay()])
            ->when($storeId, fn ($query, $store) => $query->where('store_id', $store))
            ->when($channel, fn ($query, $value) => $query->where('channel', $value))
            ->with(['customer:id,name', 'user:id,name,last_name', 'store:id,name'])
            ->orderBy('sold_at')
            ->get();

        return response()->streamDownload(function () use ($sales) {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Numero', 'Fecha', 'Sede', 'Canal', 'Cliente', 'Cajero', 'Base', 'IVA', 'Descuento', 'Total', 'Pendiente', 'Estado'], ';');

            foreach ($sales as $sale) {
                fputcsv($handle, [
                    $sale->number,
                    $sale->sold_at->format('Y-m-d H:i'),
                    $sale->store?->name,
                    $sale->channel,
                    $sale->customer?->name ?? 'Consumidor final',
                    $sale->user?->full_name,
                    $sale->subtotal,
                    $sale->tax_total,
                    $sale->discount_total,
                    $sale->total,
                    $sale->due_total,
                    $sale->statusLabel(),
                ], ';');
            }

            fclose($handle);
        }, 'ventas-'.$from->toDateString().'-a-'.$to->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportSalesPdf(Request $request): HttpResponse
    {
        $companyId = TenantContext::requireCompanyId();
        [$from, $to, $storeId, $channel] = $this->filters($request);

        $pdf = Pdf::loadView('pdf.sales-report', [
            'company' => TenantContext::company(),
            'from' => $from,
            'to' => $to,
            'store' => $storeId ? Store::query()->find($storeId) : null,
            'data' => $this->reports->sales($companyId, $from, $to, $storeId, $channel, ! $request->boolean('separate')),
        ])->setPaper('letter');

        return $pdf->stream('informe-ventas-'.$from->toDateString().'.pdf');
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: int|null, 3: string|null}
     */
    protected function filters(Request $request): array
    {
        $from = $request->filled('from')
            ? CarbonImmutable::parse($request->string('from')->toString())
            : CarbonImmutable::today()->subDays(29);

        $to = $request->filled('to')
            ? CarbonImmutable::parse($request->string('to')->toString())
            : CarbonImmutable::today();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return [
            $from,
            $to,
            $request->integer('store') ?: null,
            $request->string('channel')->toString() ?: null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function creditSummary(int $companyId): array
    {
        $balances = CustomerBalance::query()
            ->join('customers', 'customers.id', '=', 'customer_balances.customer_id')
            ->where('customers.company_id', $companyId);

        return [
            'total' => (int) $balances->clone()->sum('customer_balances.balance'),
            'overdue' => (int) $balances->clone()->sum('customer_balances.overdue_balance'),
            'customers' => (int) $balances->clone()->where('customer_balances.balance', '>', 0)->count(),
            'blocked' => (int) $balances->clone()->where('customers.credit_blocked', true)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function employeeSummary(int $companyId): array
    {
        return [
            'active' => (int) Employee::query()->where('company_id', $companyId)->where('status', Employee::STATUS_ACTIVE)->count(),
            'inactive' => (int) Employee::query()->where('company_id', $companyId)->where('status', Employee::STATUS_INACTIVE)->count(),
            'with_access' => (int) Employee::query()->where('company_id', $companyId)->whereHas('user')->count(),
        ];
    }
}

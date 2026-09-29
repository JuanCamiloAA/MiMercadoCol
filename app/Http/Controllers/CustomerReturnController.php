<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerReturns\ReceiveCustomerReturnRequest;
use App\Http\Requests\CustomerReturns\RejectCustomerReturnRequest;
use App\Http\Requests\CustomerReturns\ResolveCustomerReturnRequest;
use App\Http\Requests\CustomerReturns\StoreCustomerReturnRequest;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnItem;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerReturnDocument;
use App\Services\CustomerReturnService;
use App\Services\RegisterSessionService;
use App\Services\StoreCreditService;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Devoluciones de venta en el panel: la bandeja de lo que piden los clientes de la tienda en
 * linea, el historial de las de caja y registrar una a mano. Todo lo que mueve inventario o plata
 * pasa por `CustomerReturnService`.
 */
class CustomerReturnController extends Controller
{
    public function __construct(
        protected CustomerReturnService $returns,
        protected StoreCreditService $credits,
        protected RegisterSessionService $sessions,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();
        $filters = $this->filters($request);

        $returns = $this->filtered($companyId, $filters)
            ->with(['customer:id,name', 'sale:id,number', 'order:id,number', 'store:id,name'])
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (CustomerReturn $return) => $this->row($return));

        $count = fn (string $status) => CustomerReturn::query()->where('company_id', $companyId)->where('status', $status)->count();

        return Inertia::render('CustomerReturns/Index', [
            'returns' => $returns,
            'filters' => $filters,
            'cards' => [
                'requested' => $count(CustomerReturn::STATUS_REQUESTED),
                'approved' => $count(CustomerReturn::STATUS_APPROVED),
                'received' => $count(CustomerReturn::STATUS_RECEIVED),
                'month_value' => (int) CustomerReturn::query()
                    ->where('company_id', $companyId)
                    ->where('status', CustomerReturn::STATUS_RESOLVED)
                    ->whereBetween('resolved_at', [now()->startOfMonth(), now()->endOfMonth()])
                    ->sum('total'),
            ],
            'statuses' => $this->options(CustomerReturn::STATUS_LABELS),
            'channels' => [['value' => Sale::CHANNEL_POS, 'label' => 'Caja'], ['value' => Sale::CHANNEL_ONLINE, 'label' => 'Tienda en línea']],
            'resolutions' => $this->options(CustomerReturn::RESOLUTION_LABELS),
            'stores' => Store::query()->where('company_id', $companyId)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Store $store) => ['value' => $store->id, 'label' => $store->name])->values(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->filtered(TenantContext::requireCompanyId(), $this->filters($request))
            ->with(['customer:id,name', 'sale:id,number', 'order:id,number', 'store:id,name'])
            ->orderByDesc('id')
            ->get();

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Numero', 'Fecha', 'Estado', 'Canal', 'Origen', 'Sede', 'Venta', 'Pedido', 'Cliente', 'Valor', 'Resolucion', 'Reembolsado', 'Medio', 'Saldo a favor', 'Aplicado a cambio', 'Descontado de cartera', 'Motivo del rechazo'], ';');

            foreach ($rows as $return) {
                fputcsv($handle, [
                    $return->number,
                    $return->created_at?->format('Y-m-d H:i'),
                    $return->statusLabel(),
                    $return->channel === Sale::CHANNEL_ONLINE ? 'Tienda en linea' : 'Caja',
                    CustomerReturn::ORIGIN_LABELS[$return->origin] ?? $return->origin,
                    $return->store?->name,
                    $return->sale?->number,
                    $return->order?->number,
                    $return->customer?->name,
                    $return->total,
                    $return->resolutionLabel(),
                    $return->refunded_total,
                    $return->refund_method ? (SalePayment::METHOD_LABELS[$return->refund_method] ?? $return->refund_method) : '',
                    $return->credited_total,
                    $return->exchanged_total,
                    $return->debt_relief_total,
                    $return->reject_reason,
                ], ';');
            }

            fclose($handle);
        }, 'devoluciones-de-venta-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Registrar una devolucion: primero se busca la venta, despues se elige que vuelve. */
    public function create(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();
        $number = trim((string) $request->query('numero', ''));
        $saleId = $request->integer('venta');

        $sale = Sale::query()
            ->where('company_id', $companyId)
            ->where('status', '!=', Sale::STATUS_REFUNDED)
            ->when($saleId, fn ($query) => $query->whereKey($saleId), fn ($query) => $query->where('number', $number !== '' ? $number : '__ninguna__'))
            ->latest('id')
            ->first();

        return Inertia::render('CustomerReturns/Create', [
            'search' => $number,
            'notFound' => ($number !== '' || $saleId) && ! $sale,
            'sale' => $sale && ! $sale->isCancelled() ? $this->returns->saleForReturn($sale) : null,
            'cancelled' => $sale?->isCancelled() ? $sale->number : null,
            'reasons' => CustomerReturnItem::reasonOptions(),
            'wasteReasons' => CustomerReturnItem::WASTE_REASONS,
            'canApprove' => $request->user()->can('sales.returns.approve'),
        ]);
    }

    public function store(StoreCustomerReturnRequest $request): RedirectResponse
    {
        $sale = Sale::query()->where('company_id', TenantContext::requireCompanyId())->findOrFail($request->integer('sale_id'));
        $return = $this->returns->createFromPanel($sale, $request->returnData(), $request->user());

        return redirect()->route('customer_returns.show', $return)
            ->with('success', "Devolución {$return->number} registrada: ".mb_strtolower($return->statusLabel()).'.');
    }

    public function show(Request $request, CustomerReturn $customerReturn): Response
    {
        $this->authorizeCompany($customerReturn);

        /** @var User $user */
        $user = $request->user();
        $return = $customerReturn->load([
            'items.warehouse:id,name', 'sale:id,number,sold_at,total,channel', 'order:id,number', 'customer', 'store:id,name',
            'refundSale:id,number', 'exchangeSale:id,number', 'createdBy', 'approvedBy', 'receivedBy', 'resolvedBy', 'rejectedBy',
            'cancelledBy', 'windowApprovedBy',
        ]);

        $status = $return->status;
        $session = $this->sessions->openSessionFor($user, (int) $return->store_id);

        return Inertia::render('CustomerReturns/Show', [
            'customerReturn' => [
                'id' => $return->id,
                'number' => $return->number,
                'status' => $status,
                'status_label' => $return->statusLabel(),
                'resolution' => $return->resolution,
                'resolution_label' => $return->resolutionLabel(),
                'origin_label' => CustomerReturn::ORIGIN_LABELS[$return->origin] ?? $return->origin,
                'channel' => $return->channel,
                'store' => $return->store?->name,
                'total' => $return->total,
                'refunded_total' => $return->refunded_total,
                'refund_method_label' => $return->refund_method ? (SalePayment::METHOD_LABELS[$return->refund_method] ?? $return->refund_method) : null,
                'credited_total' => $return->credited_total,
                'exchanged_total' => $return->exchanged_total,
                'debt_relief_total' => $return->debt_relief_total,
                'reason' => $return->reason,
                'customer_note' => $return->customer_note,
                'reject_reason' => $return->reject_reason,
                'out_of_window' => $return->out_of_window,
                'window_approved_by' => $return->windowApprovedBy?->full_name,
                'photos' => collect($return->photos ?? [])->keys()->map(fn (int $index) => route('customer_returns.photo', [$return, $index]))->values(),
                'sale' => $return->sale ? ['id' => $return->sale->id, 'number' => $return->sale->number, 'sold_at' => $return->sale->sold_at?->toIso8601String(), 'total' => $return->sale->total] : null,
                'order' => $return->order ? ['id' => $return->order->id, 'number' => $return->order->number] : null,
                'customer' => $return->customer ? [
                    'id' => $return->customer->id,
                    'name' => $return->customer->name,
                    'document' => $return->customer->document_number,
                    'email' => $return->customer->email,
                    'phone' => $return->customer->phone,
                    'store_credit' => $this->credits->available($return->customer),
                    'generic' => $return->customer->isGenericConsumer(),
                ] : null,
                'refund_sale' => $return->refundSale ? ['id' => $return->refundSale->id, 'number' => $return->refundSale->number] : null,
                'exchange_sale' => $return->exchangeSale ? ['id' => $return->exchangeSale->id, 'number' => $return->exchangeSale->number] : null,
                'timeline' => $this->timeline($return),
                'items' => $return->items->map(fn (CustomerReturnItem $item) => [
                    'id' => $item->id,
                    'parent_id' => $item->parent_item_id,
                    'description' => $item->description,
                    'product_id' => $item->product_id,
                    'is_service' => $item->service_id !== null,
                    'quantity' => round((float) $item->quantity, 3),
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                    'reason' => $item->reason,
                    'reason_label' => $item->reasonLabel(),
                    'disposition' => $item->disposition,
                    'disposition_label' => $item->dispositionLabel(),
                    'suggested_disposition' => $item->product_id ? CustomerReturnItem::defaultDisposition($item->reason) : null,
                    'warehouse' => $item->warehouse?->name,
                ])->values(),
            ],
            'can' => [
                'approve' => $status === CustomerReturn::STATUS_REQUESTED && $user->can('sales.returns.approve'),
                'reject' => $return->isOpen() && $user->can('sales.returns.approve'),
                'receive' => in_array($status, [CustomerReturn::STATUS_REQUESTED, CustomerReturn::STATUS_APPROVED], true)
                    && $user->can('sales.returns.receive')
                    && ($status === CustomerReturn::STATUS_APPROVED || $user->can('sales.returns.approve')),
                'resolve' => $status === CustomerReturn::STATUS_RECEIVED && $user->can('sales.returns.resolve'),
                'cancel' => in_array($status, [CustomerReturn::STATUS_REQUESTED, CustomerReturn::STATUS_APPROVED], true) && $user->can('sales.returns.cancel'),
            ],
            'dispositions' => $this->options(CustomerReturnItem::DISPOSITION_LABELS),
            'refundMethods' => collect(CustomerReturn::REFUND_METHODS)->map(fn (string $method) => ['value' => $method, 'label' => SalePayment::METHOD_LABELS[$method]])->values(),
            // Devolver en efectivo solo con una caja abierta en esa sede: la plata tiene que salir de algun cajon.
            'hasCashSession' => $session !== null,
        ]);
    }

    public function approve(Request $request, CustomerReturn $customerReturn): RedirectResponse
    {
        $this->authorizeCompany($customerReturn);
        $this->returns->approve($customerReturn, $request->user());

        return back()->with('success', "Devolución {$customerReturn->number} aprobada. Se le avisó al cliente.");
    }

    public function reject(RejectCustomerReturnRequest $request, CustomerReturn $customerReturn): RedirectResponse
    {
        $this->authorizeCompany($customerReturn);
        $this->returns->reject($customerReturn, $request->user(), $request->string('reason')->toString());

        return back()->with('success', "Devolución {$customerReturn->number} rechazada.");
    }

    public function receive(ReceiveCustomerReturnRequest $request, CustomerReturn $customerReturn): RedirectResponse
    {
        $this->authorizeCompany($customerReturn);

        abort_if(
            $customerReturn->status === CustomerReturn::STATUS_REQUESTED && ! $request->user()->can('sales.returns.approve'),
            403,
            'Esa solicitud todavía no está aprobada.',
        );

        $this->returns->receive($customerReturn, $request->lines(), $request->user());

        return back()->with('success', "Devolución {$customerReturn->number} recibida. Falta resolverla.");
    }

    public function resolve(ResolveCustomerReturnRequest $request, CustomerReturn $customerReturn): RedirectResponse
    {
        $this->authorizeCompany($customerReturn);

        $return = $this->returns->resolve($customerReturn, $request->string('resolution')->toString(), $request->input('method'), $request->user());

        return back()->with('success', "Devolución {$return->number} resuelta: ".mb_strtolower((string) $return->resolutionLabel()).'.');
    }

    public function cancel(Request $request, CustomerReturn $customerReturn): RedirectResponse
    {
        $this->authorizeCompany($customerReturn);
        $this->returns->cancel($customerReturn, $request->user());

        return back()->with('success', "Devolución {$customerReturn->number} anulada.");
    }

    public function document(CustomerReturn $customerReturn, CustomerReturnDocument $document): HttpResponse
    {
        $this->authorizeCompany($customerReturn);

        return Pdf::loadView('pdf.customer-return', $document->viewData($customerReturn))
            ->setPaper('letter')
            ->stream("devolucion-{$customerReturn->number}.pdf");
    }

    /** Las fotos que adjunto el cliente viven en el disco privado. */
    public function photo(CustomerReturn $customerReturn, int $index): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeCompany($customerReturn);
        $path = $this->returns->photoPath($customerReturn, $index);

        abort_unless($path !== null, 404);

        return Storage::disk('local')->response($path);
    }

    /**
     * @return array<string, string>
     */
    protected function filters(Request $request): array
    {
        return [
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString(),
            'channel' => $request->string('channel')->toString(),
            'resolution' => $request->string('resolution')->toString(),
            'store' => $request->string('store')->toString(),
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
        ];
    }

    /**
     * @param  array<string, string>  $filters
     * @return Builder<CustomerReturn>
     */
    protected function filtered(int $companyId, array $filters): Builder
    {
        return CustomerReturn::query()
            ->where('company_id', $companyId)
            ->when($filters['search'], fn ($query, $search) => $query->where(fn ($inner) => $inner
                ->where('number', 'like', "%{$search}%")
                ->orWhereHas('sale', fn ($sale) => $sale->where('number', 'like', "%{$search}%"))
                ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$search}%")->orWhere('document_number', 'like', "%{$search}%"))))
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->when($filters['channel'], fn ($query, $channel) => $query->where('channel', $channel))
            ->when($filters['resolution'], fn ($query, $resolution) => $query->where('resolution', $resolution))
            ->when($filters['store'], fn ($query, $store) => $query->where('store_id', $store))
            ->when($filters['from'], fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'], fn ($query, $to) => $query->whereDate('created_at', '<=', $to));
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(CustomerReturn $return): array
    {
        return [
            'id' => $return->id,
            'number' => $return->number,
            'created_at' => $return->created_at?->toIso8601String(),
            'status' => $return->status,
            'status_label' => $return->statusLabel(),
            'channel' => $return->channel,
            'origin_label' => CustomerReturn::ORIGIN_LABELS[$return->origin] ?? $return->origin,
            'store' => $return->store?->name,
            'sale' => $return->sale ? ['id' => $return->sale->id, 'number' => $return->sale->number] : null,
            'order' => $return->order ? ['id' => $return->order->id, 'number' => $return->order->number] : null,
            'customer' => $return->customer?->name,
            'total' => $return->total,
            'resolution_label' => $return->resolutionLabel(),
        ];
    }

    /**
     * Quien hizo que y cuando, en orden.
     *
     * @return list<array{label: string, at: string, by: string|null}>
     */
    protected function timeline(CustomerReturn $return): array
    {
        $events = [
            ['Solicitada por el cliente', $return->requested_at, null],
            ['Registrada', $return->requested_at ? null : $return->created_at, $return->createdBy?->full_name],
            ['Aprobada', $return->approved_at, $return->approvedBy?->full_name],
            ['Recibida', $return->received_at, $return->receivedBy?->full_name],
            ['Resuelta', $return->resolved_at, $return->resolvedBy?->full_name],
            ['Rechazada', $return->rejected_at, $return->rejectedBy?->full_name],
            [$return->cancelled_by_customer ? 'Retirada por el cliente' : 'Anulada', $return->cancelled_at, $return->cancelledBy?->full_name],
        ];

        return collect($events)
            ->filter(fn (array $event) => $event[1] !== null)
            ->sortBy(fn (array $event) => $event[1])
            ->map(fn (array $event) => ['label' => $event[0], 'at' => $event[1]->toIso8601String(), 'by' => $event[2]])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string}>
     */
    protected function options(array $labels): array
    {
        return array_values(array_map(fn (string $value, string $label) => ['value' => $value, 'label' => $label], array_keys($labels), $labels));
    }

    protected function authorizeCompany(CustomerReturn $return): void
    {
        abort_unless((int) $return->company_id === TenantContext::requireCompanyId(), 404);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\CustomerReturn;
use App\Models\Register;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\ReceiptService;
use App\Services\SettingsService;
use App\Support\StoreContext;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Historial de ventas del back office.
 *
 * Es distinto de Pedidos: alli van los domicilios y encargos, que tienen estados y
 * domiciliario. Aqui va lo que se cobro, del mostrador y de la tienda en linea, para
 * buscar un tiquete, revisar un turno o cuadrar el dia sin tener que abrir una caja.
 */
class SaleController extends Controller
{
    public function __construct(protected SettingsService $settings) {}

    public function index(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();
        $storeId = StoreContext::requireStoreId();

        $filters = [
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString(),
            'channel' => $request->string('channel')->toString(),
            'register' => $request->string('register')->toString(),
            'user' => $request->string('user')->toString(),
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
        ];

        // Sin rango elegido se muestra la ultima semana: lo de hoy sale de primero y aun asi
        // se alcanza a ver lo de ayer sin tener que escribir fechas.
        $from = $this->parseDate($filters['from']) ?? Carbon::today()->subDays(6);
        $to = $this->parseDate($filters['to']) ?? Carbon::today();

        $filters['from'] = $from->toDateString();
        $filters['to'] = $to->toDateString();

        $base = Sale::query()
            ->where('company_id', $companyId)
            ->where('store_id', $storeId)
            ->whereBetween('sold_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($filters['search'], fn ($query, $search) => $query->where(function ($inner) use ($search) {
                $inner->where('number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$search}%"));
            }))
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->when($filters['channel'], fn ($query, $channel) => $query->where('channel', $channel))
            ->when($filters['user'], fn ($query, $user) => $query->where('user_id', (int) $user))
            ->when($filters['register'], fn ($query, $register) => $query->whereHas(
                'session',
                fn ($session) => $session->where('register_id', (int) $register)
            ));

        $sales = (clone $base)
            ->with(['customer:id,name', 'user:id,name,last_name', 'seller:id,first_name,last_name', 'session:id,register_id', 'session.register:id,name'])
            ->withCount('items')
            ->orderByDesc('sold_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Sale $sale) => [
                'id' => $sale->id,
                'number' => $sale->number,
                'status' => $sale->status,
                'status_label' => $sale->statusLabel(),
                'channel' => $sale->channel,
                'channel_label' => $sale->channel === Sale::CHANNEL_POS ? 'Mostrador' : 'Tienda en linea',
                'customer' => $sale->customer?->name,
                'cashier' => $sale->user?->full_name,
                // Quien vendio no siempre es quien cobro: la caja la abre uno y vende otro.
                'seller' => $sale->seller?->full_name,
                'register' => $sale->session?->register?->name,
                'items_count' => $sale->items_count,
                'total' => $sale->total,
                'due_total' => $sale->due_total,
                'sold_at' => $sale->sold_at->toIso8601String(),
            ]);

        return Inertia::render('Sales/Index', [
            'sales' => $sales,
            'filters' => $filters,
            'metrics' => $this->metrics(clone $base),
            'registers' => Register::query()
                ->where('company_id', $companyId)
                ->where('store_id', $storeId)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Register $register) => ['value' => $register->id, 'label' => $register->name])
                ->all(),
            'cashiers' => User::query()
                ->where('company_id', $companyId)
                ->whereHas('sales', fn ($query) => $query->where('store_id', $storeId))
                ->orderBy('name')
                ->get(['id', 'name', 'last_name'])
                ->map(fn (User $user) => ['value' => $user->id, 'label' => $user->full_name])
                ->all(),
        ]);
    }

    public function show(Request $request, Sale $sale): Response
    {
        $this->authorizeCompany($sale);

        $sale->load([
            'items:id,sale_id,product_id,service_id,parent_sale_item_id,description,quantity,master_quantity,component_change,service_snapshot,unit_price,tax_rate,discount,line_total',
            'payments',
            'customer:id,name,document_number,phone',
            'user:id,name,last_name',
            'seller:id,first_name,last_name,position',
            'authorizedBy:id,name,last_name',
            'session:id,register_id',
            'session.register:id,name',
            'store:id,name',
        ]);

        return Inertia::render('Sales/Show', [
            'sale' => [
                'id' => $sale->id,
                'number' => $sale->number,
                'status' => $sale->status,
                'status_label' => $sale->statusLabel(),
                'channel_label' => $sale->channel === Sale::CHANNEL_POS ? 'Mostrador' : 'Tienda en linea',
                'subtotal' => $sale->subtotal,
                'tax_total' => $sale->tax_total,
                'discount_total' => $sale->discount_total,
                'rounding_total' => $sale->rounding_total,
                'total' => $sale->total,
                'paid_total' => $sale->paid_total,
                'due_total' => $sale->due_total,
                'due_date' => $sale->due_date?->toDateString(),
                'note' => $sale->note,
                'sold_at' => $sale->sold_at->toIso8601String(),
                'cancelled_at' => $sale->cancelled_at?->toIso8601String(),
                'cancel_reason' => $sale->cancel_reason,
                'customer' => $sale->customer?->name,
                'customer_document' => $sale->customer?->document_number,
                'cashier' => $sale->user?->full_name,
                'seller' => $sale->seller?->full_name,
                'seller_position' => $sale->seller?->position,
                'authorized_by' => $sale->authorizedBy?->full_name,
                'register' => $sale->session?->register?->name,
                'store' => $sale->store?->name,
                // Los articulos de un servicio van anidados en su linea, nunca como articulos sueltos.
                // El total de un servicio suma sus articulos: asi la columna cuadra con la venta.
                'items' => $sale->items->whereNull('parent_sale_item_id')->values()->map(function ($item) use ($sale) {
                    $components = $sale->items->where('parent_sale_item_id', $item->id)->values();

                    return [
                        'id' => $item->id,
                        'description' => $item->description,
                        'quantity' => (float) $item->quantity,
                        'unit_price' => $item->unit_price,
                        'discount' => $item->discount + (int) $components->sum('discount'),
                        'line_total' => $item->line_total + (int) $components->sum('line_total'),
                        'is_service' => $item->service_id !== null,
                        'removed' => array_map(fn (array $removed) => $removed['name'] ?? '', $item->service_snapshot['removed'] ?? []),
                        'components' => $components->map(fn ($component) => [
                            'id' => $component->id,
                            'description' => $component->description,
                            'quantity' => (float) $component->quantity,
                            'line_total' => $component->line_total,
                            'change' => $component->component_change,
                        ])->all(),
                    ];
                })->all(),
                // `amount` es lo aplicado a la venta; en efectivo eso es lo entregado menos el cambio.
                'payments' => $sale->payments->sortBy('sequence')->values()->map(fn (SalePayment $payment) => [
                    'id' => $payment->id,
                    'method_label' => $payment->methodLabel(),
                    'amount' => $payment->netAmount(),
                    'received_amount' => $payment->method === SalePayment::METHOD_CASH ? $payment->amount : null,
                    'change_amount' => $payment->change_amount,
                    'reference' => $payment->reference,
                ])->all(),
            ],
            'refunds' => $this->refundsOf($sale),
            // Las devoluciones que todavia no han movido plata: una solicitud de la tienda, una
            // aprobada esperando la mercancia o una recibida por resolver.
            'openReturns' => CustomerReturn::query()
                ->where('sale_id', $sale->id)
                ->whereIn('status', CustomerReturn::OPEN_STATUSES)
                ->orderBy('id')
                ->get(['id', 'number', 'status', 'total', 'created_at'])
                ->map(fn (CustomerReturn $return) => [
                    'id' => $return->id,
                    'number' => $return->number,
                    'status_label' => $return->statusLabel(),
                    'total' => $return->total,
                    'created_at' => $return->created_at?->toIso8601String(),
                ])
                ->values(),
            'canRegisterReturn' => $request->user()->can('sales.returns.create')
                && ! $sale->isCancelled()
                && $sale->status !== Sale::STATUS_REFUNDED,
            'canPrint' => $request->user()->can('pos.sale.view'),
        ]);
    }

    /**
     * El tiquete listo para la impresora, sin pasar por el punto de venta. Sale con el papel,
     * los campos y el codigo que la sede de la venta tiene en Ajustes > Tiquete.
     */
    public function print(Request $request, Sale $sale): HttpResponse
    {
        $this->authorizeCompany($sale);

        $sale->load(['items', 'payments', 'customer', 'store', 'user', 'seller', 'company']);

        return response()->view('pos.receipt-print', app(ReceiptService::class)->viewData($sale, $sale->company ?? TenantContext::company()));
    }

    /**
     * Cifras del rango que se esta viendo, no del dia entero.
     *
     * @param  Builder<Sale>  $query
     * @return array<string, int>
     */
    protected function metrics($query): array
    {
        $counted = (clone $query)->counted();

        $total = (int) (clone $counted)->sum('total');
        $count = (int) (clone $counted)->count();

        return [
            'count' => $count,
            'total' => $total,
            'average' => $count > 0 ? (int) round($total / $count) : 0,
            'due' => (int) (clone $query)->where('status', Sale::STATUS_CREDIT)->sum('due_total'),
        ];
    }

    /**
     * Devoluciones hechas contra esta venta.
     *
     * Una devolucion es una venta en negativo: asi el informe del dia en que se devolvio ya la
     * trae sin cruzar nada. Se reconoce por sus lineas (`refunded_from_sale_item_id`) o, las de
     * antes, por la nota. Si tiene documento (DV) se enlaza.
     *
     * @return list<array<string, mixed>>
     */
    protected function refundsOf(Sale $sale): array
    {
        $documents = CustomerReturn::query()
            ->where('sale_id', $sale->id)
            ->whereNotNull('refund_sale_id')
            ->get(['id', 'number', 'reason', 'refund_sale_id'])
            ->keyBy('refund_sale_id');

        return Sale::query()
            ->where('company_id', $sale->company_id)
            ->where('status', Sale::STATUS_REFUNDED)
            ->where(fn ($query) => $query
                ->whereIn('id', SaleItem::query()->whereIn('refunded_from_sale_item_id', $sale->items()->select('id'))->select('sale_id'))
                ->orWhere('note', 'like', "Devolucion de la venta {$sale->number}:%"))
            ->orderBy('sold_at')
            ->get(['id', 'number', 'total', 'sold_at', 'note'])
            ->map(fn (Sale $refund) => [
                'id' => $refund->id,
                'number' => $refund->number,
                'total' => $refund->total,
                'sold_at' => $refund->sold_at->toIso8601String(),
                'reason' => $documents->get($refund->id)?->reason
                    ?? trim(str_replace("Devolucion de la venta {$sale->number}:", '', (string) $refund->note)),
                'return' => ($document = $documents->get($refund->id)) ? ['id' => $document->id, 'number' => $document->number] : null,
            ])
            ->all();
    }

    protected function parseDate(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function authorizeCompany(Sale $sale): void
    {
        abort_if(
            ! auth()->user()->isSuperAdmin() && $sale->company_id !== TenantContext::activeCompanyId(),
            403,
            'Esa venta no pertenece a tu empresa.'
        );
    }
}

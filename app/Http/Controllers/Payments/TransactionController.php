<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Concerns\AuthorizesCompanyRecords;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\ReconcileTransactionRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\RegisterSession;
use App\Models\Sale;
use App\Models\Service;
use App\Payments\Gateways\GatewayRegistry;
use App\Payments\Gateways\PaymentGateway;
use App\Services\Payments\PaymentReconciliationService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Medios de pago > Transacciones: todo lo que paso por una pasarela, con filtros, el detalle
 * saneado de cada una y la bandeja «Pagos por conciliar».
 */
class TransactionController extends Controller
{
    use AuthorizesCompanyRecords;

    public function __construct(
        protected GatewayRegistry $gateways,
        protected PaymentReconciliationService $reconciliation,
    ) {}

    public function index(Request $request): Response
    {
        TenantContext::requireCompanyId();

        $filters = [
            'estado' => in_array((string) $request->string('estado'), [...PaymentTransaction::STATUSES, 'conciliar'], true) ? (string) $request->string('estado') : '',
            'medio' => $request->integer('medio') ?: null,
            'canal' => in_array((string) $request->string('canal'), [PaymentTransaction::CHANNEL_POS, PaymentTransaction::CHANNEL_ONLINE], true) ? (string) $request->string('canal') : '',
            'desde' => $this->date($request->string('desde')),
            'hasta' => $this->date($request->string('hasta')),
            'q' => trim((string) $request->string('q')),
        ];

        $transactions = PaymentTransaction::query()
            ->with(['paymentMethod:id,name', 'store:id,name'])
            ->when($filters['estado'] === 'conciliar', fn (Builder $query) => $query->toReconcile())
            ->when($filters['estado'] !== '' && $filters['estado'] !== 'conciliar', fn (Builder $query) => $query->where('status', $filters['estado']))
            ->when($filters['medio'], fn (Builder $query, $id) => $query->where('payment_method_id', $id))
            ->when($filters['canal'] !== '', fn (Builder $query) => $query->where('channel', $filters['canal']))
            ->when($filters['desde'], fn (Builder $query, $from) => $query->where('created_at', '>=', CarbonImmutable::parse($from, 'America/Bogota')->startOfDay()))
            ->when($filters['hasta'], fn (Builder $query, $to) => $query->where('created_at', '<=', CarbonImmutable::parse($to, 'America/Bogota')->endOfDay()))
            ->when($filters['q'] !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner->where('reference', 'like', '%'.$filters['q'].'%')->orWhere('gateway_transaction_id', 'like', '%'.$filters['q'].'%')))
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (PaymentTransaction $transaction) => $this->row($transaction));

        return Inertia::render('PaymentMethods/Transactions', [
            'transactions' => $transactions,
            'filters' => array_map(fn ($value) => $value ?? '', $filters),
            'toReconcile' => PaymentTransaction::query()->toReconcile()->count(),
            'methods' => PaymentMethod::query()->whereIn('kind', PaymentMethod::GATEWAY_KINDS)->ordered()->get(['id', 'name'])
                ->map(fn (PaymentMethod $method) => ['value' => $method->id, 'label' => $method->name])->values(),
            'statusOptions' => collect(PaymentTransaction::STATUS_LABELS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    public function show(PaymentTransaction $transaction): Response
    {
        $this->authorizeCompany($transaction);
        $transaction->load(['paymentMethod:id,name,kind', 'store:id,name', 'terminal:id,name']);

        $reconcilable = $transaction->status === PaymentTransaction::STATUS_APPROVED && $transaction->applied_at === null && $transaction->resolution === null;
        $gateway = $this->gateways->exists($transaction->provider) ? $this->gateways->for($transaction->provider) : null;

        return Inertia::render('PaymentMethods/TransactionShow', [
            'transaction' => [
                ...$this->row($transaction),
                'status_detail' => $transaction->status_detail,
                'gateway_transaction_id' => $transaction->gateway_transaction_id,
                'checkout_url' => $transaction->checkout_url,
                'terminal' => $transaction->terminal?->name,
                'expires_at' => $transaction->expires_at?->toIso8601String(),
                'cancelled_at' => $transaction->cancelled_at?->toIso8601String(),
                'resolution_note' => $transaction->resolution_note,
                'resolved_at' => $transaction->resolved_at?->toIso8601String(),
                'refunded_amount' => $transaction->refunded_amount,
                // Lo que respondio la pasarela, ya saneado al guardarlo: sin tarjetas ni llaves.
                'payload' => (object) ($transaction->payload ?? []),
                'items' => $this->ticketLines($transaction),
            ],
            'reconcilable' => $reconcilable,
            'canRefundThroughGateway' => $gateway !== null && in_array(PaymentGateway::CAPABILITY_REFUND, $gateway->capabilities(), true),
            // Donde se puede aplicar: cajas abiertas de su sede y pedidos que esperan ese mismo valor.
            'sessions' => $reconcilable && $transaction->channel === PaymentTransaction::CHANNEL_POS
                ? RegisterSession::query()->with(['register:id,name', 'user:id,name,last_name'])
                    ->where('status', RegisterSession::STATUS_OPEN)
                    ->where('store_id', $transaction->store_id)
                    ->get()
                    ->map(fn (RegisterSession $session) => ['value' => $session->id, 'label' => trim(($session->register?->name ?? 'Caja').' · '.($session->user?->full_name ?? ''))])
                    ->values()
                : [],
            'orders' => $reconcilable
                ? Order::query()
                    ->whereIn('status', [Order::STATUS_NEW, Order::STATUS_PICKING, Order::STATUS_READY, Order::STATUS_ON_ROUTE])
                    ->where('payment_status', '!=', 'paid')
                    ->where('payment_method', '!=', Order::PAYMENT_CREDIT)
                    ->whereRaw('total - store_credit_amount = ?', [$transaction->amount])
                    ->latest('id')
                    ->limit(30)
                    ->get(['id', 'number', 'total', 'customer_id'])
                    ->map(fn (Order $order) => ['value' => $order->id, 'label' => "#{$order->number} · $ ".number_format($order->total, 0, ',', '.')])
                    ->values()
                : [],
        ]);
    }

    public function reconcile(ReconcileTransactionRequest $request, PaymentTransaction $transaction): RedirectResponse
    {
        $this->authorizeCompany($transaction);
        $user = $request->user();
        $note = trim((string) $request->validated('note'));

        match ($request->validated('action')) {
            'apply_sale' => $this->reconciliation->applyToSale($transaction, RegisterSession::query()->findOrFail($request->validated('register_session_id')), $user, $note),
            'apply_order' => $this->reconciliation->applyToOrder($transaction, Order::query()->findOrFail($request->validated('order_id')), $user, $note),
            'store_credit' => $this->reconciliation->creditCustomer($transaction, $this->customer((string) $request->validated('customer_document')), $user, $note),
            'refunded_outside' => $this->reconciliation->refundedOutside($transaction, $user, $note),
            'refund_gateway' => $this->reconciliation->refundThroughGateway($transaction, $user, $note),
        };

        return redirect()->route('payment_methods.transactions.show', $transaction)->with('success', 'El pago quedó conciliado.');
    }

    protected function customer(string $document): Customer
    {
        $customer = Customer::query()->where('document_number', trim($document))->first();

        if (! $customer) {
            throw ValidationException::withMessages(['customer_document' => 'No hay un cliente con ese documento.']);
        }

        return $customer;
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(PaymentTransaction $transaction): array
    {
        $payable = null;

        if ($transaction->payable_type && $transaction->payable_id) {
            $payable = match ($transaction->payable_type) {
                (new Sale)->getMorphClass() => ($sale = Sale::query()->find($transaction->payable_id))
                    ? ['label' => "Venta {$sale->number}", 'url' => route('sales.show', $sale)] : null,
                (new Order)->getMorphClass() => ($order = Order::query()->find($transaction->payable_id))
                    ? ['label' => "Pedido #{$order->number}", 'url' => route('orders.show', $order)] : null,
                default => null,
            };
        }

        return [
            'id' => $transaction->id,
            'reference' => $transaction->reference,
            'provider' => $this->gateways->exists($transaction->provider) ? $this->gateways->for($transaction->provider)->label() : $transaction->provider,
            'environment' => $transaction->environment,
            'method' => $transaction->paymentMethod?->name ?? ((($transaction->context ?? [])['test'] ?? false) ? 'Cobro de prueba' : '—'),
            'store' => $transaction->store?->name,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'channel' => $transaction->channel,
            'channel_label' => $transaction->channel === PaymentTransaction::CHANNEL_POS ? 'Caja' : 'Tienda en línea',
            'mode' => $transaction->mode,
            'status' => $transaction->status,
            'status_label' => $transaction->statusLabel(),
            'to_reconcile' => $transaction->status === PaymentTransaction::STATUS_APPROVED && $transaction->applied_at === null && $transaction->resolution === null,
            'resolution' => $transaction->resolution,
            'resolution_label' => $transaction->resolution ? (PaymentTransaction::RESOLUTION_LABELS[$transaction->resolution] ?? $transaction->resolution) : null,
            'payable' => $payable,
            'created_at' => $transaction->created_at?->toIso8601String(),
            'approved_at' => $transaction->approved_at?->toIso8601String(),
            'applied_at' => $transaction->applied_at?->toIso8601String(),
        ];
    }

    /**
     * Lo que se iba a cobrar en la caja, para reconocer el tiquete de un pago por conciliar.
     *
     * @return list<array{description: string, quantity: float}>
     */
    protected function ticketLines(PaymentTransaction $transaction): array
    {
        $items = array_values(array_filter((array) data_get($transaction->context, 'sale.items', []), 'is_array'));
        $products = Product::query()->whereIn('id', array_filter(array_column($items, 'product_id')))->pluck('name', 'id');
        $services = Service::query()->withTrashed()->whereIn('id', array_filter(array_column($items, 'service_id')))->pluck('name', 'id');

        return array_map(fn (array $item) => [
            'description' => (string) ($item['description']
                ?? (isset($item['product_id']) ? $products[$item['product_id']] ?? null : null)
                ?? (isset($item['service_id']) ? $services[$item['service_id']] ?? null : null)
                ?? 'Artículo'),
            'quantity' => (float) ($item['quantity'] ?? 0),
        ], $items);
    }

    protected function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}

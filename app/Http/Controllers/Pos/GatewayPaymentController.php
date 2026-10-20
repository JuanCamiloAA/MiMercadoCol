<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\StartPosGatewayPaymentRequest;
use App\Models\PaymentTransaction;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Store;
use App\Services\Payments\GatewayPaymentService;
use App\Services\Payments\PosGatewayPayments;
use App\Services\RegisterSessionService;
use App\Support\StoreContext;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El cobro con QR, link o datafono en la caja. JSON: la caja muestra el QR o «esperando el
 * datafono», pregunta el estado cada pocos segundos y, cuando aprueba, recibe la venta hecha.
 */
class GatewayPaymentController extends Controller
{
    public function __construct(
        protected PosGatewayPayments $pos,
        protected GatewayPaymentService $payments,
        protected RegisterSessionService $sessions,
    ) {}

    public function store(StartPosGatewayPaymentRequest $request): JsonResponse
    {
        $storeId = StoreContext::requireStoreId();
        $store = Store::query()->findOrFail($storeId);
        $session = $this->sessions->openSessionFor($request->user(), $storeId, $request->integer('register_session_id') ?: null);

        abort_if($session === null, 409, 'No hay ninguna caja abierta en esta sede.');

        $transaction = $this->pos->start($request->saleData(), $request->validated('gateway'), $request->validated('held'), $request->user(), $store, $session);

        // La autorizacion de descuento o credito ya quedo guardada con el cobro.
        foreach (array_keys(AuthorizationController::ACTIONS) as $action) {
            AuthorizationController::forget($request, $action);
        }

        return response()->json($this->present($transaction->refresh()), 201);
    }

    /**
     * El sondeo de la caja: si sigue pendiente se relee en la pasarela. El webhook puede haberla
     * aprobado ya; los dos caminos terminan en la misma liquidacion, con la fila bloqueada.
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        $transaction = $this->find($reference);

        return response()->json($this->present($this->payments->refresh($transaction)));
    }

    public function cancel(Request $request, string $reference): JsonResponse
    {
        $transaction = $this->find($reference);

        return response()->json($this->present($this->payments->cancel($transaction, $request->user())));
    }

    protected function find(string $reference): PaymentTransaction
    {
        $transaction = PaymentTransaction::query()
            ->where('reference', $reference)
            ->where('channel', PaymentTransaction::CHANNEL_POS)
            ->where('store_id', StoreContext::requireStoreId())
            ->with('gatewayAccount')
            ->first();

        abort_if($transaction === null || (int) $transaction->company_id !== (int) TenantContext::activeCompanyId(), 404);

        return $transaction;
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(PaymentTransaction $transaction): array
    {
        $sale = $transaction->applied_at && $transaction->payable_type === (new Sale)->getMorphClass()
            ? Sale::query()->with('payments.paymentMethod')->find($transaction->payable_id)
            : null;
        $cash = $sale?->payments->firstWhere('method', SalePayment::METHOD_CASH);

        return [
            'reference' => $transaction->reference,
            'status' => $transaction->status,
            'status_label' => $transaction->statusLabel(),
            'status_detail' => $transaction->status_detail,
            'mode' => $transaction->mode,
            'amount' => $transaction->amount,
            'checkout_url' => $transaction->isPending() ? $transaction->checkout_url : null,
            'qr_image' => $transaction->isPending() && $transaction->mode !== PaymentTransaction::MODE_TERMINAL ? PosGatewayPayments::qrImage($transaction->qr_payload) : null,
            'expires_at' => $transaction->expires_at?->toIso8601String(),
            'applied' => $transaction->applied_at !== null,
            // Aprobada pero sin venta (la caja se cerro, se cancelo tarde): queda por conciliar.
            'to_reconcile' => $transaction->status === PaymentTransaction::STATUS_APPROVED && $transaction->applied_at === null,
            // El tiquete guardado mientras se esperaba: al volver al cobro la caja lo descarta o lo retoma.
            'held_sale_id' => ! $transaction->isPending() && $transaction->status !== PaymentTransaction::STATUS_APPROVED ? (($transaction->context ?? [])['held_sale_id'] ?? null) : null,
            'sale' => $sale ? [
                'id' => $sale->id,
                'number' => $sale->number,
                'total' => $sale->total,
                'rounding_total' => $sale->rounding_total,
                'due_total' => $sale->due_total,
                'received_amount' => $cash?->amount,
                'change_amount' => $cash?->change_amount,
                'payments' => $sale->payments->sortBy('sequence')->values()->map(fn (SalePayment $payment) => [
                    'method' => $payment->method,
                    'label' => $payment->methodLabel(),
                    'amount' => $payment->netAmount(),
                    'reference' => $payment->reference,
                ])->all(),
                'print_url' => route('pos.sales.print', $sale),
            ] : null,
        ];
    }
}

<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\Payments\GatewayPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El pago en linea de un pedido, del lado del cliente.
 *
 * La misma pagina sirve de ida (lleva al cliente a la pasarela) y de vuelta. A la vuelta no le
 * cree a lo que traiga la URL: muestra «confirmando…» y pregunta el estado, que sale del webhook
 * o de la relectura en la pasarela. Lo que traiga la URL se usa solo como pista para preguntar.
 */
class OnlinePaymentController extends Controller
{
    public function __construct(protected GatewayPaymentService $payments) {}

    public function show(Request $request, string $reference): Response
    {
        $transaction = $this->find($reference);
        $order = Order::query()->withoutGlobalScopes()->find($transaction->payable_id);
        $returning = $request->query->count() > 0;

        return Inertia::render('Storefront/OnlinePayment', [
            'payment' => $this->present($transaction),
            'order' => $order ? ['id' => $order->id, 'number' => $order->number, 'total' => $order->total, 'url' => route('storefront.orders.show', $order)] : null,
            // Ida: el cobro abierto en la pasarela. Vuelta (la pasarela agrega parametros): a confirmar.
            'checkout' => $transaction->isPending() && ! $returning ? (($transaction->context ?? [])['checkout'] ?? null) : null,
            'hint' => $this->hint($request),
        ]);
    }

    /** El estado, releido si sigue pendiente. */
    public function status(Request $request, string $reference): JsonResponse
    {
        $transaction = $this->payments->refresh($this->find($reference), $this->hint($request));

        return response()->json($this->present($transaction));
    }

    protected function find(string $reference): PaymentTransaction
    {
        $customer = auth('customer')->user();
        $transaction = PaymentTransaction::query()->withoutGlobalScopes()
            ->with('gatewayAccount')
            ->where('reference', $reference)
            ->where('payable_type', (new Order)->getMorphClass())
            ->first();

        $order = $transaction ? Order::query()->withoutGlobalScopes()->find($transaction->payable_id) : null;

        // Solo el cliente del pedido ve su pago.
        abort_if(! $transaction || ! $order || ! $customer || (int) $order->customer_id !== (int) $customer->id, 404);

        return $transaction;
    }

    /**
     * El id que algunas pasarelas agregan a la vuelta (ePayco `ref_payco`, Wompi `id`). Solo
     * sirve para preguntarle a la pasarela; la respuesta se verifica contra la referencia.
     */
    protected function hint(Request $request): ?string
    {
        $value = $request->query('ref_payco') ?? $request->query('id') ?? $request->query('payment_id') ?? $request->query('transactionId');

        return is_string($value) && preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(PaymentTransaction $transaction): array
    {
        return [
            'reference' => $transaction->reference,
            'status' => $transaction->status,
            'status_label' => $transaction->statusLabel(),
            'amount' => $transaction->amount,
            'expires_at' => $transaction->expires_at?->toIso8601String(),
            'approved' => $transaction->status === PaymentTransaction::STATUS_APPROVED,
            'final' => $transaction->isFinal(),
        ];
    }
}

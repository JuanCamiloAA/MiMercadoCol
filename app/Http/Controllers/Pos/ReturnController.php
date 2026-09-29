<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerReturns\ExchangePosSaleRequest;
use App\Http\Requests\CustomerReturns\StorePosReturnRequest;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnItem;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Store;
use App\Services\CustomerReturnDocument;
use App\Services\CustomerReturnService;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use App\Services\StoreCreditService;
use App\Support\StoreContext;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Devoluciones en caja: buscar la venta (de cualquier dia, dentro del plazo), ver que queda por
 * devolver y registrarla resuelta en el mismo acto: reembolso, saldo a favor, cambio o rechazo.
 */
class ReturnController extends Controller
{
    public function __construct(
        protected CustomerReturnService $returns,
        protected SaleService $sales,
        protected RegisterSessionService $sessions,
        protected StoreCreditService $credits,
    ) {}

    /** Una venta por su numero, para devolver lo de otro dia o de otra caja. */
    public function lookup(Request $request): JsonResponse
    {
        $number = trim((string) $request->query('numero', ''));

        $sale = $number === '' ? null : Sale::query()
            ->where('company_id', TenantContext::requireCompanyId())
            ->where('number', $number)
            ->where('status', '!=', Sale::STATUS_REFUNDED)
            ->with('customer:id,name')
            ->latest('id')
            ->first();

        if (! $sale) {
            return response()->json(['message' => "No encontramos la venta {$number}."], 404);
        }

        return response()->json(['sale' => [
            'id' => $sale->id,
            'number' => $sale->number,
            'total' => $sale->total,
            'status' => $sale->status,
            'status_label' => $sale->statusLabel(),
            'customer' => $sale->customer?->name,
            'sold_at' => $sale->sold_at->toIso8601String(),
        ]]);
    }

    /** Lo que queda por devolver de cada linea, el plazo y el cliente con su saldo. */
    public function detail(Request $request, Sale $sale): JsonResponse
    {
        abort_unless((int) $sale->company_id === TenantContext::requireCompanyId(), 404);

        return response()->json([
            'sale' => $this->returns->saleForReturn($sale),
            'reasons' => CustomerReturnItem::reasonOptions(),
            'wasteReasons' => CustomerReturnItem::WASTE_REASONS,
        ]);
    }

    /**
     * Devolucion resuelta en caja. Acepta tambien el formulario de antes (`restock` por articulo
     * de servicio y `method`), que resuelve como reembolso.
     */
    public function store(StorePosReturnRequest $request, Sale $sale): RedirectResponse
    {
        abort_unless((int) $sale->company_id === TenantContext::requireCompanyId(), 404);

        $store = Store::query()->findOrFail(StoreContext::requireStoreId());
        $session = $this->sessions->openSessionFor($request->user(), $store->id);

        $return = $this->returns->registerAtPos(
            $sale,
            $request->returnData(),
            $request->user(),
            $store,
            $session,
            AuthorizationController::approverFor($request, 'return_window'),
        );

        AuthorizationController::forget($request, 'return_window');

        return back()
            ->with('success', $this->summary($return))
            ->with('last_return', [
                'id' => $return->id,
                'number' => $return->number,
                'status' => $return->status,
                'print_url' => route('pos.returns.print', $return),
            ]);
    }

    /** Cambio: devolucion y venta nueva en un solo acto. */
    public function exchange(ExchangePosSaleRequest $request, Sale $sale): RedirectResponse
    {
        abort_unless((int) $sale->company_id === TenantContext::requireCompanyId(), 404);

        $store = Store::query()->findOrFail(StoreContext::requireStoreId());
        $session = $this->sessions->openSessionFor($request->user(), $store->id, $request->integer('register_session_id') ?: null);

        if (! $session) {
            return redirect()->route('registers.opening.create')->with('error', 'No hay ninguna caja abierta en esta sede.');
        }

        $result = $this->returns->exchangeAtPos(
            $sale,
            $request->returnData(),
            $request->saleData(),
            $request->user(),
            $store,
            $session,
            AuthorizationController::approverFor($request, 'return_window'),
        );

        foreach (array_keys(AuthorizationController::ACTIONS) as $action) {
            AuthorizationController::forget($request, $action);
        }

        $newSale = $result['sale'];
        $return = $result['return'];
        $cashPayment = $newSale->payments->firstWhere('method', SalePayment::METHOD_CASH);

        return redirect()->route('pos.index')
            ->with('success', "Cambio registrado: devolución {$return->number} y venta {$newSale->number}.")
            ->with('last_sale', [
                'id' => $newSale->id,
                'number' => $newSale->number,
                'total' => $newSale->total,
                'rounding_total' => $newSale->rounding_total,
                'due_total' => $newSale->due_total,
                'received_amount' => $cashPayment?->amount,
                'change_amount' => $cashPayment?->change_amount,
                'payments' => $newSale->payments->sortBy('sequence')->values()->map(fn (SalePayment $payment) => [
                    'method' => $payment->method,
                    'label' => $payment->methodLabel(),
                    'amount' => $payment->netAmount(),
                    'reference' => $payment->reference,
                ])->all(),
                'print_url' => route('pos.sales.print', $newSale),
                // Lo que sobro del cambio y como salio, para decirlo en grande.
                'exchange' => [
                    'return_number' => $return->number,
                    'return_print_url' => route('pos.returns.print', $return),
                    'leftover' => $return->refunded_total + $return->credited_total,
                    'leftover_label' => $return->credited_total > 0 ? 'Saldo a favor' : 'Efectivo',
                ],
            ]);
    }

    /** Comprobante de la devolucion en el rollo de la caja. */
    public function print(Request $request, CustomerReturn $customerReturn, CustomerReturnDocument $document): HttpResponse
    {
        abort_unless((int) $customerReturn->company_id === TenantContext::requireCompanyId(), 404);

        return response()->view('pos.return-print', $document->viewData($customerReturn));
    }

    protected function summary(CustomerReturn $return): string
    {
        $money = fn (int $value) => '$ '.number_format($value, 0, ',', '.');

        if ($return->status === CustomerReturn::STATUS_REJECTED) {
            return "Devolución {$return->number} registrada como rechazada.";
        }

        $parts = [];

        if ($return->refunded_total > 0) {
            $parts[] = $money($return->refunded_total).' en '.mb_strtolower(SalePayment::METHOD_LABELS[$return->refund_method] ?? 'efectivo');
        }

        if ($return->credited_total > 0) {
            $parts[] = $money($return->credited_total).' de saldo a favor';
        }

        if ($return->debt_relief_total > 0) {
            $parts[] = $money($return->debt_relief_total).' descontados de la cartera';
        }

        return "Devolución {$return->number} registrada".($parts !== [] ? ': '.implode(', ', $parts) : '').'.';
    }
}

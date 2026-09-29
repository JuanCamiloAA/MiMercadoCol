<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Services\CreditService;
use App\Services\RegisterSessionService;
use App\Support\StoreContext;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerPaymentController extends Controller
{
    public function __construct(
        protected CreditService $credit,
        protected RegisterSessionService $sessions,
    ) {}

    public function store(Request $request, Customer $customer): RedirectResponse
    {
        abort_if(
            ! $request->user()->isSuperAdmin() && $customer->company_id !== TenantContext::activeCompanyId(),
            403,
            'Ese cliente no pertenece a tu empresa.'
        );

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'gt:0'],
            'method' => ['required', Rule::in([CustomerPayment::METHOD_CASH, CustomerPayment::METHOD_CARD, CustomerPayment::METHOD_TRANSFER])],
            'note' => ['nullable', 'string', 'max:160'],
        ], [
            'amount.required' => 'Indica el valor del abono.',
            'amount.gt' => 'El abono debe ser mayor que cero.',
        ]);

        $balance = $this->credit->currentBalance($customer);

        if ($balance <= 0) {
            return back()->with('error', 'Ese cliente no tiene deuda pendiente.');
        }

        // No se reciben anticipos: un abono mayor que la deuda dejaria un saldo a favor que el sistema no lleva.
        if ($validated['amount'] > $balance) {
            return back()->withErrors([
                'amount' => 'El abono no puede superar la deuda de $ '.number_format($balance, 0, ',', '.').'.',
            ]);
        }

        $storeId = StoreContext::requireStoreId();
        $session = $this->sessions->openSessionFor($request->user(), $storeId);

        $result = $this->credit->registerPayment(
            customer: $customer,
            amount: (int) $validated['amount'],
            method: $validated['method'],
            storeId: $storeId,
            receivedBy: $request->user(),
            // El abono en efectivo entra a la caja del turno: si no, el arqueo no cuadraria.
            registerSessionId: $validated['method'] === CustomerPayment::METHOD_CASH ? $session?->id : null,
            note: $validated['note'] ?? null,
        );

        return back()->with(
            'success',
            'Abono de $ '.number_format($result['applied'], 0, ',', '.').' aplicado a las facturas mas antiguas.'
        );
    }
}

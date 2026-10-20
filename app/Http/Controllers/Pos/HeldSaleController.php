<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\HeldSale;
use App\Models\PaymentTransaction;
use App\Services\PosCustomerPresenter;
use App\Services\RegisterSessionService;
use App\Support\StoreContext;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tiquetes suspendidos: se guardan tal cual estan para retomarlos mas tarde, con su cliente.
 */
class HeldSaleController extends Controller
{
    public function __construct(
        protected RegisterSessionService $sessions,
        protected PosCustomerPresenter $customers,
    ) {}

    /**
     * El cliente viaja completo y calculado ahora (cupo, saldo, saldo a favor): al retomar el
     * tiquete la caja lo deja puesto sin volver a buscarlo, y con los numeros de este momento.
     */
    public function index(Request $request): JsonResponse
    {
        $storeId = StoreContext::requireStoreId();

        $held = HeldSale::query()
            ->where('store_id', $storeId)
            ->whereNotIn('id', PaymentTransaction::heldSaleIdsAwaiting($storeId))
            ->with('customer')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        // Uno desactivado despues de suspender no se vuelve a poner: se avisa y se elige otro.
        $presented = $this->customers->present($held->pluck('customer')->filter(fn (?Customer $customer) => $customer?->is_active)->unique('id')->values());

        return response()->json(['held' => $held->map(fn (HeldSale $sale) => [
            'id' => $sale->id,
            'label' => $sale->label,
            'total' => $sale->total,
            'customer' => $sale->customer_id ? $presented->get($sale->customer_id) : null,
            'customer_name' => $sale->customer?->name,
            'payload' => $sale->payload,
            'created_at' => $sale->created_at->toIso8601String(),
        ])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $storeId = StoreContext::requireStoreId();
        $session = $this->sessions->openSessionFor($request->user(), $storeId);

        abort_if($session === null, 409, 'No tienes un turno de caja abierto.');

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:40'],
            'total' => ['required', 'integer', 'min:0'],
            // Solo un cliente de esta empresa: el id llega del navegador.
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'payload' => ['required', 'array'],
        ], [
            'label.required' => 'Ponle un nombre al tiquete suspendido.',
            'customer_id.exists' => 'Ese cliente no es de esta empresa.',
            'payload.required' => 'El tiquete esta vacio.',
        ]);

        $held = HeldSale::create([
            'company_id' => $companyId,
            'store_id' => $storeId,
            'register_session_id' => $session->id,
            'user_id' => $request->user()->id,
            'customer_id' => $validated['customer_id'] ?? null,
            'label' => $validated['label'],
            'payload' => $validated['payload'],
            'total' => $validated['total'],
        ]);

        return response()->json(['id' => $held->id]);
    }

    public function destroy(Request $request, HeldSale $held): JsonResponse
    {
        abort_if(
            ! $request->user()->isSuperAdmin() && $held->company_id !== TenantContext::activeCompanyId(),
            403,
            'Ese tiquete no pertenece a tu empresa.'
        );

        $held->delete();

        return response()->json(['ok' => true]);
    }
}

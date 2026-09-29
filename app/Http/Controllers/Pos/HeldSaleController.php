<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\HeldSale;
use App\Services\RegisterSessionService;
use App\Support\StoreContext;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tiquetes suspendidos: se guardan tal cual estan para retomarlos mas tarde.
 */
class HeldSaleController extends Controller
{
    public function __construct(protected RegisterSessionService $sessions) {}

    public function index(Request $request): JsonResponse
    {
        $storeId = StoreContext::requireStoreId();

        $held = HeldSale::query()
            ->where('store_id', $storeId)
            ->with('customer:id,name')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (HeldSale $sale) => [
                'id' => $sale->id,
                'label' => $sale->label,
                'total' => $sale->total,
                'customer' => $sale->customer?->name,
                'payload' => $sale->payload,
                'created_at' => $sale->created_at->toIso8601String(),
            ])
            ->values();

        return response()->json(['held' => $held]);
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
            'customer_id' => ['nullable', 'integer'],
            'payload' => ['required', 'array'],
        ], [
            'label.required' => 'Ponle un nombre al tiquete suspendido.',
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

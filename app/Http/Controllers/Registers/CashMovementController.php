<?php

namespace App\Http\Controllers\Registers;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\RegisterSession;
use App\Services\RegisterSessionService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CashMovementController extends Controller
{
    public function __construct(protected RegisterSessionService $sessions) {}

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'register_session_id' => ['required', 'integer'],
            'type' => ['required', Rule::in([CashMovement::TYPE_IN, CashMovement::TYPE_OUT])],
            'amount' => ['required', 'integer', 'gt:0'],
            'reason' => ['required', 'string', 'max:160'],
        ], [
            'amount.gt' => 'El monto debe ser mayor que cero.',
            'reason.required' => 'Explica el motivo del movimiento.',
        ]);

        $session = RegisterSession::query()
            ->where('company_id', TenantContext::requireCompanyId())
            ->findOrFail($validated['register_session_id']);

        abort_if(
            $session->user_id !== $request->user()->id && ! $request->user()->can('registers.close.view_others'),
            403,
            'Solo puedes mover el efectivo de tu propio turno.'
        );

        $this->sessions->registerCashMovement(
            $session,
            $validated['type'],
            (int) $validated['amount'],
            $validated['reason'],
            $request->user(),
        );

        return back()->with('success', 'El movimiento de efectivo quedo registrado.');
    }
}

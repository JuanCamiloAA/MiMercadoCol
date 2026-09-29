<?php

namespace App\Http\Controllers\Registers;

use App\Http\Controllers\Controller;
use App\Models\RegisterSession;
use App\Services\RegisterSessionService;
use App\Support\StoreContext;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ClosingController extends Controller
{
    public function __construct(protected RegisterSessionService $sessions) {}

    public function create(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();
        $storeId = StoreContext::requireStoreId();
        $user = $request->user();
        $canSeeOthers = $user->can('registers.close.view_others');

        $openSessions = RegisterSession::query()
            ->where('company_id', $companyId)
            ->where('store_id', $storeId)
            ->where('status', RegisterSession::STATUS_OPEN)
            ->when(! $canSeeOthers, fn ($query) => $query->where('user_id', $user->id))
            ->with(['register:id,name,code', 'user:id,name,last_name'])
            ->orderBy('opened_at')
            ->get();

        $selectedId = $request->integer('session') ?: $openSessions->first()?->id;
        $selected = $openSessions->firstWhere('id', $selectedId);

        return Inertia::render('Pos/Closing', [
            'sessions' => $openSessions->map(fn (RegisterSession $session) => [
                'id' => $session->id,
                'register' => $session->register?->name,
                'cashier' => $session->user?->full_name,
                'opened_at' => $session->opened_at->toIso8601String(),
            ])->values(),
            'session' => $selected ? [
                'id' => $selected->id,
                'register' => $selected->register?->name,
                'register_code' => $selected->register?->code,
                'cashier' => $selected->user?->full_name,
                'opened_at' => $selected->opened_at->toIso8601String(),
                'opening_amount' => $selected->opening_amount,
            ] : null,
            'summary' => $selected ? $this->sessions->summary($selected) : null,
            'denominations' => OpeningController::DENOMINATIONS,
            'closedToday' => RegisterSession::query()
                ->where('store_id', $storeId)
                ->where('status', RegisterSession::STATUS_CLOSED)
                ->whereDate('closed_at', today())
                ->with(['register:id,name', 'user:id,name,last_name'])
                ->orderByDesc('closed_at')
                ->get()
                ->map(fn (RegisterSession $session) => [
                    'id' => $session->id,
                    'register' => $session->register?->name,
                    'cashier' => $session->user?->full_name,
                    'closed_at' => $session->closed_at?->toIso8601String(),
                    'expected_amount' => $session->expected_amount,
                    'counted_amount' => $session->counted_amount,
                    'difference' => $session->difference,
                ])
                ->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'register_session_id' => ['required', 'integer'],
            'breakdown' => ['array'],
            'breakdown.*' => ['integer', 'min:0'],
            'closing_note' => ['nullable', 'string', 'max:200'],
        ], ['register_session_id.required' => 'Elige el turno a cerrar.']);

        $session = $this->findSession($request, (int) $validated['register_session_id']);

        $breakdown = array_filter($validated['breakdown'] ?? [], fn ($count) => (int) $count > 0);
        $counted = 0;

        foreach ($breakdown as $denomination => $count) {
            $counted += (int) $denomination * (int) $count;
        }

        $this->sessions->close($session, $counted, $breakdown, $validated['closing_note'] ?? null);

        return redirect()->route('registers.closing.create')
            ->with('success', 'Turno cerrado. Ya puedes imprimir el informe Z.');
    }

    /**
     * Informe Z del turno.
     */
    public function report(Request $request, RegisterSession $session): HttpResponse
    {
        $session = $this->authorizeSession($request, $session);
        $session->load(['register', 'store', 'user']);

        $pdf = Pdf::loadView('pdf.z-report', [
            'session' => $session,
            'summary' => $this->sessions->summary($session),
            'company' => TenantContext::company(),
        ])->setPaper('letter');

        return $pdf->stream("informe-z-{$session->id}.pdf");
    }

    protected function findSession(Request $request, int $id): RegisterSession
    {
        $session = RegisterSession::query()
            ->where('company_id', TenantContext::requireCompanyId())
            ->where('status', RegisterSession::STATUS_OPEN)
            ->findOrFail($id);

        return $this->authorizeSession($request, $session);
    }

    protected function authorizeSession(Request $request, RegisterSession $session): RegisterSession
    {
        abort_if(
            ! $request->user()->isSuperAdmin() && $session->company_id !== TenantContext::activeCompanyId(),
            403,
            'Ese turno no pertenece a tu empresa.'
        );

        abort_if(
            $session->user_id !== $request->user()->id && ! $request->user()->can('registers.close.view_others'),
            403,
            'Solo puedes cerrar tu propio turno.'
        );

        return $session;
    }
}

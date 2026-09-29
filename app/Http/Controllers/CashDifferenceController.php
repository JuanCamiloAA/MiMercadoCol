<?php

namespace App\Http\Controllers;

use App\Models\AccessLog;
use App\Models\CashMovement;
use App\Models\Register;
use App\Models\RegisterSession;
use App\Models\User;
use App\Services\RegisterSessionService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Diferencias de caja.
 *
 * El cierre deja anotado el descuadre y la pantalla de apertura se lo recuerda al siguiente
 * cajero, pero alguien tiene que decidir que paso con esa plata. Aqui el administrador revisa
 * cada turno que no cuadro, mira de donde pudo salir la diferencia y la cierra con una
 * decision: se justifico, la asume la empresa, se le descuenta al cajero o el cajero repuso.
 */
class CashDifferenceController extends Controller
{
    public function __construct(protected RegisterSessionService $sessions) {}

    public function index(Request $request): Response
    {
        $companyId = TenantContext::requireCompanyId();

        $filters = [
            'status' => $request->string('status')->toString() ?: 'pending',
            'register' => $request->string('register')->toString(),
            'user' => $request->string('user')->toString(),
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
        ];

        $from = $this->parseDate($filters['from']) ?? CarbonImmutable::today()->subDays(29);
        $to = $this->parseDate($filters['to']) ?? CarbonImmutable::today();

        $filters['from'] = $from->toDateString();
        $filters['to'] = $to->toDateString();

        $base = RegisterSession::query()
            ->where('company_id', $companyId)
            ->withDifference()
            ->whereBetween('closed_at', [$from->startOfDay(), $to->endOfDay()])
            ->when($filters['register'], fn ($query, $register) => $query->where('register_id', (int) $register))
            ->when($filters['user'], fn ($query, $user) => $query->where('user_id', (int) $user))
            ->when(
                $filters['status'] === 'pending',
                fn ($query) => $query->where('difference_status', RegisterSession::DIFFERENCE_PENDING)
            )
            ->when(
                $filters['status'] === 'resolved',
                fn ($query) => $query->where('difference_status', RegisterSession::DIFFERENCE_RESOLVED)
            );

        $differences = (clone $base)
            ->with(['register:id,name', 'user:id,name,last_name', 'resolvedBy:id,name,last_name'])
            ->orderByDesc('closed_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (RegisterSession $session) => $this->present($session));

        return Inertia::render('Registers/Differences', [
            'differences' => $differences,
            'filters' => $filters,
            'metrics' => $this->metrics($companyId, $from, $to),
            'resolutions' => collect(RegisterSession::RESOLUTION_LABELS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'registers' => Register::query()
                ->where('company_id', $companyId)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Register $register) => ['value' => $register->id, 'label' => $register->name])
                ->all(),
            'cashiers' => User::query()
                ->where('company_id', $companyId)
                ->whereHas('registerSessions', fn ($query) => $query->withDifference())
                ->orderBy('name')
                ->get(['id', 'name', 'last_name'])
                ->map(fn (User $user) => ['value' => $user->id, 'label' => $user->full_name])
                ->all(),
        ]);
    }

    /**
     * Cierra el descuadre con una decision.
     */
    public function resolve(Request $request, RegisterSession $session): RedirectResponse
    {
        $this->authorizeCompany($session);

        abort_if($session->status !== RegisterSession::STATUS_CLOSED, 422, 'Ese turno todavia esta abierto.');
        abort_if((int) $session->difference === 0, 422, 'Ese turno cuadro: no hay nada que resolver.');

        $validated = $request->validate([
            'resolution' => ['required', Rule::in(array_keys(RegisterSession::RESOLUTION_LABELS))],
            'note' => ['required', 'string', 'min:5', 'max:200'],
        ], [
            'resolution.required' => 'Elige que se hizo con la diferencia.',
            'note.required' => 'Escribe que paso: es lo que va a leer quien revise esto despues.',
            'note.min' => 'Explica un poco mas que paso.',
        ]);

        $session->fill([
            'difference_status' => RegisterSession::DIFFERENCE_RESOLVED,
            'resolution' => $validated['resolution'],
            'resolution_note' => $validated['note'],
            'resolved_by_user_id' => $request->user()->id,
            'resolved_at' => now(),
        ])->save();

        // Una diferencia de caja es plata: quede constancia de quien decidio que hacer con ella.
        AccessLog::log('cash_difference_resolved', $request->user()->id, [
            'register_session_id' => $session->id,
            'register' => $session->register?->name,
            'difference' => (int) $session->difference,
            'resolution' => $validated['resolution'],
        ]);

        return back()->with('success', 'La diferencia quedo resuelta.');
    }

    /** Vuelve a dejarla pendiente, cuando la decision se tomo por error. */
    public function reopen(Request $request, RegisterSession $session): RedirectResponse
    {
        $this->authorizeCompany($session);

        $session->fill([
            'difference_status' => RegisterSession::DIFFERENCE_PENDING,
            'resolution' => null,
            'resolution_note' => null,
            'resolved_by_user_id' => null,
            'resolved_at' => null,
        ])->save();

        AccessLog::log('cash_difference_reopened', $request->user()->id, [
            'register_session_id' => $session->id,
        ]);

        return back()->with('success', 'La diferencia volvio a quedar pendiente.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(RegisterSession $session): array
    {
        $difference = (int) $session->difference;

        return [
            'id' => $session->id,
            'register' => $session->register?->name,
            'cashier' => $session->user?->full_name,
            'opened_at' => $session->opened_at?->toIso8601String(),
            'closed_at' => $session->closed_at?->toIso8601String(),
            'opening_amount' => (int) $session->opening_amount,
            'expected_amount' => (int) $session->expected_amount,
            'counted_amount' => (int) $session->counted_amount,
            'difference' => $difference,
            'kind' => $difference < 0 ? 'faltante' : 'sobrante',
            'closing_note' => $session->closing_note,
            'status' => $session->difference_status,
            'resolution' => $session->resolution,
            'resolution_label' => $session->resolutionLabel(),
            'resolution_note' => $session->resolution_note,
            'resolved_by' => $session->resolvedBy?->full_name,
            'resolved_at' => $session->resolved_at?->toIso8601String(),
            'report_url' => route('registers.closing.report', $session),
            // Lo que ayuda a entender de donde pudo salir la plata.
            'movements' => CashMovement::query()
                ->where('register_session_id', $session->id)
                ->orderBy('id')
                ->get(['id', 'type', 'amount', 'reason'])
                ->map(fn (CashMovement $movement) => [
                    'id' => $movement->id,
                    'type' => $movement->type,
                    'amount' => (int) $movement->amount,
                    'reason' => $movement->reason,
                ])->all(),
        ];
    }

    /**
     * Cifras del rango: cuanto se perdio, cuanto sobro y que falta por revisar.
     *
     * @return array<string, int>
     */
    protected function metrics(int $companyId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $range = fn () => RegisterSession::query()
            ->where('company_id', $companyId)
            ->withDifference()
            ->whereBetween('closed_at', [$from->startOfDay(), $to->endOfDay()]);

        return [
            'pending' => (clone $range())->where('difference_status', RegisterSession::DIFFERENCE_PENDING)->count(),
            'shortfall' => abs((int) (clone $range())->where('difference', '<', 0)->sum('difference')),
            'surplus' => (int) (clone $range())->where('difference', '>', 0)->sum('difference'),
            'sessions' => (clone $range())->count(),
        ];
    }

    protected function parseDate(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function authorizeCompany(RegisterSession $session): void
    {
        abort_if(
            ! auth()->user()->isSuperAdmin() && $session->company_id !== TenantContext::activeCompanyId(),
            403,
            'Ese turno no pertenece a tu empresa.'
        );
    }
}

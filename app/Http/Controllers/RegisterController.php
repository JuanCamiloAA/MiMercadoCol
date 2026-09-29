<?php

namespace App\Http\Controllers;

use App\Models\Register;
use App\Models\RegisterSession;
use App\Models\Store;
use App\Services\Membership\MembershipLimiter;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RegisterController extends Controller
{
    public function index(): Response
    {
        $companyId = TenantContext::requireCompanyId();

        $registers = Register::query()
            ->where('company_id', $companyId)
            ->with(['store:id,name', 'openSession.user:id,name,last_name'])
            ->orderBy('store_id')
            ->orderBy('code')
            ->get()
            ->map(fn (Register $register) => [
                'id' => $register->id,
                'name' => $register->name,
                'code' => $register->code,
                'store' => $register->store ? ['id' => $register->store->id, 'name' => $register->store->name] : null,
                'is_active' => (bool) $register->is_active,
                'open_session' => $register->openSession ? [
                    'id' => $register->openSession->id,
                    'cashier' => $register->openSession->user?->full_name,
                    'opened_at' => $register->openSession->opened_at->toIso8601String(),
                ] : null,
            ]);

        $recentSessions = RegisterSession::query()
            ->where('company_id', $companyId)
            ->where('status', RegisterSession::STATUS_CLOSED)
            ->with(['register:id,name', 'store:id,name', 'user:id,name,last_name'])
            ->orderByDesc('closed_at')
            ->limit(12)
            ->get()
            ->map(fn (RegisterSession $session) => [
                'id' => $session->id,
                'register' => $session->register?->name,
                'store' => $session->store?->name,
                'cashier' => $session->user?->full_name,
                'opened_at' => $session->opened_at->toIso8601String(),
                'closed_at' => $session->closed_at?->toIso8601String(),
                'expected_amount' => $session->expected_amount,
                'counted_amount' => $session->counted_amount,
                'difference' => $session->difference,
            ]);

        return Inertia::render('Registers/Index', [
            'registers' => $registers,
            'recentSessions' => $recentSessions,
            'stores' => Store::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Store $store) => ['value' => $store->id, 'label' => $store->name])
                ->values(),
        ]);
    }

    public function store(Request $request, MembershipLimiter $limiter): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $validated = $this->validateRegister($request, $companyId);

        if ($validated['is_active'] ?? true) {
            $limiter->assertCanAdd(TenantContext::company(), 'registers', 'name');
        }

        Register::create(array_merge($validated, ['company_id' => $companyId]));

        return back()->with('success', 'La caja quedo creada.');
    }

    public function update(Request $request, Register $register, MembershipLimiter $limiter): RedirectResponse
    {
        $this->authorizeCompany($register);
        $validated = $this->validateRegister($request, $register->company_id, $register->id);

        if (! $register->is_active && ($validated['is_active'] ?? false)) {
            $limiter->assertCanAdd(TenantContext::company(), 'registers', 'is_active');
        }

        $register->update($validated);

        return back()->with('success', 'La caja quedo actualizada.');
    }

    public function destroy(Register $register): RedirectResponse
    {
        $this->authorizeCompany($register);

        if ($register->sessions()->exists()) {
            return back()->with('error', 'Esa caja ya tiene turnos registrados: desactivala en vez de eliminarla.');
        }

        $register->delete();

        return back()->with('success', 'La caja quedo eliminada.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateRegister(Request $request, int $companyId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'store_id' => ['required', 'integer', Rule::exists('stores', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'name' => ['required', 'string', 'max:40'],
            'code' => [
                'required', 'string', 'max:10',
                Rule::unique('registers', 'code')->ignore($ignoreId)->where(fn ($query) => $query->where('store_id', $request->integer('store_id'))),
            ],
            'is_active' => ['boolean'],
        ], [
            'store_id.required' => 'Elige la sede.',
            'name.required' => 'Ponle un nombre a la caja.',
            'code.required' => 'El codigo es obligatorio: es el prefijo del numero de tiquete.',
            'code.unique' => 'Ya hay una caja con ese codigo en la sede.',
        ]);
    }

    protected function authorizeCompany(Register $register): void
    {
        abort_if(
            ! auth()->user()->isSuperAdmin() && $register->company_id !== TenantContext::activeCompanyId(),
            403,
            'Esa caja no pertenece a tu empresa.'
        );
    }
}

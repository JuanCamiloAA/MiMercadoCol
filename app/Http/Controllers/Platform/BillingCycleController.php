<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\SaveBillingCycleRequest;
use App\Models\BillingCycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plataforma > Ciclos de cobro. Siempre queda al menos uno activo: sin ciclos no se puede
 * contratar ni renovar.
 */
class BillingCycleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Platform/Cycles/Index', [
            'cycles' => BillingCycle::query()
                ->ordered()
                ->withCount('companies')
                ->get()
                ->map(fn (BillingCycle $cycle) => [
                    'id' => $cycle->id,
                    'name' => $cycle->name,
                    'code' => $cycle->code,
                    'months' => $cycle->months,
                    'discount_percent' => $cycle->discount_percent,
                    'is_active' => $cycle->is_active,
                    'sort_order' => $cycle->sort_order,
                    'companies_count' => $cycle->companies_count,
                ])->values(),
        ]);
    }

    public function store(SaveBillingCycleRequest $request): RedirectResponse
    {
        $cycle = BillingCycle::query()->create($request->validated());

        return back()->with('success', "El ciclo {$cycle->name} quedó creado.");
    }

    public function update(SaveBillingCycleRequest $request, BillingCycle $cycle): RedirectResponse
    {
        $data = $request->validated();

        if ($cycle->is_active && ! $data['is_active']) {
            $this->assertNotLastActive($cycle, 'is_active');
        }

        $cycle->update($data);

        return back()->with('success', "El ciclo {$cycle->name} quedó guardado.");
    }

    public function toggle(BillingCycle $cycle): RedirectResponse
    {
        if ($cycle->is_active && ! BillingCycle::query()->active()->whereKeyNot($cycle->id)->exists()) {
            return back()->with('error', 'Es el único ciclo activo: sin ciclos nadie podría contratar ni renovar.');
        }

        $cycle->update(['is_active' => ! $cycle->is_active]);

        return back()->with('success', $cycle->is_active ? "{$cycle->name} quedó activo." : "{$cycle->name} quedó desactivado.");
    }

    protected function assertNotLastActive(BillingCycle $cycle, string $field): void
    {
        if (! BillingCycle::query()->active()->whereKeyNot($cycle->id)->exists()) {
            throw ValidationException::withMessages([$field => 'Es el único ciclo activo: sin ciclos nadie podría contratar ni renovar.']);
        }
    }
}

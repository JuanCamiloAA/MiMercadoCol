<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\SaveMembershipPlanRequest;
use App\Models\BillingCycle;
use App\Models\MembershipCharge;
use App\Models\MembershipPlan;
use App\Services\Membership\MembershipFeatures;
use App\Services\Membership\MembershipPricing;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plataforma > Planes. El precio de cada ciclo lo calcula `MembershipPricing`; la pantalla solo
 * lo pinta. Un plan con empresas se desactiva, no se borra.
 */
class MembershipPlanController extends Controller
{
    public function index(): Response
    {
        $cycles = BillingCycle::query()->active()->ordered()->get();

        return Inertia::render('Platform/Plans/Index', [
            'plans' => MembershipPlan::query()
                ->ordered()
                ->withCount('companies')
                ->get()
                ->map(fn (MembershipPlan $plan) => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'slug' => $plan->slug,
                    'description' => $plan->description,
                    'monthly_price' => $plan->monthly_price,
                    'trial_days' => $plan->trial_days,
                    'limits' => ['stores' => $plan->max_stores, 'users' => $plan->max_users, 'registers' => $plan->max_registers],
                    'features' => array_map(fn (string $key) => MembershipFeatures::CATALOGUE[$key]['label'], MembershipFeatures::sanitize($plan->features)),
                    'prices' => (object) MembershipPricing::priceTable($plan, $cycles),
                    'is_public' => $plan->is_public,
                    'is_featured' => $plan->is_featured,
                    'is_active' => $plan->is_active,
                    'companies_count' => $plan->companies_count,
                ])->values(),
            'cycles' => $cycles->map(fn (BillingCycle $cycle) => ['id' => $cycle->id, 'name' => $cycle->name])->values(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Platform/Plans/Form', [
            'plan' => null,
            'featureOptions' => MembershipFeatures::options(),
        ]);
    }

    public function store(SaveMembershipPlanRequest $request): RedirectResponse
    {
        $plan = MembershipPlan::query()->create($request->planData());

        return redirect()->route('platform.plans.index')->with('success', "El plan {$plan->name} quedó creado.");
    }

    public function edit(MembershipPlan $plan): Response
    {
        return Inertia::render('Platform/Plans/Form', [
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'description' => $plan->description,
                'monthly_price' => $plan->monthly_price,
                'trial_days' => $plan->trial_days,
                'max_stores' => $plan->max_stores,
                'max_users' => $plan->max_users,
                'max_registers' => $plan->max_registers,
                'features' => MembershipFeatures::sanitize($plan->features),
                'is_public' => $plan->is_public,
                'is_featured' => $plan->is_featured,
                'is_active' => $plan->is_active,
                'sort_order' => $plan->sort_order,
                'companies_count' => $plan->companies()->count(),
            ],
            'featureOptions' => MembershipFeatures::options(),
        ]);
    }

    public function update(SaveMembershipPlanRequest $request, MembershipPlan $plan): RedirectResponse
    {
        $plan->update($request->planData());

        return redirect()->route('platform.plans.index')->with('success', "El plan {$plan->name} quedó guardado.");
    }

    /**
     * Desactivar o activar. Desactivado no se ofrece en la landing ni al cambiar de plan; las
     * empresas que ya lo tienen lo conservan.
     */
    public function toggle(MembershipPlan $plan): RedirectResponse
    {
        $plan->update(['is_active' => ! $plan->is_active]);

        return back()->with('success', $plan->is_active ? "{$plan->name} quedó activo." : "{$plan->name} quedó desactivado. Las empresas que lo tienen lo conservan.");
    }

    /**
     * Borrar solo lo que nunca se uso: con empresas o cobros se desactiva.
     */
    public function destroy(MembershipPlan $plan): RedirectResponse
    {
        if ($plan->companies()->exists() || MembershipCharge::query()->where('membership_plan_id', $plan->id)->exists()) {
            return back()->with('error', 'Este plan tiene empresas o cobros: desactívalo en vez de borrarlo.');
        }

        $plan->delete();

        return back()->with('success', 'El plan quedó borrado.');
    }
}

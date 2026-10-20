<?php

namespace App\Services\Membership;

use App\Models\BillingCycle;
use App\Models\Company;
use App\Models\MembershipCharge;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Services\PlatformSettingsService;

/**
 * Lo que pinta Ajustes > Membresia. Precios de `MembershipPricing`: el navegador no calcula.
 */
class MembershipOverviewService
{
    public function __construct(
        protected MembershipBillingService $billing,
        protected MembershipLimiter $limiter,
        protected PlatformSettingsService $platform,
        protected EntitlementService $entitlements,
        protected PlanCatalogue $catalogue,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Company $company, User $user): array
    {
        $company->loadMissing(['plan', 'cycle', 'nextPlan', 'nextCycle', 'paymentMethod']);

        $wompi = $this->billing->gateway();
        $gatewayOn = $wompi->isUsable();
        $card = $company->paymentMethod;
        $usableCard = $this->billing->usableCard($company);
        $canManage = $user->can('settings.membership.manage_payment');
        $price = $company->plan && $company->cycle ? MembershipPricing::priceFor($company->plan, $company->cycle, $company) : null;
        $special = $company->plan ? MembershipPricing::specialPrice($company, $company->plan) : null;
        $entitlement = $this->entitlements->for($company);

        $quote = null;

        if ($company->plan && $company->cycle) {
            $next = $this->billing->quote($company);
            $quote = [
                'amount' => $next['amount'],
                'concept' => $next['concept'],
                'starts_at' => $next['starts']->toIso8601String(),
                'ends_at' => $next['ends']->toIso8601String(),
            ];
        }

        $cycles = BillingCycle::query()->where('is_active', true)->orderBy('sort_order')->orderBy('months')->get();

        return [
            'plan' => $company->plan ? [
                'id' => $company->plan->id,
                'name' => $company->plan->name,
                'description' => $company->plan->description,
                'modules' => $this->catalogue->modules($company->plan->entitlements),
            ] : null,
            // Lo que incluye: el arbol en solo lectura, con lo agregado y su vencimiento.
            'entitlements' => [
                'tree' => $entitlement->tree(),
                'exceptions' => $company->entitlementExceptions()
                    ->notExpired(now())
                    ->get()
                    ->map(fn ($exception) => [
                        'id' => $exception->id,
                        'node' => $exception->node,
                        'label' => Entitlements::label($exception->node),
                        'effect' => $exception->effect,
                        'starts_at' => $exception->starts_at?->toIso8601String(),
                        'ends_at' => $exception->ends_at?->toIso8601String(),
                    ])->values()->all(),
            ],
            'special_price' => $special ? [
                'description' => $special->describe(),
                'ends_at' => $special->ends_at?->toIso8601String(),
            ] : null,
            'cycle' => $company->cycle ? ['id' => $company->cycle->id, 'name' => $company->cycle->name, 'months' => $company->cycle->months] : null,
            'price' => $price,
            'status' => $company->membership_status,
            'status_label' => $company->membershipLabel(),
            'started_at' => $company->membership_started_at?->toIso8601String(),
            'ends_at' => $company->membership_ends_at?->toIso8601String(),
            'days_left' => MembershipService::daysLeft($company),
            'grace_ends_at' => $company->grace_ends_at?->toIso8601String(),
            'next_charge_at' => $company->next_charge_at?->toIso8601String(),
            'auto_renew' => (bool) $company->auto_renew,
            'scheduled_change' => $company->nextPlan || $company->nextCycle ? [
                'plan' => ($company->nextPlan ?? $company->plan)?->name,
                'cycle' => ($company->nextCycle ?? $company->cycle)?->name,
                'applies_at' => $company->membership_ends_at?->toIso8601String(),
            ] : null,
            'usage' => $this->limiter->usage($company),
            'card' => $card ? [
                'brand' => $card->brand,
                'last4' => $card->last4,
                'exp_month' => $card->exp_month,
                'exp_year' => $card->exp_year,
                'holder' => $card->holder_name,
                'usable' => $usableCard !== null,
                // Guardada en pruebas y la pasarela ya cobra en produccion, o al reves.
                'other_environment' => $card->environment !== $wompi->environment(),
                'expires_before_renewal' => $company->membership_ends_at !== null && $card->expiresBefore($company->membership_ends_at),
            ] : null,
            'gateway' => [
                'enabled' => $gatewayOn,
                'support_email' => $this->platform->get('platform.support_email'),
            ],
            'can_manage' => $canManage,
            'quote' => $quote,
            'in_flight' => MembershipCharge::query()
                ->where('company_id', $company->id)
                ->where('status', MembershipCharge::STATUS_PENDING)
                ->where('method', MembershipCharge::METHOD_SAVED_CARD)
                ->where('created_at', '>=', now()->subDay())
                ->exists(),
            'plans' => $canManage ? $this->planOptions($company, $cycles) : [],
            'cycles' => $canManage ? $cycles->map(fn (BillingCycle $cycle) => [
                'id' => $cycle->id,
                'name' => $cycle->name,
                'months' => $cycle->months,
                'discount_percent' => $cycle->discount_percent,
            ])->values()->all() : [],
            'charges' => MembershipCharge::query()
                ->where('company_id', $company->id)
                ->latest('id')
                ->limit(24)
                ->get()
                ->map(fn (MembershipCharge $charge) => [
                    'id' => $charge->id,
                    'date' => ($charge->paid_at ?? $charge->charged_at ?? $charge->created_at)?->toIso8601String(),
                    'concept' => $charge->concept,
                    'period_starts_at' => $charge->period_starts_at?->toIso8601String(),
                    'period_ends_at' => $charge->period_ends_at?->toIso8601String(),
                    'amount' => $charge->amount,
                    'status' => $charge->status,
                    'status_label' => $charge->statusLabel(),
                    'method_label' => MembershipCharge::METHOD_LABELS[$charge->method] ?? $charge->method,
                    'reference' => $charge->reference,
                    'failure_reason' => $charge->failure_reason,
                ])->values()->all(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, BillingCycle>  $cycles
     * @return list<array<string, mixed>>
     */
    protected function planOptions(Company $company, $cycles): array
    {
        $current = $this->entitlements->for($company)->permissions();
        $exceptions = $company->entitlementExceptions()->notExpired(now())->get();

        return MembershipPlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('monthly_price')
            ->get()
            ->map(fn (MembershipPlan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'description' => $plan->description,
                'modules' => $this->catalogue->modules($plan->entitlements),
                // Que gana y que pierde la empresa con ese plan, con sus excepciones encima.
                'diff' => $this->catalogue->diff(
                    $current,
                    $this->entitlements->build((int) $company->id, true, $plan->entitlements, $exceptions, now())->permissions(),
                ),
                'limits' => [
                    'stores' => $plan->max_stores,
                    'users' => $plan->max_users,
                    'registers' => $plan->max_registers,
                ],
                // El precio de ESTA empresa en cada plan: con su precio especial, si lo tiene.
                'prices' => (object) MembershipPricing::priceTable($plan, $cycles, $company),
            ])->values()->all();
    }
}

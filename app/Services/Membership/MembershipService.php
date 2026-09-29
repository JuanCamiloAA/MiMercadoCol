<?php

namespace App\Services\Membership;

use App\Models\BillingCycle;
use App\Models\Company;
use App\Models\CompanyMembershipEvent;
use App\Models\MembershipCharge;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Services\PlatformSettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * El ciclo de vida de la membresia de una empresa.
 *
 *     trial ──(pago)──▶ active ──(vence sin renovar)──▶ grace ──(vence la gracia)──▶ suspended
 *       └──(vence la prueba)──▶ grace                    └──────────(pago)──────────────┴──▶ active
 *     cualquiera ──(super usuario)──▶ cancelled
 *
 * Es el unico que cambia `membership_status` y sus fechas, y cada cambio deja su renglon en la
 * bitacora. Un update suelto en otro lado se salta la bitacora y deja fechas incoherentes.
 */
class MembershipService
{
    public function __construct(protected PlatformSettingsService $platform) {}

    /**
     * La membresia con que nace una empresa. Con prueba gratis arranca en `trial`; si no, o si
     * ya se pago (contratacion en linea), `active` por los meses del ciclo.
     */
    public function start(Company $company, ?MembershipPlan $plan, ?BillingCycle $cycle, ?User $actor = null, bool $paid = false): void
    {
        $now = now();
        $trial = ! $paid && $plan !== null && (int) $plan->trial_days > 0;

        $endsAt = match (true) {
            $plan === null || $cycle === null => null,
            $trial => $now->copy()->addDays((int) $plan->trial_days),
            default => $now->copy()->addMonthsNoOverflow((int) $cycle->months),
        };

        $company->forceFill([
            'membership_plan_id' => $plan?->id,
            'billing_cycle_id' => $cycle?->id,
            'membership_status' => $trial ? Company::MEMBERSHIP_TRIAL : Company::MEMBERSHIP_ACTIVE,
            'membership_started_at' => $now,
            'membership_ends_at' => $endsAt,
            'grace_ends_at' => null,
            'next_charge_at' => $company->auto_renew ? $endsAt : null,
        ])->save();

        $this->record($company, CompanyMembershipEvent::PROVISIONED, [
            'plan' => $plan?->name,
            'cycle' => $cycle?->name,
            'status' => $company->membership_status,
            'ends_at' => $endsAt?->toIso8601String(),
        ], $actor);
    }

    /**
     * El periodo que pagaria un cobro hecho hoy: si la empresa esta al dia, empieza cuando
     * termina el actual; si venia de gracia o suspendida, empieza hoy.
     *
     * @return array{starts: Carbon, ends: Carbon, plan: ?MembershipPlan, cycle: ?BillingCycle}
     */
    public function nextPeriod(Company $company): array
    {
        $plan = $company->nextPlan ?? $company->plan;
        $cycle = $company->nextCycle ?? $company->cycle;
        $now = now();

        $upToDate = in_array($company->membership_status, [Company::MEMBERSHIP_ACTIVE, Company::MEMBERSHIP_TRIAL], true)
            && $company->membership_ends_at !== null
            && $company->membership_ends_at->isFuture();

        $starts = $upToDate ? $company->membership_ends_at->copy() : $now->copy();
        $ends = $starts->copy()->addMonthsNoOverflow(max(1, (int) ($cycle?->months ?? 1)));

        return ['starts' => $starts, 'ends' => $ends, 'plan' => $plan, 'cycle' => $cycle];
    }

    /**
     * Liquida un cobro aprobado: renueva el periodo que el cobro dice que pago.
     *
     * Idempotente por `reference`: un webhook repetido o una segunda lectura encuentran el
     * cobro ya aprobado y no renuevan dos veces. Devuelve si hubo renovacion.
     */
    public function approveCharge(MembershipCharge $charge, ?string $transactionId = null): bool
    {
        return DB::transaction(function () use ($charge, $transactionId) {
            /** @var MembershipCharge $locked */
            $locked = MembershipCharge::query()->lockForUpdate()->findOrFail($charge->id);

            if ($locked->status === MembershipCharge::STATUS_APPROVED) {
                return false;
            }

            /** @var Company $company */
            $company = Company::query()->lockForUpdate()->findOrFail($locked->company_id);
            $previous = $company->membership_status;

            // El periodo se recalcula al aprobar: si el cobro quedo pendiente dias, la empresa
            // pudo pasar a gracia en el entretanto y el periodo arranca hoy.
            $period = $this->nextPeriod($company);
            $cycle = $locked->cycle ?? $period['cycle'];
            $starts = $period['starts'];
            $ends = $starts->copy()->addMonthsNoOverflow(max(1, (int) ($cycle?->months ?? 1)));

            $locked->forceFill([
                'status' => MembershipCharge::STATUS_APPROVED,
                'gateway_transaction_id' => $transactionId ?? $locked->gateway_transaction_id,
                'paid_at' => now(),
                'period_starts_at' => $starts,
                'period_ends_at' => $ends,
                'failure_reason' => null,
            ])->save();

            $planChanged = $locked->membership_plan_id !== null && (int) $locked->membership_plan_id !== (int) $company->membership_plan_id;

            $company->forceFill([
                // El cobro dice que plan y ciclo pago: un cambio programado entra aqui.
                'membership_plan_id' => $locked->membership_plan_id ?? $company->membership_plan_id,
                'billing_cycle_id' => $locked->billing_cycle_id ?? $company->billing_cycle_id,
                'next_membership_plan_id' => null,
                'next_billing_cycle_id' => null,
                'membership_status' => Company::MEMBERSHIP_ACTIVE,
                'membership_started_at' => $company->membership_started_at ?? now(),
                'membership_ends_at' => $ends,
                'grace_ends_at' => null,
                'next_charge_at' => $company->auto_renew ? $ends : null,
            ])->save();

            $this->record($company, CompanyMembershipEvent::RENEWED, [
                'reference' => $locked->reference,
                'amount' => $locked->amount,
                'from' => $starts->toIso8601String(),
                'until' => $ends->toIso8601String(),
                'previous_status' => $previous,
                'plan_changed' => $planChanged,
            ], $locked->created_by_user_id ? User::query()->find($locked->created_by_user_id) : null);

            if (in_array($previous, [Company::MEMBERSHIP_GRACE, Company::MEMBERSHIP_SUSPENDED], true)) {
                $this->record($company, CompanyMembershipEvent::REACTIVATED, ['reference' => $locked->reference]);
            }

            return true;
        });
    }

    /**
     * Un cobro que no paso. No cambia la membresia: la gracia la mueve el calendario.
     */
    public function failCharge(MembershipCharge $charge, string $status, ?string $reason, ?string $transactionId = null): void
    {
        DB::transaction(function () use ($charge, $status, $reason, $transactionId) {
            /** @var MembershipCharge $locked */
            $locked = MembershipCharge::query()->lockForUpdate()->findOrFail($charge->id);

            // Uno aprobado no se deshace por un evento viejo que llega tarde.
            if ($locked->status === MembershipCharge::STATUS_APPROVED) {
                return;
            }

            $locked->forceFill([
                'status' => $status,
                'failure_reason' => $reason ? mb_substr($reason, 0, 250) : null,
                'gateway_transaction_id' => $transactionId ?? $locked->gateway_transaction_id,
            ])->save();

            $this->record($locked->company, CompanyMembershipEvent::CHARGE_FAILED, [
                'reference' => $locked->reference,
                'status' => $status,
                'reason' => $reason,
            ]);
        });
    }

    /**
     * La prueba o el periodo vencieron sin renovar: empieza la gracia.
     */
    public function startGrace(Company $company): void
    {
        $graceDays = max(0, (int) $this->platform->get('membership.grace_days'));
        $from = $company->membership_ends_at ?? now();

        $company->forceFill([
            'membership_status' => Company::MEMBERSHIP_GRACE,
            'grace_ends_at' => $from->copy()->addDays($graceDays),
        ])->save();

        $this->record($company, CompanyMembershipEvent::GRACE_STARTED, [
            'until' => $company->grace_ends_at?->toIso8601String(),
        ]);
    }

    public function suspend(Company $company): void
    {
        $company->forceFill(['membership_status' => Company::MEMBERSHIP_SUSPENDED])->save();

        $this->record($company, CompanyMembershipEvent::SUSPENDED, [
            'grace_ended_at' => $company->grace_ends_at?->toIso8601String(),
        ]);
    }

    /**
     * La empresa pide cambiar de plan o de ciclo: aplica en la proxima renovacion.
     */
    public function scheduleChange(Company $company, MembershipPlan $plan, BillingCycle $cycle, User $user): void
    {
        $same = (int) $plan->id === (int) $company->membership_plan_id && (int) $cycle->id === (int) $company->billing_cycle_id;

        $company->forceFill([
            'next_membership_plan_id' => $same ? null : $plan->id,
            'next_billing_cycle_id' => $same ? null : $cycle->id,
        ])->save();

        $this->record($company, CompanyMembershipEvent::CHANGE_SCHEDULED, [
            'plan' => $plan->name,
            'cycle' => $cycle->name,
            'applies_at' => $company->membership_ends_at?->toIso8601String(),
            'cancelled' => $same,
        ], $user);
    }

    /**
     * El super usuario cambia la membresia a mano desde Empresas. Siempre con motivo.
     *
     * @param  array{membership_plan_id?: ?int, billing_cycle_id?: ?int, membership_status?: string, membership_ends_at?: ?string, grace_ends_at?: ?string}  $changes
     */
    public function manualUpdate(Company $company, array $changes, string $reason, User $user): void
    {
        $before = $company->only(array_keys($changes));
        $types = [];

        if (array_key_exists('membership_plan_id', $changes) && (int) $changes['membership_plan_id'] !== (int) $company->membership_plan_id) {
            $types[] = CompanyMembershipEvent::PLAN_CHANGED;
        }

        if (array_key_exists('billing_cycle_id', $changes) && (int) $changes['billing_cycle_id'] !== (int) $company->billing_cycle_id) {
            $types[] = CompanyMembershipEvent::CYCLE_CHANGED;
        }

        $company->fill($changes);

        if ($company->isDirty('membership_ends_at') && $company->membership_ends_at?->isFuture()) {
            $types[] = CompanyMembershipEvent::MANUAL_EXTENSION;
        }

        if (! $company->isDirty()) {
            return;
        }

        // Un cambio de plan desde aqui aplica ya: se olvida el programado.
        if (in_array(CompanyMembershipEvent::PLAN_CHANGED, $types, true) || in_array(CompanyMembershipEvent::CYCLE_CHANGED, $types, true)) {
            $company->next_membership_plan_id = null;
            $company->next_billing_cycle_id = null;
        }

        if ($company->isDirty('membership_status') && $company->membership_status !== Company::MEMBERSHIP_GRACE) {
            $company->grace_ends_at = $company->membership_status === Company::MEMBERSHIP_SUSPENDED ? $company->grace_ends_at : null;
        }

        $company->next_charge_at = $company->auto_renew ? $company->membership_ends_at : null;
        $company->save();

        $after = $company->only(array_keys($changes));

        foreach ($types ?: [CompanyMembershipEvent::MANUAL_UPDATE] as $type) {
            $this->record($company, $type, ['before' => $this->serialize($before), 'after' => $this->serialize($after), 'reason' => $reason], $user);
        }
    }

    public function setAutoRenew(Company $company, bool $enabled, ?User $user): void
    {
        $company->forceFill([
            'auto_renew' => $enabled,
            'next_charge_at' => $enabled ? $company->membership_ends_at : null,
        ])->save();

        $this->record($company, CompanyMembershipEvent::AUTO_RENEW_TOGGLED, ['enabled' => $enabled], $user);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(Company $company, string $type, array $data = [], ?User $user = null): CompanyMembershipEvent
    {
        return CompanyMembershipEvent::query()->create([
            'company_id' => $company->id,
            'type' => $type,
            'data' => $data,
            'user_id' => $user?->id,
            'created_at' => now(),
        ]);
    }

    /**
     * Dias que faltan para el vencimiento; negativo si ya vencio. Null si no vence.
     */
    public static function daysLeft(Company $company): ?int
    {
        if ($company->membership_ends_at === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($company->membership_ends_at->copy()->startOfDay(), false);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function serialize(array $values): array
    {
        return array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value, $values);
    }
}

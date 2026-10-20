<?php

namespace App\Services\Membership;

use App\Models\Company;
use App\Models\CompanyEntitlement;
use App\Models\CompanyMembershipEvent;
use App\Models\CompanyPlanPrice;
use App\Models\MembershipPlan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lo que el super usuario le cambia al plan de una empresa: excepciones que suman o quitan un
 * nodo y el precio especial. Todo cambio deja su renglon en la bitacora de membresia, y nada se
 * borra: terminar algo antes de tiempo es ponerle su fecha de fin.
 *
 * Los topes (sedes, usuarios, cajas) no tienen excepcion: son solo del plan.
 */
class CompanyEntitlementService
{
    public function __construct(protected MembershipService $membership) {}

    /**
     * @param  array{node: string, effect: string, starts_at?: ?string, ends_at?: ?string, reason: string}  $data
     */
    public function addException(Company $company, array $data, User $user): CompanyEntitlement
    {
        $node = trim($data['node']);

        if (! Entitlements::isValid($node)) {
            throw ValidationException::withMessages(['node' => Entitlements::isPlatformOnly($node)
                ? 'Lo de la plataforma no se le suma ni se le quita a una empresa.'
                : 'Esa función no existe en el árbol de permisos.']);
        }

        if ($data['effect'] === CompanyEntitlement::EFFECT_REVOKE && Entitlements::isAlways($node)) {
            throw ValidationException::withMessages(['node' => 'Eso siempre va incluido: sin ello la empresa no podría entrar ni pagar.']);
        }

        [$starts, $ends] = $this->period($data);

        return DB::transaction(function () use ($company, $data, $user, $node, $starts, $ends) {
            $exception = CompanyEntitlement::query()->create([
                'company_id' => $company->id,
                'node' => $node,
                'effect' => $data['effect'],
                'starts_at' => $starts,
                'ends_at' => $ends,
                'reason' => trim($data['reason']),
                'created_by_user_id' => $user->id,
            ]);

            $this->membership->record($company, CompanyMembershipEvent::ENTITLEMENT_ADDED, [
                'node' => $node,
                'label' => Entitlements::label($node),
                'effect' => $data['effect'],
                'starts_at' => $starts->toIso8601String(),
                'ends_at' => $ends?->toIso8601String(),
                'reason' => $exception->reason,
            ], $user);

            return $exception;
        });
    }

    /** Terminar una excepcion antes de tiempo: no se borra, es historia. */
    public function endException(CompanyEntitlement $exception, User $user, string $reason): CompanyEntitlement
    {
        if ($exception->ends_at !== null && $exception->ends_at->lte(now())) {
            throw ValidationException::withMessages(['exception' => 'Esa excepción ya terminó.']);
        }

        return DB::transaction(function () use ($exception, $user, $reason) {
            // Una que todavia no empezaba termina sin haber valido nunca.
            $ends = $exception->starts_at->gt(now()) ? $exception->starts_at->copy() : now();

            $exception->forceFill(['ends_at' => $ends, 'ended_by_user_id' => $user->id, 'expiry_recorded_at' => now()])->save();

            $this->membership->record($exception->company, CompanyMembershipEvent::ENTITLEMENT_ENDED, [
                'node' => $exception->node,
                'label' => Entitlements::label($exception->node),
                'effect' => $exception->effect,
                'reason' => trim($reason),
            ], $user);

            return $exception;
        });
    }

    /**
     * @param  array{membership_plan_id: int, monthly_price?: ?int, discount_percent?: ?int, starts_at?: ?string, ends_at?: ?string, reason: string}  $data
     */
    public function setSpecialPrice(Company $company, array $data, User $user): CompanyPlanPrice
    {
        $plan = MembershipPlan::query()->findOrFail($data['membership_plan_id']);
        $monthly = isset($data['monthly_price']) && $data['monthly_price'] !== null ? (int) $data['monthly_price'] : null;
        $discount = isset($data['discount_percent']) && $data['discount_percent'] !== null ? (int) $data['discount_percent'] : null;

        if (($monthly === null) === ($discount === null)) {
            throw ValidationException::withMessages(['monthly_price' => 'Pon un precio mensual o un descuento, uno de los dos.']);
        }

        [$starts, $ends] = $this->period($data);

        return DB::transaction(function () use ($company, $plan, $monthly, $discount, $starts, $ends, $data, $user) {
            // Un precio nuevo para el mismo plan reemplaza al vigente desde que empieza el nuevo.
            CompanyPlanPrice::query()
                ->where('company_id', $company->id)
                ->where('membership_plan_id', $plan->id)
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $starts))
                ->get()
                ->each(fn (CompanyPlanPrice $previous) => $previous->forceFill([
                    'ends_at' => $previous->starts_at->gt($starts) ? $previous->starts_at : $starts,
                    'ended_by_user_id' => $user->id,
                    'expiry_recorded_at' => now(),
                ])->save());

            $price = CompanyPlanPrice::query()->create([
                'company_id' => $company->id,
                'membership_plan_id' => $plan->id,
                'monthly_price' => $monthly,
                'discount_percent' => $discount,
                'starts_at' => $starts,
                'ends_at' => $ends,
                'reason' => trim($data['reason']),
                'created_by_user_id' => $user->id,
            ]);

            $this->membership->record($company, CompanyMembershipEvent::SPECIAL_PRICE_SET, [
                'plan' => $plan->name,
                'price' => $price->describe(),
                'starts_at' => $starts->toIso8601String(),
                'ends_at' => $ends?->toIso8601String(),
                'reason' => $price->reason,
            ], $user);

            return $price;
        });
    }

    public function endSpecialPrice(CompanyPlanPrice $price, User $user, string $reason): CompanyPlanPrice
    {
        if ($price->ends_at !== null && $price->ends_at->lte(now())) {
            throw ValidationException::withMessages(['price' => 'Ese precio especial ya terminó.']);
        }

        return DB::transaction(function () use ($price, $user, $reason) {
            $ends = $price->starts_at->gt(now()) ? $price->starts_at->copy() : now();
            $price->forceFill(['ends_at' => $ends, 'ended_by_user_id' => $user->id, 'expiry_recorded_at' => now()])->save();

            $this->membership->record($price->company, CompanyMembershipEvent::SPECIAL_PRICE_ENDED, [
                'plan' => $price->plan?->name,
                'price' => $price->describe(),
                'reason' => trim($reason),
            ], $user);

            return $price;
        });
    }

    /**
     * Desde y hasta, en la hora de Colombia. Sin desde, ya; el hasta es opcional y va despues.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: Carbon, 1: ?Carbon}
     */
    protected function period(array $data): array
    {
        $starts = filled($data['starts_at'] ?? null) ? Carbon::parse($data['starts_at'], 'America/Bogota') : now();
        $ends = filled($data['ends_at'] ?? null) ? Carbon::parse($data['ends_at'], 'America/Bogota') : null;

        // Una fecha sola («hasta el 30») vale todo ese dia.
        if ($ends !== null && strlen((string) $data['ends_at']) <= 10) {
            $ends = $ends->endOfDay()->startOfSecond();
        }

        if ($starts->lt(now()->subMinute()) && strlen((string) ($data['starts_at'] ?? '')) <= 10 && filled($data['starts_at'] ?? null)) {
            // «Desde hoy» con solo la fecha: desde ya, no desde la medianoche que ya paso.
            $starts = $starts->isToday() ? now() : $starts;
        }

        if ($ends !== null && $ends->lte($starts)) {
            throw ValidationException::withMessages(['ends_at' => 'El fin tiene que ser después del comienzo.']);
        }

        if ($ends !== null && $ends->lte(now())) {
            throw ValidationException::withMessages(['ends_at' => 'El fin ya pasó.']);
        }

        return [$starts, $ends];
    }
}

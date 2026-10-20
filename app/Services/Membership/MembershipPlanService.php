<?php

namespace App\Services\Membership;

use App\Models\Company;
use App\Models\CompanyMembershipEvent;
use App\Models\MembershipPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Guardar un plan. Lo que incluye describe el producto: un cambio aplica de inmediato a todas
 * las empresas que lo tienen, y queda en la bitacora de cada una. Los datos de la empresa no se
 * tocan nunca; si el plan quita algo, se corta el acceso, no los registros.
 */
class MembershipPlanService
{
    public function __construct(
        protected MembershipService $membership,
        protected PlanCatalogue $catalogue,
    ) {}

    /**
     * Lo que pierden las empresas si el plan pasa a esos nodos: cuantas son y que se les quita.
     *
     * @param  array<int, string>  $nodes
     * @return array{companies: int, losses: list<array{module: string, items: list<string>}>, lost: int}
     */
    public function removalImpact(MembershipPlan $plan, array $nodes): array
    {
        $diff = $this->diff($plan, $nodes);

        return [
            'companies' => $diff['lost'] > 0 ? $plan->companies()->count() : 0,
            'losses' => $diff['losses'],
            'lost' => $diff['lost'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  ya validado; `entitlements` normalizado
     */
    public function update(MembershipPlan $plan, array $data, User $user, bool $confirmed): MembershipPlan
    {
        $diff = $this->diff($plan, $data['entitlements']);
        $affected = $plan->companies()->count();

        if ($diff['lost'] > 0 && $affected > 0 && ! $confirmed) {
            throw ValidationException::withMessages([
                'entitlements' => "Este cambio le quita {$diff['lost']} ".($diff['lost'] === 1 ? 'función' : 'funciones')
                    ." a {$affected} ".($affected === 1 ? 'empresa' : 'empresas').'. Confírmalo para guardar.',
            ]);
        }

        return DB::transaction(function () use ($plan, $data, $user, $diff) {
            $plan->update($data);

            if ($diff['gained'] === 0 && $diff['lost'] === 0) {
                return $plan;
            }

            Company::query()->where('membership_plan_id', $plan->id)->get()->each(
                fn (Company $company) => $this->membership->record($company, CompanyMembershipEvent::PLAN_ENTITLEMENTS_CHANGED, [
                    'plan' => $plan->name,
                    'gains' => $diff['gains'],
                    'losses' => $diff['losses'],
                    'reason' => $diff['lost'] > 0 ? 'El plan dejó de incluir '.$diff['lost'].' '.($diff['lost'] === 1 ? 'función' : 'funciones').'.' : 'El plan incluye funciones nuevas.',
                ], $user)
            );

            return $plan;
        });
    }

    /**
     * @param  array<int, string>  $nodes
     * @return array{gains: list<array{module: string, items: list<string>}>, losses: list<array{module: string, items: list<string>}>, gained: int, lost: int}
     */
    protected function diff(MembershipPlan $plan, array $nodes): array
    {
        // Lo que siempre va no se gana ni se pierde.
        $always = Entitlements::alwaysPermissions();

        return $this->catalogue->diff(
            array_values(array_unique([...Entitlements::expand($plan->entitlements ?? [], warn: false), ...$always])),
            array_values(array_unique([...Entitlements::expand($nodes, warn: false), ...$always])),
        );
    }
}

<?php

namespace App\Services\Membership;

use App\Helpers\PermissionHelper;
use App\Models\Company;
use App\Models\CompanyEntitlement;
use App\Models\MembershipPlan;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * La unica respuesta a «¿la empresa puede?».
 *
 * El calculo va siempre en este orden:
 *
 *     1. los nodos del plan (`membership_plans.entitlements`), expandidos contra el arbol de hoy;
 *     2. mas las excepciones `grant` vigentes;
 *     3. menos las excepciones `revoke` vigentes: quitar gana a sumar;
 *     4. mas `Entitlements::ALWAYS`, y fuera `Entitlements::PLATFORM_ONLY`.
 *
 * Una excepcion vale si `starts_at <= ahora` y (`ends_at` es nulo o `ends_at > ahora`). Se
 * resuelve al leer, en la hora de Colombia: si el comando del dia no corre, igual deja de
 * valer a tiempo. Una empresa sin plan (de antes de las membresias) tiene todo.
 *
 * Se memoriza por peticion, y el resultado sabe hasta cuando vale: si en la misma peticion una
 * excepcion empieza o vence, se vuelve a calcular.
 */
class EntitlementService
{
    protected const MEMO_KEY = 'membership.entitlements';

    public function for(Company $company): Entitlement
    {
        $now = now();
        $memo = $this->memo();
        $cached = $memo[$company->id] ?? null;

        if ($cached instanceof Entitlement && $cached->isFreshAt($now)) {
            return $cached;
        }

        $plan = $company->membership_plan_id !== null
            ? ($company->relationLoaded('plan') && $company->plan?->id === (int) $company->membership_plan_id
                ? $company->plan
                : MembershipPlan::query()->find($company->membership_plan_id))
            : null;

        $exceptions = CompanyEntitlement::query()
            ->where('company_id', $company->id)
            ->notExpired($now)
            ->get();

        $entitlement = $this->build((int) $company->id, $company->membership_plan_id !== null, $plan?->entitlements, $exceptions, $now);

        $memo[$company->id] = $entitlement;
        $this->remember($memo);

        return $entitlement;
    }

    /**
     * Lo que se le dice a quien tiene el permiso pero no el plan: con la etiqueta del permiso y
     * donde se cambia.
     */
    public static function deniedMessage(string $permission): string
    {
        $parts = explode('.', $permission);
        $label = count($parts) === 3 ? PermissionHelper::actionLabel($parts[0], $parts[1], $parts[2]) : Entitlements::label($permission);

        return "Tu plan no incluye «{$label}». Cámbialo en Ajustes > Membresía.";
    }

    public function forCompanyId(?int $companyId): ?Entitlement
    {
        if ($companyId === null) {
            return null;
        }

        $cached = $this->memo()[$companyId] ?? null;

        if ($cached instanceof Entitlement && $cached->isFreshAt(now())) {
            return $cached;
        }

        $company = Company::query()->find($companyId);

        return $company ? $this->for($company) : null;
    }

    /**
     * Arma el calculo con los datos ya leidos. Publico para poder probar el orden sin base.
     *
     * @param  array<int, mixed>|null  $planNodes
     * @param  iterable<CompanyEntitlement>  $exceptions
     */
    public function build(int $companyId, bool $hasPlan, ?array $planNodes, iterable $exceptions, CarbonInterface $now): Entitlement
    {
        $all = Entitlements::all();
        $permissions = [];

        // 1. El plan. Sin plan, todo.
        $fromPlan = $hasPlan ? array_flip(Entitlements::expand($planNodes ?? [])) : array_flip($all);

        foreach ($all as $permission) {
            $permissions[$permission] = isset($fromPlan[$permission])
                ? ['source' => $hasPlan ? Entitlement::SOURCE_PLAN : Entitlement::SOURCE_NO_PLAN, 'ends_at' => null]
                : ['source' => Entitlement::SOURCE_NONE, 'ends_at' => null];
        }

        $validUntil = null;
        $grants = [];
        $revokes = [];

        foreach ($exceptions as $exception) {
            // Lo que todavia no empieza o ya vencio no cuenta, pero dice cuando hay que volver a mirar.
            foreach ([$exception->starts_at, $exception->ends_at] as $boundary) {
                if ($boundary !== null && $boundary->gt($now) && ($validUntil === null || $boundary->lt($validUntil))) {
                    $validUntil = $boundary;
                }
            }

            if (! $exception->isActiveAt($now) || Entitlements::isPlatformOnly($exception->node)) {
                continue;
            }

            if ($exception->effect === CompanyEntitlement::EFFECT_REVOKE) {
                $revokes[] = $exception;
            } else {
                $grants[] = $exception;
            }
        }

        // 2. Lo que suma una excepcion. Si el plan ya lo traia, el origen sigue siendo el plan.
        foreach ($grants as $grant) {
            foreach (Entitlements::expand([$grant->node]) as $permission) {
                if ($permissions[$permission]['source'] === Entitlement::SOURCE_NONE
                    || $permissions[$permission]['source'] === Entitlement::SOURCE_GRANT) {
                    $permissions[$permission] = [
                        'source' => Entitlement::SOURCE_GRANT,
                        'ends_at' => $this->later($permissions[$permission]['ends_at'], $grant->ends_at, $permissions[$permission]['source'] === Entitlement::SOURCE_GRANT),
                    ];
                }
            }
        }

        // 3. Lo que quita una excepcion: gana a cualquier suma.
        foreach ($revokes as $revoke) {
            foreach (Entitlements::expand([$revoke->node]) as $permission) {
                $permissions[$permission] = ['source' => Entitlement::SOURCE_REVOKE, 'ends_at' => $revoke->ends_at];
            }
        }

        // 4. Lo que siempre va. Lo de la plataforma ni siquiera esta en el arbol del plan.
        foreach (Entitlements::alwaysPermissions() as $permission) {
            $permissions[$permission] = ['source' => Entitlement::SOURCE_ALWAYS, 'ends_at' => null];
        }

        return new Entitlement($companyId, $hasPlan, $permissions, $now->copy(), $validUntil?->copy());
    }

    /**
     * Las empresas que tienen algo de un nodo, sin calcular empresa por empresa lo que no hace
     * falta: las sin plan entran todas; las demas, por su plan y sus excepciones.
     *
     * @return Collection<int, int>
     */
    public function companiesAllowing(string $node): Collection
    {
        $now = now();
        $plans = MembershipPlan::query()->get(['id', 'entitlements'])->keyBy('id');
        $exceptions = CompanyEntitlement::query()->notExpired($now)->get()->groupBy('company_id');

        return Company::query()
            ->get(['id', 'membership_plan_id'])
            ->filter(function (Company $company) use ($node, $now, $plans, $exceptions) {
                $plan = $company->membership_plan_id !== null ? $plans->get($company->membership_plan_id) : null;

                return $this->build(
                    (int) $company->id,
                    $company->membership_plan_id !== null,
                    $plan?->entitlements,
                    $exceptions->get($company->id, collect()),
                    $now,
                )->allowsNode($node);
            })
            ->pluck('id')
            ->values();
    }

    /**
     * Olvida lo calculado (de una empresa o de todas) despues de cambiar un plan o una excepcion.
     */
    public function forget(?int $companyId = null): void
    {
        if ($companyId === null) {
            $this->remember([]);

            return;
        }

        $memo = $this->memo();
        unset($memo[$companyId]);
        $this->remember($memo);
    }

    /**
     * @return array<int, Entitlement>
     */
    protected function memo(): array
    {
        $memo = request()->attributes->get(self::MEMO_KEY);

        return is_array($memo) ? $memo : [];
    }

    /**
     * @param  array<int, Entitlement>  $memo
     */
    protected function remember(array $memo): void
    {
        request()->attributes->set(self::MEMO_KEY, $memo);
    }

    /** Entre dos vencimientos de suma, el que llegue mas tarde; nulo es «sin vencimiento». */
    protected function later(?CarbonInterface $current, ?CarbonInterface $candidate, bool $hadGrant): ?CarbonInterface
    {
        if (! $hadGrant) {
            return $candidate;
        }

        if ($current === null || $candidate === null) {
            return null;
        }

        return $candidate->gt($current) ? $candidate : $current;
    }
}

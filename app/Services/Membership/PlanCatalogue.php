<?php

namespace App\Services\Membership;

use App\Helpers\PermissionHelper;
use App\Models\MembershipPlan;

/**
 * Como se cuenta un plan a quien lo mira: sus modulos, sus destacados, la tabla comparativa y
 * que se gana o se pierde al cambiar de uno a otro. Todo sale de los nodos del plan, nunca de
 * una lista escrita en el `.tsx`.
 */
class PlanCatalogue
{
    /**
     * Los modulos que toca el plan, con cuanto de cada uno trae.
     *
     * @param  array<int, mixed>|null  $nodes
     * @return list<array{key: string, display: string, state: string}>
     */
    public function modules(?array $nodes): array
    {
        $included = array_flip(array_merge(Entitlements::expand($nodes ?? [], warn: false), Entitlements::alwaysPermissions()));
        $modules = [];

        foreach ($this->orderedMatrix() as $key => $config) {
            $state = $this->state(Entitlements::permissionsFor("{$key}.*"), $included);

            if ($state !== 'none') {
                $modules[] = ['key' => $key, 'display' => $config['display'], 'state' => $state];
            }
        }

        return $modules;
    }

    /**
     * Los destacados de un plan: los que escribio el super usuario o, si no hay, los modulos
     * que trae completos.
     *
     * @return list<string>
     */
    public function highlights(MembershipPlan $plan): array
    {
        $written = array_values(array_filter((array) ($plan->highlights ?? []), fn ($line) => is_string($line) && trim($line) !== ''));

        if ($written !== []) {
            return array_slice($written, 0, MembershipPlan::MAX_HIGHLIGHTS);
        }

        return $this->generatedHighlights($plan->entitlements);
    }

    /**
     * Destacados armados a partir de los modulos: lo que el boton «Generar» propone.
     *
     * @param  array<int, mixed>|null  $nodes
     * @return list<string>
     */
    public function generatedHighlights(?array $nodes): array
    {
        $modules = array_values(array_filter($this->modules($nodes), fn (array $module) => $module['state'] === 'all' && ! in_array($module['key'], ['dashboard', 'settings'], true)));

        // Primero lo que no traen todos los planes: es lo que distingue a este. Despues lo comun.
        $common = $this->commonModules();
        usort($modules, fn (array $a, array $b) => (int) in_array($a['key'], $common, true) <=> (int) in_array($b['key'], $common, true));

        return array_slice(array_map(fn (array $module) => $module['display'], $modules), 0, MembershipPlan::MAX_HIGHLIGHTS);
    }

    /**
     * Los modulos que traen completos todos los planes activos.
     *
     * @return list<string>
     */
    protected function commonModules(): array
    {
        $common = null;

        foreach (MembershipPlan::query()->active()->get(['id', 'entitlements']) as $plan) {
            $keys = array_column(array_filter($this->modules($plan->entitlements), fn (array $module) => $module['state'] === 'all'), 'key');
            $common = $common === null ? $keys : array_values(array_intersect($common, $keys));
        }

        return $common ?? [];
    }

    /**
     * La tabla comparativa de la landing: un renglon por modulo y, por plan, si lo trae todo,
     * en parte o nada.
     *
     * @param  iterable<MembershipPlan>  $plans
     * @return list<array{key: string, display: string, plans: array<string, string>}>
     */
    public function comparison(iterable $plans): array
    {
        $included = [];

        foreach ($plans as $plan) {
            $included[$plan->id] = array_flip(array_merge(Entitlements::expand($plan->entitlements ?? [], warn: false), Entitlements::alwaysPermissions()));
        }

        $rows = [];

        foreach ($this->orderedMatrix() as $key => $config) {
            $permissions = Entitlements::permissionsFor("{$key}.*");
            $states = [];

            foreach ($included as $planId => $set) {
                $states[(string) $planId] = $this->state($permissions, $set);
            }

            if (array_filter($states, fn (string $state) => $state !== 'none') !== []) {
                $rows[] = ['key' => $key, 'display' => $config['display'], 'plans' => $states];
            }
        }

        return $rows;
    }

    /**
     * Lo que se gana y lo que se pierde al pasar de un conjunto de permisos a otro, agrupado por
     * modulo y con las etiquetas que lee una persona.
     *
     * @param  array<int, string>  $from
     * @param  array<int, string>  $to
     * @return array{gains: list<array{module: string, items: list<string>}>, losses: list<array{module: string, items: list<string>}>, gained: int, lost: int}
     */
    public function diff(array $from, array $to): array
    {
        $gained = array_values(array_diff($to, $from));
        $lost = array_values(array_diff($from, $to));

        return [
            'gains' => $this->group($gained),
            'losses' => $this->group($lost),
            'gained' => count($gained),
            'lost' => count($lost),
        ];
    }

    /**
     * El arbol del plan para el editor, con el mismo formato que el asignador de Roles.
     *
     * @return list<array<string, mixed>>
     */
    public function editorCatalogue(): array
    {
        return PermissionHelper::catalogue(only: Entitlements::all());
    }

    /**
     * @param  list<string>  $permissions
     * @return list<array{module: string, items: list<string>}>
     */
    protected function group(array $permissions): array
    {
        $groups = [];

        foreach ($this->orderedMatrix() as $module => $config) {
            $items = [];

            foreach ($permissions as $permission) {
                [$permModule, $page, $action] = array_pad(explode('.', $permission), 3, '');

                if ($permModule === $module) {
                    $pageLabel = $config['pages'][$page]['display'] ?? $page;
                    $items[] = $pageLabel.': '.PermissionHelper::actionLabel($module, $page, $action);
                }
            }

            if ($items !== []) {
                $groups[] = ['module' => $config['display'], 'items' => $items];
            }
        }

        return $groups;
    }

    /**
     * @param  list<string>  $permissions
     * @param  array<string, int>  $included
     */
    protected function state(array $permissions, array $included): string
    {
        $count = count(array_filter($permissions, fn (string $permission) => isset($included[$permission])));

        return match (true) {
            $count === 0 => 'none',
            $count === count($permissions) => 'all',
            default => 'some',
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function orderedMatrix(): array
    {
        $matrix = Entitlements::matrix();
        uasort($matrix, fn (array $a, array $b) => ($a['order'] ?? 999) <=> ($b['order'] ?? 999));

        return $matrix;
    }
}

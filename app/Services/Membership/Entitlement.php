<?php

namespace App\Services\Membership;

use App\Helpers\PermissionHelper;
use Carbon\CarbonInterface;

/**
 * Lo que una empresa tiene, ya calculado. Lo arma `EntitlementService::for()`.
 *
 * Responde por permisos concretos (`allows`), por modulos o paginas (`allowsNode`) y dice de
 * donde sale cada cosa (`explain`), para que las pantallas pinten «Del plan», «Agregado hasta…»,
 * «Quitado» o «Siempre incluido».
 */
final class Entitlement
{
    public const SOURCE_PLAN = 'plan';

    /** Empresa sin plan (de antes de las membresias): tiene todo. */
    public const SOURCE_NO_PLAN = 'no_plan';

    public const SOURCE_GRANT = 'grant';

    public const SOURCE_REVOKE = 'revoke';

    public const SOURCE_ALWAYS = 'always';

    public const SOURCE_NONE = 'none';

    /**
     * @param  array<string, array{source: string, ends_at: ?CarbonInterface}>  $permissions  permiso => origen, de todo el arbol
     */
    public function __construct(
        public readonly int $companyId,
        public readonly bool $hasPlan,
        protected array $permissions,
        public readonly CarbonInterface $computedAt,
        public readonly ?CarbonInterface $validUntil,
    ) {}

    public function allows(string $permission): bool
    {
        $source = $this->permissions[$permission]['source'] ?? null;

        return $source !== null && ! in_array($source, [self::SOURCE_NONE, self::SOURCE_REVOKE], true);
    }

    /**
     * Para preguntas de modulo o pagina («¿tiene `storefront.*`?»): basta con que tenga algo de
     * ese nodo. Para una accion, es lo mismo que `allows`.
     */
    public function allowsNode(string $node): bool
    {
        if (! Entitlements::isWildcard($node)) {
            return $this->allows($node);
        }

        foreach (array_keys($this->permissions) as $permission) {
            if (Entitlements::covers($node, $permission) && $this->allows($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deja solo lo permitido.
     *
     * @param  array<int, string>  $permissionNames
     * @return list<string>
     */
    public function filter(array $permissionNames): array
    {
        return array_values(array_filter($permissionNames, fn (string $name) => $this->allows($name)));
    }

    /**
     * Todos los permisos que tiene la empresa.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return array_values(array_filter(array_keys($this->permissions), fn (string $name) => $this->allows($name)));
    }

    /**
     * De donde sale un nodo.
     *
     * Para una accion: si la tiene, el origen y, si es una excepcion, hasta cuando. Para un
     * modulo o una pagina: cuantas de sus acciones tiene y de que origenes salen.
     *
     * @return array{node: string, allowed: bool, state: string, source: string, sources: list<string>, ends_at: ?string, count: int, total: int}
     */
    public function explain(string $node): array
    {
        $covered = array_filter(
            $this->permissions,
            fn (string $permission) => Entitlements::covers($node, $permission),
            ARRAY_FILTER_USE_KEY,
        );

        $total = count($covered);
        $allowed = array_filter($covered, fn (array $info, string $permission) => $this->allows($permission), ARRAY_FILTER_USE_BOTH);
        $count = count($allowed);
        $sources = array_values(array_unique(array_map(fn (array $info) => $info['source'], $covered)));

        $endsAt = null;

        foreach ($covered as $info) {
            if ($info['ends_at'] !== null && ($endsAt === null || $info['ends_at']->lt($endsAt))) {
                $endsAt = $info['ends_at'];
            }
        }

        $source = match (true) {
            $total === 0 => self::SOURCE_NONE,
            count($sources) === 1 => $sources[0],
            in_array(self::SOURCE_REVOKE, $sources, true) => self::SOURCE_REVOKE,
            in_array(self::SOURCE_GRANT, $sources, true) => self::SOURCE_GRANT,
            in_array(self::SOURCE_PLAN, $sources, true) => self::SOURCE_PLAN,
            default => $sources[0],
        };

        return [
            'node' => $node,
            'allowed' => $count > 0,
            'state' => $count === 0 ? 'none' : ($count === $total ? 'all' : 'some'),
            'source' => $source,
            'sources' => $sources,
            'ends_at' => $endsAt?->toIso8601String(),
            'count' => $count,
            'total' => $total,
        ];
    }

    /**
     * El arbol completo con el origen de cada accion, listo para pintar en solo lectura.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(): array
    {
        $modules = [];

        foreach (Entitlements::matrix() as $moduleKey => $config) {
            $pages = [];

            foreach ($config['pages'] as $pageKey => $pageConfig) {
                $actions = [];

                foreach ($pageConfig['actions'] as $action) {
                    $name = "{$moduleKey}.{$pageKey}.{$action}";
                    $info = $this->permissions[$name] ?? ['source' => self::SOURCE_NONE, 'ends_at' => null];

                    $actions[] = [
                        'name' => $name,
                        'label' => PermissionHelper::actionLabel($moduleKey, $pageKey, $action),
                        'allowed' => $this->allows($name),
                        'source' => $info['source'],
                        'ends_at' => $info['ends_at']?->toIso8601String(),
                    ];
                }

                $pages[] = [
                    'key' => $pageKey,
                    'node' => "{$moduleKey}.{$pageKey}.*",
                    'display' => $pageConfig['display'] ?? $pageKey,
                    'actions' => $actions,
                ];
            }

            $modules[] = [
                'key' => $moduleKey,
                'node' => "{$moduleKey}.*",
                'display' => $config['display'],
                'order' => $config['order'] ?? 999,
                'pages' => $pages,
            ];
        }

        usort($modules, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return $modules;
    }

    /** Si este calculo sigue valiendo en ese instante: ninguna excepcion empezo ni vencio. */
    public function isFreshAt(CarbonInterface $moment): bool
    {
        if ($moment->lt($this->computedAt)) {
            return false;
        }

        return $this->validUntil === null || $moment->lt($this->validUntil);
    }
}

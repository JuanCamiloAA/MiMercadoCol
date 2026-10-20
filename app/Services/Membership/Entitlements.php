<?php

namespace App\Services\Membership;

use App\Helpers\PermissionHelper;
use Illuminate\Support\Facades\Log;

/**
 * Los nodos del arbol de permisos con que se describe lo que incluye un plan.
 *
 * Un nodo tiene la misma forma que un permiso:
 *
 *     inventory.*                  todo el modulo, tambien lo que se le agregue despues
 *     inventory.transfers.*        toda la pagina, tambien las acciones que se le agreguen
 *     inventory.transfers.direct   una sola accion
 *
 * Clase pura: no consulta la base. Quien sabe que tiene una empresa es `EntitlementService`.
 */
final class Entitlements
{
    /** Lo de la plataforma: nunca forma parte de un plan ni de una excepcion. */
    public const PLATFORM_ONLY = ['platform.*', 'companies.*'];

    /**
     * Lo que una empresa necesita para no quedar encerrada y poder pagar. No se quita ni en el
     * plan ni por excepcion; es el mismo espiritu que `NavigationOverrides::LOCKED`.
     */
    public const ALWAYS = ['dashboard.index.view', 'settings.index.view', 'settings.membership.*'];

    /**
     * Lo que no estaba detras de ninguna funcion cuando los planes se describian con cuatro
     * interruptores. Queda congelado aqui y no se calcula del arbol: si se calculara, un modulo
     * nuevo entraria solo a todos los planes al correr la conversion en una base vacia.
     */
    public const LEGACY_BASE = [
        'dashboard.*',
        'pos.*',
        'sales.*',
        'registers.*',
        'products.index.*',
        'products.show.*',
        'products.attributes.*',
        'categories.*',
        'suppliers.*',
        'inventory.*',
        'services.*',
        'purchases.*',
        'customers.*',
        'credit.*',
        'orders.*',
        'employees.*',
        'users.*',
        'roles.*',
        'reports.*',
        'settings.*',
        'payment_terms.*',
        'stores.index.*',
        'stores.warehouses.view',
        'stores.warehouses.edit',
        'stores.warehouses.deactivate',
    ];

    /** Cada funcion vieja y los nodos que incluia, para convertir los planes que ya existian. */
    public const LEGACY_FEATURES = [
        MembershipFeatures::ONLINE_STORE => ['storefront.*'],
        MembershipFeatures::PROMOTIONS => ['promotions.*', 'coupons.*'],
        MembershipFeatures::MULTI_WAREHOUSE => ['stores.warehouses.create'],
        MembershipFeatures::CATALOG_IMPORT => ['products.catalog_import.*'],
    ];

    /**
     * El nodo por el que se pregunta cada funcion vieja (`Company::hasFeature`). Los cupones van
     * aparte: `CouponService` pregunta por `coupons.*`.
     */
    public const FEATURE_NODES = [
        MembershipFeatures::ONLINE_STORE => 'storefront.*',
        MembershipFeatures::PROMOTIONS => 'promotions.*',
        MembershipFeatures::MULTI_WAREHOUSE => 'stores.warehouses.create',
        MembershipFeatures::CATALOG_IMPORT => 'products.catalog_import.*',
    ];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $defaultMatrix = null;

    /** @var list<string>|null */
    private static ?array $defaultAll = null;

    /** @var array<string, list<string>|null> */
    private static array $expansions = [];

    /**
     * El arbol que puede entrar en un plan: el catalogo sin los modulos de la plataforma.
     *
     * @param  array<string, array<string, mixed>>|null  $matrix
     * @return array<string, array<string, mixed>>
     */
    public static function matrix(?array $matrix = null): array
    {
        // El catalogo es codigo: el de la aplicacion se arma una vez por proceso.
        if ($matrix === null) {
            return self::$defaultMatrix ??= self::matrix(PermissionHelper::getPermissionMatrix());
        }

        return array_filter(
            $matrix,
            fn (string $module) => ! self::isPlatformOnly($module.'.*'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public static function isWildcard(string $node): bool
    {
        return str_ends_with($node, '.*');
    }

    /** Si el nodo cae en lo que es solo de la plataforma. */
    public static function isPlatformOnly(string $node): bool
    {
        $module = explode('.', $node)[0] ?? '';

        foreach (self::PLATFORM_ONLY as $platform) {
            if ($module === explode('.', $platform)[0]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Si el nodo corresponde a algo del arbol de hoy: un modulo, una pagina o una accion.
     *
     * @param  array<string, array<string, mixed>>|null  $matrix
     */
    public static function isValid(string $node, ?array $matrix = null): bool
    {
        if (self::isPlatformOnly($node)) {
            return false;
        }

        $matrix = self::matrix($matrix);
        $parts = explode('.', $node);

        return match (true) {
            count($parts) === 2 && $parts[1] === '*' => isset($matrix[$parts[0]]),
            count($parts) === 3 && $parts[2] === '*' => isset($matrix[$parts[0]]['pages'][$parts[1]]),
            count($parts) === 3 => in_array($parts[2], $matrix[$parts[0]]['pages'][$parts[1]]['actions'] ?? [], true),
            default => false,
        };
    }

    /** Si el nodo cubre el permiso: el comodin por prefijo, la accion por igualdad. */
    public static function covers(string $node, string $permission): bool
    {
        if (self::isWildcard($node)) {
            return str_starts_with($permission, substr($node, 0, -1));
        }

        return $node === $permission;
    }

    /**
     * Los permisos de hoy que cubre un nodo.
     *
     * @param  array<string, array<string, mixed>>|null  $matrix
     * @return list<string>
     */
    public static function permissionsFor(string $node, ?array $matrix = null): array
    {
        return self::coveredBy($node, $matrix) ?? [];
    }

    /**
     * Todos los permisos que pueden entrar en un plan.
     *
     * @param  array<string, array<string, mixed>>|null  $matrix
     * @return list<string>
     */
    public static function all(?array $matrix = null): array
    {
        if ($matrix === null && self::$defaultAll !== null) {
            return self::$defaultAll;
        }

        $permissions = [];

        foreach (self::matrix($matrix) as $module => $config) {
            foreach ($config['pages'] as $page => $pageConfig) {
                foreach ($pageConfig['actions'] as $action) {
                    $permissions[] = "{$module}.{$page}.{$action}";
                }
            }
        }

        if ($matrix === null) {
            self::$defaultAll = $permissions;
        }

        return $permissions;
    }

    /**
     * Los permisos de hoy que cubre una lista de nodos. Un nodo que no corresponde a nada se
     * ignora y deja un aviso en el log: un plan viejo con una pagina que ya no existe no puede
     * tumbar el inicio de sesion de nadie.
     *
     * @param  array<int, mixed>  $nodes
     * @param  array<string, array<string, mixed>>|null  $matrix
     * @return list<string>
     */
    public static function expand(array $nodes, ?array $matrix = null, bool $warn = true): array
    {
        $result = [];

        foreach ($nodes as $node) {
            $covered = is_string($node) ? self::coveredBy($node, $matrix) : null;

            if ($covered === null) {
                if ($warn) {
                    Log::warning('Planes: nodo que no corresponde al arbol de permisos; se ignora.', ['node' => $node]);
                }

                continue;
            }

            foreach ($covered as $permission) {
                $result[$permission] = true;
            }
        }

        return array_keys($result);
    }

    /**
     * Lo que cubre un nodo, o null si no es valido. Con el arbol de la aplicacion se recuerda por
     * proceso: el catalogo es codigo y no cambia mientras corre.
     *
     * @param  array<string, array<string, mixed>>|null  $matrix
     * @return list<string>|null
     */
    protected static function coveredBy(string $node, ?array $matrix): ?array
    {
        if ($matrix === null && array_key_exists($node, self::$expansions)) {
            return self::$expansions[$node];
        }

        $covered = self::isValid($node, $matrix)
            ? array_values(array_filter(self::all($matrix), fn (string $permission) => self::covers($node, $permission)))
            : null;

        if ($matrix === null) {
            self::$expansions[$node] = $covered;
        }

        return $covered;
    }

    /**
     * La lista mas corta de nodos para unos permisos: el modulo completo se guarda como
     * `modulo.*`, la pagina completa como `modulo.pagina.*` y lo demas accion por accion.
     *
     * @param  array<int, string>  $permissions
     * @param  array<string, array<string, mixed>>|null  $matrix
     * @return list<string>
     */
    public static function compress(array $permissions, ?array $matrix = null): array
    {
        $selected = array_flip($permissions);
        $nodes = [];

        foreach (self::matrix($matrix) as $module => $config) {
            $pageNodes = [];
            $complete = true;

            foreach ($config['pages'] as $page => $pageConfig) {
                $actions = array_map(fn (string $action) => "{$module}.{$page}.{$action}", $pageConfig['actions']);
                $chosen = array_values(array_filter($actions, fn (string $permission) => isset($selected[$permission])));

                if ($chosen === []) {
                    $complete = false;

                    continue;
                }

                if (count($chosen) === count($actions)) {
                    $pageNodes[] = "{$module}.{$page}.*";

                    continue;
                }

                $complete = false;
                array_push($pageNodes, ...$chosen);
            }

            if ($complete && $pageNodes !== []) {
                $nodes[] = "{$module}.*";
            } else {
                array_push($nodes, ...$pageNodes);
            }
        }

        return $nodes;
    }

    /**
     * Expande y vuelve a comprimir: deja los nodos de un plan limpios y sin repetir.
     *
     * @param  array<int, mixed>  $nodes
     * @return list<string>
     */
    public static function normalize(array $nodes): array
    {
        return self::compress(self::expand($nodes, warn: false));
    }

    /**
     * Los nodos de un plan descrito con las funciones viejas.
     *
     * @param  array<int, mixed>|null  $features
     * @return list<string>
     */
    public static function fromLegacyFeatures(?array $features): array
    {
        $nodes = self::LEGACY_BASE;

        foreach (MembershipFeatures::sanitize($features) as $feature) {
            array_push($nodes, ...self::LEGACY_FEATURES[$feature]);
        }

        return array_values(array_unique($nodes));
    }

    /**
     * El punto de partida de los planes que trae la plataforma. El super usuario lo cambia
     * despues en el editor del plan; esto solo dice con que nacen.
     *
     * @return list<string>
     */
    public static function preset(string $slug): array
    {
        // Los medios manuales van en todos; las pasarelas, datafonos y transacciones, en los que
        // venden en linea.
        $nodes = match ($slug) {
            'pro' => [...self::fromLegacyFeatures([MembershipFeatures::ONLINE_STORE, MembershipFeatures::PROMOTIONS]), 'payment_methods.*'],
            'cadena' => [...self::fromLegacyFeatures(array_keys(MembershipFeatures::CATALOGUE)), 'payment_methods.*'],
            default => [...self::fromLegacyFeatures([]), 'payment_methods.index.*'],
        };

        return self::compress(self::expand($nodes, warn: false));
    }

    /**
     * Los permisos que siempre tiene una empresa.
     *
     * @return list<string>
     */
    public static function alwaysPermissions(): array
    {
        return self::expand(self::ALWAYS, warn: false);
    }

    /** Si el nodo queda entero dentro de lo que siempre se incluye: no se puede quitar. */
    public static function isAlways(string $node): bool
    {
        $permissions = self::expand([$node], warn: false);

        if ($permissions === []) {
            return false;
        }

        $always = array_flip(self::alwaysPermissions());

        foreach ($permissions as $permission) {
            if (! isset($always[$permission])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Como se lee un nodo: «Inventario», «Inventario › Traslados» o
     * «Inventario › Traslados › Trasladar directo».
     */
    public static function label(string $node): string
    {
        $parts = explode('.', $node);
        $matrix = PermissionHelper::getPermissionMatrix();
        $module = $matrix[$parts[0]] ?? null;

        if ($module === null) {
            return $node;
        }

        $labels = [$module['display']];
        $page = $module['pages'][$parts[1] ?? ''] ?? null;

        if ($page !== null) {
            $labels[] = $page['display'] ?? $parts[1];

            if (isset($parts[2]) && $parts[2] !== '*') {
                $labels[] = PermissionHelper::actionLabel($parts[0], $parts[1], $parts[2]);
            }
        }

        return implode(' › ', $labels);
    }
}

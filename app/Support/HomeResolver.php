<?php

namespace App\Support;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Route;

/**
 * A donde entra cada quien despues de ingresar.
 *
 * La empresa elige en Ajustes > Menu una pagina de inicio por rol (el cajero directo a la caja,
 * bodega a existencias). Configurar no da permisos: si el rol no puede abrir la pagina elegida,
 * se cae a la primera de la lista que si pueda, y si no hay ninguna, al panel de inicio.
 */
final class HomeResolver
{
    /** Paginas que se pueden elegir como inicio, con el permiso que exigen. */
    public const PAGES = [
        'dashboard' => 'dashboard.index.view',
        'pos.index' => 'pos.sale.view',
        'orders.index' => 'orders.index.view',
        'inventory.index' => 'inventory.index.view',
        'reports.index' => 'reports.sales.view',
    ];

    /** Como se lee cada pagina en el selector. */
    public const LABELS = [
        'dashboard' => 'Panel de inicio',
        'pos.index' => 'Punto de venta',
        'orders.index' => 'Pedidos en línea',
        'inventory.index' => 'Existencias',
        'reports.index' => 'Informes',
    ];

    public const DEFAULT = 'dashboard';

    /**
     * El nombre de la ruta de inicio de esta persona.
     */
    public static function routeFor(User $user): string
    {
        // El super usuario entra al inicio: en consolidado es el resumen de la plataforma.
        if ($user->isSuperAdmin()) {
            return self::DEFAULT;
        }

        $companyId = TenantContext::activeCompanyId($user);
        $configured = self::DEFAULT;

        if ($companyId !== null) {
            $byRole = app(SettingsService::class)->get($companyId, 'panel.home_by_role');
            $role = $user->primaryRole()?->name;

            if (is_array($byRole) && $role !== null && isset($byRole[$role], self::PAGES[$byRole[$role]])) {
                $configured = $byRole[$role];
            }
        }

        // Rol primario → ruta configurada → si no tiene permiso, la primera accesible → inicio.
        $candidates = array_unique(array_merge([$configured], array_keys(self::PAGES)));

        foreach ($candidates as $routeName) {
            if (Route::has($routeName) && $user->can(self::PAGES[$routeName])) {
                return $routeName;
            }
        }

        return self::DEFAULT;
    }

    /**
     * La direccion de inicio de esta persona.
     */
    public static function for(User $user): string
    {
        return route(self::routeFor($user));
    }

    /**
     * Las paginas para el selector de Ajustes, solo las que existen.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::PAGES as $routeName => $permission) {
            if (Route::has($routeName)) {
                $options[] = ['value' => $routeName, 'label' => self::LABELS[$routeName] ?? $routeName];
            }
        }

        return $options;
    }
}

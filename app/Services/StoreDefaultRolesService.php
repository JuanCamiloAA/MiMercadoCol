<?php

namespace App\Services;

use App\Helpers\PermissionHelper;
use App\Models\Company;
use App\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles por defecto de una empresa de supermercado.
 *
 * Se crean al dar de alta la empresa y al sembrar la base. `admin` es de sistema: no se
 * edita ni se borra, porque es el unico rol que garantiza que siempre quede alguien capaz
 * de administrar la cuenta.
 */
class StoreDefaultRolesService
{
    /** @var array<string, array{display_name: string, description: string, color: string, is_system: bool}> */
    public const ROLES = [
        'admin' => [
            'display_name' => 'Administrador',
            'description' => 'Acceso completo a la empresa.',
            'color' => '#0E6E4E',
            'is_system' => true,
        ],
        'supervisor' => [
            'display_name' => 'Supervisor de tienda',
            'description' => 'Opera la tienda completa sin administrar cuentas ni sedes.',
            'color' => '#1D6FA5',
            'is_system' => false,
        ],
        'cajero' => [
            'display_name' => 'Cajero',
            'description' => 'Abre caja, vende y cierra su turno.',
            'color' => '#0E6E4E',
            'is_system' => false,
        ],
        'bodega' => [
            'display_name' => 'Bodega e inventario',
            'description' => 'Recibe mercancia, ajusta existencias y alista pedidos.',
            'color' => '#8F4705',
            'is_system' => false,
        ],
        'domiciliario' => [
            'display_name' => 'Domiciliario',
            'description' => 'Lleva los pedidos y registra el cobro contraentrega.',
            'color' => '#1D6FA5',
            'is_system' => false,
        ],
        'auxiliar_contable' => [
            'display_name' => 'Auxiliar contable',
            'description' => 'Consulta informes, cartera y cierres de caja.',
            'color' => '#565E64',
            'is_system' => false,
        ],
    ];

    public function __construct(protected PermissionRegistrar $permissionRegistrar) {}

    /**
     * Crea (o completa) los roles de la empresa y les asigna su plantilla de permisos.
     *
     * @return array<string, Role>
     */
    public function createFor(Company $company): array
    {
        $roles = [];

        foreach (self::ROLES as $name => $config) {
            $role = Role::query()->firstOrCreate(
                ['name' => $name, 'guard_name' => 'web', 'company_id' => $company->id],
                [
                    'display_name' => $config['display_name'],
                    'description' => $config['description'],
                    'color' => $config['color'],
                    'is_system' => $config['is_system'],
                ]
            );

            $permissions = Permission::query()
                ->whereIn('name', PermissionHelper::presetPermissions($name))
                ->where('guard_name', 'web')
                ->get();

            $role->syncPermissions($permissions);

            $roles[$name] = $role;
        }

        $this->permissionRegistrar->forgetCachedPermissions();

        return $roles;
    }

    public function createSuperAdminRole(): Role
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'super_admin', 'guard_name' => 'web', 'company_id' => null],
            [
                'display_name' => 'Super Administrador',
                'description' => 'Administra todas las empresas del sistema.',
                'color' => '#B3261E',
                'is_system' => true,
            ]
        );

        $role->syncPermissions(Permission::query()->where('guard_name', 'web')->get());

        $this->permissionRegistrar->forgetCachedPermissions();

        return $role;
    }
}

<?php

namespace App\Policies;

use App\Models\CatalogImport;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Quien puede ver y manejar una importacion de catalogo.
 *
 * El permiso dice si la persona puede usar el modulo; la politica dice si esa corrida en
 * concreto es suya. Son dos preguntas distintas: un administrador de la empresa A tiene el
 * permiso y aun asi no puede mirar lo que importo la empresa B.
 */
class CatalogImportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('products.catalog_import.view');
    }

    public function view(User $user, CatalogImport $import): bool
    {
        return $user->can('products.catalog_import.view') && $this->sameCompany($user, $import);
    }

    public function create(User $user): bool
    {
        return $user->can('products.catalog_import.run');
    }

    /** Ejecutar en el acto es la misma potestad que lanzar, sobre una corrida concreta. */
    public function run(User $user, CatalogImport $import): bool
    {
        return $user->can('products.catalog_import.run') && $this->sameCompany($user, $import);
    }

    public function cancel(User $user, CatalogImport $import): bool
    {
        return $user->can('products.catalog_import.cancel') && $this->sameCompany($user, $import);
    }

    protected function sameCompany(User $user, CatalogImport $import): bool
    {
        if ($user->isSuperAdmin()) {
            $selected = TenantContext::superAdminSelectedCompanyId();

            // El super administrador en consolidado ve todo; con una empresa elegida, solo esa.
            return $selected === null || $selected === (int) $import->company_id;
        }

        return (int) $import->company_id === (int) TenantContext::activeCompanyId($user);
    }
}

<?php

namespace App\Models\Scopes;

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Guard explicito: la tienda en linea usa el guard `customer`, que no filtra por empresa.
        if (! auth('web')->check()) {
            return;
        }

        /** @var User $user */
        $user = auth('web')->user();

        if ($user->isSuperAdmin()) {
            $selected = TenantContext::superAdminSelectedCompanyId();

            if ($selected !== null) {
                $builder->where($model->getTable().'.company_id', $selected);
            }

            return;
        }

        if ($user->company_id) {
            $builder->where($model->getTable().'.company_id', $user->company_id);
        }
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withoutCompanyScope', fn (Builder $b) => $b->withoutGlobalScope(self::class));

        $builder->macro('forCompany', fn (Builder $b, int $companyId) => $b
            ->withoutGlobalScope(self::class)
            ->where($b->getModel()->getTable().'.company_id', $companyId));
    }
}

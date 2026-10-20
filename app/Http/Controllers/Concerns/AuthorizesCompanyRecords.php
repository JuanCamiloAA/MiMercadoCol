<?php

namespace App\Http\Controllers\Concerns;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Un registro de otra empresa no se toca, aunque el id llegue en la URL. El super usuario trabaja
 * sobre la empresa que eligio arriba.
 */
trait AuthorizesCompanyRecords
{
    protected function authorizeCompany(Model $record): void
    {
        abort_if((int) $record->getAttribute('company_id') !== (int) TenantContext::activeCompanyId(), 404);
    }
}

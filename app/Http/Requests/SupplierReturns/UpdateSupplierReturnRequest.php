<?php

namespace App\Http\Requests\SupplierReturns;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;

/**
 * Corregir el borrador. La orden y la sede no cambian; la forma es la misma que al crearlo.
 */
class UpdateSupplierReturnRequest extends StoreSupplierReturnRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $companyId = TenantContext::activeCompanyId();

        // La orden la fija el borrador: no se cambia al corregirlo.
        unset($rules['purchase_order_id'], $rules['dispatch_now']);
        $rules['supplier_id'] = ['nullable', 'integer', Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('company_id', $companyId))];

        return $rules;
    }
}

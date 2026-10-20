<?php

namespace App\Http\Requests\Payments;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * El orden en que aparecen los medios, como quedo despues de arrastrarlos.
 */
class ReorderPaymentMethodsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = TenantContext::activeCompanyId();

        return [
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'distinct', Rule::exists('payment_methods', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
        ];
    }
}

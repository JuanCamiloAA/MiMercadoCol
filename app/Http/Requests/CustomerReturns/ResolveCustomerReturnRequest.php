<?php

namespace App\Http\Requests\CustomerReturns;

use App\Models\CustomerReturn;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Resolver desde el panel: reembolso (con su medio) o saldo a favor. El cambio se hace en caja,
 * donde esta el cliente para llevarse lo nuevo.
 */
class ResolveCustomerReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sales.returns.resolve') ?? false;
    }

    public function rules(): array
    {
        return [
            'resolution' => ['required', Rule::in([CustomerReturn::RESOLUTION_REFUND, CustomerReturn::RESOLUTION_STORE_CREDIT])],
            'method' => ['nullable', 'required_if:resolution,'.CustomerReturn::RESOLUTION_REFUND, Rule::in(CustomerReturn::REFUND_METHODS)],
        ];
    }

    public function messages(): array
    {
        return [
            'resolution.required' => 'Elige cómo se le responde al cliente.',
            'method.required_if' => 'Elige con qué medio se le devuelve la plata.',
        ];
    }
}

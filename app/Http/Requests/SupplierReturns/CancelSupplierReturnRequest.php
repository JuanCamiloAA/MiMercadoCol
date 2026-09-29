<?php

namespace App\Http\Requests\SupplierReturns;

use Illuminate\Foundation\Http\FormRequest;

/** Anular un borrador: siempre con motivo. */
class CancelSupplierReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchases.returns.cancel') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Escribe por qué se anula.',
            'reason.min' => 'Escribe por qué se anula.',
        ];
    }
}

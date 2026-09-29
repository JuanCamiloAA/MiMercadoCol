<?php

namespace App\Http\Requests\CustomerReturns;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rechazar: no procede. El motivo lo lee el cliente en su correo y en la tienda.
 */
class RejectCustomerReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sales.returns.approve') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Escribe por qué no procede: el cliente lo va a leer.',
        ];
    }
}

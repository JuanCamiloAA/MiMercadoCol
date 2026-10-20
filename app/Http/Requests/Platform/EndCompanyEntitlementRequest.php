<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Terminar antes de tiempo una excepcion o un precio especial. Siempre con motivo.
 */
class EndCompanyEntitlementRequest extends FormRequest
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
        return [
            'reason' => ['required', 'string', 'min:5', 'max:250'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Escribe por qué se termina.',
            'reason.min' => 'Explica el motivo en al menos cinco letras.',
        ];
    }
}

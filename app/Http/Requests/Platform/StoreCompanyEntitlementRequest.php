<?php

namespace App\Http\Requests\Platform;

use App\Models\CompanyEntitlement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sumarle o quitarle un nodo del arbol al plan de una empresa, con vigencia y motivo.
 */
class StoreCompanyEntitlementRequest extends FormRequest
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
            'node' => ['required', 'string', 'max:120'],
            'effect' => ['required', Rule::in(CompanyEntitlement::EFFECTS)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:250'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'node.required' => 'Elige la función del árbol.',
            'effect.in' => 'Elige si se suma o se quita.',
            'reason.required' => 'Escribe el motivo.',
            'reason.min' => 'Explica el motivo en al menos cinco letras.',
        ];
    }
}

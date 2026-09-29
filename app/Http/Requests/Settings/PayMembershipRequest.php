<?php

namespace App\Http\Requests\Settings;

use App\Models\MembershipCharge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pagar ahora la membresia: con la tarjeta guardada o por el checkout de la pasarela.
 */
class PayMembershipRequest extends FormRequest
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
            'method' => ['required', Rule::in([MembershipCharge::METHOD_SAVED_CARD, MembershipCharge::METHOD_CHECKOUT])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['method.required' => 'Elige cómo pagar.', 'method.in' => 'Elige cómo pagar.'];
    }
}

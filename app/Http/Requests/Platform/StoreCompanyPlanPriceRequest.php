<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Precio especial de una empresa en un plan: precio mensual fijo o descuento, uno de los dos.
 */
class StoreCompanyPlanPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['monthly_price', 'discount_percent'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'membership_plan_id' => ['required', 'integer', 'exists:membership_plans,id'],
            'monthly_price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'discount_percent' => ['nullable', 'integer', 'min:1', 'max:90'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:250'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $monthly = $this->input('monthly_price');
            $discount = $this->input('discount_percent');

            if (($monthly === null) === ($discount === null)) {
                $validator->errors()->add('monthly_price', 'Pon un precio mensual o un descuento, uno de los dos.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'membership_plan_id.required' => 'Elige el plan del precio especial.',
            'monthly_price.integer' => 'El precio va en pesos, sin decimales.',
            'discount_percent.min' => 'El descuento va de 1 % a 90 %.',
            'discount_percent.max' => 'El descuento va de 1 % a 90 %.',
            'reason.required' => 'Escribe el motivo.',
            'reason.min' => 'Explica el motivo en al menos cinco letras.',
        ];
    }
}

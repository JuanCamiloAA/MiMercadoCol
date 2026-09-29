<?php

namespace App\Http\Requests;

use App\Models\PaymentTerm;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crear o editar un plazo de pago de la empresa.
 */
class SavePaymentTermRequest extends FormRequest
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
        /** @var PaymentTerm|null $term */
        $term = $this->route('term');
        $companyId = TenantContext::activeCompanyId();

        return [
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('payment_terms', 'name')->ignore($term?->id)->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'days' => ['required', 'integer', 'min:0', 'max:365'],
            'is_default_customers' => ['boolean'],
            'is_default_suppliers' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Ponle un nombre al plazo, por ejemplo «45 días».',
            'name.unique' => 'Ya hay un plazo con ese nombre.',
            'days.required' => 'Indica cuántos días da el plazo.',
            'days.max' => 'Un plazo no pasa de 365 días.',
        ];
    }
}

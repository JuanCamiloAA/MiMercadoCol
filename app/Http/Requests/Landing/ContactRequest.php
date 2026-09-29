<?php

namespace App\Http\Requests\Landing;

use App\Models\BillingCycle;
use App\Models\MembershipPlan;
use App\Models\PlatformLead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * «Quiero que me contacten», desde /contratar o desde el bloque de contacto de la landing.
 *
 * `website` es un campo trampa: la persona no lo ve y un robot lo llena. El controlador responde
 * como si todo saliera bien y no guarda nada.
 */
class ContactRequest extends FormRequest
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
            'source' => ['nullable', Rule::in([PlatformLead::SOURCE_SIGNUP, PlatformLead::SOURCE_CONTACT])],
            'company_name' => ['nullable', 'string', 'max:120'],
            'tax_id' => ['nullable', 'string', 'max:40'],
            'city' => ['nullable', 'string', 'max:80'],
            'contact_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'estimated_stores' => ['nullable', 'integer', 'min:1', 'max:500'],
            'membership_plan_id' => ['nullable', 'integer', Rule::exists(MembershipPlan::class, 'id')],
            'billing_cycle_id' => ['nullable', 'integer', Rule::exists(BillingCycle::class, 'id')],
            'message' => ['nullable', 'string', 'max:1000'],
            'website' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function isBot(): bool
    {
        return filled($this->input('website'));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contact_name.required' => 'Escribe tu nombre.',
            'email.required' => 'Escribe tu correo.',
            'email.email' => 'Tu correo no es válido.',
        ];
    }
}

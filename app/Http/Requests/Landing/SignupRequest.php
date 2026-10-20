<?php

namespace App\Http\Requests\Landing;

use App\Models\BillingCycle;
use App\Models\BusinessCategory;
use App\Models\CompanySignup;
use App\Models\MembershipPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Contratar en linea: la empresa, su administrador y el plan.
 */
class SignupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'company_email' => Str::lower(trim((string) $this->input('company_email'))),
            'admin_email' => Str::lower(trim((string) $this->input('admin_email'))),
            'tax_id' => trim((string) $this->input('tax_id')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'membership_plan_id' => ['required', 'integer', Rule::exists(MembershipPlan::class, 'id')->where('is_active', true)->where('is_public', true)],
            'billing_cycle_id' => ['required', 'integer', Rule::exists(BillingCycle::class, 'id')->where('is_active', true)],

            'company_name' => ['required', 'string', 'max:120'],
            'tax_id' => ['required', 'string', 'max:40', 'unique:companies,tax_id'],
            'city' => ['required', 'string', 'max:80'],
            'business_category_id' => ['required', 'integer', Rule::exists(BusinessCategory::class, 'id')->where('is_active', true)],
            'company_phone' => ['nullable', 'string', 'max:40'],
            'company_email' => ['required', 'email', 'max:160'],

            'admin_name' => ['required', 'string', 'max:80'],
            'admin_last_name' => ['nullable', 'string', 'max:80'],
            'admin_email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')],
            'admin_document' => ['nullable', 'string', 'max:40'],
            'admin_password' => ['required', 'confirmed', Password::defaults()],

            'estimated_stores' => ['nullable', 'integer', 'min:1', 'max:500'],
            'message' => ['nullable', 'string', 'max:1000'],
            'accept_terms' => ['accepted'],
        ];
    }

    /**
     * El NIT tampoco puede estar en una contratacion pendiente de pago: pagaria dos veces.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $pending = CompanySignup::query()
                    ->where('tax_id', $this->input('tax_id'))
                    ->where('status', CompanySignup::STATUS_PENDING)
                    ->where('expires_at', '>', now())
                    ->exists();

                if ($pending && ! $validator->errors()->has('tax_id')) {
                    $validator->errors()->add('tax_id', 'Ya hay una contratación con ese NIT esperando el pago. Termínala o espera dos horas.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'membership_plan_id.required' => 'Elige un plan.',
            'membership_plan_id.exists' => 'Ese plan no está disponible.',
            'billing_cycle_id.required' => 'Elige cada cuánto pagar.',
            'billing_cycle_id.exists' => 'Ese ciclo no está disponible.',
            'company_name.required' => 'Escribe el nombre de tu negocio.',
            'business_category_id.required' => 'Elige qué tipo de comercio es.',
            'business_category_id.exists' => 'Ese tipo de comercio no está disponible.',
            'tax_id.required' => 'Escribe el NIT.',
            'tax_id.unique' => 'Ese NIT ya tiene una cuenta. Si es tuyo, ingresa como empresa.',
            'city.required' => 'Escribe la ciudad.',
            'company_email.required' => 'Escribe el correo de la empresa.',
            'company_email.email' => 'El correo de la empresa no es válido.',
            'admin_name.required' => 'Escribe tu nombre.',
            'admin_email.required' => 'Escribe tu correo.',
            'admin_email.email' => 'Tu correo no es válido.',
            'admin_email.unique' => 'Ese correo ya tiene una cuenta. Ingresa o usa otro.',
            'admin_password.required' => 'Elige una contraseña.',
            'admin_password.confirmed' => 'Las contraseñas no coinciden.',
            'accept_terms.accepted' => 'Acepta los términos y el tratamiento de datos para continuar.',
        ];
    }
}

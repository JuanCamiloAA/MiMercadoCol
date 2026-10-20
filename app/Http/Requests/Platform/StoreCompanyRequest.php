<?php

namespace App\Http\Requests\Platform;

use App\Models\BillingCycle;
use App\Models\BusinessCategory;
use App\Models\MembershipPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crear una empresa desde Plataforma > Empresas: sus datos, su primera sede, su administrador y
 * su membresia. Lo aprovisiona `CompanyProvisioner`.
 */
class StoreCompanyRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'tax_id' => ['required', 'string', 'max:40', 'unique:companies,tax_id'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:80'],
            // Alimentos, textiles, belleza…: con ella la landing la agrupa.
            'business_category_id' => ['required', 'integer', Rule::exists(BusinessCategory::class, 'id')->where('is_active', true)],

            'store_name' => ['required', 'string', 'max:80'],
            'store_code' => ['required', 'string', 'max:20', 'alpha_dash'],
            'store_address' => ['nullable', 'string', 'max:160'],
            'store_city' => ['nullable', 'string', 'max:80'],
            'store_latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:store_longitude'],
            'store_longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:store_latitude'],

            'admin_name' => ['required', 'string', 'max:80'],
            'admin_last_name' => ['nullable', 'string', 'max:80'],
            'admin_email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')],
            'admin_document' => ['nullable', 'string', 'max:40'],

            'membership_plan_id' => ['required', 'integer', Rule::exists(MembershipPlan::class, 'id')],
            'billing_cycle_id' => ['required', 'integer', Rule::exists(BillingCycle::class, 'id')],

            // La solicitud de donde sale, para dejarla ganada.
            'lead_id' => ['nullable', 'integer', 'exists:platform_leads,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Escribe el nombre de la empresa.',
            'tax_id.required' => 'Escribe el NIT.',
            'tax_id.unique' => 'Ya hay una empresa con ese NIT.',
            'email.email' => 'El correo de la empresa no es válido.',
            'business_category_id.required' => 'Elige la clasificación de la empresa.',
            'business_category_id.exists' => 'Esa clasificación no existe o está desactivada.',
            'store_name.required' => 'Escribe el nombre de la primera sede.',
            'store_code.required' => 'Escribe el código de la sede.',
            'store_code.alpha_dash' => 'El código de la sede solo lleva letras, números y guiones.',
            'store_latitude.between' => 'La latitud va entre -90 y 90.',
            'store_longitude.between' => 'La longitud va entre -180 y 180.',
            'store_latitude.required_with' => 'Falta la latitud.',
            'store_longitude.required_with' => 'Falta la longitud.',
            'admin_name.required' => 'Escribe el nombre del administrador.',
            'admin_email.required' => 'Escribe el correo del administrador.',
            'admin_email.email' => 'El correo del administrador no es válido.',
            'admin_email.unique' => 'Ese correo ya tiene una cuenta en la plataforma.',
            'membership_plan_id.required' => 'Elige un plan.',
            'membership_plan_id.exists' => 'Ese plan no existe.',
            'billing_cycle_id.required' => 'Elige un ciclo de cobro.',
            'billing_cycle_id.exists' => 'Ese ciclo no existe.',
        ];
    }

    /**
     * Lo que recibe `CompanyProvisioner::provision()`.
     *
     * @return array<string, mixed>
     */
    public function provisioningData(): array
    {
        $data = $this->validated();

        return [
            'company' => [
                'name' => $data['name'],
                'legal_name' => $data['legal_name'] ?? null,
                'tax_id' => $data['tax_id'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'business_category_id' => (int) $data['business_category_id'],
            ],
            'store' => [
                'name' => $data['store_name'],
                'code' => $data['store_code'],
                'address' => $data['store_address'] ?? null,
                'city' => $data['store_city'] ?? ($data['city'] ?? null),
                'latitude' => isset($data['store_latitude']) ? (float) $data['store_latitude'] : null,
                'longitude' => isset($data['store_longitude']) ? (float) $data['store_longitude'] : null,
            ],
            'admin' => [
                'name' => $data['admin_name'],
                'last_name' => $data['admin_last_name'] ?? null,
                'email' => $data['admin_email'],
                'document' => $data['admin_document'] ?? null,
            ],
            'plan_id' => (int) $data['membership_plan_id'],
            'cycle_id' => (int) $data['billing_cycle_id'],
        ];
    }
}

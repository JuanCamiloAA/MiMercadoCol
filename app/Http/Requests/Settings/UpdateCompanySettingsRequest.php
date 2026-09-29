<?php

namespace App\Http\Requests\Settings;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ajustes > Empresa: los datos legales que salen impresos en tiquetes e informes.
 */
class UpdateCompanySettingsRequest extends FormRequest
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
            'legal_name' => ['required', 'string', 'max:160'],
            'tax_id' => ['required', 'string', 'max:40', Rule::unique('companies', 'tax_id')->ignore(TenantContext::requireCompanyId())],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:80'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Escribe el nombre de la empresa.',
            'legal_name.required' => 'Escribe la razón social.',
            'tax_id.required' => 'Escribe el NIT.',
            'tax_id.unique' => 'Ya hay otra empresa con ese NIT.',
            'email.email' => 'El correo no es válido.',
            'max' => 'Es muy largo: admite máximo :max caracteres.',
        ];
    }
}

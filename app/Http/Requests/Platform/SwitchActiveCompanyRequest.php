<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Elegir la empresa activa del super usuario, o `null` para «Todas las empresas».
 *
 * Se permiten empresas inactivas o suspendidas: el super usuario tiene que poder entrar a
 * arreglarlas.
 */
class SwitchActiveCompanyRequest extends FormRequest
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
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            // A donde volver. Solo se usa la ruta, nunca el dominio.
            'redirect' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'company_id.exists' => 'Esa empresa no existe.',
            'company_id.integer' => 'Esa empresa no existe.',
        ];
    }
}

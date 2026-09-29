<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Activar o desactivar una empresa. Desactivar pide motivo: no se borra nada y sus usuarios
 * quedan por fuera hasta que se vuelva a activar.
 */
class ToggleCompanyStatusRequest extends FormRequest
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
            'is_active' => ['required', 'boolean'],
            'reason' => ['required_if:is_active,false,0', 'nullable', 'string', 'max:250'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['reason.required_if' => 'Escribe por qué se desactiva la empresa.'];
    }
}

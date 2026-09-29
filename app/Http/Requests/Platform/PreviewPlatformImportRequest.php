<?php

namespace App\Http\Requests\Platform;

use App\Services\Platform\PlatformImportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Subir un archivo a la carga masiva: tipo, empresa destino y el CSV.
 *
 * La empresa destino es de este formulario, no de la barra superior: con A elegida arriba se
 * puede cargar para B.
 */
class PreviewPlatformImportRequest extends FormRequest
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
        $type = (string) $this->input('type');

        return [
            'type' => ['required', Rule::in(array_keys(PlatformImportService::types()))],
            'company_id' => [
                Rule::requiredIf(PlatformImportService::supports($type) && PlatformImportService::needsCompany($type)),
                'nullable', 'integer', Rule::exists('companies', 'id'),
            ],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'Elige qué vas a cargar.',
            'type.in' => 'Ese tipo de carga no existe.',
            'company_id.required' => 'Elige la empresa a la que va la carga.',
            'company_id.exists' => 'Esa empresa no existe.',
            'file.required' => 'Sube el archivo CSV.',
            'file.mimes' => 'El archivo debe ser CSV.',
            'file.max' => 'El archivo no puede pesar más de 5 MB.',
        ];
    }
}

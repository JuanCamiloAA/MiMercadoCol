<?php

namespace App\Http\Requests\Platform;

use App\Models\StorefrontTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Crear una plantilla de tienda (o cambiarle el nombre, la descripcion y para quien es). */
class SaveStoreTemplateRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'audience' => ['nullable', 'string', 'max:120'],
            'audience_group' => ['nullable', Rule::in(StorefrontTemplate::AUDIENCE_GROUPS)],
            'copy_from' => ['nullable', 'integer', 'exists:storefront_templates,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.required' => 'Ponle un nombre a la plantilla.'];
    }
}

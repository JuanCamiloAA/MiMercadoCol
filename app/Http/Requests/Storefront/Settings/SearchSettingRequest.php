<?php

namespace App\Http\Requests\Storefront\Settings;

/** Busqueda: sugerencias, busquedas populares y el aviso sin resultados. */
class SearchSettingRequest extends StorefrontSettingRequest
{
    protected function valueRules(): array
    {
        return [
            'value.suggestions' => ['required', 'boolean'],
            'value.popular_terms' => ['present', 'array', 'max:8'],
            'value.popular_terms.*' => ['required', 'string', 'max:30'],
            'value.empty_title' => ['required', 'string', 'max:60'],
            'value.empty_text' => ['nullable', 'string', 'max:160'],
        ];
    }

    public function value(): array
    {
        $value = parent::value();
        $value['popular_terms'] = array_values(array_unique(array_filter(array_map('trim', $value['popular_terms'] ?? []))));

        return $value;
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), ['value.popular_terms.max' => 'Caben hasta ocho búsquedas populares.']);
    }
}

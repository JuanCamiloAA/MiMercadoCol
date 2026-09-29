<?php

namespace App\Http\Requests\Storefront\Settings;

/** Cobertura: por barrios o por radio. */
class CoverageSettingRequest extends StorefrontSettingRequest
{
    protected function valueRules(): array
    {
        return [
            'value.mode' => ['required', 'in:zones,radius'],
            'value.radius_km' => ['required', 'numeric', 'min:0.5', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), ['value.radius_km.min' => 'El radio mínimo es medio kilómetro.']);
    }
}

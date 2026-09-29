<?php

namespace App\Http\Requests\Storefront\Settings;

use App\Services\Storefront\StorefrontStructure;
use Illuminate\Validation\Rule;

/** Navegacion del celular: de 3 a 5 de una lista cerrada. */
class MobileNavSettingRequest extends StorefrontSettingRequest
{
    protected function valueRules(): array
    {
        return [
            'value.items' => ['required', 'array', 'min:3', 'max:5'],
            'value.items.*' => ['required', 'distinct', Rule::in(array_keys(StorefrontStructure::MOBILE_NAV_ITEMS))],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'value.items.min' => 'La navegación del celular lleva al menos 3 opciones.',
            'value.items.max' => 'La navegación del celular lleva a lo sumo 5 opciones.',
            'value.items.*.distinct' => 'Una opción está repetida.',
        ]);
    }
}

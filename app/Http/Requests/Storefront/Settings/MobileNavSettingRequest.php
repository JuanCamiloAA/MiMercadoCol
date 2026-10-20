<?php

namespace App\Http\Requests\Storefront\Settings;

use App\Services\Storefront\StorefrontStructure;
use Closure;
use Illuminate\Validation\Rule;

/** Navegacion del celular: de 3 a 5 de una lista cerrada, o apagada. */
class MobileNavSettingRequest extends StorefrontSettingRequest
{
    protected function valueRules(): array
    {
        return [
            'value.enabled' => ['sometimes', 'boolean'],
            'value.items' => [
                'present',
                'array',
                'max:5',
                // Apagada no necesita opciones; encendida, al menos tres.
                function (string $attribute, mixed $items, Closure $fail) {
                    if ($this->input('value.enabled', true) !== false && count((array) $items) < 3) {
                        $fail('La navegación del celular lleva al menos 3 opciones.');
                    }
                },
            ],
            'value.items.*' => ['required', 'distinct', Rule::in(array_keys(StorefrontStructure::MOBILE_NAV_ITEMS))],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'value.items.max' => 'La navegación del celular lleva a lo sumo 5 opciones.',
            'value.items.*.distinct' => 'Una opción está repetida.',
        ]);
    }
}

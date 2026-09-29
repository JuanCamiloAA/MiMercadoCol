<?php

namespace App\Http\Requests\Storefront\Settings;

/** Barra flotante del carrito. */
class CartBarSettingRequest extends StorefrontSettingRequest
{
    protected function valueRules(): array
    {
        return [
            'value.enabled' => ['required', 'boolean'],
            'value.show_free_delivery_progress' => ['required', 'boolean'],
            'value.texts.missing' => ['required', 'string', 'max:80', 'regex:/\{faltan\}/'],
            'value.texts.reached' => ['required', 'string', 'max:80'],
            'value.desktop_behavior' => ['required', 'in:drawer,page'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'value.texts.missing.regex' => 'El texto de lo que falta tiene que llevar {faltan}, donde va la cifra.',
        ]);
    }
}

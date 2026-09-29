<?php

namespace App\Http\Requests\Storefront\Settings;

/** Como se ve la tienda al compartir el enlace. */
class SeoSettingRequest extends StorefrontSettingRequest
{
    protected function valueRules(): array
    {
        return [
            'value.title' => ['nullable', 'string', 'max:70'],
            'value.description' => ['nullable', 'string', 'max:160'],
        ];
    }
}

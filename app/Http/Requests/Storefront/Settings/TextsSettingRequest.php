<?php

namespace App\Http\Requests\Storefront\Settings;

/** Textos que se le dicen al cliente al pedir. */
class TextsSettingRequest extends StorefrontSettingRequest
{
    protected function valueRules(): array
    {
        return ['value.delivery_note' => ['nullable', 'string', 'max:200']];
    }
}

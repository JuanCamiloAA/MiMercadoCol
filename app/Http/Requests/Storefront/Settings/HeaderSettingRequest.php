<?php

namespace App\Http\Requests\Storefront\Settings;

use App\Services\Storefront\StorefrontStructure;
use Illuminate\Validation\Rule;

/** Encabezado de compra. */
class HeaderSettingRequest extends StorefrontSettingRequest
{
    protected function valueRules(): array
    {
        return [
            'value.style' => ['required', Rule::in(array_keys(StorefrontStructure::HEADER_STYLES))],
            'value.sticky' => ['required', 'boolean'],
            'value.show_tagline' => ['required', 'boolean'],
            'value.show_location' => ['required', 'boolean'],
            'value.show_account' => ['required', 'boolean'],
            'value.cart_shows_total' => ['required', 'boolean'],
            'value.search_placeholder' => ['required', 'string', 'max:60'],
            'value.logo_mode' => ['required', Rule::in(array_keys(StorefrontStructure::LOGO_MODES))],
            // Lo nuevo es opcional: un formulario de antes no lo manda y vale lo de siempre.
            'value.layout' => ['sometimes', Rule::in(array_keys(StorefrontStructure::CHOICES['header_layout']))],
            'value.overlay' => ['sometimes', 'boolean'],
            'value.sticky_style' => ['sometimes', Rule::in(array_keys(StorefrontStructure::CHOICES['sticky_style']))],
            'value.search_style' => ['sometimes', Rule::in(array_keys(StorefrontStructure::CHOICES['search_style']))],
        ];
    }
}

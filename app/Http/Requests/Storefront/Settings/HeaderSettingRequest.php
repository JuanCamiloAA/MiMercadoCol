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
        ];
    }
}

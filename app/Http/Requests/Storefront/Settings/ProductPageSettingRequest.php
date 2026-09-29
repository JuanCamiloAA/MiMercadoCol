<?php

namespace App\Http\Requests\Storefront\Settings;

/** Pagina de producto. */
class ProductPageSettingRequest extends StorefrontSettingRequest
{
    protected function valueRules(): array
    {
        return [
            'value.show_unit_price' => ['required', 'boolean'],
            'value.show_description' => ['required', 'boolean'],
            'value.related' => ['required', 'in:same_category,best_sellers,none'],
            'value.related_limit' => ['required', 'integer', 'min:2', 'max:16'],
        ];
    }
}

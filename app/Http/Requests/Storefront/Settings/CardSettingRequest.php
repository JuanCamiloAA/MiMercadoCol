<?php

namespace App\Http\Requests\Storefront\Settings;

/** Tarjeta de producto. */
class CardSettingRequest extends StorefrontSettingRequest
{
    protected function valueRules(): array
    {
        return [
            'value.image_fit' => ['required', 'in:contain,cover'],
            'value.image_bg' => ['required', 'in:light,white'],
            'value.show_brand' => ['required', 'boolean'],
            'value.show_content' => ['required', 'boolean'],
            'value.show_unit_price' => ['required', 'boolean'],
            'value.deal_badge' => ['required', 'in:percent,savings,none'],
            'value.add_button' => ['required', 'in:outline,solid'],
            'value.stock_display' => ['required', 'in:hidden,low_only,exact'],
            'value.low_stock_threshold' => ['required', 'integer', 'min:1', 'max:20'],
            'value.price_size' => ['required', 'in:md,lg'],
        ];
    }
}

<?php

namespace App\Http\Requests\Storefront\Settings;

use App\Services\Storefront\StorefrontStructure;
use Illuminate\Validation\Rule;

/** Tarjeta de producto. */
class CardSettingRequest extends StorefrontSettingRequest
{
    protected function valueRules(): array
    {
        $in = fn (string $choice) => Rule::in(array_map('strval', array_keys(StorefrontStructure::CHOICES[$choice])));

        return [
            'value.image_fit' => ['required', 'in:contain,cover'],
            'value.image_bg' => ['required', $in('image_bg')],
            'value.show_brand' => ['required', 'boolean'],
            'value.show_content' => ['required', 'boolean'],
            'value.show_unit_price' => ['required', 'boolean'],
            'value.deal_badge' => ['required', 'in:percent,savings,none'],
            'value.add_button' => ['required', $in('add_button')],
            'value.stock_display' => ['required', 'in:hidden,low_only,exact'],
            'value.low_stock_threshold' => ['required', 'integer', 'min:1', 'max:20'],
            'value.price_size' => ['required', $in('price_size')],
            // Lo nuevo es opcional: un formulario de antes no lo manda y vale lo de siempre.
            'value.style' => ['sometimes', $in('card_style')],
            'value.image_ratio' => ['sometimes', $in('image_ratio')],
            'value.text_align' => ['sometimes', $in('text_align')],
            'value.show_swatches' => ['sometimes', 'boolean'],
            'value.show_description' => ['sometimes', 'boolean'],
            'value.image_hover' => ['sometimes', $in('image_hover')],
        ];
    }
}

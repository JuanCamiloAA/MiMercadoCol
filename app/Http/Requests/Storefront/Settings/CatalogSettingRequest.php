<?php

namespace App\Http\Requests\Storefront\Settings;

/** Catalogo en linea. `hide_zero_price` siempre es verdadero: un articulo en cero no se pinta. */
class CatalogSettingRequest extends StorefrontSettingRequest
{
    protected function valueRules(): array
    {
        return [
            'value.show_out_of_stock' => ['required', 'boolean'],
            'value.price_per_unit' => ['required', 'boolean'],
        ];
    }

    public function value(): array
    {
        return array_merge(parent::value(), ['hide_zero_price' => true]);
    }
}

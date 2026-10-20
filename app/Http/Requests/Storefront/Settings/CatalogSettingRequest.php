<?php

namespace App\Http\Requests\Storefront\Settings;

/**
 * Catalogo en linea. `hide_zero_price` siempre es verdadero: un articulo en cero no se pinta.
 * `show_out_of_stock` ya no se pide: la tienda nunca muestra lo que no tiene disponible.
 */
class CatalogSettingRequest extends StorefrontSettingRequest
{
    protected function valueRules(): array
    {
        return [
            'value.price_per_unit' => ['required', 'boolean'],
        ];
    }

    public function value(): array
    {
        return array_merge(parent::value(), ['hide_zero_price' => true]);
    }
}

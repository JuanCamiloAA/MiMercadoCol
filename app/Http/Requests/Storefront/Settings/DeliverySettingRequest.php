<?php

namespace App\Http\Requests\Storefront\Settings;

/** Domicilio: tarifa, pedido minimo, gratis desde, tiempo, recoger y propina. */
class DeliverySettingRequest extends StorefrontSettingRequest
{
    protected function valueRules(): array
    {
        return [
            'value.enabled' => ['required', 'boolean'],
            'value.fee' => ['required', 'integer', 'min:0'],
            'value.min_order_total' => ['required', 'integer', 'min:0'],
            // Cero es «nunca es gratis», no «gratis desde $ 0».
            'value.free_from' => ['required', 'integer', 'min:0'],
            'value.eta_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'value.pickup' => ['required', 'boolean'],
            'value.tip' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'value.fee.min' => 'La tarifa no puede ser negativa.',
            'value.eta_minutes.max' => 'Un tiempo de entrega de más de cuatro horas no es creíble.',
        ]);
    }
}

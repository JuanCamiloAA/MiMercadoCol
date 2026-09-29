<?php

namespace App\Http\Requests\Storefront\Settings;

/** Medios de pago de la sede. */
class PaymentsSettingRequest extends StorefrontSettingRequest
{
    protected function valueRules(): array
    {
        return [
            'value.cash_on_delivery' => ['required', 'boolean'],
            'value.card_on_delivery' => ['required', 'boolean'],
            'value.transfer' => ['required', 'boolean'],
            'value.customer_credit' => ['required', 'boolean'],
        ];
    }
}

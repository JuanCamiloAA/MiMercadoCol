<?php

namespace App\Http\Requests\Storefront\Settings;

/** Franjas de entrega: encendidas o no. */
class SlotsSettingRequest extends StorefrontSettingRequest
{
    protected function valueRules(): array
    {
        return ['value.enabled' => ['required', 'boolean']];
    }
}

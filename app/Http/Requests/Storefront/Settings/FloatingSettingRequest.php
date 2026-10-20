<?php

namespace App\Http\Requests\Storefront\Settings;

use App\Services\Storefront\StorefrontStructure;
use Illuminate\Validation\Rule;

/** El boton flotante de WhatsApp. El numero sale del contacto de la sede, no de aqui. */
class FloatingSettingRequest extends StorefrontSettingRequest
{
    protected function valueRules(): array
    {
        return [
            'value.whatsapp' => ['required', 'boolean'],
            'value.position' => ['required', Rule::in(array_keys(StorefrontStructure::CHOICES['floating_position']))],
        ];
    }
}

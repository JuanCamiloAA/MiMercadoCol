<?php

namespace App\Http\Requests\Settings;

use App\Settings\SettingsSchema;

/**
 * Ajustes > Reglas de caja: descuento maximo, redondeo del efectivo y reglas de devolucion.
 */
class UpdatePosRulesSettingsRequest extends SettingsTabRequest
{
    protected function tab(): string
    {
        return SettingsSchema::TAB_POS_RULES;
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'pos.cash_rounding.in' => 'El redondeo solo puede ser 0, 50 o 100 pesos.',
        ];
    }
}

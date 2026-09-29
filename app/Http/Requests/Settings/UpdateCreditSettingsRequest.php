<?php

namespace App\Http\Requests\Settings;

use App\Settings\SettingsSchema;

/**
 * Ajustes > Credito: a cuantos dias de mora se bloquea el credito.
 */
class UpdateCreditSettingsRequest extends SettingsTabRequest
{
    protected function tab(): string
    {
        return SettingsSchema::TAB_CREDIT;
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'credit.block_on_overdue_days.required' => 'Indica a cuantos dias de mora se bloquea el credito.',
        ];
    }
}

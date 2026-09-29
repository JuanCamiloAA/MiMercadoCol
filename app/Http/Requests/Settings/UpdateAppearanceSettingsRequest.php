<?php

namespace App\Http\Requests\Settings;

use App\Settings\SettingsSchema;

/**
 * Ajustes > Apariencia: tipografia, esquinas, densidad, tema y menu del panel.
 */
class UpdateAppearanceSettingsRequest extends SettingsTabRequest
{
    protected function tab(): string
    {
        return SettingsSchema::TAB_APPEARANCE;
    }
}

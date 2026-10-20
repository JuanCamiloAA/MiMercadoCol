<?php

namespace App\Http\Requests\Settings;

use App\Services\Catalog\CatalogImages;
use App\Settings\SettingsSchema;

/**
 * Ajustes > Catalogo: cuantas fotos se le montan a cada ficha.
 */
class UpdateCatalogSettingsRequest extends SettingsTabRequest
{
    protected function tab(): string
    {
        return SettingsSchema::TAB_CATALOG;
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'catalog.max_images.required' => 'Indica cuántas fotos se le montan a cada artículo.',
            'catalog.max_images.min' => 'Cada artículo lleva al menos una foto.',
            'catalog.max_images.max' => 'Se pueden montar hasta '.CatalogImages::MAX_LIMIT.' fotos por artículo.',
        ];
    }
}

<?php

namespace App\Http\Requests\Settings;

use App\Services\BrandThemeService;
use App\Settings\SettingsSchema;
use App\Support\Color;
use Illuminate\Validation\Validator;

/**
 * Ajustes > Marca: nombre, subtitulo y color. Las imagenes tienen su propio endpoint.
 */
class UpdateBrandSettingsRequest extends SettingsTabRequest
{
    protected function tab(): string
    {
        return SettingsSchema::TAB_BRAND;
    }

    /**
     * El color claro se ajusta solo; el oscuro escrito a mano no: se usa tal cual, asi que
     * tiene que leerse sobre los fondos oscuros o no se guarda.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $dark = $this->input('brand.accent_dark');

                if ($validator->errors()->has('brand.accent_dark') || ! Color::isHex($dark)) {
                    return;
                }

                $contrast = app(BrandThemeService::class)->worstContrast(
                    Color::normalize($dark),
                    BrandThemeService::DARK,
                    BrandThemeService::SOFT_WEIGHT['dark'],
                );

                if ($contrast < BrandThemeService::MIN_CONTRAST) {
                    $validator->errors()->add(
                        'brand.accent_dark',
                        'Ese color da '.number_format($contrast, 1, ',', '.').':1 sobre el fondo oscuro; hace falta al menos 4,5:1 para que se lea. Prueba uno más claro.',
                    );
                }
            },
        ];
    }

    public function values(): array
    {
        $values = parent::values();

        // Se guarda siempre en mayusculas: asi «es el de fabrica» es una comparacion exacta.
        foreach (['brand.accent', 'brand.accent_dark'] as $key) {
            if (isset($values[$key]) && is_string($values[$key])) {
                $values[$key] = Color::normalize($values[$key]);
            }
        }

        if (isset($values['brand.tagline'])) {
            $values['brand.tagline'] = trim((string) $values['brand.tagline']) ?: null;
        }

        $values['brand.display_name'] = trim((string) ($values['brand.display_name'] ?? ''));

        return $values;
    }
}

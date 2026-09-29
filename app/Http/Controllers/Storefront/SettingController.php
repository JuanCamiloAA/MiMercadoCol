<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\Settings\SettingRequests;
use App\Models\StorefrontSetting;
use App\Models\StorefrontTemplate;
use App\Services\Storefront\StorefrontManager;
use Illuminate\Http\RedirectResponse;

/**
 * Los ajustes de la tienda: lo operativo (domicilio, cobertura, franjas, pagos, textos,
 * buscadores) y la estructura (barra superior, encabezado, menu, tarjeta, carrito, pie, paginas
 * de categoria, producto y busqueda).
 *
 * Cada clave se valida con su propio request (`SettingRequests::MAP`). Todo cae en el borrador:
 * se publica junto con los bloques.
 */
class SettingController extends Controller
{
    use ResolvesStorefront;

    public function update(string $key): RedirectResponse
    {
        abort_if(! in_array($key, StorefrontSetting::KEYS, true), 404, 'Ese ajuste no existe.');

        $storefront = $this->storefront();
        $value = SettingRequests::validate($key)->value();

        // Con la plantilla, el diseño no se toca aqui: se personaliza primero. Del pie si se
        // guarda lo que es de la sede (contacto, redes y datos legales).
        if ($storefront->follows_template && in_array($key, StorefrontTemplate::DESIGN_KEYS['settings'], true)) {
            $own = StorefrontTemplate::DESIGN_KEYS['settings_except'][$key] ?? [];

            if ($own === []) {
                return back()->withErrors([
                    'template' => 'Esta tienda usa una plantilla. Personalízala para cambiar su diseño.',
                ]);
            }

            $value = array_merge(
                (array) $storefront->setting($key, app(StorefrontManager::class)->defaultSettings()[$key] ?? []),
                array_intersect_key($value, array_flip($own))
            );
        }

        StorefrontSetting::updateOrCreate(
            ['storefront_id' => $storefront->id, 'key' => $key],
            ['value' => $value]
        );

        return back(303);
    }
}

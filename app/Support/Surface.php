<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * En que superficie se pinta una peticion: el panel, la caja o la tienda en linea.
 *
 * De aqui sale `data-surface` en `app.blade.php`, y con el el tema inicial y la escala de la
 * pantalla. Si agregas una pantalla con `PosLayout`, suma su ruta a `POS_PATHS`: sin eso se
 * pinta con el tema y la escala del panel un instante antes de que React la corrija.
 */
final class Surface
{
    public const PANEL = 'panel';

    public const POS = 'pos';

    public const STORE = 'store';

    /** Pantallas que usan `PosLayout`. */
    public const POS_PATHS = ['pos', 'pos/*', 'caja/apertura', 'caja/cierre'];

    /** Rutas `storefront.*` que son del panel, no de la tienda en linea. */
    protected const PANEL_STOREFRONT_ROUTES = ['storefront.editor.', 'storefront.catalog.'];

    public static function for(Request $request): string
    {
        if (self::isStorefront($request)) {
            return self::STORE;
        }

        return $request->is(...self::POS_PATHS) ? self::POS : self::PANEL;
    }

    /**
     * La tienda en linea tiene su propio aspecto (`storeTheme`): la marca del panel no se
     * comparte ni se inyecta ahi.
     */
    public static function isStorefront(Request $request): bool
    {
        $name = $request->route()?->getName() ?? '';

        if (! str_starts_with($name, 'storefront.')) {
            return false;
        }

        foreach (self::PANEL_STOREFRONT_ROUTES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return false;
            }
        }

        return true;
    }
}

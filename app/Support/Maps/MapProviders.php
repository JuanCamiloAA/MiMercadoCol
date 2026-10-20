<?php

namespace App\Support\Maps;

/**
 * Los proveedores de mapa que la plataforma sabe pintar y los estilos que ofrece de cada uno.
 *
 * Todos menos Google se pintan con Leaflet a partir de teselas: cambia la direccion, no el
 * codigo. Los estilos son listas cerradas porque su id va dentro de esa direccion.
 */
final class MapProviders
{
    public const OSM = 'osm';

    public const GOOGLE = 'google';

    public const MAPTILER = 'maptiler';

    public const MAPBOX = 'mapbox';

    public const CUSTOM = 'custom';

    public const ALL = [self::OSM, self::GOOGLE, self::MAPTILER, self::MAPBOX, self::CUSTOM];

    /** Sin estilo oscuro propio: se oscurece el claro en el navegador, o se deja claro. */
    public const DARK_FILTER = 'filter';

    public const DARK_NONE = 'none';

    public const GOOGLE_TYPES = [
        'roadmap' => 'Calles',
        'terrain' => 'Relieve',
        'hybrid' => 'Satélite con calles',
        'satellite' => 'Satélite',
    ];

    public const MAPTILER_STYLES = [
        'streets-v2' => 'Calles',
        'basic-v2' => 'Básico',
        'bright-v2' => 'Brillante',
        'outdoor-v2' => 'Exterior',
        'dataviz' => 'Sobrio',
        'satellite' => 'Satélite',
        'hybrid' => 'Satélite con calles',
    ];

    public const MAPTILER_DARK_STYLES = [
        'streets-v2-dark' => 'Calles oscuro',
        'basic-v2-dark' => 'Básico oscuro',
        'dataviz-dark' => 'Sobrio oscuro',
    ];

    /** Los que MapTiler sirve en JPEG: pedirlos en PNG responde error. */
    public const MAPTILER_JPEG = ['satellite', 'hybrid'];

    public const MAPBOX_STYLES = [
        'mapbox/streets-v12' => 'Calles',
        'mapbox/outdoors-v12' => 'Exterior',
        'mapbox/light-v11' => 'Claro',
        'mapbox/satellite-streets-v12' => 'Satélite con calles',
    ];

    public const MAPBOX_DARK_STYLES = [
        'mapbox/dark-v11' => 'Oscuro',
        'mapbox/navigation-night-v1' => 'Navegación nocturna',
    ];

    /**
     * Lo que la pantalla de Plataforma > Mapas necesita para ofrecerlos.
     *
     * @return list<array<string, mixed>>
     */
    public static function catalogue(): array
    {
        $dark = fn (array $styles) => self::options([
            ...$styles,
            self::DARK_FILTER => 'Oscurecer el estilo claro',
            self::DARK_NONE => 'Dejarlo claro',
        ]);

        return [
            [
                'value' => self::OSM,
                'label' => 'OpenStreetMap',
                'description' => 'Gratis y sin llave. Para tráfico moderado: con muchas visitas, pasa a uno con llave.',
                'needs_key' => false,
                'dark_options' => self::options([self::DARK_FILTER => 'Oscurecer el mapa', self::DARK_NONE => 'Dejarlo claro']),
            ],
            [
                'value' => self::GOOGLE,
                'label' => 'Google Maps',
                'description' => 'El que todos conocen. Pide llave de API con facturación activa en Google Cloud.',
                'needs_key' => true,
                'styles' => self::options(self::GOOGLE_TYPES),
            ],
            [
                'value' => self::MAPTILER,
                'label' => 'MapTiler',
                'description' => 'Estilos cuidados y satélite. Llave gratuita con cupo mensual.',
                'needs_key' => true,
                'styles' => self::options(self::MAPTILER_STYLES),
                'dark_options' => $dark(self::MAPTILER_DARK_STYLES),
            ],
            [
                'value' => self::MAPBOX,
                'label' => 'Mapbox',
                'description' => 'Estilos de Mapbox con su token público (pk.). Cupo gratuito mensual.',
                'needs_key' => true,
                'styles' => self::options(self::MAPBOX_STYLES),
                'dark_options' => $dark(self::MAPBOX_DARK_STYLES),
            ],
            [
                'value' => self::CUSTOM,
                'label' => 'Personalizado',
                'description' => 'Un servidor de teselas propio o contratado, con su dirección y el crédito que pida.',
                'needs_key' => false,
            ],
        ];
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string}>
     */
    private static function options(array $labels): array
    {
        return collect($labels)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all();
    }
}

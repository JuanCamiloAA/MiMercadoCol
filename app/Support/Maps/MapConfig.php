<?php

namespace App\Support\Maps;

use App\Services\PlatformSettingsService;

/**
 * Lo que el navegador necesita para pintar el mapa, armado desde Plataforma > Mapas.
 *
 * Es el unico lugar que arma la direccion de las teselas y el credito: la landing, el formulario
 * de sedes y la vista previa de Plataforma lo reciben hecho. Solo viaja la llave del proveedor en
 * uso, y esa llave es de navegador: la ve cualquiera que abra la pagina, por eso se restringe al
 * dominio en la consola del proveedor.
 */
final class MapConfig
{
    /** Las claves de `PlatformSettingsService` que lo arman. */
    public const KEYS = [
        'maps.provider', 'maps.osm.dark',
        'maps.google.key', 'maps.google.map_id', 'maps.google.map_type',
        'maps.maptiler.key', 'maps.maptiler.style', 'maps.maptiler.dark',
        'maps.mapbox.token', 'maps.mapbox.style', 'maps.mapbox.dark',
        'maps.custom.url', 'maps.custom.dark_url', 'maps.custom.attribution', 'maps.custom.max_zoom', 'maps.custom.tile_size',
        'maps.default_lat', 'maps.default_lng', 'maps.default_zoom',
    ];

    private const OSM_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>';

    /** @return array<string, mixed> */
    public static function current(): array
    {
        return self::build(array_intersect_key(app(PlatformSettingsService::class)->all(), array_flip(self::KEYS)));
    }

    /**
     * @param  array<string, mixed>  $settings  Las claves de `KEYS`; lo que falte toma su defecto.
     * @return array<string, mixed>
     */
    public static function build(array $settings): array
    {
        $settings = array_merge(array_intersect_key(PlatformSettingsService::DEFAULTS, array_flip(self::KEYS)), $settings);
        $provider = in_array($settings['maps.provider'], MapProviders::ALL, true) ? $settings['maps.provider'] : MapProviders::OSM;

        // Un proveedor sin su llave no pinta nada: mejor el mapa libre que un recuadro gris.
        $config = self::forProvider($provider, $settings) ?? self::forProvider(MapProviders::OSM, $settings);

        return [
            ...$config,
            'center' => [(float) $settings['maps.default_lat'], (float) $settings['maps.default_lng']],
            'zoom' => (int) $settings['maps.default_zoom'],
        ];
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>|null
     */
    private static function forProvider(string $provider, array $s): ?array
    {
        return match ($provider) {
            MapProviders::GOOGLE => filled($s['maps.google.key']) ? [
                'provider' => MapProviders::GOOGLE,
                'driver' => 'google',
                'google' => [
                    'key' => (string) $s['maps.google.key'],
                    'map_id' => filled($s['maps.google.map_id']) ? (string) $s['maps.google.map_id'] : null,
                    'map_type' => array_key_exists((string) $s['maps.google.map_type'], MapProviders::GOOGLE_TYPES) ? $s['maps.google.map_type'] : 'roadmap',
                ],
            ] : null,

            MapProviders::MAPTILER => filled($s['maps.maptiler.key']) ? self::tiles(
                MapProviders::MAPTILER,
                self::maptilerUrl(self::pick($s['maps.maptiler.style'], MapProviders::MAPTILER_STYLES, 'streets-v2'), (string) $s['maps.maptiler.key']),
                self::darkUrl($s['maps.maptiler.dark'], MapProviders::MAPTILER_DARK_STYLES, fn (string $style) => self::maptilerUrl($style, (string) $s['maps.maptiler.key'])),
                $s['maps.maptiler.dark'] === MapProviders::DARK_FILTER,
                '<a href="https://www.maptiler.com/copyright/" target="_blank" rel="noopener">&copy; MapTiler</a> '.self::OSM_ATTRIBUTION,
                maxZoom: 20,
            ) : null,

            MapProviders::MAPBOX => filled($s['maps.mapbox.token']) ? self::tiles(
                MapProviders::MAPBOX,
                self::mapboxUrl(self::pick($s['maps.mapbox.style'], MapProviders::MAPBOX_STYLES, 'mapbox/streets-v12'), (string) $s['maps.mapbox.token']),
                self::darkUrl($s['maps.mapbox.dark'], MapProviders::MAPBOX_DARK_STYLES, fn (string $style) => self::mapboxUrl($style, (string) $s['maps.mapbox.token'])),
                $s['maps.mapbox.dark'] === MapProviders::DARK_FILTER,
                '&copy; <a href="https://www.mapbox.com/about/maps/" target="_blank" rel="noopener">Mapbox</a> '.self::OSM_ATTRIBUTION
                    .' <strong><a href="https://www.mapbox.com/map-feedback/" target="_blank" rel="noopener">Mejorar este mapa</a></strong>',
                maxZoom: 20,
                tileSize: 512,
            ) : null,

            MapProviders::CUSTOM => filled($s['maps.custom.url']) ? self::tiles(
                MapProviders::CUSTOM,
                (string) $s['maps.custom.url'],
                filled($s['maps.custom.dark_url']) ? (string) $s['maps.custom.dark_url'] : null,
                blank($s['maps.custom.dark_url']),
                // Texto libre: se escapa, el credito se pinta como HTML.
                e((string) $s['maps.custom.attribution']),
                maxZoom: max(1, min(22, (int) $s['maps.custom.max_zoom'])),
                tileSize: (int) $s['maps.custom.tile_size'] === 512 ? 512 : 256,
            ) : null,

            default => self::tiles(
                MapProviders::OSM,
                'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                null,
                $s['maps.osm.dark'] !== MapProviders::DARK_NONE,
                self::OSM_ATTRIBUTION,
                maxZoom: 19,
            ),
        };
    }

    /**
     * Una capa de teselas para Leaflet. Las de 512 se piden un nivel arriba (`zoom_offset`).
     *
     * @return array<string, mixed>
     */
    private static function tiles(string $provider, string $url, ?string $darkUrl, bool $darkFilter, string $attribution, int $maxZoom, int $tileSize = 256): array
    {
        return [
            'provider' => $provider,
            'driver' => 'leaflet',
            'tiles' => [
                'url' => $url,
                'dark_url' => $darkUrl,
                'dark_filter' => $darkUrl === null && $darkFilter,
                'attribution' => $attribution,
                'max_zoom' => $maxZoom,
                'tile_size' => $tileSize,
                'zoom_offset' => $tileSize === 512 ? -1 : 0,
            ],
        ];
    }

    private static function maptilerUrl(string $style, string $key): string
    {
        $format = in_array($style, MapProviders::MAPTILER_JPEG, true) ? 'jpg' : 'png';

        return "https://api.maptiler.com/maps/{$style}/256/{z}/{x}/{y}{r}.{$format}?key=".rawurlencode($key);
    }

    private static function mapboxUrl(string $style, string $token): string
    {
        return "https://api.mapbox.com/styles/v1/{$style}/tiles/512/{z}/{x}/{y}{r}?access_token=".rawurlencode($token);
    }

    /**
     * @param  array<string, string>  $styles
     */
    private static function darkUrl(mixed $value, array $styles, callable $url): ?string
    {
        return is_string($value) && array_key_exists($value, $styles) ? $url($value) : null;
    }

    /**
     * @param  array<string, string>  $options
     */
    private static function pick(mixed $value, array $options, string $default): string
    {
        return is_string($value) && array_key_exists($value, $options) ? $value : $default;
    }
}

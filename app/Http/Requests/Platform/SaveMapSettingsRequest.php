<?php

namespace App\Http\Requests\Platform;

use App\Support\Maps\MapProviders;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Plataforma > Mapas: el proveedor y lo de cada uno. La misma regla vale para guardar y para
 * probar sin guardar.
 *
 * Las llaves de los proveedores que no se eligieron se guardan igual: volver a uno no obliga a
 * pegarla otra vez. Solo la del elegido es obligatoria.
 */
class SaveMapSettingsRequest extends FormRequest
{
    /** Campo del formulario => clave de `PlatformSettingsService`. */
    public const FIELDS = [
        'provider' => 'maps.provider',
        'osm_dark' => 'maps.osm.dark',
        'google_key' => 'maps.google.key',
        'google_map_id' => 'maps.google.map_id',
        'google_map_type' => 'maps.google.map_type',
        'maptiler_key' => 'maps.maptiler.key',
        'maptiler_style' => 'maps.maptiler.style',
        'maptiler_dark' => 'maps.maptiler.dark',
        'mapbox_token' => 'maps.mapbox.token',
        'mapbox_style' => 'maps.mapbox.style',
        'mapbox_dark' => 'maps.mapbox.dark',
        'custom_url' => 'maps.custom.url',
        'custom_dark_url' => 'maps.custom.dark_url',
        'custom_attribution' => 'maps.custom.attribution',
        'custom_max_zoom' => 'maps.custom.max_zoom',
        'custom_tile_size' => 'maps.custom.tile_size',
        'default_lat' => 'maps.default_lat',
        'default_lng' => 'maps.default_lng',
        'default_zoom' => 'maps.default_zoom',
    ];

    /** Una direccion de teselas: https, sin comillas ni espacios, con sus tres marcadores. */
    private const TILE_URL = '#^https://[^\s"\'<>]+$#';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(collect(self::FIELDS)->keys()
            ->filter(fn (string $field) => is_string($this->input($field)))
            ->mapWithKeys(fn (string $field) => [$field => trim((string) $this->input($field))])
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $dark = fn (array $styles) => Rule::in([...array_keys($styles), MapProviders::DARK_FILTER, MapProviders::DARK_NONE]);
        $tiles = ['max:500', 'regex:'.self::TILE_URL, function (string $attribute, mixed $value, \Closure $fail) {
            foreach (['{z}', '{x}', '{y}'] as $placeholder) {
                if (! str_contains((string) $value, $placeholder)) {
                    $fail("A la dirección le falta {$placeholder}: sin él Leaflet no sabe qué tesela pedir.");

                    return;
                }
            }
        }];

        return [
            'provider' => ['required', Rule::in(MapProviders::ALL)],
            'osm_dark' => ['required', Rule::in([MapProviders::DARK_FILTER, MapProviders::DARK_NONE])],

            'google_key' => ['nullable', 'required_if:provider,'.MapProviders::GOOGLE, 'string', 'max:200', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'google_map_id' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'google_map_type' => ['required', Rule::in(array_keys(MapProviders::GOOGLE_TYPES))],

            'maptiler_key' => ['nullable', 'required_if:provider,'.MapProviders::MAPTILER, 'string', 'max:100', 'regex:/^[A-Za-z0-9]+$/'],
            'maptiler_style' => ['required', Rule::in(array_keys(MapProviders::MAPTILER_STYLES))],
            'maptiler_dark' => ['required', $dark(MapProviders::MAPTILER_DARK_STYLES)],

            // El token secreto (sk.) daria acceso a la cuenta: aqui solo el publico.
            'mapbox_token' => ['nullable', 'required_if:provider,'.MapProviders::MAPBOX, 'string', 'max:300', 'regex:/^pk\.[A-Za-z0-9_\-.]+$/'],
            'mapbox_style' => ['required', Rule::in(array_keys(MapProviders::MAPBOX_STYLES))],
            'mapbox_dark' => ['required', $dark(MapProviders::MAPBOX_DARK_STYLES)],

            'custom_url' => ['nullable', 'required_if:provider,'.MapProviders::CUSTOM, 'string', ...$tiles],
            'custom_dark_url' => ['nullable', 'string', ...$tiles],
            'custom_attribution' => ['nullable', 'required_if:provider,'.MapProviders::CUSTOM, 'string', 'max:200'],
            'custom_max_zoom' => ['required', 'integer', 'between:1,22'],
            'custom_tile_size' => ['required', 'integer', Rule::in([256, 512])],

            'default_lat' => ['required', 'numeric', 'between:-90,90'],
            'default_lng' => ['required', 'numeric', 'between:-180,180'],
            'default_zoom' => ['required', 'integer', 'between:2,18'],
        ];
    }

    /**
     * Lo validado con las claves de `PlatformSettingsService`. Un campo vacio queda en `null`.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $data = $this->validated();

        return collect(self::FIELDS)->mapWithKeys(function (string $key, string $field) use ($data) {
            $value = $data[$field] ?? null;

            return [$key => match (true) {
                $value === null || $value === '' => null,
                in_array($field, ['custom_max_zoom', 'custom_tile_size', 'default_zoom'], true) => (int) $value,
                in_array($field, ['default_lat', 'default_lng'], true) => round((float) $value, 7),
                default => $value,
            }];
        })->all();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'google_key.required_if' => 'Pega la llave de API de Google Maps.',
            'google_key.regex' => 'La llave de Google lleva solo letras, números, guiones y guiones bajos.',
            'google_map_id.regex' => 'El Map ID lleva solo letras, números y guiones.',
            'maptiler_key.required_if' => 'Pega la llave de MapTiler.',
            'maptiler_key.regex' => 'La llave de MapTiler lleva solo letras y números.',
            'mapbox_token.required_if' => 'Pega el token público de Mapbox.',
            'mapbox_token.regex' => 'Usa el token público de Mapbox (empieza por «pk.»). El secreto (sk.) nunca va en una página.',
            'custom_url.required_if' => 'Escribe la dirección de las teselas.',
            'custom_url.regex' => 'La dirección empieza por https:// y no lleva espacios ni comillas.',
            'custom_dark_url.regex' => 'La dirección empieza por https:// y no lleva espacios ni comillas.',
            'custom_attribution.required_if' => 'Escribe el crédito que pide el proveedor (por ejemplo «© OpenStreetMap»).',
            'default_lat.between' => 'La latitud va entre -90 y 90.',
            'default_lng.between' => 'La longitud va entre -180 y 180.',
            'default_zoom.between' => 'El acercamiento va entre 2 y 18.',
        ];
    }
}

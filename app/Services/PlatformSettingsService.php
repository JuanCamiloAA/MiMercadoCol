<?php

namespace App\Services;

use App\Models\PlatformSetting;

/**
 * Ajustes de la plataforma, sin empresa. Espejo de `SettingsService`: los defectos viven aqui y
 * no en la base, y una clave que no este en `DEFAULTS` no se guarda.
 */
class PlatformSettingsService
{
    /** @var array<string, mixed> */
    public const DEFAULTS = [
        // Membresias
        'membership.grace_days' => 5,
        'membership.reminder_days' => [7, 3, 1],

        // Plataforma. Sin nombre propio se usa el de la aplicacion.
        'platform.name' => null,
        'platform.support_email' => null,
        'platform.leads_notify_email' => null,

        // Marca de plataforma: la del login con varias empresas y la del consolidado. Ninguna
        // empresa la hereda ni la pisa (`PlatformBrandAssets`). El favicon es `landing.favicon`.
        'platform.brand_accent' => '#0E6E4E',
        'platform.brand_logo' => null,
        'platform.brand_logo_dark' => null,
        'platform.brand_mark' => null,
        'platform.font_body' => 'IBM Plex Sans',
        'platform.font_display' => 'Bricolage Grotesque',
        'platform.font_numeric' => 'IBM Plex Mono',

        // Mapas (Plataforma > Mapas). Las llaves son de navegador: viajan a cada visitante, por
        // eso se restringen al dominio en la consola del proveedor. `MapConfig` las arma.
        'maps.provider' => 'osm',
        'maps.osm.dark' => 'filter',
        'maps.google.key' => null,
        'maps.google.map_id' => null,
        'maps.google.map_type' => 'roadmap',
        'maps.maptiler.key' => null,
        'maps.maptiler.style' => 'streets-v2',
        'maps.maptiler.dark' => 'streets-v2-dark',
        'maps.mapbox.token' => null,
        'maps.mapbox.style' => 'mapbox/streets-v12',
        'maps.mapbox.dark' => 'mapbox/dark-v11',
        'maps.custom.url' => null,
        'maps.custom.dark_url' => null,
        'maps.custom.attribution' => null,
        'maps.custom.max_zoom' => 19,
        'maps.custom.tile_size' => 256,
        // Donde arranca un mapa sin sedes: Colombia.
        'maps.default_lat' => 4.6097,
        'maps.default_lng' => -74.0817,
        'maps.default_zoom' => 6,

        // Pasarelas para empresas (Plataforma > Pasarelas para empresas): que proveedores pueden
        // conectar las empresas a su propia cuenta. Apagar uno lo saca de la caja y la tienda de
        // todas sin tocar sus cuentas. No es la pasarela de las membresias.
        'payments.company_providers' => ['wompi', 'mercadopago', 'payu', 'epayco', 'bold'],

        // Landing publica
        'landing.seo_title' => null,
        'landing.seo_description' => null,
        'landing.seo_image' => null,
        'landing.favicon' => null,
        'landing.whatsapp' => null,
        'landing.terms_text' => "Al usar la plataforma aceptas estas condiciones. Cada tienda es responsable de los precios, la disponibilidad y la entrega de lo que vende.\n\nPuedes cancelar tu cuenta cuando quieras escribiendo al correo de soporte.",
        'landing.privacy_text' => "Tratamos tus datos personales conforme a la Ley 1581 de 2012 y sus decretos reglamentarios.\n\nLos usamos solo para atender tus compras, avisarte sobre tus pedidos y, si lo autorizas, contarte novedades de la tienda que elegiste. Puedes conocer, actualizar, rectificar o suprimir tus datos escribiendo al correo de soporte.",
    ];

    /** @var array<string, mixed>|null */
    protected ?array $cache = null;

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cache === null) {
            $stored = PlatformSetting::query()->pluck('value', 'key')->all();
            $this->cache = array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
        }

        return $this->cache;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default ?? self::DEFAULTS[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        if (! array_key_exists($key, self::DEFAULTS)) {
            return;
        }

        PlatformSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        $this->cache = null;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
    }

    /**
     * El nombre con que se presenta la plataforma.
     */
    public function name(): string
    {
        return (string) ($this->get('platform.name') ?: config('app.name'));
    }
}

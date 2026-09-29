<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\StoreSettingOverride;
use App\Settings\SettingsSchema;
use InvalidArgumentException;

/**
 * Ajustes por empresa, con excepciones por sede para los que las admiten.
 *
 * Los valores por defecto viven aqui y no en la base: una empresa recien creada funciona
 * sin que nadie tenga que sembrarle filas, y anadir un ajuste nuevo no exige migracion.
 * Que pestana edita cada clave y con que reglas lo dice `SettingsSchema`.
 */
class SettingsService
{
    /** @var array<string, mixed> */
    public const DEFAULTS = [
        // Marca. Reproducen lo que el panel mostraba antes de ser configurable.
        'brand.display_name' => 'La Canasta',
        'brand.tagline' => 'Sistema de supermercado',
        'brand.accent' => '#0E6E4E',
        // null = se deriva del acento. Solo para quien quiere afinar el tema oscuro a mano.
        'brand.accent_dark' => null,
        'brand.mark' => null,
        'brand.logo_light' => null,
        'brand.logo_dark' => null,
        'brand.logo_receipt' => null,

        // Apariencia, compartida por el panel y la caja.
        'appearance.font_body' => 'IBM Plex Sans',
        'appearance.font_display' => 'Bricolage Grotesque',
        'appearance.font_numeric' => 'IBM Plex Mono',
        'appearance.radius' => 'soft',
        'appearance.density' => 'cozy',

        // Panel administrativo.
        'panel.default_theme' => 'light',
        'panel.allow_theme_toggle' => true,
        'panel.sidebar_style' => 'surface',
        'panel.sidebar_mode' => 'expanded',
        'panel.ui_scale' => 100,
        'panel.content_width' => 'centered',
        // Solo las diferencias con el menu del codigo: orden, nombres y lo oculto.
        'panel.navigation' => [],
        'panel.home_by_role' => [],

        // Disposicion de la caja.
        'pos.default_theme' => 'dark',
        'pos.allow_theme_toggle' => true,
        'pos.ui_scale' => 100,
        'pos.ticket_side' => 'right',
        'pos.catalog_view' => 'grid',
        'pos.tile_size' => 'md',
        'pos.total_size' => 'normal',
        'pos.rail' => ['sale', 'opening', 'closing'],
        'pos.shortcuts' => [
            'scan' => 'F1',
            'customer' => 'F2',
            'discount' => 'F3',
            'hold' => 'F4',
            'resume' => 'F5',
            'recent' => 'F6',
            'pay' => 'F12',
            'pay_alt' => 'F8',
        ],
        'pos.sounds' => false,

        // Reglas de caja.
        'pos.allow_discount_percent' => 10,
        // Escalon de redondeo del efectivo en pesos. 0 lo apaga.
        'pos.cash_rounding' => 100,

        // Tiquete. Admite excepcion por sede. Los defectos son lo que imprimia la caja.
        'receipt.paper_width' => 80,
        'receipt.font_size' => 'normal',
        'receipt.show_logo' => false,
        'receipt.header' => '',
        'receipt.fields' => ['store_address', 'store_phone', 'cashier', 'seller', 'customer'],
        'receipt.code' => 'none',
        'pos.receipt_footer' => 'Gracias por su compra. Cambios dentro de los 5 dias con este tiquete.',
        'receipt.copies' => 1,
        'receipt.auto_print' => true,

        // Credito.
        'credit.block_on_overdue_days' => 15,

        // Devoluciones de venta: dias para devolver desde la venta (o la entrega, en linea) y
        // dias que dura el saldo a favor. 0 en el saldo: no vence.
        'returns.max_days' => 30,
        'returns.store_credit_days' => 365,
    ];

    /** @var array<int, array<string, mixed>> */
    protected array $cache = [];

    /** @var array<int, array<int, array<string, mixed>>> excepciones por empresa y sede */
    protected array $overrides = [];

    public function all(int $companyId): array
    {
        if (! isset($this->cache[$companyId])) {
            // `pluck` ya aplica el cast `array` del modelo: el valor llega decodificado. Volver a
            // pasarlo por `json_decode` convertia en null todo ajuste de texto.
            $stored = Setting::query()
                ->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->pluck('value', 'key')
                ->all();

            // Una clave vieja que ya no esta en el catalogo no se arrastra.
            $stored = array_intersect_key($stored, self::DEFAULTS);

            $this->cache[$companyId] = array_merge(self::DEFAULTS, $stored);
        }

        return $this->cache[$companyId];
    }

    public function get(int $companyId, string $key, mixed $default = null): mixed
    {
        return $this->all($companyId)[$key] ?? $default ?? self::DEFAULTS[$key] ?? null;
    }

    public function set(int $companyId, string $key, mixed $value): void
    {
        Setting::query()->withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'key' => $key],
            ['value' => $value]
        );

        unset($this->cache[$companyId]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(int $companyId, array $values): void
    {
        foreach ($values as $key => $value) {
            if (array_key_exists($key, self::DEFAULTS)) {
                $this->set($companyId, $key, $value);
            }
        }
    }

    /**
     * Ajustes efectivos de una sede: los de la empresa con las excepciones de la sede encima.
     *
     * Solo se aplican excepciones de claves que `SettingsSchema` reconoce con alcance de sede.
     * Una fila vieja con otra clave se ignora.
     *
     * @return array<string, mixed>
     */
    public function forStore(int $companyId, ?int $storeId): array
    {
        $settings = $this->all($companyId);

        if ($storeId === null) {
            return $settings;
        }

        foreach ($this->overridesFor($companyId, $storeId) as $key => $value) {
            $settings[$key] = $value;
        }

        return $settings;
    }

    /**
     * Las excepciones que tiene una sede, solo las vigentes.
     *
     * @return array<string, mixed>
     */
    public function overridesFor(int $companyId, int $storeId): array
    {
        if (! isset($this->overrides[$companyId])) {
            $this->overrides[$companyId] = [];

            StoreSettingOverride::query()
                ->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->get(['store_id', 'key', 'value'])
                ->each(function (StoreSettingOverride $row) use ($companyId) {
                    if (SettingsSchema::isStoreScoped($row->key) && $row->value !== null) {
                        $this->overrides[$companyId][(int) $row->store_id][$row->key] = $row->value;
                    }
                });
        }

        return $this->overrides[$companyId][$storeId] ?? [];
    }

    /**
     * Guarda o quita (con `null`) la excepcion de una sede. Solo claves con alcance de sede.
     */
    public function setForStore(int $companyId, int $storeId, string $key, mixed $value): void
    {
        if (! SettingsSchema::isStoreScoped($key)) {
            throw new InvalidArgumentException("El ajuste {$key} no admite excepcion por sede.");
        }

        if ($value === null) {
            StoreSettingOverride::query()
                ->withoutGlobalScopes()
                ->where('store_id', $storeId)
                ->where('key', $key)
                ->delete();
        } else {
            StoreSettingOverride::query()->withoutGlobalScopes()->updateOrCreate(
                ['store_id' => $storeId, 'key' => $key],
                ['company_id' => $companyId, 'value' => $value],
            );
        }

        unset($this->overrides[$companyId]);
    }
}

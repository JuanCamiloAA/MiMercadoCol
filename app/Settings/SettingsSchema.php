<?php

namespace App\Settings;

use App\Services\Catalog\CatalogImages;
use Illuminate\Validation\Rule;

/**
 * Una definicion por ajuste: en que pestana se edita, si admite excepcion por sede y con que
 * reglas se valida.
 *
 * Los valores por defecto NO viven aqui: siguen en `SettingsService::DEFAULTS`, como pide la
 * regla del proyecto. `SettingsSchemaTest` verifica que las dos listas tengan las mismas claves.
 */
final class SettingsSchema
{
    public const TAB_BRAND = 'brand';

    public const TAB_APPEARANCE = 'appearance';

    public const TAB_NAVIGATION = 'navigation';

    public const TAB_POS = 'pos';

    public const TAB_POS_RULES = 'pos_rules';

    public const TAB_RECEIPT = 'receipt';

    public const TAB_CREDIT = 'credit';

    public const TAB_CATALOG = 'catalog';

    public const SCOPE_COMPANY = 'company';

    /** Vale para la empresa, y cada sede puede tener su excepcion. */
    public const SCOPE_STORE = 'store';

    public const THEMES = ['light', 'dark', 'system'];

    public const RADII = ['sharp', 'soft', 'round'];

    public const DENSITIES = ['cozy', 'compact'];

    public const SIDEBAR_STYLES = ['surface', 'dark', 'brand'];

    public const SIDEBAR_MODES = ['expanded', 'icons'];

    public const PANEL_SCALES = [90, 95, 100, 105, 110, 115, 120];

    public const CONTENT_WIDTHS = ['centered', 'wide', 'full'];

    public const POS_SCALES = [100, 115, 130];

    public const TICKET_SIDES = ['right', 'left'];

    public const CATALOG_VIEWS = ['grid', 'list', 'search'];

    public const TILE_SIZES = ['sm', 'md', 'lg'];

    public const TOTAL_SIZES = ['normal', 'large'];

    /** Lo que se ve en el riel de la caja. */
    public const RAIL_ITEMS = ['sale', 'opening', 'closing'];

    /** Acciones de la caja que tienen atajo, con el nombre que se lee en los mensajes. */
    public const SHORTCUT_ACTIONS = [
        'scan' => 'enfocar el lector',
        'customer' => 'elegir cliente',
        'discount' => 'descuento',
        'hold' => 'suspender venta',
        'resume' => 'retomar venta',
        'recent' => 'ventas recientes',
        'pay' => 'cobrar',
        'pay_alt' => 'cobrar (segunda tecla)',
    ];

    /** Solo teclas de funcion: una letra chocaria con lo que se escribe en el buscador. */
    public const SHORTCUT_KEYS = ['F1', 'F2', 'F3', 'F4', 'F5', 'F6', 'F7', 'F8', 'F9', 'F10', 'F11', 'F12'];

    public const PAPER_WIDTHS = [58, 80];

    public const RECEIPT_FONT_SIZES = ['small', 'normal', 'large'];

    /** Campos del tiquete que se pueden apagar. NIT, base e IVA no estan: siempre salen. */
    public const RECEIPT_FIELDS = ['store_address', 'store_phone', 'cashier', 'seller', 'customer'];

    public const RECEIPT_CODES = ['none', 'barcode', 'qr'];

    public const RECEIPT_COPIES = [1, 2, 3];

    public const RECEIPT_HEADER_MAX_LINES = 6;

    /** Caracteres por renglon que caben en cada papel con la letra normal. */
    public const RECEIPT_CHARS_PER_LINE = [58 => 32, 80 => 48];

    /**
     * @return array<string, array{tab: string, scope: string, rules?: array<int, mixed>, managed?: bool}>
     */
    public static function definitions(): array
    {
        $in = static fn (array $values): string => 'in:'.implode(',', $values);
        $fonts = static fn (string $group) => Rule::in(array_keys(config("brand_fonts.{$group}", [])));
        $hex = 'regex:/^#[0-9A-Fa-f]{6}$/';

        return [
            // Marca
            'brand.display_name' => ['tab' => self::TAB_BRAND, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', 'string', 'max:40']],
            'brand.tagline' => ['tab' => self::TAB_BRAND, 'scope' => self::SCOPE_COMPANY, 'rules' => ['nullable', 'string', 'max:60']],
            'brand.accent' => ['tab' => self::TAB_BRAND, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', 'string', $hex]],
            'brand.accent_dark' => ['tab' => self::TAB_BRAND, 'scope' => self::SCOPE_COMPANY, 'rules' => ['nullable', 'string', $hex]],
            'brand.mark' => ['tab' => self::TAB_BRAND, 'scope' => self::SCOPE_COMPANY, 'managed' => true],
            'brand.logo_light' => ['tab' => self::TAB_BRAND, 'scope' => self::SCOPE_COMPANY, 'managed' => true],
            'brand.logo_dark' => ['tab' => self::TAB_BRAND, 'scope' => self::SCOPE_COMPANY, 'managed' => true],
            'brand.logo_receipt' => ['tab' => self::TAB_BRAND, 'scope' => self::SCOPE_COMPANY, 'managed' => true],

            // Apariencia
            'appearance.font_body' => ['tab' => self::TAB_APPEARANCE, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', 'string', $fonts('body')]],
            'appearance.font_display' => ['tab' => self::TAB_APPEARANCE, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', 'string', $fonts('display')]],
            'appearance.font_numeric' => ['tab' => self::TAB_APPEARANCE, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', 'string', $fonts('numeric')]],
            'appearance.radius' => ['tab' => self::TAB_APPEARANCE, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', $in(self::RADII)]],
            'appearance.density' => ['tab' => self::TAB_APPEARANCE, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', $in(self::DENSITIES)]],
            'panel.default_theme' => ['tab' => self::TAB_APPEARANCE, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', $in(self::THEMES)]],
            'panel.allow_theme_toggle' => ['tab' => self::TAB_APPEARANCE, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', 'boolean']],
            'panel.sidebar_style' => ['tab' => self::TAB_APPEARANCE, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', $in(self::SIDEBAR_STYLES)]],
            'panel.sidebar_mode' => ['tab' => self::TAB_APPEARANCE, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', $in(self::SIDEBAR_MODES)]],
            'panel.ui_scale' => ['tab' => self::TAB_APPEARANCE, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', 'integer', $in(self::PANEL_SCALES)]],
            'panel.content_width' => ['tab' => self::TAB_APPEARANCE, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', $in(self::CONTENT_WIDTHS)]],

            // Menu
            'panel.navigation' => ['tab' => self::TAB_NAVIGATION, 'scope' => self::SCOPE_COMPANY, 'rules' => ['present', 'array']],
            'panel.home_by_role' => ['tab' => self::TAB_NAVIGATION, 'scope' => self::SCOPE_COMPANY, 'rules' => ['present', 'array']],

            // Caja
            'pos.default_theme' => ['tab' => self::TAB_POS, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', $in(self::THEMES)]],
            'pos.allow_theme_toggle' => ['tab' => self::TAB_POS, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', 'boolean']],
            'pos.ui_scale' => ['tab' => self::TAB_POS, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', 'integer', $in(self::POS_SCALES)]],
            'pos.ticket_side' => ['tab' => self::TAB_POS, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', $in(self::TICKET_SIDES)]],
            'pos.catalog_view' => ['tab' => self::TAB_POS, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', $in(self::CATALOG_VIEWS)]],
            'pos.tile_size' => ['tab' => self::TAB_POS, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', $in(self::TILE_SIZES)]],
            'pos.total_size' => ['tab' => self::TAB_POS, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', $in(self::TOTAL_SIZES)]],
            'pos.rail' => ['tab' => self::TAB_POS, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', 'array']],
            'pos.shortcuts' => ['tab' => self::TAB_POS, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', 'array']],
            'pos.sounds' => ['tab' => self::TAB_POS, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', 'boolean']],

            // Reglas de caja
            'pos.allow_discount_percent' => ['tab' => self::TAB_POS_RULES, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', 'integer', 'min:0', 'max:100']],
            'pos.cash_rounding' => ['tab' => self::TAB_POS_RULES, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', 'integer', 'in:0,50,100']],
            // `sometimes`: un guardado de antes, que solo trae las reglas de caja, no las pierde.
            'returns.max_days' => ['tab' => self::TAB_POS_RULES, 'scope' => self::SCOPE_COMPANY, 'rules' => ['sometimes', 'required', 'integer', 'min:1', 'max:365']],
            'returns.store_credit_days' => ['tab' => self::TAB_POS_RULES, 'scope' => self::SCOPE_COMPANY, 'rules' => ['sometimes', 'required', 'integer', 'min:0', 'max:1825']],
            'payments.online_hold_minutes' => ['tab' => self::TAB_POS_RULES, 'scope' => self::SCOPE_COMPANY, 'rules' => ['sometimes', 'required', 'integer', 'min:5', 'max:1440']],
            'payments.pos_link_minutes' => ['tab' => self::TAB_POS_RULES, 'scope' => self::SCOPE_COMPANY, 'rules' => ['sometimes', 'required', 'integer', 'min:2', 'max:120']],

            // Tiquete
            'receipt.paper_width' => ['tab' => self::TAB_RECEIPT, 'scope' => self::SCOPE_STORE, 'rules' => ['required', 'integer', $in(self::PAPER_WIDTHS)]],
            'receipt.font_size' => ['tab' => self::TAB_RECEIPT, 'scope' => self::SCOPE_STORE, 'rules' => ['required', $in(self::RECEIPT_FONT_SIZES)]],
            'receipt.show_logo' => ['tab' => self::TAB_RECEIPT, 'scope' => self::SCOPE_STORE, 'rules' => ['required', 'boolean']],
            'receipt.header' => ['tab' => self::TAB_RECEIPT, 'scope' => self::SCOPE_STORE, 'rules' => ['nullable', 'string', 'max:400']],
            'receipt.fields' => ['tab' => self::TAB_RECEIPT, 'scope' => self::SCOPE_STORE, 'rules' => ['present', 'array']],
            'receipt.code' => ['tab' => self::TAB_RECEIPT, 'scope' => self::SCOPE_STORE, 'rules' => ['required', $in(self::RECEIPT_CODES)]],
            'pos.receipt_footer' => ['tab' => self::TAB_RECEIPT, 'scope' => self::SCOPE_STORE, 'rules' => ['required', 'string', 'max:200']],
            'receipt.copies' => ['tab' => self::TAB_RECEIPT, 'scope' => self::SCOPE_STORE, 'rules' => ['required', 'integer', $in(self::RECEIPT_COPIES)]],
            'receipt.auto_print' => ['tab' => self::TAB_RECEIPT, 'scope' => self::SCOPE_STORE, 'rules' => ['required', 'boolean']],

            // Credito
            'credit.block_on_overdue_days' => ['tab' => self::TAB_CREDIT, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', 'integer', 'min:1', 'max:120']],

            // Catalogo
            'catalog.max_images' => ['tab' => self::TAB_CATALOG, 'scope' => self::SCOPE_COMPANY, 'rules' => ['required', 'integer', 'min:1', 'max:'.CatalogImages::MAX_LIMIT]],
            // Lo que «Tienda en linea» llama lote por vencer, poca rotacion y margen bajo. Opcionales en
            // el PUT: un formulario que solo manda las fotos no los borra.
            'storefront_catalog.expiring_days' => ['tab' => self::TAB_CATALOG, 'scope' => self::SCOPE_COMPANY, 'rules' => ['sometimes', 'required', 'integer', 'min:1', 'max:90']],
            'storefront_catalog.overstock_days' => ['tab' => self::TAB_CATALOG, 'scope' => self::SCOPE_COMPANY, 'rules' => ['sometimes', 'required', 'integer', 'min:7', 'max:365']],
            'storefront_catalog.overstock_min_factor' => ['tab' => self::TAB_CATALOG, 'scope' => self::SCOPE_COMPANY, 'rules' => ['sometimes', 'required', 'integer', 'min:1', 'max:20']],
            'storefront_catalog.low_margin_percent' => ['tab' => self::TAB_CATALOG, 'scope' => self::SCOPE_COMPANY, 'rules' => ['sometimes', 'required', 'integer', 'min:0', 'max:90']],
        ];
    }

    /**
     * Reglas del PUT de una pestana: excluye las gestionadas, que tienen su propio endpoint.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(string $tab): array
    {
        $rules = [];

        foreach (self::definitions() as $key => $definition) {
            if ($definition['tab'] === $tab && ! ($definition['managed'] ?? false)) {
                $rules[$key] = $definition['rules'] ?? [];
            }
        }

        return $rules;
    }

    /**
     * Claves que se editan en una pestana, sin las gestionadas.
     *
     * @return list<string>
     */
    public static function keysFor(string $tab): array
    {
        return array_keys(self::rulesFor($tab));
    }

    public static function isStoreScoped(string $key): bool
    {
        return (self::definitions()[$key]['scope'] ?? null) === self::SCOPE_STORE;
    }

    /**
     * @return list<string>
     */
    public static function storeScopedKeys(): array
    {
        return array_keys(array_filter(
            self::definitions(),
            fn (array $definition) => $definition['scope'] === self::SCOPE_STORE,
        ));
    }

    /**
     * Toma del arreglo validado —anidado, porque el validador lee `brand.accent` como
     * `brand[accent]`— los valores de las claves de una pestana, ya planos.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function pluck(string $tab, array $validated): array
    {
        $values = [];

        foreach (self::keysFor($tab) as $key) {
            [$prefix, $name] = explode('.', $key, 2);

            if (is_array($validated[$prefix] ?? null) && array_key_exists($name, $validated[$prefix])) {
                $values[$key] = $validated[$prefix][$name];
            }
        }

        return $values;
    }
}

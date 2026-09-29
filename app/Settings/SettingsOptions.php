<?php

namespace App\Settings;

/**
 * Las opciones de cada ajuste con el texto que se lee en pantalla.
 *
 * Viajan como prop de Ajustes para que la pantalla no tenga listas escritas a mano: los valores
 * salen de las mismas constantes de `SettingsSchema` que valida el servidor, asi que lo que se
 * ofrece y lo que se acepta no se pueden separar.
 */
final class SettingsOptions
{
    /** Colores sugeridos para la marca: todos se leen como texto sobre blanco sin ajuste. */
    /** El texto de cada boton de la caja que tiene atajo. */
    public const SHORTCUT_BUTTONS = [
        'scan' => 'Código',
        'customer' => 'Cliente',
        'discount' => 'Descuento',
        'hold' => 'Suspender',
        'resume' => 'Recuperar',
        'recent' => 'Devolución',
        'pay' => 'Cobrar',
        'pay_alt' => 'Cobrar',
    ];

    public const SWATCHES = ['#0E6E4E', '#1D7457', '#0F5F8A', '#3949AB', '#7A2E8E', '#A8301F', '#9C4A1A', '#3A4A5C'];

    /**
     * @return array<string, list<array{value: string|int, label: string, hint?: string}>>
     */
    public static function all(): array
    {
        $themes = [
            ['value' => 'light', 'label' => 'Claro'],
            ['value' => 'dark', 'label' => 'Oscuro'],
            ['value' => 'system', 'label' => 'Como el sistema'],
        ];

        return [
            'panel.default_theme' => $themes,
            'pos.default_theme' => $themes,
            'appearance.radius' => [
                ['value' => 'sharp', 'label' => 'Rectas'],
                ['value' => 'soft', 'label' => 'Suaves'],
                ['value' => 'round', 'label' => 'Redondas'],
            ],
            'appearance.density' => [
                ['value' => 'cozy', 'label' => 'Cómoda', 'hint' => 'La de siempre.'],
                ['value' => 'compact', 'label' => 'Compacta', 'hint' => 'Más filas por pantalla en tablas y formularios.'],
            ],
            'panel.sidebar_style' => [
                ['value' => 'surface', 'label' => 'Como el panel'],
                ['value' => 'dark', 'label' => 'Oscuro'],
                ['value' => 'brand', 'label' => 'Color de la marca'],
            ],
            'panel.sidebar_mode' => [
                ['value' => 'expanded', 'label' => 'Completo'],
                ['value' => 'icons', 'label' => 'Solo íconos'],
            ],
            'panel.ui_scale' => array_map(
                fn (int $scale) => ['value' => $scale, 'label' => "{$scale} %"],
                SettingsSchema::PANEL_SCALES,
            ),
            'panel.content_width' => [
                ['value' => 'centered', 'label' => 'Centrado'],
                ['value' => 'wide', 'label' => 'Amplio'],
                ['value' => 'full', 'label' => 'Todo el ancho'],
            ],
            'pos.ui_scale' => [
                ['value' => 100, 'label' => 'Normal'],
                ['value' => 115, 'label' => 'Grande'],
                ['value' => 130, 'label' => 'Muy grande'],
            ],
            'pos.ticket_side' => [
                ['value' => 'left', 'label' => 'Izquierda'],
                ['value' => 'right', 'label' => 'Derecha'],
            ],
            'pos.catalog_view' => [
                ['value' => 'grid', 'label' => 'Cuadrícula', 'hint' => 'Teclas con foto y precio, como siempre.'],
                ['value' => 'list', 'label' => 'Lista', 'hint' => 'Una fila por artículo: caben más en la pantalla.'],
                ['value' => 'search', 'label' => 'Solo búsqueda', 'hint' => 'Sin catálogo a la vista: se escanea o se busca, y aparecen los resultados.'],
            ],
            'pos.tile_size' => [
                ['value' => 'sm', 'label' => 'Pequeñas'],
                ['value' => 'md', 'label' => 'Medianas'],
                ['value' => 'lg', 'label' => 'Grandes'],
            ],
            'pos.total_size' => [
                ['value' => 'normal', 'label' => 'Normal'],
                ['value' => 'large', 'label' => 'Grande'],
            ],
            'pos.rail' => [
                ['value' => 'sale', 'label' => 'Vender'],
                ['value' => 'opening', 'label' => 'Apertura'],
                ['value' => 'closing', 'label' => 'Cierre'],
            ],
            'pos.shortcuts' => array_map(
                fn (string $action, string $label) => [
                    'value' => $action,
                    'label' => mb_strtoupper(mb_substr($label, 0, 1)).mb_substr($label, 1),
                    // Lo que dice el boton en la caja.
                    'short' => self::SHORTCUT_BUTTONS[$action] ?? null,
                ],
                array_keys(SettingsSchema::SHORTCUT_ACTIONS),
                SettingsSchema::SHORTCUT_ACTIONS,
            ),
            'pos.shortcut_keys' => array_map(
                fn (string $key) => ['value' => $key, 'label' => $key],
                SettingsSchema::SHORTCUT_KEYS,
            ),
            'pos.cash_rounding' => [
                ['value' => 0, 'label' => 'Sin redondeo, se cobra exacto'],
                ['value' => 50, 'label' => 'A la cincuentena más cercana'],
                ['value' => 100, 'label' => 'A la centena más cercana'],
            ],
            'receipt.paper_width' => array_map(
                fn (int $width) => ['value' => $width, 'label' => "{$width} mm"],
                SettingsSchema::PAPER_WIDTHS,
            ),
            'receipt.font_size' => [
                ['value' => 'small', 'label' => 'Pequeña'],
                ['value' => 'normal', 'label' => 'Normal'],
                ['value' => 'large', 'label' => 'Grande'],
            ],
            'receipt.fields' => [
                ['value' => 'store_address', 'label' => 'Dirección de la sede'],
                ['value' => 'store_phone', 'label' => 'Teléfono de la sede'],
                ['value' => 'cashier', 'label' => 'Cajero'],
                ['value' => 'seller', 'label' => 'Vendedor', 'hint' => 'Solo cuando es otro distinto del cajero.'],
                ['value' => 'customer', 'label' => 'Cliente'],
            ],
            'receipt.code' => [
                ['value' => 'none', 'label' => 'Ninguno'],
                ['value' => 'barcode', 'label' => 'Barras'],
                ['value' => 'qr', 'label' => 'QR'],
            ],
            'receipt.copies' => array_map(
                fn (int $copies) => ['value' => $copies, 'label' => $copies === 1 ? '1 copia' : "{$copies} copias"],
                SettingsSchema::RECEIPT_COPIES,
            ),
        ];
    }
}

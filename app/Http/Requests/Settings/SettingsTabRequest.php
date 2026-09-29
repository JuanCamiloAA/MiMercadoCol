<?php

namespace App\Http\Requests\Settings;

use App\Settings\SettingsSchema;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base de los formularios de Ajustes: una pestana, sus reglas y sus mensajes.
 *
 * Las reglas salen de `SettingsSchema`, asi que cada formulario valida solo las claves de su
 * pestana: un `PUT /ajustes/caja` no puede colar `brand.accent`. El permiso lo exige la ruta.
 *
 * Los datos llegan anidados (`{brand: {accent: ...}}`): el validador lee `brand.accent` como
 * `brand[accent]`, y una clave con punto literal no la encontraria.
 */
abstract class SettingsTabRequest extends FormRequest
{
    abstract protected function tab(): string;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return SettingsSchema::rulesFor($this->tab());
    }

    /**
     * Los valores de la pestana, planos (`brand.accent` => '#0E6E4E'), listos para guardar.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return SettingsSchema::pluck($this->tab(), $this->validated());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => 'Falta :attribute.',
            'present' => 'Falta :attribute.',
            'string' => ':Attribute debe ser texto.',
            'max.string' => ':Attribute admite máximo :max caracteres.',
            'max.numeric' => ':Attribute no puede pasar de :max.',
            'min.numeric' => ':Attribute no puede ser menor que :min.',
            'integer' => ':Attribute debe ser un número entero.',
            'boolean' => ':Attribute debe ser sí o no.',
            'array' => ':Attribute no tiene el formato esperado.',
            'in' => ':Attribute no es una de las opciones.',
            'regex' => ':Attribute va en formato #RRGGBB.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'brand.display_name' => 'el nombre visible',
            'brand.tagline' => 'el subtítulo',
            'brand.accent' => 'el color de la marca',
            'brand.accent_dark' => 'el color para el tema oscuro',
            'appearance.font_body' => 'la letra del texto',
            'appearance.font_display' => 'la letra de los títulos',
            'appearance.font_numeric' => 'la letra de las cifras',
            'appearance.radius' => 'la forma de las esquinas',
            'appearance.density' => 'la densidad',
            'panel.default_theme' => 'el tema inicial del panel',
            'panel.allow_theme_toggle' => 'el interruptor de tema del panel',
            'panel.sidebar_style' => 'el estilo del menú',
            'panel.sidebar_mode' => 'el modo del menú',
            'panel.ui_scale' => 'el tamaño del panel',
            'panel.content_width' => 'el ancho del contenido',
            'panel.navigation' => 'el menú',
            'panel.home_by_role' => 'la página de inicio por rol',
            'pos.default_theme' => 'el tema inicial de la caja',
            'pos.allow_theme_toggle' => 'el interruptor de tema de la caja',
            'pos.ui_scale' => 'el tamaño de la caja',
            'pos.ticket_side' => 'el lado del tiquete',
            'pos.catalog_view' => 'la vista del catálogo',
            'pos.tile_size' => 'el tamaño de las teclas',
            'pos.total_size' => 'el tamaño del total',
            'pos.rail' => 'el orden del riel',
            'pos.shortcuts' => 'los atajos de teclado',
            'pos.sounds' => 'los sonidos',
            'pos.allow_discount_percent' => 'el descuento máximo',
            'pos.cash_rounding' => 'el redondeo del efectivo',
            'returns.max_days' => 'el plazo para devolver',
            'returns.store_credit_days' => 'la vigencia del saldo a favor',
            'receipt.paper_width' => 'el ancho del papel',
            'receipt.font_size' => 'el tamaño de la letra',
            'receipt.show_logo' => 'imprimir el logo',
            'receipt.header' => 'el encabezado',
            'receipt.fields' => 'lo que sale impreso',
            'receipt.code' => 'el código al pie',
            'pos.receipt_footer' => 'el pie del tiquete',
            'receipt.copies' => 'las copias',
            'receipt.auto_print' => 'la impresión automática',
            'credit.block_on_overdue_days' => 'los días de mora',
        ];
    }
}

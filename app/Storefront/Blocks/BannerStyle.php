<?php

namespace App\Storefront\Blocks;

/**
 * El diseño de cada banner dentro de un bloque (una lamina del banner principal, un banner del
 * mosaico): proporcion, donde va el texto, tamaño del titulo, forma del velo y estilo del boton.
 *
 * Se guarda en `item.style` y solo con lo que alguien cambio: `auto` (o la clave ausente) es lo
 * que el bloque ya pintaba, asi un banner que nunca toco «Diseño de esta lamina» se ve igual que
 * antes. El color del texto y el velo de las laminas siguen en la fila (`text_color`, `overlay`),
 * donde ya vivian; se pintan en el mismo grupo del formulario (`group: 'design'`).
 */
final class BannerStyle
{
    public const AUTO = 'auto';

    /** Las proporciones. En el banner principal manda el alto de la seccion si lo tiene. */
    public const RATIOS = [
        'auto' => 'La del bloque',
        'square' => 'Cuadrado (1:1)',
        'portrait' => 'Vertical (3:4)',
        'tall' => 'Alto (3:4,2)',
        'landscape' => 'Apaisado (16:9)',
        'wide' => 'Panorámico (21:9)',
    ];

    public const POSITIONS = [
        'auto' => 'La del bloque',
        'top-left' => 'Arriba a la izquierda',
        'top-center' => 'Arriba al centro',
        'top-right' => 'Arriba a la derecha',
        'middle-left' => 'Al centro a la izquierda',
        'middle-center' => 'En el centro',
        'middle-right' => 'Al centro a la derecha',
        'bottom-left' => 'Abajo a la izquierda',
        'bottom-center' => 'Abajo al centro',
        'bottom-right' => 'Abajo a la derecha',
    ];

    public const TITLE_SIZES = [
        'auto' => 'El del bloque',
        'sm' => 'Pequeño',
        'md' => 'Mediano',
        'lg' => 'Grande',
        'xl' => 'Muy grande',
        'display' => 'Gigante, en mayúsculas',
    ];

    public const OVERLAY_STYLES = [
        'auto' => 'El del bloque',
        'flat' => 'Parejo sobre toda la foto',
        'bottom' => 'Degradado desde abajo',
        'top' => 'Degradado desde arriba',
    ];

    public const BUTTON_STYLES = [
        'auto' => 'El del bloque',
        'solid' => 'Lleno, del color de la marca',
        'outline' => 'Solo el borde',
        'pill_white' => 'Píldora blanca',
        'link' => 'Enlace subrayado',
    ];

    /** Que lista de opciones va con cada clave. */
    protected const OPTIONS = [
        'ratio' => self::RATIOS,
        'text_position' => self::POSITIONS,
        'title_size' => self::TITLE_SIZES,
        'overlay_style' => self::OVERLAY_STYLES,
        'button_style' => self::BUTTON_STYLES,
    ];

    protected const LABELS = [
        'ratio' => 'Proporción',
        'text_position' => 'Dónde va el texto',
        'title_size' => 'Tamaño del título',
        'overlay_style' => 'Forma del velo',
        'button_style' => 'Botón',
    ];

    /**
     * El campo `style` de un repetidor, con las claves que tienen sentido para ese banner (un
     * mosaico no tiene boton, un banner lateral mide lo que mide el principal).
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function field(array $keys, string $label = 'Diseño de esta lámina'): array
    {
        $fields = [];

        foreach ($keys as $key) {
            $fields[$key] = [
                'type' => 'select',
                'label' => self::LABELS[$key],
                'options' => array_map(fn (string $value, string $text) => ['value' => $value, 'label' => $text], array_keys(self::OPTIONS[$key]), array_values(self::OPTIONS[$key])),
            ];
        }

        return ['type' => 'style', 'label' => $label, 'group' => 'design', 'fields' => $fields];
    }

    /**
     * Solo lo que vale y no es `auto`: un banner sin cambios no guarda nada.
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, string>
     */
    public static function clean(mixed $value, array $fields): array
    {
        if (! is_array($value)) {
            return [];
        }

        $clean = [];

        foreach ($fields as $key => $field) {
            $chosen = $value[$key] ?? null;

            if (is_string($chosen) && $chosen !== self::AUTO && array_key_exists($chosen, self::OPTIONS[$key] ?? [])) {
                $clean[$key] = $chosen;
            }
        }

        return $clean;
    }
}

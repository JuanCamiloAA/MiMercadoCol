<?php

namespace App\Support;

/**
 * Las cuatro versiones de una imagen o un video de banner: computador y celular, en modo claro y
 * en modo oscuro.
 *
 * La de computador en modo claro es la de siempre (la clave del campo); las otras tres son claves
 * hermanas con nombre fijo. Asi lo ya guardado no cambia de forma, y quien recorre los ids de un
 * bloque (publicar, pintar, independizarse) tambien las encuentra.
 *
 *   Tienda (ids de la biblioteca; terminan como pide `BlockData::isMediaKey`):
 *     media_id       → media_mobile_id · dark_media_id · dark_media_mobile_id
 *     video_media_id → video_media_mobile_id · video_dark_media_id · video_dark_media_mobile_id
 *   Landing (rutas bajo `landing/`):
 *     image → image_mobile · image_dark · image_mobile_dark
 *
 * Ninguna hermana es obligatoria. En modo oscuro, si falta la de celular se usa la oscura de
 * computador, y si no hay ninguna oscura, las claras; en modo claro, sin la de celular se recorta
 * la de computador. `media-variants.ts` hace la misma cuenta en el navegador.
 */
final class MediaVariants
{
    public const MOBILE = 'mobile';

    public const DARK = 'dark';

    public const MOBILE_DARK = 'mobile_dark';

    public const ALL = [self::MOBILE, self::DARK, self::MOBILE_DARK];

    public const LABELS = [
        'base' => 'Computador · claro',
        self::MOBILE => 'Celular · claro',
        self::DARK => 'Computador · oscuro',
        self::MOBILE_DARK => 'Celular · oscuro',
    ];

    public const SURFACE_STORE = 'store';

    public const SURFACE_LANDING = 'landing';

    /** La clave de una version en la tienda, a partir de la principal (`media_id`, `video_media_id`). */
    public static function storeKey(string $base, string $variant): string
    {
        $prefix = str_ends_with($base, 'media_id') ? substr($base, 0, -strlen('media_id')) : $base.'_';

        return match ($variant) {
            self::MOBILE => $prefix.'media_mobile_id',
            self::DARK => $prefix.'dark_media_id',
            default => $prefix.'dark_media_mobile_id',
        };
    }

    /** La clave de una version en la landing (`image` → `image_mobile_dark`). */
    public static function landingKey(string $base, string $variant): string
    {
        return $base.'_'.$variant;
    }

    /**
     * Un campo de imagen o video con sus versiones.
     *
     * El principal lleva `variants` (version → clave) y cada hermana `variant_of`: el editor pinta
     * las cuatro juntas y el resto del sistema las trata como un campo mas (limpiar, revisar,
     * pintar, copiar al independizarse).
     *
     * @param  array<string, mixed>  $field
     * @param  list<string>  $variants
     * @return array<string, array<string, mixed>>
     */
    public static function field(string $key, array $field, string $surface = self::SURFACE_STORE, array $variants = self::ALL): array
    {
        $map = [];

        foreach ($variants as $variant) {
            $map[$variant] = $surface === self::SURFACE_STORE ? self::storeKey($key, $variant) : self::landingKey($key, $variant);
        }

        $fields = [$key => array_merge($field, ['variants' => $map])];

        foreach ($map as $variant => $sibling) {
            $copy = $field;
            unset($copy['help'], $copy['required'], $copy['default']);
            $fields[$sibling] = array_merge($copy, [
                'label' => $field['label'].' ('.mb_strtolower(self::LABELS[$variant]).')',
                'variant_of' => $key,
                'variant' => $variant,
            ]);
        }

        return $fields;
    }

    /**
     * Las hermanas de un esquema, en nulo: los defectos de un bloque no las repiten una por una.
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, null>
     */
    public static function defaults(array $fields): array
    {
        $defaults = [];

        foreach ($fields as $key => $field) {
            if (isset($field['variant_of'])) {
                $defaults[$key] = null;
            }
        }

        return $defaults;
    }

    /**
     * Las claves de las versiones oscuras de un esquema (principal → su oscura de computador).
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, string>
     */
    public static function darkKeys(array $fields): array
    {
        $keys = [];

        foreach ($fields as $key => $field) {
            if (isset($field['variants'][self::DARK])) {
                $keys[$key] = $field['variants'][self::DARK];
            }
        }

        return $keys;
    }

    /**
     * Cuantas imagenes o videos principales de unos datos no tienen version para modo oscuro, en
     * el primer nivel y dentro de las listas. Sirve para avisar, nunca para bloquear.
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $data
     */
    public static function missingDark(array $fields, array $data): int
    {
        $missing = 0;

        foreach (self::darkKeys($fields) as $key => $dark) {
            if (! empty($data[$key]) && empty($data[$dark]) && empty($data[$fields[$key]['variants'][self::MOBILE_DARK] ?? ''])) {
                $missing++;
            }
        }

        foreach ($fields as $key => $field) {
            if (($field['type'] ?? null) === 'repeater' && is_array($data[$key] ?? null)) {
                foreach ($data[$key] as $row) {
                    $missing += is_array($row) ? self::missingDark($field['item'] ?? [], $row) : 0;
                }
            }
        }

        return $missing;
    }
}

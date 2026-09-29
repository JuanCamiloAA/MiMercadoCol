<?php

namespace App\Support;

/**
 * Aritmetica de color compartida por la tienda en linea y la marca del panel.
 *
 * `resources/js/lib/color.ts` repite estas mismas cuentas para avisar mientras se arrastra el
 * selector. Si una cambia, cambia la otra: si no, la vista previa promete un color y el
 * servidor guarda otro.
 */
final class Color
{
    /** La letra oscura que se pone encima de un color claro. */
    public const DARK_INK = '#10161C';

    /**
     * `#abc` o `abc` a `#AABBCC`. Lo que no sea un color devuelve negro.
     */
    public static function normalize(string $hex): string
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return '#000000';
        }

        return '#'.strtoupper($hex);
    }

    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1;
    }

    /**
     * Luminancia relativa segun WCAG.
     */
    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::channels($hex);

        $linear = static fn (int $value): float => ($value / 255) <= 0.03928
            ? ($value / 255) / 12.92
            : ((($value / 255) + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $linear($r) + 0.7152 * $linear($g) + 0.0722 * $linear($b);
    }

    /**
     * Contraste real entre dos colores (no contra «el mejor de blanco o negro»).
     */
    public static function contrast(string $a, string $b): float
    {
        $first = self::luminance($a);
        $second = self::luminance($b);

        return round((max($first, $second) + 0.05) / (min($first, $second) + 0.05), 2);
    }

    /**
     * Mezcla lineal en sRGB; $t = 0 devuelve $a, $t = 1 devuelve $b.
     */
    public static function mix(string $a, string $b, float $t): string
    {
        $t = max(0.0, min(1.0, $t));
        $from = self::channels($a);
        $to = self::channels($b);

        $mixed = array_map(
            static fn (int $x, int $y): int => (int) round($x + ($y - $x) * $t),
            $from,
            $to,
        );

        return sprintf('#%02X%02X%02X', ...$mixed);
    }

    /**
     * Letra encima del color: blanca o #10161C, la que mejor se lea.
     *
     * Se decide contra blanco y negro puros, como siempre lo hizo la tienda: cambiar el umbral
     * le cambiaria la letra a acentos que ya estan publicados.
     */
    public static function ink(string $hex): string
    {
        $luminance = self::luminance($hex);

        return (1.05 / ($luminance + 0.05)) >= (($luminance + 0.05) / 0.05) ? '#FFFFFF' : self::DARK_INK;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    protected static function channels(string $hex): array
    {
        $hex = ltrim(self::normalize($hex), '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}

<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * La llave con que se compara lo que el cliente escribe en el buscador de la tienda.
 *
 * Quita tildes y mayusculas, y ademas junta lo que en espanol suena igual y se escribe
 * distinto: «arros» encuentra «Arroz», «aseite» encuentra «Aceite», «javon» encuentra «Jabón».
 * Quien busca desde el celular escribe como habla; comparar letra por letra lo deja sin nada.
 *
 * `products.search_name` guarda esta llave (nombre + marca), y lo que se busca se pasa por la
 * misma funcion antes de compararlo. Si cambias las reglas, recalcula la columna.
 */
final class SearchText
{
    public static function key(?string $text): string
    {
        $text = Str::lower(Str::ascii(trim((string) $text)));
        $text = (string) preg_replace('/[^a-z0-9]+/', ' ', $text);

        $text = (string) preg_replace(
            ['/ch/', '/h/', '/ll/', '/qu(?=[ei])/', '/c(?=[ei])/', '/g(?=[ei])/', '/[cq]/', '/z/', '/v/', '/w/', '/y\b/'],
            ['x', '', 'y', 'k', 's', 'j', 'k', 's', 'b', 'u', 'i'],
            $text
        );

        // Las dobles suenan como una: «arroz» y «aros» son la misma llave.
        $text = (string) preg_replace('/([a-z])\1+/', '$1', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Las palabras de una busqueda, ya como llave, sin las que no dicen nada.
     *
     * @return list<string>
     */
    public static function terms(?string $text): array
    {
        return array_values(array_filter(
            explode(' ', self::key($text)),
            fn (string $term) => mb_strlen($term) >= 2 && ! in_array($term, ['de', 'la', 'el', 'los', 'las', 'en', 'por', 'kon'], true)
        ));
    }
}

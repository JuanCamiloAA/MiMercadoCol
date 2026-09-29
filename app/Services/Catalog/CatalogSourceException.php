<?php

namespace App\Services\Catalog;

use RuntimeException;

/**
 * La fuente externa no respondio, o respondio algo que no se puede usar.
 *
 * Se lanza en vez de devolver un arreglo a medias: media respuesta se propaga en silencio y
 * termina creando articulos con los campos en blanco.
 */
class CatalogSourceException extends RuntimeException
{
    public static function unreachable(string $detail): self
    {
        return new self('No se pudo consultar el catálogo externo: '.$detail);
    }

    public static function badResponse(int $status): self
    {
        return new self('El catálogo externo respondió con un error '.$status.'.');
    }

    public static function malformed(): self
    {
        return new self('El catálogo externo respondió algo que no se pudo leer.');
    }
}

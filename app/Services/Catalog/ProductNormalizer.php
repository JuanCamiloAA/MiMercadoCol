<?php

namespace App\Services\Catalog;

/**
 * Convierte el payload crudo de la fuente externa en algo que el catalogo pueda usar.
 *
 * Es una clase pura a proposito: no toca base de datos, ni cache, ni configuracion. Aqui vive
 * la mitad del trabajo de este modulo —los datos publicos vienen sucios— y poder probarla sin
 * levantar Laravel es lo que permite cubrir cada caso raro sin que las pruebas tarden.
 */
class ProductNormalizer
{
    public const MAX_NAME = 200;

    public const MAX_DESCRIPTION = 255;

    public const MAX_BRAND = 100;

    public const MAX_PACKAGING = 50;

    /** Las unidades que entiende el catalogo, con sus variantes escritas en la fuente. */
    private const UNITS = [
        'kg' => ['kg', 'kgs', 'kilo', 'kilos', 'kilogramo', 'kilogramos', 'kilogram'],
        'g' => ['g', 'gr', 'grs', 'gramo', 'gramos', 'gram', 'grams'],
        'l' => ['l', 'lt', 'lts', 'litro', 'litros', 'liter', 'litre'],
        'ml' => ['ml', 'mls', 'cc', 'mililitro', 'mililitros'],
    ];

    /**
     * @param  array<string, mixed>  $raw
     */
    public function normalize(array $raw): NormalizedProduct
    {
        $ean = $this->ean($raw['code'] ?? null);
        $name = $this->name($raw);

        if ($ean === null) {
            return NormalizedProduct::rejected('invalid_ean', null, $name, $raw);
        }

        if ($name === null) {
            return NormalizedProduct::rejected('missing_name', $ean, null, $raw);
        }

        $packaging = $this->packaging($raw['quantity'] ?? null);
        [$amount, $unit] = $this->content($packaging);

        return NormalizedProduct::usable(
            ean: $ean,
            name: $name,
            description: $this->description($raw, $name),
            brand: $this->brand($raw['brands'] ?? null),
            packaging: $packaging,
            contentAmount: $amount,
            contentUnit: $unit,
            externalCategory: $this->externalCategory($raw['categories_tags'] ?? null),
            imageUrl: $this->imageUrl($raw['image_front_small_url'] ?? null),
            payload: $raw,
        );
    }

    /**
     * El codigo de barras, limpio y validado.
     *
     * Siempre string. Un `(int)` en cualquier punto del camino se come los ceros de la
     * izquierda —que son significativos— y el escaner de caja deja de encontrar el articulo.
     * Es el bug clasico de este modulo y por eso el tipo de retorno tambien es string.
     */
    public function ean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Excel y algunos volcados anteponen una comilla para forzar el texto.
        $ean = trim((string) $value, " \t\n\r\0\x0B'\"");
        $ean = preg_replace('/\s+/', '', $ean) ?? '';

        if (! preg_match('/^\d{8}$|^\d{12}$|^\d{13}$|^\d{14}$/', $ean)) {
            return null;
        }

        if (! $this->hasValidCheckDigit($ean)) {
            return null;
        }

        // El UPC de doce digitos es un EAN-13 con un cero al frente. Guardarlo en un solo
        // formato evita que el mismo articulo entre dos veces segun quien lo escanee.
        return strlen($ean) === 12 ? '0'.$ean : $ean;
    }

    /**
     * Digito de verificacion GTIN.
     *
     * Desde la derecha, sin contar el verificador, se alternan pesos 3 y 1; el verificador es
     * lo que falta para la siguiente decena.
     */
    public function hasValidCheckDigit(string $ean): bool
    {
        $digits = array_map('intval', str_split($ean));
        $check = array_pop($digits);
        $sum = 0;

        foreach (array_reverse($digits) as $index => $digit) {
            $sum += $digit * ($index % 2 === 0 ? 3 : 1);
        }

        return $check === (10 - $sum % 10) % 10;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    protected function name(array $raw): ?string
    {
        foreach (['product_name', 'generic_name', 'product_name_es'] as $key) {
            $value = $this->collapse($raw[$key] ?? null);

            if ($value !== null) {
                return $this->cut($value, self::MAX_NAME);
            }
        }

        return null;
    }

    /**
     * La descripcion sale de `generic_name`, salvo que ya se haya usado como nombre: repetir
     * la misma frase en los dos campos no le dice nada a nadie.
     *
     * @param  array<string, mixed>  $raw
     */
    protected function description(array $raw, string $name): ?string
    {
        $generic = $this->collapse($raw['generic_name'] ?? null);

        if ($generic === null || $this->cut($generic, self::MAX_NAME) === $name) {
            return null;
        }

        return $this->cut($generic, self::MAX_DESCRIPTION);
    }

    /**
     * `brands` es un string con marcas separadas por coma, no un array. Se toma la primera.
     */
    public function brand(mixed $value): ?string
    {
        $brands = $this->collapse($value);

        if ($brands === null) {
            return null;
        }

        $first = $this->collapse(explode(',', $brands)[0] ?? null);

        return $first === null ? null : $this->cut($first, self::MAX_BRAND);
    }

    protected function packaging(mixed $value): ?string
    {
        $packaging = $this->collapse($value);

        return $packaging === null ? null : $this->cut($packaging, self::MAX_PACKAGING);
    }

    /**
     * Parte «1 L» o «500 g» en cantidad y unidad, que es como el catalogo calcula el precio
     * por litro o por kilo. Lo que no se entienda se queda solo como texto de presentacion.
     *
     * @return array{0: float|null, 1: string|null}
     */
    public function content(?string $packaging): array
    {
        if ($packaging === null) {
            return [null, null];
        }

        if (! preg_match('/(\d+(?:[.,]\d+)?)\s*([a-zA-Z]+)/u', $packaging, $matches)) {
            return [null, null];
        }

        $amount = (float) str_replace(',', '.', $matches[1]);
        $written = mb_strtolower($matches[2]);

        foreach (self::UNITS as $unit => $variants) {
            if (in_array($written, $variants, true)) {
                return [$amount, $unit];
            }
        }

        return [null, null];
    }

    /**
     * `categories_tags` llega como `["en:dairies", ...]`: se quita el prefijo de idioma y los
     * guiones pasan a espacios.
     */
    public function externalCategory(mixed $value): ?string
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        $tag = $this->collapse($value[0] ?? null);

        if ($tag === null) {
            return null;
        }

        $tag = preg_replace('/^[a-z]{2,3}:/i', '', $tag) ?? $tag;

        return $this->collapse(str_replace('-', ' ', $tag));
    }

    protected function imageUrl(mixed $value): ?string
    {
        $url = $this->collapse($value);

        if ($url === null || ! str_starts_with($url, 'https://')) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) === false ? null : $url;
    }

    /** Colapsa espacios y devuelve null si no queda nada: «   » no es un nombre. */
    protected function collapse(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $clean = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $clean === '' ? null : $clean;
    }

    protected function cut(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }
}

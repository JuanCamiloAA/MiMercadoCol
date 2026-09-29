<?php

namespace App\Storefront;

/**
 * A donde lleva un boton, un enlace del menu o del pie de la tienda.
 *
 * Un destino es `{kind, value}`: una categoria (por id), una pagina de la tienda (de una lista
 * cerrada), una busqueda, un enlace o WhatsApp (con el numero de la sede). El editor lo guarda
 * asi y el renderizador le agrega el `href` ya armado: el navegador nunca compone direcciones.
 */
final class Targets
{
    public const KIND_CATEGORY = 'category';

    public const KIND_PAGE = 'page';

    public const KIND_SEARCH = 'search';

    public const KIND_URL = 'url';

    public const KIND_WHATSAPP = 'whatsapp';

    /** @var list<string> */
    public const KINDS = [self::KIND_CATEGORY, self::KIND_PAGE, self::KIND_SEARCH, self::KIND_URL, self::KIND_WHATSAPP];

    /** Lo que admite una plantilla: nada que apunte a una fila de una empresa. */
    public const TEMPLATE_KINDS = [self::KIND_PAGE, self::KIND_SEARCH, self::KIND_URL, self::KIND_WHATSAPP];

    /**
     * Las paginas a las que se puede enlazar. Son rutas fijas de la tienda o de la plataforma.
     *
     * @var array<string, string>
     */
    public const PAGES = [
        '/tienda' => 'Portada de la tienda',
        '/tienda/ofertas' => 'Ofertas',
        '/tienda/buscar' => 'Todo el surtido',
        '/tienda/carrito' => 'Carrito',
        '/tienda/pedidos' => 'Mis pedidos',
        '/tienda/registro' => 'Crear cuenta',
        '/terminos' => 'Términos y condiciones',
        '/privacidad' => 'Tratamiento de datos personales',
    ];

    /**
     * Deja un destino con su forma, o `null` si no sirve.
     *
     * @param  list<string>|null  $kinds  Los tipos que admite este campo.
     * @return array{kind: string, value: string}|null
     */
    public static function sanitize(mixed $target, ?array $kinds = null): ?array
    {
        if (! is_array($target)) {
            return null;
        }

        $kind = (string) ($target['kind'] ?? '');
        $value = trim((string) ($target['value'] ?? ''));

        if (! in_array($kind, $kinds ?? self::KINDS, true)) {
            return null;
        }

        $clean = match ($kind) {
            self::KIND_CATEGORY => ctype_digit($value) && (int) $value > 0 ? $value : null,
            self::KIND_PAGE => array_key_exists($value, self::PAGES) ? $value : null,
            self::KIND_SEARCH => mb_substr($value, 0, 60),
            self::KIND_URL => self::safeUrl($value),
            // En WhatsApp el valor es el mensaje con que abre la conversacion; puede ir vacio.
            self::KIND_WHATSAPP => mb_substr($value, 0, 200),
            default => null,
        };

        if ($clean === null || ($clean === '' && ! in_array($kind, [self::KIND_WHATSAPP, self::KIND_SEARCH], true))) {
            return null;
        }

        return ['kind' => $kind, 'value' => $clean];
    }

    /**
     * Solo enlaces de verdad: http, https, correo, telefono o una ruta de este mismo sitio.
     * Un `javascript:` en un boton de la tienda seria un agujero.
     */
    public static function safeUrl(string $value): ?string
    {
        if ($value === '' || mb_strlen($value) > 300) {
            return null;
        }

        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return $value;
        }

        return preg_match('#^(https?://[^\s]+|mailto:[^\s]+|tel:[0-9+ ]+)$#i', $value) === 1 ? $value : null;
    }

    /**
     * La direccion ya armada.
     *
     * @param  array<int, string>  $categorySlugs  `[id => slug]` de la empresa.
     */
    public static function href(?array $target, array $categorySlugs = [], ?string $whatsapp = null): ?string
    {
        if (! $target) {
            return null;
        }

        $value = (string) ($target['value'] ?? '');

        return match ($target['kind'] ?? null) {
            self::KIND_CATEGORY => isset($categorySlugs[(int) $value]) ? '/tienda/categoria/'.$categorySlugs[(int) $value] : null,
            self::KIND_PAGE => array_key_exists($value, self::PAGES) ? $value : null,
            self::KIND_SEARCH => '/tienda/buscar'.($value !== '' ? '?q='.rawurlencode($value) : ''),
            self::KIND_URL => self::safeUrl($value),
            self::KIND_WHATSAPP => $whatsapp ? 'https://wa.me/'.$whatsapp.($value !== '' ? '?text='.rawurlencode($value) : '') : null,
            default => null,
        };
    }

    /**
     * El numero de WhatsApp en el formato de wa.me: solo digitos y con el indicativo de
     * Colombia si viene sin el.
     */
    public static function whatsappNumber(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (strlen($digits) === 10 && str_starts_with($digits, '3')) {
            return '57'.$digits;
        }

        return strlen($digits) >= 11 ? $digits : null;
    }

    /**
     * Las opciones de pagina para el editor.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function pageOptions(): array
    {
        return collect(self::PAGES)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all();
    }
}

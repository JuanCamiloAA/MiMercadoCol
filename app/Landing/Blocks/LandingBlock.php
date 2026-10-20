<?php

namespace App\Landing\Blocks;

use App\Support\MediaVariants;
use App\Support\SectionAppearance;
use App\Support\Uploads;

/**
 * Un tipo de bloque de la landing de plataforma.
 *
 * Como en la tienda: el editor arma el formulario solo con `fields()`, el servidor guarda solo
 * lo que declara `defaults()`, y el front lo pinta en `LandingBlocks.tsx`. Los datos que vienen
 * de la base (sedes, empresas, planes) no se escriben aqui: los resuelve `LandingRenderer`.
 *
 * Tipos de campo: text · textarea · url · toggle · select · image · video · number · icon · color · repeater
 *
 * Un `video` se sube aparte (`LandingAdminController::uploadVideo`) y se guarda su ruta, como una
 * imagen. Un `number` lleva `min`, `max` y `step`; vacio queda en `null`.
 *
 * Claves opcionales de cualquier campo, que el formulario lee sin saber de qué bloque se trata:
 * - `section`: subtítulo del grupo («Diseño», «Contenido», «Mapa»); sale cuando cambia de un campo al siguiente.
 * - `when`: `['clave' => ['valor', …]]`. El campo solo se ve si el valor actual de cada clave está en
 *   su lista. Ocultar no borra: `normalize()` conserva el valor, y volver a mostrarlo lo trae como estaba.
 * - En un select, cada opción puede llevar su propio `when` para ofrecer solo las que valen. El
 *   formulario ayuda; el servidor manda: lo que no valga lo corrige `normalize()` del bloque.
 */
abstract class LandingBlock
{
    abstract public function type(): string;

    abstract public function label(): string;

    /** Icono de Phosphor del editor, sin el sufijo `Icon`. */
    abstract public function icon(): string;

    abstract public function help(): string;

    /** @return array<string, array<string, mixed>> */
    abstract public function fields(): array;

    /** @return array<string, mixed> */
    abstract public function defaults(): array;

    /** Solo puede haber uno en la pagina. */
    public function unique(): bool
    {
        return false;
    }

    /** Se puede borrar. Los obligatorios no. */
    public function deletable(): bool
    {
        return true;
    }

    /** Se puede ocultar. */
    public function hideable(): bool
    {
        return true;
    }

    /** Advertencia al ocultarlo, si la hay. */
    public function hideWarning(): ?string
    {
        return null;
    }

    /**
     * Deja los datos con la forma exacta del tipo: descarta lo que sobra y completa lo que falta.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalize(array $data): array
    {
        $data = $this->upgrade($data);
        $clean = [];

        // Las versiones de una imagen o un video (celular, modo oscuro) no se repiten en los defectos.
        foreach ($this->defaults() + MediaVariants::defaults($this->fields()) as $key => $default) {
            $value = array_key_exists($key, $data) ? $data[$key] : $default;
            $field = $this->fields()[$key] ?? null;
            $clean[$key] = $field ? $this->cleanValue($field, $value, $default) : $value;
        }

        // El diseño de la seccion: solo lo que alguien cambio, y solo si el tipo lo admite.
        $appearance = $this->supportsAppearance()
            ? SectionAppearance::normalize($data['appearance'] ?? null, $this->appearanceDefaults(), SectionAppearance::SURFACE_LANDING)
            : [];

        if ($appearance !== []) {
            $clean['appearance'] = $appearance;
        }

        return $clean;
    }

    /**
     * Lleva datos guardados con una forma vieja a la de hoy. Por defecto no cambia nada.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function upgrade(array $data): array
    {
        return $data;
    }

    /**
     * Si el bloque lleva la pestaña «Diseño». El encabezado y el pie no: el encabezado es fijo al
     * desplazar, y envolverlo en una seccion le quitaria lo fijo.
     */
    public function supportsAppearance(): bool
    {
        return true;
    }

    /**
     * El diseño que tiene el tipo sin que nadie lo toque. En la landing las secciones van de borde
     * a borde: ese es el marco de fabrica.
     *
     * @return array<string, mixed>
     */
    public function appearanceDefaults(): array
    {
        return ['frame' => 'bleed'];
    }

    /**
     * El fondo de antes (`tone`: light/dark/custom + `background_color`) llevado a `appearance`.
     * Lo usan los bloques que lo tenian (`upgrade()`) y la migracion que paso los fondos guardados.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function upgradeTone(array $data): array
    {
        if (! array_key_exists('tone', $data)) {
            return $data;
        }

        $appearance = is_array($data['appearance'] ?? null) ? $data['appearance'] : [];

        if (! array_key_exists('bg_type', $appearance)) {
            $appearance = array_merge($appearance, self::legacyTone($data['tone'], $data['background_color'] ?? null));
        }

        unset($data['tone'], $data['background_color']);
        $data['appearance'] = $appearance;

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public static function legacyTone(mixed $tone, mixed $color): array
    {
        return match ($tone) {
            'dark' => ['bg_type' => 'color', 'bg_color' => 'dark'],
            'custom' => is_string($color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1
                ? ['bg_type' => 'color', 'bg_color' => strtoupper($color)]
                : ['bg_type' => 'none'],
            default => ['bg_type' => 'none'],
        };
    }

    /** Si el campo guarda un archivo de la landing (imagen o video). */
    public static function isMedia(array $field): bool
    {
        return in_array($field['type'] ?? null, ['image', 'video'], true);
    }

    /**
     * Le suma a cada imagen o video su direccion publica (`logo` → `logo_url`), tambien dentro de
     * las listas (`slides.0.image` → `slides.0.image_url`). Es lo que pinta la landing.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function withMediaUrls(array $data): array
    {
        // Tambien los fondos del diseño de la seccion (`appearance.bg_image` → `appearance.bg_image_url`),
        // y los de modo oscuro (`appearance.dark.bg_image_url`).
        if (is_array($data['appearance'] ?? null)) {
            foreach (SectionAppearance::MEDIA_KEYS[SectionAppearance::SURFACE_LANDING] as $key) {
                $data['appearance'][$key.'_url'] = self::mediaUrl($data['appearance'][$key] ?? null);

                // Solo los que oscuro cambia: los demás heredan la dirección de claro al resolverse.
                if (is_array($data['appearance'][SectionAppearance::DARK_KEY] ?? null) && array_key_exists($key, $data['appearance'][SectionAppearance::DARK_KEY])) {
                    $data['appearance'][SectionAppearance::DARK_KEY][$key.'_url'] = self::mediaUrl($data['appearance'][SectionAppearance::DARK_KEY][$key]);
                }
            }
        }

        foreach ($this->fields() as $key => $field) {
            if (self::isMedia($field)) {
                $data[$key.'_url'] = self::mediaUrl($data[$key] ?? null);
            } elseif ($field['type'] === 'repeater' && is_array($data[$key] ?? null)) {
                $data[$key] = array_map(function (mixed $item) use ($field) {
                    foreach ($field['item'] as $sub => $subField) {
                        if (is_array($item) && self::isMedia($subField)) {
                            $item[$sub.'_url'] = self::mediaUrl($item[$sub] ?? null);
                        }
                    }

                    return $item;
                }, $data[$key]);
            }
        }

        return $data;
    }

    /**
     * Las direcciones que necesita el editor para mostrar lo ya subido: por clave las del bloque
     * (`video`) y por ruta las de las listas, que no tienen una clave fija.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    public function mediaUrlMap(array $data): array
    {
        $urls = [];

        // Los fondos del diseño van por ruta, como los de las listas: no tienen una clave fija del bloque.
        $appearance = is_array($data['appearance'] ?? null) ? $data['appearance'] : [];
        $dark = is_array($appearance[SectionAppearance::DARK_KEY] ?? null) ? $appearance[SectionAppearance::DARK_KEY] : [];

        foreach (SectionAppearance::MEDIA_KEYS[SectionAppearance::SURFACE_LANDING] as $key) {
            foreach ([$appearance[$key] ?? null, $dark[$key] ?? null] as $path) {
                if (is_string($path) && $path !== '') {
                    $urls[$path] = self::mediaUrl($path);
                }
            }
        }

        foreach ($this->fields() as $key => $field) {
            if (self::isMedia($field)) {
                $urls[$key] = self::mediaUrl($data[$key] ?? null);
            } elseif ($field['type'] === 'repeater') {
                foreach ((array) ($data[$key] ?? []) as $item) {
                    foreach ($field['item'] as $sub => $subField) {
                        $path = is_array($item) && self::isMedia($subField) ? ($item[$sub] ?? null) : null;

                        if (is_string($path) && $path !== '') {
                            $urls[$path] = self::mediaUrl($path);
                        }
                    }
                }
            }
        }

        return $urls;
    }

    protected static function mediaUrl(mixed $path): ?string
    {
        return is_string($path) && $path !== '' ? Uploads::url($path) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toCatalogue(): array
    {
        return [
            'type' => $this->type(),
            'label' => $this->label(),
            'icon' => $this->icon(),
            'help' => $this->help(),
            'fields' => $this->fields(),
            'defaults' => $this->defaults(),
            'unique' => $this->unique(),
            'deletable' => $this->deletable(),
            'supports_appearance' => $this->supportsAppearance(),
            'appearance_defaults' => SectionAppearance::defaults($this->appearanceDefaults(), SectionAppearance::SURFACE_LANDING),
            'hideable' => $this->hideable(),
            'hide_warning' => $this->hideWarning(),
        ];
    }

    /**
     * Un valor con el tipo de su campo. Texto recortado a su maximo, URL sin esquemas raros,
     * listas con sus subcampos: la landing es publica y se pinta tal cual.
     *
     * @param  array<string, mixed>  $field
     */
    protected function cleanValue(array $field, mixed $value, mixed $default): mixed
    {
        return match ($field['type']) {
            'text', 'textarea' => mb_substr(trim((string) ($value ?? '')), 0, (int) ($field['max'] ?? 500)),
            'url' => $this->cleanUrl($value),
            'image', 'video' => is_string($value) && preg_match('#^landing/[A-Za-z0-9/_\-.]+$#', $value) ? $value : null,
            'number' => $this->cleanNumber($field, $value),
            'toggle' => (bool) $value,
            'select' => in_array($value, array_column($field['options'] ?? [], 'value'), true) ? $value : $default,
            'icon' => in_array($value, (array) config('landing_icons'), true) ? $value : $default,
            'color' => is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? strtoupper($value) : $default,
            'repeater' => $this->cleanItems($field, $value),
            default => $value,
        };
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<array<string, mixed>>
     */
    protected function cleanItems(array $field, mixed $value): array
    {
        $items = [];

        foreach (array_slice(is_array($value) ? array_values($value) : [], 0, (int) ($field['max_items'] ?? 12)) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $row = [];

            foreach ($field['item'] as $key => $sub) {
                $row[$key] = $this->cleanValue($sub, $item[$key] ?? null, $sub['default'] ?? null);
            }

            $items[] = $row;
        }

        return $items;
    }

    /**
     * Un numero dentro de su rango, redondeado a su paso. Vacio o invalido queda en `null`.
     *
     * @param  array<string, mixed>  $field
     */
    protected function cleanNumber(array $field, mixed $value): int|float|null
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $step = (float) ($field['step'] ?? 1);
        $number = min((float) ($field['max'] ?? PHP_INT_MAX), max((float) ($field['min'] ?? 0), (float) $value));
        $number = $step > 0 ? round($number / $step) * $step : $number;

        return floor($number) == $number ? (int) $number : round($number, 2);
    }

    /**
     * Enlaces internos (`/contratar`, `#tiendas`), `mailto:`, `tel:`, `https://` y `http://`.
     * Nada de `javascript:` ni esquemas raros en una pagina publica.
     */
    protected function cleanUrl(mixed $value): string
    {
        $url = trim((string) ($value ?? ''));

        if ($url === '') {
            return '';
        }

        return preg_match('#^(/(?!/)|\#|https?://|mailto:|tel:)#i', $url) ? mb_substr($url, 0, 300) : '';
    }

    /** @return array<string, mixed> */
    protected static function repeater(string $label, string $singular, array $item, int $max = 8): array
    {
        return ['type' => 'repeater', 'label' => $label, 'singular' => $singular, 'max_items' => $max, 'item' => $item];
    }
}

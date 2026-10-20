<?php

namespace App\Storefront\Blocks;

use App\Models\StorefrontBlock;
use App\Storefront\Targets;
use App\Support\MediaVariants;
use App\Support\SectionAppearance;
use App\Support\VideoOptions;
use Carbon\CarbonImmutable;

/**
 * Definicion de un tipo de bloque.
 *
 * Un tipo no existe si no tiene las tres piezas: este DTO, su formulario en el editor —que se
 * arma solo con `fields()`— y su renderizador en la tienda. Aqui viven las dos primeras.
 *
 * Tipos de campo que entiende el editor:
 *   text · textarea · markdown · number · toggle · select · media · video · color · icon · target
 *   repeater · categories · products · datetime
 *
 * `video` guarda el id de un video de la biblioteca, como `media` el de una imagen; su clave
 * termina en `_media_id` (`video_media_id`) para que publicar, pintar e independizarse lo
 * encuentren igual que a una imagen. `color` es un hexadecimal.
 *
 * Cada campo puede llevar `show_if` (`{field, value}`; `value` puede ser una lista) y los de
 * tipo `target`, los destinos que admite (`kinds`).
 *
 * Contexto: en la tienda de una sede (`store`) o en la plantilla de la plataforma
 * (`template`). Una plantilla no tiene catalogo: sus bloques no pueden apuntar a articulos,
 * categorias ni promociones por id. `fields('template')` no los ofrece y `violations()` dice
 * cuales trae un payload, para rechazarlo.
 */
abstract class BlockData
{
    public const CONTEXT_STORE = 'store';

    public const CONTEXT_TEMPLATE = 'template';

    /** Por encima de esto una imagen pesa de mas para un celular con datos. */
    public const HEAVY_IMAGE_BYTES = 1048576;

    abstract public function type(): string;

    abstract public function label(): string;

    /** Icono de Phosphor, sin el sufijo `Icon`. */
    abstract public function icon(): string;

    abstract public function help(): string;

    /**
     * Esquema de los campos, en el orden en que se editan.
     *
     * @return array<string, array<string, mixed>>
     */
    abstract public function fields(string $context = self::CONTEXT_STORE): array;

    /**
     * @return array<string, mixed>
     */
    abstract public function defaults(): array;

    /**
     * En que paginas de la tienda se puede usar.
     *
     * @return list<string>
     */
    public function pages(): array
    {
        return [StorefrontBlock::PAGE_HOME];
    }

    /**
     * Deja el payload con la forma exacta del tipo.
     *
     * El editor manda lo que tenga en pantalla; aqui se descarta lo que sobra, se completa lo
     * que falta y cada campo se limpia segun su esquema, para que un bloque guardado nunca
     * llegue incompleto ni con basura al renderizador.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalize(array $payload, string $context = self::CONTEXT_STORE): array
    {
        $payload = self::upgradeBackground($this->upgrade($payload));
        $fields = $this->fields($context);
        $clean = [];

        // Las versiones de una imagen (celular, modo oscuro) no se repiten en los defectos de cada tipo.
        foreach ($this->defaults() + MediaVariants::defaults($fields) as $key => $default) {
            if (! array_key_exists($key, $payload)) {
                $clean[$key] = $default;

                continue;
            }

            // Lo que la plantilla no ofrece vuelve a su valor por defecto.
            if (! isset($fields[$key])) {
                $clean[$key] = $context === self::CONTEXT_TEMPLATE && $this->isIdField($key) ? $default : $payload[$key];

                continue;
            }

            $clean[$key] = $this->cleanField($fields[$key], $payload[$key], $default);
        }

        // Comun a todos: en la pagina de categoria, un bloque puede limitarse a unas categorias.
        $clean['only_categories'] = $context === self::CONTEXT_TEMPLATE ? [] : $this->ids($payload['only_categories'] ?? []);

        // El diseño de la seccion: solo lo que alguien cambio. Un bloque que nunca toco la pestaña
        // «Diseño» no lleva la clave, y su payload queda igual que antes de que existiera.
        $appearance = SectionAppearance::normalize($payload['appearance'] ?? null, $this->appearanceDefaults(), SectionAppearance::SURFACE_STORE);

        if ($appearance !== []) {
            $clean['appearance'] = $appearance;
        }

        return $clean;
    }

    /**
     * El diseño de la seccion que tiene el tipo sin que nadie lo toque: lo que hoy pinta. Solo
     * las claves que cambian respecto a lo neutro (`SectionAppearance::BASE`).
     *
     * @return array<string, mixed>
     */
    public function appearanceDefaults(): array
    {
        return [];
    }

    /**
     * El fondo de antes (`background`: none/soft/dark/brand/custom + `background_color`) llevado a
     * `appearance`. Lo usan `normalize()` con lo que llegue sin migrar (una version vieja que se
     * restaura) y la migracion que paso los fondos guardados.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function upgradeBackground(array $payload): array
    {
        if (! array_key_exists('background', $payload)) {
            return $payload;
        }

        $appearance = is_array($payload['appearance'] ?? null) ? $payload['appearance'] : [];

        if (! array_key_exists('bg_type', $appearance)) {
            $appearance = array_merge($appearance, self::legacyBackground($payload['background'], $payload['background_color'] ?? null));
        }

        unset($payload['background'], $payload['background_color']);
        $payload['appearance'] = $appearance;

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    public static function legacyBackground(mixed $background, mixed $color): array
    {
        return match ($background) {
            'soft' => ['bg_type' => 'color', 'bg_color' => 'soft'],
            'dark' => ['bg_type' => 'color', 'bg_color' => 'brand_dark'],
            'brand' => ['bg_type' => 'color', 'bg_color' => 'brand'],
            'custom' => is_string($color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1
                ? ['bg_type' => 'color', 'bg_color' => strtoupper($color)]
                : ['bg_type' => 'none'],
            default => ['bg_type' => 'none'],
        };
    }

    /**
     * Lleva un payload guardado con una forma vieja a la de hoy. Por defecto no cambia nada.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function upgrade(array $payload): array
    {
        return $payload;
    }

    /**
     * Lo que en una plantilla no puede ir: referencias por id a filas de una empresa.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function violations(array $payload): array
    {
        $found = [];

        if ($this->ids($payload['only_categories'] ?? []) !== []) {
            $found[] = 'Una plantilla no puede limitar un bloque a categorías de una empresa.';
        }

        foreach ($this->targetsIn($payload) as $target) {
            if (($target['kind'] ?? null) === Targets::KIND_CATEGORY) {
                $found[] = 'Una plantilla no puede enlazar a una categoría por su id: usa una búsqueda o una página.';

                break;
            }
        }

        return array_values(array_unique([...$found, ...$this->ownViolations($payload)]));
    }

    /**
     * Las referencias por id propias del tipo (articulos, categorias, promociones).
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    protected function ownViolations(array $payload): array
    {
        return [];
    }

    /**
     * Como se nombra este bloque en la lista, con su regla.
     *
     * Dos vitrinas tienen que poder distinguirse sin abrirlas.
     *
     * @param  array<string, mixed>  $payload
     */
    public function summary(array $payload): string
    {
        return $this->label();
    }

    /**
     * Si el bloque no tiene nada que mostrar todavia.
     *
     * @param  array<string, mixed>  $payload
     */
    public function isEmpty(array $payload): bool
    {
        return false;
    }

    /**
     * Problemas del bloque antes de publicar.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, array{alt: string|null, bytes: int|null}>  $media  Los medios que usa, por id.
     * @return list<array{level: 'block'|'warn', message: string}>
     */
    public function issues(array $payload, array $media = [], string $context = self::CONTEXT_STORE): array
    {
        $issues = $this->ownIssues($payload, $media);

        if ($context === self::CONTEXT_TEMPLATE) {
            foreach ($this->violations($payload) as $violation) {
                $issues[] = ['level' => 'block', 'message' => $violation];
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array{alt: string|null, bytes: int|null}>  $media
     * @return list<array{level: 'block'|'warn', message: string}>
     */
    protected function ownIssues(array $payload, array $media): array
    {
        return [];
    }

    /** Si una clave del payload guarda una imagen: `media_id` o `media_mobile_id`, en cualquier nivel. */
    public static function isMediaKey(mixed $key): bool
    {
        return is_string($key) && preg_match('/(^|_)media(_mobile)?_id$/', $key) === 1;
    }

    /**
     * Los ids de imagen que usa el bloque, en cualquier nivel.
     *
     * @param  array<string, mixed>  $payload
     * @return list<int>
     */
    public function mediaIds(array $payload): array
    {
        $ids = [];

        array_walk_recursive($payload, function ($value, $key) use (&$ids) {
            if (BlockData::isMediaKey($key) && is_numeric($value) && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        });

        return array_values(array_unique($ids));
    }

    /**
     * Ficha del tipo para el editor.
     *
     * @return array<string, mixed>
     */
    public function toCatalogue(string $context = self::CONTEXT_STORE): array
    {
        return [
            'type' => $this->type(),
            'label' => $this->label(),
            'icon' => $this->icon(),
            'help' => $this->help(),
            'pages' => $this->pages(),
            'fields' => $this->fields($context),
            'defaults' => $this->normalize($this->defaults(), $context),
            // Lo que vale cada clave del diseño sin tocarlo: la pestaña «Diseño» lo muestra.
            'appearance_defaults' => SectionAppearance::defaults($this->appearanceDefaults(), SectionAppearance::SURFACE_STORE),
        ];
    }

    /** Un texto recortado, para los resumenes. */
    protected function short(?string $text, int $length = 40): string
    {
        $text = trim((string) $text);

        return $text === '' ? '' : mb_strimwidth($text, 0, $length, '…');
    }

    /**
     * Revisa las imagenes de una lamina: que exista, que tenga texto alternativo y que no pese
     * de mas.
     *
     * @param  array<int, array{alt: string|null, bytes: int|null}>  $media
     * @return list<array{level: 'block'|'warn', message: string}>
     */
    protected function imageIssues(mixed $id, array $media, string $where, bool $required = true): array
    {
        if (! is_numeric($id) || (int) $id <= 0) {
            return $required ? [['level' => 'block', 'message' => $where.' no tiene imagen.']] : [];
        }

        $asset = $media[(int) $id] ?? null;

        if ($asset === null) {
            return [['level' => 'block', 'message' => $where.' usa una imagen que ya no está en la biblioteca.']];
        }

        $issues = [];

        if (trim((string) ($asset['alt'] ?? '')) === '') {
            $issues[] = ['level' => 'block', 'message' => $where.' no tiene texto alternativo.'];
        }

        if ((int) ($asset['bytes'] ?? 0) > self::HEAVY_IMAGE_BYTES) {
            $issues[] = ['level' => 'warn', 'message' => $where.' pesa más de 1 MB: en celular tardará en cargar.'];
        }

        return $issues;
    }

    /**
     * Revisa un video: que exista en la biblioteca y que no pese de mas. La descripcion se pide
     * al subirlo, asi que aqui no puede faltar.
     *
     * @param  array<int, array{alt: string|null, bytes: int|null}>  $media
     * @return list<array{level: 'block'|'warn', message: string}>
     */
    protected function videoIssues(mixed $id, array $media, string $where, bool $required = true): array
    {
        if (! is_numeric($id) || (int) $id <= 0) {
            return $required ? [['level' => 'block', 'message' => $where.' no tiene video.']] : [];
        }

        $asset = $media[(int) $id] ?? null;

        if ($asset === null) {
            return [['level' => 'block', 'message' => $where.' usa un video que ya no está en la biblioteca.']];
        }

        if ((int) ($asset['bytes'] ?? 0) > VideoOptions::HEAVY_VIDEO_BYTES) {
            return [['level' => 'warn', 'message' => $where.' pesa más de '.(VideoOptions::HEAVY_VIDEO_BYTES / 1048576).' MB: en celular tardará en arrancar.']];
        }

        return [];
    }

    /**
     * Si una fecha de vigencia deja la lamina por fuera hoy.
     *
     * @param  array<string, mixed>  $item
     */
    public static function itemIsLive(array $item, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        try {
            $from = ! empty($item['visible_from']) ? CarbonImmutable::parse((string) $item['visible_from']) : null;
            $to = ! empty($item['visible_to']) ? CarbonImmutable::parse((string) $item['visible_to']) : null;
        } catch (\Throwable) {
            return true;
        }

        return ! ($from && $from->gt($now)) && ! ($to && $to->lt($now));
    }

    /**
     * @return list<int>
     */
    protected function ids(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', array_filter($value, 'is_numeric')), fn (int $id) => $id > 0)));
    }

    /** Los campos que guardan ids de filas de una empresa. */
    protected function isIdField(string $key): bool
    {
        return in_array($key, ['product_ids', 'category_ids', 'promotion_id', 'value', 'only_categories'], true);
    }

    /**
     * Todos los destinos de un payload, en cualquier nivel.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    protected function targetsIn(array $payload): array
    {
        $found = [];

        $walk = function (array $node) use (&$walk, &$found) {
            if (isset($node['kind'], $node['value']) && in_array($node['kind'], Targets::KINDS, true)) {
                $found[] = $node;

                return;
            }

            foreach ($node as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };

        $walk($payload);

        return $found;
    }

    /**
     * Limpia un valor segun su campo.
     *
     * @param  array<string, mixed>  $field
     */
    protected function cleanField(array $field, mixed $value, mixed $default): mixed
    {
        return match ($field['type'] ?? 'text') {
            'text', 'textarea', 'markdown' => $value === null ? $default : mb_substr(trim((string) $value), 0, (int) ($field['max'] ?? 2000)),
            'number' => is_numeric($value)
                ? max((int) ($field['min'] ?? PHP_INT_MIN), min((int) ($field['max'] ?? PHP_INT_MAX), (int) $value))
                : $default,
            'toggle' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default,
            'select' => $this->cleanSelect($field, $value, $default),
            'media', 'video' => is_numeric($value) && (int) $value > 0 ? (int) $value : null,
            'color' => is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1 ? strtoupper($value) : $default,
            'icon' => is_string($value) && in_array($value, config('storefront_icons'), true) ? $value : $default,
            'target' => Targets::sanitize($value, $field['kinds'] ?? null),
            'categories', 'products' => $this->ids($value),
            'datetime' => $this->cleanDate($value),
            'repeater' => $this->cleanRepeater($field, $value),
            'style' => BannerStyle::clean($value, $field['fields'] ?? []),
            default => $value,
        };
    }

    /**
     * @param  array<string, mixed>  $field
     */
    protected function cleanSelect(array $field, mixed $value, mixed $default): mixed
    {
        // Las opciones que salen de la base (categorias, promociones) se validan al pintar.
        if (isset($field['options_from'])) {
            return is_numeric($value) && (int) $value > 0 ? (string) (int) $value : null;
        }

        foreach ($field['options'] ?? [] as $option) {
            if ((string) $option['value'] === (string) $value) {
                return $option['value'];
            }
        }

        return $default;
    }

    protected function cleanDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->format('Y-m-d\TH:i');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<array<string, mixed>>
     */
    protected function cleanRepeater(array $field, mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $schema = $field['item'] ?? [];
        $defaults = $field['item_defaults'] ?? [];
        $rows = [];

        foreach (array_slice(array_values(array_filter($value, 'is_array')), 0, (int) ($field['max_items'] ?? 24)) as $row) {
            $clean = [];

            foreach ($schema as $key => $item) {
                $clean[$key] = array_key_exists($key, $row)
                    ? $this->cleanField($item, $row[$key], $defaults[$key] ?? null)
                    : ($defaults[$key] ?? null);

                // El diseño del banner sin cambios no se guarda: la fila queda como antes de existir.
                if (($item['type'] ?? null) === 'style' && ($clean[$key] === [] || $clean[$key] === null)) {
                    unset($clean[$key]);
                }
            }

            // Lo que no se edita pero viaja con la fila (una imagen heredada) se conserva.
            foreach ($field['keep'] ?? [] as $key) {
                if (array_key_exists($key, $row)) {
                    $clean[$key] = $row[$key];
                }
            }

            // El diseño de la lámina en modo oscuro: solo lo que alguien cambió; lo demás, como en claro.
            $dark = $this->cleanDarkDesign($schema, $row['dark'] ?? null);

            if ($dark !== []) {
                $clean['dark'] = $dark;
            }

            $rows[] = $clean;
        }

        return $rows;
    }

    /**
     * Lo que cambia el diseño de una lámina en modo oscuro (`fila.dark`): los mismos campos de su
     * grupo «Diseño» (color del texto, velo, estilo), limpios con su esquema. Lo vacío no se
     * guarda: en modo oscuro vale lo de modo claro.
     *
     * @param  array<string, array<string, mixed>>  $schema
     * @return array<string, mixed>
     */
    protected function cleanDarkDesign(array $schema, mixed $dark): array
    {
        if (! is_array($dark)) {
            return [];
        }

        $clean = [];

        foreach ($schema as $key => $field) {
            if (($field['group'] ?? null) !== 'design' || ! array_key_exists($key, $dark) || $dark[$key] === null || $dark[$key] === '') {
                continue;
            }

            $value = $this->cleanField($field, $dark[$key], null);

            if ($value !== null && $value !== '' && $value !== []) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * Lo que conviene saber de las versiones de una imagen o un video (celular, modo oscuro):
     * ninguna es obligatoria, pero la que esté puesta tiene que existir y describirse.
     *
     * @param  array<string, array<string, mixed>>  $fields  El esquema donde vive el campo (el bloque o el ítem de una lista).
     * @param  array<string, mixed>  $data
     * @param  array<int, array{alt: string|null, bytes: int|null}>  $media
     * @return list<array{level: 'block'|'warn', message: string}>
     */
    protected function variantIssues(array $fields, string $key, array $data, array $media, string $where): array
    {
        $issues = [];
        $video = ($fields[$key]['type'] ?? null) === 'video';

        foreach ($fields[$key]['variants'] ?? [] as $variant => $sibling) {
            $label = $where.' ('.mb_strtolower(MediaVariants::LABELS[$variant]).')';
            array_push($issues, ...($video
                ? $this->videoIssues($data[$sibling] ?? null, $media, $label, required: false)
                : $this->imageIssues($data[$sibling] ?? null, $media, $label, required: false)));
        }

        return $issues;
    }

    /**
     * Cuantas imagenes o videos del bloque no tienen version para modo oscuro. Lo usa la revision
     * de publicar solo cuando la tienda se puede ver en los dos modos.
     *
     * @param  array<string, mixed>  $payload
     */
    public function missingDarkMedia(array $payload, string $context = self::CONTEXT_STORE): int
    {
        return MediaVariants::missingDark($this->fields($context), $payload);
    }

    /**
     * «Diseño de esta lámina» de un banner que antes no lo tenía (un banner del mosaico, uno de
     * los laterales): color del texto, velo y lo que diga `BannerStyle`. Vacíos, el banner se
     * pinta como siempre (letra clara y el degradado de abajo).
     *
     * @param  list<string>  $styleKeys
     * @return array<string, array<string, mixed>>
     */
    protected static function bannerDesign(array $styleKeys): array
    {
        return [
            'text_color' => [
                'type' => 'select',
                'label' => 'Color del texto',
                'group' => 'design',
                'options' => self::options(['light' => 'Claro (sobre foto oscura)', 'dark' => 'Oscuro (sobre foto clara)']),
            ],
            'overlay' => ['type' => 'number', 'label' => 'Oscurecer la foto (%)', 'help' => 'Vacío: el degradado de siempre.', 'min' => 0, 'max' => 60, 'step' => 10, 'group' => 'design'],
            'style' => BannerStyle::field($styleKeys, 'Diseño de este banner'),
        ];
    }

    /**
     * Opciones de un select desde un arreglo `[valor => etiqueta]`.
     *
     * @param  array<string|int, string>  $map
     * @return list<array{value: string|int, label: string}>
     */
    protected static function options(array $map): array
    {
        return collect($map)->map(fn (string $label, string|int $value) => ['value' => $value, 'label' => $label])->values()->all();
    }

    /**
     * Los destinos que ofrece un campo en cada contexto.
     *
     * @return list<string>
     */
    protected static function targetKinds(string $context, bool $whatsapp = false): array
    {
        $kinds = $context === self::CONTEXT_TEMPLATE
            ? [Targets::KIND_PAGE, Targets::KIND_SEARCH, Targets::KIND_URL]
            : [Targets::KIND_CATEGORY, Targets::KIND_PAGE, Targets::KIND_SEARCH, Targets::KIND_URL];

        return $whatsapp ? [...$kinds, Targets::KIND_WHATSAPP] : $kinds;
    }
}

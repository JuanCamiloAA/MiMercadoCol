<?php

namespace App\Support;

use App\Models\MediaAsset;

/**
 * El diseño de una seccion —tamaño, espacio, fondo y animacion— para la tienda y para la landing.
 *
 * Es un solo catalogo para las dos superficies: las mismas opciones, los mismos defectos y la
 * misma traduccion a CSS (`components/sections/appearance.ts`). Lo unico que cambia es como se
 * guarda una imagen o un video de fondo: en la tienda es un id de la biblioteca (`bg_media_id`,
 * que termina en `_media_id` para que publicar, pintar e independizarse lo encuentren como a
 * cualquier imagen) y en la landing una ruta bajo `landing/` (`bg_image`).
 *
 * Se guarda en `payload.appearance` (tienda) o `data.appearance` (landing) y solo con lo que
 * alguien cambio: una clave ausente vale el defecto del tipo de bloque (`appearanceDefaults()`).
 * Asi un bloque que nunca toco esta pestaña se ve exactamente como antes, y si mañana cambia el
 * defecto de un tipo, lo cambian todos los que no lo tocaron.
 *
 * El editor pinta la pestaña «Diseño» con `groups()`: agregar un ajuste aqui (y su traduccion en
 * `appearance.ts`) no obliga a tocar el formulario.
 */
final class SectionAppearance
{
    public const SURFACE_STORE = 'store';

    public const SURFACE_LANDING = 'landing';

    /** Lo neutro: lo que pone la envoltura si ni el tipo ni quien edita dicen otra cosa. */
    public const BASE = [
        'height' => 'auto',
        'height_mobile' => 'same',
        'align' => 'start',
        'frame' => 'contained',
        'width' => 'normal',
        'pad_top' => 'md',
        'pad_bottom' => 'md',
        'gap' => 'md',
        'radius' => 'theme',
        'bg_type' => 'none',
        'bg_color' => 'soft',
        'bg_gradient' => 'brand',
        'bg_texture' => 'dots',
        'bg_fit' => 'cover',
        'bg_position' => 'center',
        'bg_fixed' => false,
        'bg_blur' => 0,
        'overlay' => 0,
        'overlay_color' => 'dark',
        'parallax' => 'none',
        'ink' => 'auto',
        'anim' => 'none',
        'anim_speed' => 'normal',
        'anim_stagger' => 'normal',
        'anim_delay' => 0,
        'anim_once' => true,
    ];

    /** Los archivos de fondo de cada superficie: ids en la tienda, rutas en la landing. */
    public const MEDIA_KEYS = [
        self::SURFACE_STORE => ['bg_media_id', 'bg_media_mobile_id', 'bg_video_media_id', 'bg_video_media_mobile_id'],
        self::SURFACE_LANDING => ['bg_image', 'bg_image_mobile', 'bg_video', 'bg_video_mobile'],
    ];

    /** Los archivos de fondo que son video (el resto son imagenes). */
    public const VIDEO_KEYS = ['bg_video_media_id', 'bg_video_media_mobile_id', 'bg_video', 'bg_video_mobile'];

    /**
     * Los grupos que pueden ser distintos en modo oscuro. Solo lo visual: el fondo (con sus
     * archivos, el velo y el color del texto). El tamaño, el espacio y la animacion son los mismos
     * en los dos modos.
     *
     * Lo de modo oscuro se guarda aparte, en `appearance.dark`, y solo con lo que alguien cambio:
     * lo que no esta ahi vale lo de modo claro.
     */
    public const THEMED_GROUPS = ['background'];

    public const DARK_KEY = 'dark';

    /**
     * Los colores con nombre. Salen del tema de cada superficie (en la tienda el de la sede, en la
     * landing el de la plataforma), nunca del panel. Ademas se acepta un hexadecimal propio.
     */
    public const COLOR_TOKENS = [
        self::SURFACE_STORE => [
            'page' => 'Fondo de la tienda',
            'soft' => 'Suave, de la marca',
            'brand' => 'Color de la marca',
            'brand_dark' => 'Marca oscuro',
            'deal' => 'Color de oferta',
            'dark' => 'Oscuro',
            'light' => 'Blanco',
        ],
        self::SURFACE_LANDING => [
            'page' => 'Fondo de la página',
            'band' => 'Banda gris',
            'soft' => 'Suave, de la marca',
            'brand' => 'Color de la marca',
            'dark' => 'Oscuro',
            'light' => 'Blanco',
        ],
    ];

    /** Lo que sale si la seccion no tiene fondo propio. */
    protected const ANIMATED = ['fade', 'up', 'down', 'left', 'right', 'zoom', 'zoom_out', 'blur', 'rise'];

    /** Los tipos de fondo que llevan archivo, color de base o velo. */
    protected const WITH_FILE = ['image', 'video'];

    /**
     * El catalogo para el editor, por grupos, como `config/landing_appearance.php` de miTallerCol.
     *
     * @return array<string, array{label: string, icon: string, help?: string, fields: array<string, array<string, mixed>>}>
     */
    public static function groups(string $surface): array
    {
        $store = $surface === self::SURFACE_STORE;
        $pad = self::options(['none' => 'Sin espacio', 'xs' => 'Mínimo', 'sm' => 'Corto', 'md' => 'Normal', 'lg' => 'Amplio', 'xl' => 'Muy amplio']);
        $animated = ['field' => 'anim', 'value' => self::ANIMATED];

        return [
            'size' => [
                'label' => 'Tamaño y espacio',
                'icon' => 'ArrowsOut',
                'fields' => [
                    'height' => [
                        'type' => 'select',
                        'label' => 'Alto de la sección',
                        'help' => 'Es un alto mínimo: si el contenido es más largo, la sección crece.',
                        'options' => self::options([
                            'auto' => 'Según el contenido',
                            'third' => 'Franja (38 % de la pantalla)',
                            'half' => 'Media pantalla (55 %)',
                            'tall' => 'Casi completa (78 %)',
                            'screen' => 'Pantalla completa (100 %)',
                        ]),
                    ],
                    'height_mobile' => [
                        'type' => 'select',
                        'label' => 'Alto en celular',
                        'help' => 'En un celular una sección de pantalla completa casi nunca conviene.',
                        'options' => self::options([
                            'same' => 'Como en computador',
                            'auto' => 'Según el contenido',
                            'half' => 'Media pantalla (55 %)',
                            'tall' => 'Casi completa (78 %)',
                            'screen' => 'Pantalla completa (100 %)',
                        ]),
                    ],
                    'align' => [
                        'type' => 'select',
                        'label' => 'Contenido en vertical',
                        'help' => 'Manda cuando la sección es más alta que su contenido.',
                        'options' => self::options(['start' => 'Arriba', 'center' => 'Centrado', 'end' => 'Abajo']),
                    ],
                    'frame' => [
                        'type' => 'select',
                        'label' => 'Marco',
                        'help' => 'De borde a borde: el fondo y la sección ocupan todo el ancho de la pantalla.',
                        'options' => self::options([
                            'contained' => $store ? 'Dentro de la página, con las esquinas del tema' : 'Tarjeta dentro de la página',
                            'bleed' => 'De borde a borde (100 % del ancho)',
                        ]),
                    ],
                    'width' => [
                        'type' => 'select',
                        'label' => 'Ancho del contenido',
                        'help' => 'Completo y de borde a borde: el contenido también toca los bordes, como un banner sin margen.',
                        'options' => self::options(['narrow' => 'Angosto (lectura)', 'normal' => 'Normal', 'wide' => 'Amplio', 'full' => 'Completo (100 %)']),
                    ],
                    'pad_top' => ['type' => 'select', 'label' => 'Espacio arriba', 'options' => $pad],
                    'pad_bottom' => ['type' => 'select', 'label' => 'Espacio abajo', 'options' => $pad],
                    'gap' => [
                        'type' => 'select',
                        'label' => 'Separación entre piezas',
                        'help' => 'Entre los banners, tarjetas o columnas de la sección. Sin separación quedan pegados.',
                        'options' => self::options(['none' => 'Sin separación', 'sm' => 'Corta', 'md' => 'Normal', 'lg' => 'Amplia']),
                    ],
                    'radius' => [
                        'type' => 'select',
                        'label' => 'Esquinas de la sección',
                        'help' => $store
                            ? 'Solo se notan dentro de la página, con fondo y en computador: en el celular la sección con fondo va de borde a borde.'
                            : 'Solo se notan dentro de la página y con fondo.',
                        'options' => self::options(['theme' => 'Las del tema', 'none' => 'Rectas', 'lg' => 'Muy redondas']),
                        'show_if' => ['field' => 'frame', 'value' => 'contained'],
                    ],
                ],
            ],
            'background' => [
                'label' => 'Fondo',
                'icon' => 'ImageSquare',
                // Puede ser distinto en modo oscuro (`appearance.dark`); el editor lo cambia con su conmutador.
                'themed' => true,
                'fields' => [
                    'bg_type' => [
                        'type' => 'select',
                        'label' => 'Tipo de fondo',
                        'options' => self::options([
                            'none' => 'Sin fondo propio',
                            'color' => 'Color',
                            'gradient' => 'Degradado',
                            'image' => 'Imagen',
                            'video' => 'Video',
                            'texture' => 'Textura',
                        ]),
                    ],
                    'bg_color' => [
                        'type' => 'color',
                        'label' => 'Color',
                        'help' => 'Con textura, degradado, imagen o video es el color de base que va debajo.',
                        'tokens' => self::options(self::COLOR_TOKENS[$surface] ?? []),
                        'show_if' => ['field' => 'bg_type', 'value' => ['color', 'texture', 'gradient', 'image', 'video']],
                    ],
                    'bg_gradient' => [
                        'type' => 'select',
                        'label' => 'Degradado',
                        'options' => self::options(['brand' => 'Fondo → marca', 'soft' => 'Suave', 'dark' => 'Oscuro', 'halo' => 'Centro claro', 'sunset' => 'Atardecer']),
                        'show_if' => ['field' => 'bg_type', 'value' => 'gradient'],
                    ],
                    'bg_texture' => [
                        'type' => 'select',
                        'label' => 'Textura',
                        'options' => self::options(['dots' => 'Puntos', 'grid' => 'Cuadrícula', 'lines' => 'Líneas diagonales', 'noise' => 'Grano', 'glow' => 'Resplandor']),
                        'show_if' => ['field' => 'bg_type', 'value' => 'texture'],
                    ],
                    ...self::mediaFields($surface),
                    'bg_fit' => [
                        'type' => 'select',
                        'label' => 'Ajuste',
                        'options' => self::options(['cover' => 'Cubrir (recorta si hace falta)', 'contain' => 'Contener (se ve completa)', 'repeat' => 'Repetir en mosaico']),
                        'show_if' => ['field' => 'bg_type', 'value' => 'image'],
                    ],
                    'bg_position' => [
                        'type' => 'select',
                        'label' => 'Encuadre',
                        'options' => self::options(['center' => 'Centro', 'top' => 'Arriba', 'bottom' => 'Abajo', 'left' => 'Izquierda', 'right' => 'Derecha']),
                        'show_if' => ['field' => 'bg_type', 'value' => self::WITH_FILE],
                    ],
                    'bg_fixed' => [
                        'type' => 'toggle',
                        'label' => 'Fondo fijo al desplazar',
                        'help' => 'La imagen se queda quieta mientras el contenido pasa por encima. Con paralaje no aplica.',
                        'show_if' => ['field' => 'bg_type', 'value' => 'image'],
                    ],
                    'bg_blur' => [
                        'type' => 'range',
                        'label' => 'Desenfoque',
                        'min' => 0,
                        'max' => 24,
                        'step' => 2,
                        'unit' => 'px',
                        'show_if' => ['field' => 'bg_type', 'value' => self::WITH_FILE],
                    ],
                    'overlay' => [
                        'type' => 'range',
                        'label' => 'Velo sobre el fondo',
                        'help' => 'Oscurece (o aclara) el fondo para que el texto se siga leyendo.',
                        'min' => 0,
                        'max' => 90,
                        'step' => 5,
                        'unit' => '%',
                        'show_if' => ['field' => 'bg_type', 'value' => ['image', 'video', 'texture', 'gradient']],
                    ],
                    'overlay_color' => [
                        'type' => 'select',
                        'label' => 'Color del velo',
                        'options' => self::options(['dark' => 'Oscuro', 'light' => 'Claro', 'brand' => 'De la marca']),
                        'show_if' => ['field' => 'bg_type', 'value' => ['image', 'video', 'texture', 'gradient']],
                    ],
                    'parallax' => [
                        'type' => 'select',
                        'label' => 'Paralaje al desplazar',
                        'help' => 'El fondo se mueve más despacio que el contenido.',
                        'options' => self::options(['none' => 'Sin paralaje', 'soft' => 'Suave', 'medium' => 'Medio', 'strong' => 'Marcado']),
                        'show_if' => ['field' => 'bg_type', 'value' => 'image'],
                    ],
                    'ink' => [
                        'type' => 'select',
                        'label' => 'Color del texto',
                        'help' => 'Automático: se escoge según el fondo para que se lea.',
                        'options' => self::options(['auto' => 'Automático', 'light' => 'Claro', 'dark' => 'Oscuro']),
                        'show_if' => ['field' => 'bg_type', 'value' => ['color', 'gradient', 'image', 'video', 'texture']],
                    ],
                ],
            ],
            'animation' => [
                'label' => 'Animación al aparecer',
                'icon' => 'MagicWand',
                'help' => 'La sección y sus piezas entran cuando llegan a la pantalla. Quien pide menos movimiento la ve quieta.',
                'fields' => [
                    'anim' => [
                        'type' => 'select',
                        'label' => 'Entrada',
                        'options' => self::options([
                            'none' => 'Sin animación',
                            'fade' => 'Aparecer',
                            'up' => 'Subir',
                            'down' => 'Bajar',
                            'left' => 'Entrar desde la derecha',
                            'right' => 'Entrar desde la izquierda',
                            'zoom' => 'Acercar',
                            'zoom_out' => 'Alejar',
                            'blur' => 'Enfocar',
                            'rise' => 'Subir y crecer',
                        ]),
                    ],
                    'anim_speed' => [
                        'type' => 'select',
                        'label' => 'Velocidad',
                        'options' => self::options(['fast' => 'Rápida', 'normal' => 'Normal', 'slow' => 'Lenta']),
                        'show_if' => $animated,
                    ],
                    'anim_stagger' => [
                        'type' => 'select',
                        'label' => 'Escalonado entre piezas',
                        'help' => 'Cuánto espera cada pieza respecto a la anterior.',
                        'options' => self::options(['none' => 'Todas a la vez', 'subtle' => 'Leve', 'normal' => 'Normal', 'wide' => 'Marcado']),
                        'show_if' => $animated,
                    ],
                    'anim_delay' => [
                        'type' => 'range',
                        'label' => 'Retraso inicial',
                        'min' => 0,
                        'max' => 800,
                        'step' => 50,
                        'unit' => 'ms',
                        'show_if' => $animated,
                    ],
                    'anim_once' => [
                        'type' => 'toggle',
                        'label' => 'Animar solo la primera vez',
                        'help' => 'Apagado, la sección vuelve a animarse cada vez que entra en pantalla.',
                        'show_if' => $animated,
                    ],
                ],
            ],
        ];
    }

    /**
     * Todos los campos del catalogo de una superficie, planos.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fields(string $surface): array
    {
        return array_merge(...array_values(array_map(fn (array $group) => $group['fields'], self::groups($surface))));
    }

    /** @return list<string> */
    public static function keys(string $surface): array
    {
        return array_keys(self::fields($surface));
    }

    /**
     * Los campos que pueden cambiar en modo oscuro (los de `THEMED_GROUPS`).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function themedFields(string $surface): array
    {
        $groups = array_intersect_key(self::groups($surface), array_flip(self::THEMED_GROUPS));

        return array_merge(...array_values(array_map(fn (array $group) => $group['fields'], $groups)));
    }

    /**
     * Lo que vale cada clave si nadie la cambio: lo neutro con lo propio del tipo encima.
     *
     * @param  array<string, mixed>  $typeDefaults
     * @return array<string, mixed>
     */
    public static function defaults(array $typeDefaults, string $surface): array
    {
        $defaults = array_merge(self::BASE, array_intersect_key($typeDefaults, self::BASE));

        foreach (self::MEDIA_KEYS[$surface] ?? [] as $key) {
            $defaults[$key] = null;
        }

        return $defaults;
    }

    /**
     * Deja lo guardado con la forma del catalogo: descarta claves desconocidas y valores que no
     * valen, y quita lo que coincide con el defecto del tipo. Lo que queda es solo lo que alguien
     * cambio (puede quedar vacio).
     *
     * @param  array<string, mixed>|mixed  $saved
     * @param  array<string, mixed>  $typeDefaults
     * @return array<string, mixed>
     */
    public static function normalize(mixed $saved, array $typeDefaults, string $surface): array
    {
        if (! is_array($saved)) {
            return [];
        }

        $defaults = self::defaults($typeDefaults, $surface);
        $clean = [];

        foreach (self::fields($surface) as $key => $field) {
            if (! array_key_exists($key, $saved) || $saved[$key] === null || $saved[$key] === '') {
                continue;
            }

            $value = self::clean($field, $saved[$key], $surface);

            if ($value === null || $value === ($defaults[$key] ?? null)) {
                continue;
            }

            $clean[$key] = $value;
        }

        // Lo de modo oscuro: solo los campos del fondo, y solo lo que difiere de modo claro. Lo que
        // es igual se quita, asi sigue a modo claro si alla cambia.
        if (is_array($saved[self::DARK_KEY] ?? null)) {
            $light = array_merge($defaults, $clean);
            $dark = [];

            foreach (self::themedFields($surface) as $key => $field) {
                $value = $saved[self::DARK_KEY][$key] ?? null;

                if ($value === null || $value === '') {
                    continue;
                }

                $value = self::clean($field, $value, $surface);

                if ($value !== null && $value !== ($light[$key] ?? null)) {
                    $dark[$key] = $value;
                }
            }

            if ($dark !== []) {
                $clean[self::DARK_KEY] = $dark;
            }
        }

        return $clean;
    }

    /**
     * El diseño de modo oscuro ya resuelto: el de modo claro (resuelto) con lo propio de oscuro
     * encima. `null` si la seccion no cambia nada en oscuro: el front usa el de modo claro.
     *
     * @param  array<string, mixed>  $resolved  Lo de modo claro, ya con los defectos.
     * @param  array<string, mixed>  $normalized  Lo guardado, normalizado (puede traer `dark`).
     * @return array<string, mixed>|null
     */
    public static function resolveDark(array $resolved, array $normalized): ?array
    {
        $dark = $normalized[self::DARK_KEY] ?? null;

        if (! is_array($dark) || $dark === []) {
            return null;
        }

        return array_merge(array_diff_key($resolved, [self::DARK_KEY => true]), $dark);
    }

    /**
     * Lo que impide guardar el diseño que llega: un color que no es color, o un archivo de otra
     * empresa o que no es lo que dice ser. Una plantilla solo usa medios de plataforma.
     *
     * @param  array<string, mixed>|mixed  $saved
     * @return list<string>
     */
    public static function violations(mixed $saved, string $surface, ?int $companyId = null, bool $template = false): array
    {
        if ($saved === null) {
            return [];
        }

        if (! is_array($saved)) {
            return ['El diseño de la sección no tiene la forma esperada.'];
        }

        $found = [];

        // Lo de modo oscuro se revisa con las mismas reglas.
        if (array_key_exists(self::DARK_KEY, $saved)) {
            $found = is_array($saved[self::DARK_KEY])
                ? self::violations(array_diff_key($saved[self::DARK_KEY], [self::DARK_KEY => true]), $surface, $companyId, $template)
                : ['El diseño de modo oscuro no tiene la forma esperada.'];
        }

        $color = $saved['bg_color'] ?? null;

        if ($color !== null && $color !== '' && self::cleanColor($color, $surface) === null) {
            $found[] = 'El color del fondo no es válido: usa uno de la lista o un hexadecimal (#RRGGBB).';
        }

        if ($surface !== self::SURFACE_STORE) {
            foreach (self::MEDIA_KEYS[self::SURFACE_LANDING] as $key) {
                $path = $saved[$key] ?? null;

                if ($path !== null && $path !== '' && self::cleanPath($path) === null) {
                    $found[] = 'El archivo de fondo no es uno de la landing.';
                }
            }

            return array_values(array_unique($found));
        }

        $ids = array_filter(
            array_intersect_key($saved, array_flip(self::MEDIA_KEYS[self::SURFACE_STORE])),
            fn ($id) => $id !== null && $id !== '',
        );

        if ($ids === []) {
            return array_values(array_unique($found));
        }

        $assets = MediaAsset::query()
            ->withoutGlobalScopes()
            ->whereIn('id', array_map('intval', array_filter($ids, 'is_numeric')))
            ->when($template, fn ($query) => $query->whereNull('company_id'), fn ($query) => $query->where('company_id', $companyId))
            ->get()
            ->keyBy('id');

        foreach ($ids as $key => $id) {
            $asset = is_numeric($id) ? $assets->get((int) $id) : null;

            if ($asset === null) {
                $found[] = $template
                    ? 'Una plantilla solo puede usar imágenes y videos de la plataforma.'
                    : 'La imagen o el video de fondo no es de la biblioteca de esta empresa.';

                continue;
            }

            $wantsVideo = in_array($key, self::VIDEO_KEYS, true);

            if ($wantsVideo && ! $asset->isVideo()) {
                $found[] = 'El video de fondo tiene que ser un video.';
            }

            if (! $wantsVideo && $asset->isVideo()) {
                $found[] = 'La imagen de fondo tiene que ser una imagen, no un video.';
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Los campos de archivo, segun la superficie.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function mediaFields(string $surface): array
    {
        $image = ['field' => 'bg_type', 'value' => 'image'];
        $video = ['field' => 'bg_type', 'value' => 'video'];

        // Cada archivo en computador y en celular; el modo oscuro tiene los suyos en `appearance.dark`.
        if ($surface === self::SURFACE_STORE) {
            return [
                'bg_media_id' => ['type' => 'media', 'label' => 'Imagen de fondo · computador', 'help' => 'Con 1920 px de ancho basta para pantalla completa.', 'show_if' => $image],
                'bg_media_mobile_id' => ['type' => 'media', 'label' => 'Imagen de fondo · celular', 'help' => 'Opcional y vertical. Si falta, se recorta la de computador.', 'show_if' => $image],
                'bg_video_media_id' => ['type' => 'video', 'label' => 'Video de fondo · computador', 'help' => 'Sin sonido y en bucle; quien pide menos movimiento ve la primera imagen.', 'show_if' => $video],
                'bg_video_media_mobile_id' => ['type' => 'video', 'label' => 'Video de fondo · celular', 'help' => 'Opcional y vertical. Si falta, va el de computador.', 'show_if' => $video],
            ];
        }

        return [
            'bg_image' => ['type' => 'image', 'label' => 'Imagen de fondo · computador', 'help' => 'Con 1920 px de ancho basta para pantalla completa.', 'show_if' => $image],
            'bg_image_mobile' => ['type' => 'image', 'label' => 'Imagen de fondo · celular', 'help' => 'Opcional y vertical. Si falta, se recorta la de computador.', 'show_if' => $image],
            'bg_video' => ['type' => 'video', 'label' => 'Video de fondo · computador', 'help' => 'Sin sonido y en bucle; quien pide menos movimiento ve la primera imagen.', 'show_if' => $video],
            'bg_video_mobile' => ['type' => 'video', 'label' => 'Video de fondo · celular', 'help' => 'Opcional y vertical. Si falta, va el de computador.', 'show_if' => $video],
        ];
    }

    /**
     * Un valor con el tipo de su campo, o null si no vale.
     *
     * @param  array<string, mixed>  $field
     */
    protected static function clean(array $field, mixed $value, string $surface): mixed
    {
        return match ($field['type']) {
            'select' => in_array((string) $value, array_map('strval', array_column($field['options'], 'value')), true) ? (string) $value : null,
            'toggle' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'range' => is_numeric($value) ? self::cleanRange($field, (float) $value) : null,
            'color' => self::cleanColor($value, $surface),
            'media', 'video' => $surface === self::SURFACE_STORE
                ? (is_numeric($value) && (int) $value > 0 ? (int) $value : null)
                : self::cleanPath($value),
            'image' => self::cleanPath($value),
            default => null,
        };
    }

    /** @param  array<string, mixed>  $field */
    protected static function cleanRange(array $field, float $value): int
    {
        $step = max(1, (int) ($field['step'] ?? 1));
        $value = max((int) $field['min'], min((int) $field['max'], $value));

        return (int) (round($value / $step) * $step);
    }

    protected static function cleanColor(mixed $value, string $surface): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        if (array_key_exists($value, self::COLOR_TOKENS[$surface] ?? [])) {
            return $value;
        }

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1 ? strtoupper($value) : null;
    }

    protected static function cleanPath(mixed $value): ?string
    {
        return is_string($value) && preg_match('#^landing/[A-Za-z0-9/_\-.]+$#', $value) === 1 && ! str_contains($value, '..') ? $value : null;
    }

    /**
     * @param  array<string, string>  $map
     * @return list<array{value: string, label: string}>
     */
    protected static function options(array $map): array
    {
        return array_map(fn (string $value, string $label) => ['value' => $value, 'label' => $label], array_keys($map), array_values($map));
    }
}

<?php

namespace App\Support;

/**
 * Lo que comparten todos los videos que se suben para mostrarse solos, como un GIF: los de la
 * landing de la plataforma y los de la tienda en linea de cada empresa. Que formatos y cuanto
 * pesan, y sus opciones (velocidad, arranca solo, se repite, como llena, esquinas, sombra,
 * marco). Cada bloque toma las que le sirven; el front las lee con `videoOptions()` de
 * `components/media/looping-video.tsx`.
 */
final class VideoOptions
{
    /** Lo mas que pesa un video. Se repite en cada visita: mientras menos, mejor. */
    public const MAX_VIDEO_KB = 25 * 1024;

    /** Por encima de esto un video tarda en arrancar en un celular con datos. */
    public const HEAVY_VIDEO_BYTES = 8 * 1024 * 1024;

    /** Las extensiones que se aceptan (`mimes:`) y lo que se le ofrece al navegador al elegir. */
    public const EXTENSIONS = ['mp4', 'webm'];

    public const ACCEPT = 'video/mp4,video/webm';

    public const SECTION = 'Opciones del video';

    /**
     * @return array<string, array{field: array<string, mixed>, default: mixed}>
     */
    private static function all(): array
    {
        return [
            'video_speed' => ['default' => '1', 'field' => ['type' => 'select', 'label' => 'Velocidad', 'options' => [
                ['value' => '0.5', 'label' => 'Muy lenta (0,5×)'],
                ['value' => '0.75', 'label' => 'Lenta (0,75×)'],
                ['value' => '1', 'label' => 'Normal'],
                ['value' => '1.25', 'label' => 'Rápida (1,25×)'],
                ['value' => '1.5', 'label' => 'Más rápida (1,5×)'],
                ['value' => '2', 'label' => 'El doble (2×)'],
            ]]],
            'video_autoplay' => ['default' => true, 'field' => ['type' => 'toggle', 'label' => 'Arranca solo', 'help' => 'Apagado, se ve el botón de reproducir hasta que lo tocan.']],
            'video_loop' => ['default' => true, 'field' => ['type' => 'toggle', 'label' => 'Se repite', 'help' => 'Apagado, se reproduce una vez y se queda en el último cuadro.']],
            'video_fit' => ['default' => 'cover', 'field' => ['type' => 'select', 'label' => 'Cómo llena el espacio', 'options' => [
                ['value' => 'cover', 'label' => 'Lo llena, recortando los bordes'],
                ['value' => 'contain', 'label' => 'Se ve completo, con el fondo alrededor'],
            ]]],
            'video_radius' => ['default' => 'round', 'field' => ['type' => 'select', 'label' => 'Esquinas', 'options' => [
                ['value' => 'none', 'label' => 'Rectas'],
                ['value' => 'soft', 'label' => 'Un poco redondas'],
                ['value' => 'round', 'label' => 'Redondas'],
            ]]],
            'video_shadow' => ['default' => true, 'field' => ['type' => 'toggle', 'label' => 'Sombra']],
            'video_frame' => ['default' => 'none', 'field' => ['type' => 'select', 'label' => 'Marco', 'options' => [
                ['value' => 'none', 'label' => 'Sin marco'],
                ['value' => 'browser', 'label' => 'Ventana de navegador'],
            ]]],
        ];
    }

    /**
     * Los campos de las opciones pedidas, en su orden; la primera abre el grupo en el formulario.
     *
     * @param  list<string>  $keys
     * @return array<string, array<string, mixed>>
     */
    public static function fields(array $keys): array
    {
        $fields = [];

        foreach ($keys as $position => $key) {
            $fields[$key] = self::all()[$key]['field'] + ($position === 0 ? ['section' => self::SECTION] : []);
        }

        return $fields;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function defaults(array $keys): array
    {
        return array_map(fn (string $key) => self::all()[$key]['default'], array_combine($keys, $keys));
    }

    /**
     * Las reglas para subir un video y sus mensajes, las mismas en la landing y en la tienda.
     *
     * @return array{0: list<string>, 1: array<string, string>}
     */
    public static function uploadRules(string $field = 'video'): array
    {
        return [
            ['required', 'file', 'mimes:'.implode(',', self::EXTENSIONS), 'max:'.self::MAX_VIDEO_KB],
            [
                $field.'.uploaded' => 'El video no alcanzó a subir: puede pesar más de lo que acepta el servidor.',
                $field.'.mimes' => 'El video debe ser MP4 o WebM.',
                $field.'.max' => 'El video no puede pesar más de '.(self::MAX_VIDEO_KB / 1024).' MB.',
            ],
        ];
    }
}

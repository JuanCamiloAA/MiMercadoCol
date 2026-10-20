<?php

namespace App\Landing\Blocks;

use App\Support\MediaVariants;
use App\Support\VideoOptions;

/**
 * Un carrusel de imagenes y videos que avanza solo. Cada lamina lleva una imagen, un video o los
 * dos (la imagen es la que se ve mientras el video carga). Una lamina sin ninguno no sale.
 */
class MediaCarouselBlock extends LandingBlock
{
    /** Las opciones de video que ofrece (`VideoOptions`). Sin «se repite»: un video pasa a la siguiente lámina al terminar. */
    public const VIDEO_OPTIONS = ['video_speed', 'video_autoplay', 'video_fit', 'video_radius', 'video_shadow'];

    public function type(): string
    {
        return 'media_carousel';
    }

    public function label(): string
    {
        return 'Carrusel de imágenes y videos';
    }

    public function icon(): string
    {
        return 'Slideshow';
    }

    public function help(): string
    {
        return 'Láminas con imagen o video que avanzan solas. Los videos pasan a la siguiente cuando terminan.';
    }

    public function fields(): array
    {
        return [
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 80, 'section' => 'Contenido'],
            'text' => ['type' => 'textarea', 'label' => 'Texto', 'max' => 240],
            'slides' => self::repeater('Láminas', 'lámina', [
                ...MediaVariants::field('image', ['type' => 'image', 'label' => 'Imagen', 'preview' => 'logo'], MediaVariants::SURFACE_LANDING),
                ...MediaVariants::field('video', ['type' => 'video', 'label' => 'Video (opcional)', 'max_mb' => VideoOptions::MAX_VIDEO_KB / 1024, 'help' => 'Si la lámina tiene video, la imagen se ve mientras carga.'], MediaVariants::SURFACE_LANDING),
                'caption' => ['type' => 'text', 'label' => 'Leyenda', 'max' => 90],
            ], 10),
            ...self::videoOptions(),
            'interval' => ['type' => 'select', 'label' => 'Cada imagen se queda', 'options' => [
                ['value' => '4', 'label' => '4 segundos'],
                ['value' => '6', 'label' => '6 segundos'],
                ['value' => '8', 'label' => '8 segundos'],
            ]],
            'ratio' => ['type' => 'select', 'label' => 'Proporción', 'options' => [
                ['value' => '16/9', 'label' => 'Panorámica (16:9)'],
                ['value' => '21/9', 'label' => 'Muy ancha (21:9)'],
                ['value' => '4/3', 'label' => 'Clásica (4:3)'],
                ['value' => '1/1', 'label' => 'Cuadrada'],
            ]],
        ];
    }

    /**
     * En el carrusel «arranca solo» es tambien que las laminas avancen solas: apagado, se pasan
     * con las flechas y los videos con un toque.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function videoOptions(): array
    {
        $fields = VideoOptions::fields(self::VIDEO_OPTIONS);
        $fields['video_autoplay']['label'] = 'Avanza solo';
        $fields['video_autoplay']['help'] = 'Apagado, las láminas se pasan con las flechas y cada video arranca con un toque.';

        return $fields;
    }

    public function defaults(): array
    {
        return [
            'title' => '',
            'text' => '',
            'slides' => [],
            'interval' => '6',
            'ratio' => '16/9',
            ...VideoOptions::defaults(self::VIDEO_OPTIONS),
        ];
    }

    /** Antes el fondo se elegia aqui (`tone`); ahora va en la pestaña «Diseño». */
    protected function upgrade(array $data): array
    {
        return self::upgradeTone($data);
    }
}

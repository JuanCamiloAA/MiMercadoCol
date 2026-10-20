<?php

namespace App\Storefront\Blocks;

use App\Models\StorefrontBlock;
use App\Support\MediaVariants;
use App\Support\VideoOptions;

/**
 * Un carrusel de imagenes y videos que avanza solo: la imagen se queda lo que diga el bloque y el
 * video, lo que dure. Cada lamina lleva una imagen, un video o los dos (la imagen se ve mientras
 * el video carga), y si se quiere, a donde lleva. El «Carrusel» de siempre sigue siendo solo de
 * imagenes, con varias a la vez.
 */
class MediaCarouselBlockData extends BlockData
{
    /** Sin «se repite»: un video pasa a la siguiente lamina al terminar. */
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

    public function pages(): array
    {
        return [StorefrontBlock::PAGE_HOME, StorefrontBlock::PAGE_CATEGORY, StorefrontBlock::PAGE_SEARCH];
    }

    public function fields(string $context = self::CONTEXT_STORE): array
    {
        $options = VideoOptions::fields(self::VIDEO_OPTIONS);
        $options['video_autoplay']['label'] = 'Avanza solo';
        $options['video_autoplay']['help'] = 'Apagado, las láminas se pasan con las flechas y cada video arranca con un toque.';

        return [
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 50, 'help' => 'Opcional.'],
            'slides' => [
                'type' => 'repeater',
                'label' => 'Láminas',
                'singular' => 'lámina',
                'max_items' => 8,
                'item' => [
                    ...MediaVariants::field('media_id', ['type' => 'media', 'label' => 'Imagen', 'help' => 'Si la lámina tiene video, es la que se ve mientras carga.']),
                    ...MediaVariants::field('video_media_id', ['type' => 'video', 'label' => 'Video (opcional)']),
                    'caption' => ['type' => 'text', 'label' => 'Leyenda', 'max' => 80],
                    'target' => ['type' => 'target', 'label' => 'A dónde lleva', 'kinds' => self::targetKinds($context)],
                ],
            ],
            ...$options,
            'interval' => ['type' => 'select', 'label' => 'Cada imagen se queda', 'options' => self::options([4 => '4 segundos', 6 => '6 segundos', 8 => '8 segundos'])],
            'ratio' => ['type' => 'select', 'label' => 'Proporción', 'options' => self::options(['16/9' => 'Panorámica (16:9)', '21/9' => 'Muy ancha (21:9)', '4/3' => 'Clásica (4:3)', '1/1' => 'Cuadrada'])],
        ];
    }

    public function defaults(): array
    {
        return [
            'title' => null,
            'slides' => [],
            'interval' => 6,
            'ratio' => '16/9',
            ...VideoOptions::defaults(self::VIDEO_OPTIONS),
        ];
    }

    public function summary(array $payload): string
    {
        $count = count($payload['slides'] ?? []);

        return 'Carrusel de imágenes y videos — '.$count.' '.($count === 1 ? 'lámina' : 'láminas');
    }

    public function isEmpty(array $payload): bool
    {
        return ($payload['slides'] ?? []) === [];
    }

    protected function ownIssues(array $payload, array $media): array
    {
        $issues = [];

        foreach ($payload['slides'] ?? [] as $index => $slide) {
            $where = 'La lámina '.($index + 1).' del carrusel de imágenes y videos';
            $hasVideo = is_numeric($slide['video_media_id'] ?? null);

            if (! $hasVideo && ! is_numeric($slide['media_id'] ?? null)) {
                $issues[] = ['level' => 'block', 'message' => $where.' no tiene ni imagen ni video.'];

                continue;
            }

            $item = $this->fields()['slides']['item'];
            array_push($issues, ...$this->imageIssues($slide['media_id'] ?? null, $media, $where, required: false));
            array_push($issues, ...$this->videoIssues($slide['video_media_id'] ?? null, $media, $where, required: false));
            array_push($issues, ...$this->variantIssues($item, 'media_id', $slide, $media, $where));
            array_push($issues, ...$this->variantIssues($item, 'video_media_id', $slide, $media, $where));
        }

        return $issues;
    }
}

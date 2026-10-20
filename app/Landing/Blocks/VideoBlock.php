<?php

namespace App\Landing\Blocks;

use App\Support\MediaVariants;
use App\Support\VideoOptions;

/**
 * Solo el video, como un GIF: se repite solo, sin sonido y sin barra. Con un titulo opcional
 * encima, al ancho del contenido o de lado a lado. Sin video no sale en la landing.
 */
class VideoBlock extends LandingBlock
{
    /** Las opciones de video que ofrece (`VideoOptions`). */
    public const VIDEO_OPTIONS = ['video_speed', 'video_autoplay', 'video_loop', 'video_radius', 'video_shadow', 'video_frame'];

    public function type(): string
    {
        return 'video';
    }

    public function label(): string
    {
        return 'Video';
    }

    public function icon(): string
    {
        return 'VideoCamera';
    }

    public function help(): string
    {
        return 'Solo un video que se repite solo, sin sonido ni barra, como un GIF.';
    }

    public function fields(): array
    {
        return [
            ...MediaVariants::field('video', ['type' => 'video', 'label' => 'Video', 'max_mb' => VideoOptions::MAX_VIDEO_KB / 1024, 'help' => 'MP4 o WebM, sin sonido. Que dure poco y pese menos de 8 MB: se repite solo.'], MediaVariants::SURFACE_LANDING),
            ...MediaVariants::field('poster', ['type' => 'image', 'label' => 'Imagen mientras carga', 'preview' => 'logo', 'help' => 'Opcional. Un cuadro del video, para que el espacio no quede vacío.'], MediaVariants::SURFACE_LANDING),
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 80, 'section' => 'Texto (opcional)'],
            'text' => ['type' => 'textarea', 'label' => 'Texto', 'max' => 240],
            ...VideoOptions::fields(self::VIDEO_OPTIONS),
            'width' => ['type' => 'select', 'label' => 'Ancho', 'options' => [
                ['value' => 'contained', 'label' => 'Al ancho del contenido'],
                ['value' => 'full', 'label' => 'De lado a lado de la pantalla'],
            ]],
        ];
    }

    public function defaults(): array
    {
        return [
            'video' => null,
            'poster' => null,
            'title' => '',
            'text' => '',
            ...VideoOptions::defaults(self::VIDEO_OPTIONS),
            'width' => 'contained',
        ];
    }

    /** Antes el fondo se elegia aqui (`tone`); ahora va en la pestaña «Diseño». */
    protected function upgrade(array $data): array
    {
        return self::upgradeTone($data);
    }
}

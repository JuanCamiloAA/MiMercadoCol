<?php

namespace App\Landing\Blocks;

use App\Support\MediaVariants;
use App\Support\VideoOptions;

/**
 * Una seccion completa con un video que se repite solo, sin sonido y sin barra (como un GIF), al
 * lado de un titulo, los pasos que muestra y unas etiquetas. Los pasos se iluminan a medida que el
 * video avanza, y tocar uno lleva el video a esa parte.
 */
class VideoShowcaseBlock extends LandingBlock
{
    /** Lo mas que pesa un video de la landing (`VideoOptions`, el mismo que en la tienda). */
    public const MAX_VIDEO_KB = VideoOptions::MAX_VIDEO_KB;

    /** Las opciones de video que ofrece (`VideoOptions`). */
    public const VIDEO_OPTIONS = ['video_speed', 'video_autoplay', 'video_loop', 'video_radius', 'video_shadow', 'video_frame'];

    public function type(): string
    {
        return 'video_showcase';
    }

    public function label(): string
    {
        return 'Video con pasos';
    }

    public function icon(): string
    {
        return 'FilmStrip';
    }

    public function help(): string
    {
        return 'Una sección con un video que se repite solo, sin sonido ni barra, y los pasos que muestra.';
    }

    public function fields(): array
    {
        return [
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 60, 'section' => 'Contenido'],
            'title_highlight' => ['type' => 'text', 'label' => 'Final resaltado del título', 'max' => 60, 'help' => 'Va después del título, con el color de la marca en degradado.'],
            'text' => ['type' => 'textarea', 'label' => 'Texto', 'max' => 240],
            'steps' => self::repeater('Pasos', 'paso', [
                'icon' => ['type' => 'icon', 'label' => 'Ícono', 'default' => 'Sparkle'],
                'title' => ['type' => 'text', 'label' => 'Título', 'max' => 40],
                'text' => ['type' => 'textarea', 'label' => 'Texto', 'max' => 160],
                'starts_at' => ['type' => 'number', 'label' => 'Empieza en el segundo', 'min' => 0, 'max' => 600, 'step' => 0.5, 'help' => 'Vacío en todos: el video se reparte por igual entre los pasos.'],
            ], 5),
            'tags' => self::repeater('Etiquetas', 'etiqueta', [
                'label' => ['type' => 'text', 'label' => 'Texto', 'max' => 30],
            ], 8),
            ...MediaVariants::field('video', ['type' => 'video', 'label' => 'Video', 'section' => 'Video', 'max_mb' => self::MAX_VIDEO_KB / 1024, 'help' => 'MP4 o WebM, sin sonido. Que dure poco y pese menos de 8 MB: se repite solo.'], MediaVariants::SURFACE_LANDING),
            ...MediaVariants::field('poster', ['type' => 'image', 'label' => 'Imagen mientras carga', 'preview' => 'logo', 'help' => 'Opcional. Un cuadro del video, para que el espacio no quede vacío.'], MediaVariants::SURFACE_LANDING),
            'video_side' => ['type' => 'select', 'label' => 'Lado del video', 'options' => [
                ['value' => 'right', 'label' => 'A la derecha'],
                ['value' => 'left', 'label' => 'A la izquierda'],
            ]],
            ...VideoOptions::fields(self::VIDEO_OPTIONS),
        ];
    }

    public function defaults(): array
    {
        return [
            'title' => 'Tu primer producto,',
            'title_highlight' => 'en menos de un minuto',
            'text' => 'Un solo formulario, sin pasos confusos. Así se crea un producto:',
            'steps' => [
                ['icon' => 'Image', 'title' => 'Sube tus fotos', 'text' => 'Arrástralas desde tu computador o tu celular. La primera queda como portada.', 'starts_at' => null],
                ['icon' => 'PencilSimple', 'title' => 'Completa lo esencial', 'text' => 'Nombre, precio, existencias y categoría. Lo demás es opcional.', 'starts_at' => null],
                ['icon' => 'Rocket', 'title' => 'Publica', 'text' => 'Actívalo y aparece al instante en tu tienda, listo para vender.', 'starts_at' => null],
            ],
            'tags' => [
                ['label' => 'Varias fotos'],
                ['label' => 'Precio por canal'],
                ['label' => 'Existencia mínima'],
                ['label' => 'Exporta tu catálogo'],
            ],
            'video' => null,
            'poster' => null,
            'video_side' => 'right',
            ...VideoOptions::defaults(self::VIDEO_OPTIONS),
        ];
    }

    /** Antes el fondo se elegia aqui (`tone`); ahora va en la pestaña «Diseño». */
    protected function upgrade(array $data): array
    {
        return self::upgradeTone($data);
    }

    /** Sale sobre fondo oscuro, como siempre: el fondo ahora se cambia en «Diseño». */
    public function appearanceDefaults(): array
    {
        return ['frame' => 'bleed', 'bg_type' => 'color', 'bg_color' => 'dark'];
    }
}

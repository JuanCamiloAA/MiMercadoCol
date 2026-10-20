<?php

namespace App\Storefront\Blocks;

use App\Models\StorefrontBlock;
use App\Support\MediaVariants;
use App\Support\VideoOptions;

/**
 * Un video al lado de un titulo y los pasos que muestra («Elige, paga, recibe»). Los pasos se
 * iluminan a medida que el video avanza y tocar uno lleva el video a esa parte.
 */
class VideoShowcaseBlockData extends BlockData
{
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
        return 'Un video que se repite solo al lado de los pasos que muestra. Los pasos se iluminan con el video.';
    }

    public function pages(): array
    {
        return [StorefrontBlock::PAGE_HOME, StorefrontBlock::PAGE_CATEGORY, StorefrontBlock::PAGE_SEARCH];
    }

    public function fields(string $context = self::CONTEXT_STORE): array
    {
        return [
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 50],
            'title_highlight' => ['type' => 'text', 'label' => 'Final resaltado del título', 'max' => 50, 'help' => 'Va después del título, con el color de la marca.'],
            'text' => ['type' => 'textarea', 'label' => 'Texto', 'max' => 200, 'rows' => 2],
            'steps' => [
                'type' => 'repeater',
                'label' => 'Pasos',
                'singular' => 'paso',
                'max_items' => 5,
                'item_defaults' => ['icon' => 'Sparkle'],
                'item' => [
                    'icon' => ['type' => 'icon', 'label' => 'Ícono'],
                    'title' => ['type' => 'text', 'label' => 'Título', 'max' => 40],
                    'text' => ['type' => 'textarea', 'label' => 'Texto', 'max' => 140, 'rows' => 2],
                    'starts_at' => ['type' => 'number', 'label' => 'Empieza en el segundo', 'min' => 0, 'max' => 600, 'step' => 1, 'help' => 'Vacío en todos: el video se reparte por igual entre los pasos.'],
                ],
            ],
            ...MediaVariants::field('video_media_id', ['type' => 'video', 'label' => 'Video', 'required' => true, 'help' => 'MP4 o WebM, sin sonido. Que dure poco y pese menos de 8 MB: se repite solo.']),
            ...MediaVariants::field('poster_media_id', ['type' => 'media', 'label' => 'Imagen mientras carga', 'help' => 'Opcional. Un cuadro del video.']),
            'video_side' => ['type' => 'select', 'label' => 'Lado del video', 'options' => self::options(['right' => 'A la derecha', 'left' => 'A la izquierda'])],
            ...VideoOptions::fields(self::VIDEO_OPTIONS),
        ];
    }

    public function defaults(): array
    {
        return [
            'title' => 'Tu mercado,',
            'title_highlight' => 'en tres pasos',
            'text' => 'Así de fácil es comprar en la tienda en línea.',
            'steps' => [
                ['icon' => 'ShoppingCart', 'title' => 'Arma tu pedido', 'text' => 'Con los precios y promociones de la tienda.', 'starts_at' => null],
                ['icon' => 'CreditCard', 'title' => 'Paga como quieras', 'text' => 'En línea o al recibir.', 'starts_at' => null],
                ['icon' => 'Truck', 'title' => 'Recíbelo', 'text' => 'A domicilio o recógelo en la sede.', 'starts_at' => null],
            ],
            'video_media_id' => null,
            'poster_media_id' => null,
            'video_side' => 'right',
            ...VideoOptions::defaults(self::VIDEO_OPTIONS),
        ];
    }

    /** Sale sobre el oscuro de la marca, como siempre: el fondo ahora se cambia en «Diseño». */
    public function appearanceDefaults(): array
    {
        return ['bg_type' => 'color', 'bg_color' => 'brand_dark'];
    }

    public function summary(array $payload): string
    {
        $title = $this->short(trim(($payload['title'] ?? '').' '.($payload['title_highlight'] ?? '')));

        return $title === '' ? 'Video con pasos' : 'Video con pasos — '.$title;
    }

    public function isEmpty(array $payload): bool
    {
        return empty($payload['video_media_id']);
    }

    protected function ownIssues(array $payload, array $media): array
    {
        return [
            ...$this->videoIssues($payload['video_media_id'] ?? null, $media, 'El video con pasos'),
            ...$this->variantIssues($this->fields(), 'video_media_id', $payload, $media, 'El video con pasos'),
            ...$this->variantIssues($this->fields(), 'poster_media_id', $payload, $media, 'La imagen del video con pasos'),
        ];
    }
}

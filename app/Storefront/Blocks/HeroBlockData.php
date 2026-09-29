<?php

namespace App\Storefront\Blocks;

use App\Models\StorefrontBlock;

/**
 * Banner principal: una o varias laminas, solo o con uno o dos banners al lado.
 *
 * Cada lamina lleva su imagen de computador y, si se quiere, una distinta para celular (un
 * banner apaisado recortado en un telefono pierde justo el texto). Cada una tiene su vigencia:
 * la de la promocion del jueves se va sola el viernes.
 */
class HeroBlockData extends BlockData
{
    public function type(): string
    {
        return 'hero';
    }

    public function label(): string
    {
        return 'Banner principal';
    }

    public function icon(): string
    {
        return 'Image';
    }

    public function help(): string
    {
        return 'Las imágenes grandes del comienzo: una o varias que se pasan solas, con banners al lado si quieres.';
    }

    public function pages(): array
    {
        return [StorefrontBlock::PAGE_HOME, StorefrontBlock::PAGE_CATEGORY, StorefrontBlock::PAGE_SEARCH];
    }

    public function fields(string $context = self::CONTEXT_STORE): array
    {
        return [
            'layout' => [
                'type' => 'select',
                'label' => 'Diseño',
                'options' => self::options(['full' => 'Ancho completo', 'main_side' => 'Principal y banners al lado']),
            ],
            'slides' => [
                'type' => 'repeater',
                'label' => 'Láminas',
                'singular' => 'lámina',
                'max_items' => 6,
                'keep' => ['legacy_image'],
                'item_defaults' => ['text_color' => 'light', 'overlay' => 30],
                'item' => [
                    'media_id' => ['type' => 'media', 'label' => 'Imagen para computador', 'help' => 'Apaisada, idealmente 1600 × 600.'],
                    'media_mobile_id' => ['type' => 'media', 'label' => 'Imagen para celular', 'help' => 'Opcional. Más alta que ancha; si falta se usa la de computador recortada.'],
                    'title' => ['type' => 'text', 'label' => 'Título', 'max' => 60],
                    'text' => ['type' => 'textarea', 'label' => 'Texto', 'max' => 160, 'rows' => 2],
                    'button_label' => ['type' => 'text', 'label' => 'Texto del botón', 'max' => 30],
                    'target' => ['type' => 'target', 'label' => 'A dónde lleva', 'kinds' => self::targetKinds($context)],
                    'text_color' => [
                        'type' => 'select',
                        'label' => 'Color del texto',
                        'options' => self::options(['light' => 'Claro (sobre foto oscura)', 'dark' => 'Oscuro (sobre foto clara)']),
                    ],
                    'overlay' => ['type' => 'number', 'label' => 'Oscurecer la foto (%)', 'min' => 0, 'max' => 60, 'step' => 10],
                    'visible_from' => ['type' => 'datetime', 'label' => 'Se ve desde', 'help' => 'Vacío: desde ya.'],
                    'visible_to' => ['type' => 'datetime', 'label' => 'Se ve hasta', 'help' => 'Vacío: sin fin.'],
                ],
            ],
            'side' => [
                'type' => 'repeater',
                'label' => 'Banners al lado',
                'singular' => 'banner',
                'max_items' => 2,
                'show_if' => ['field' => 'layout', 'value' => 'main_side'],
                'item_defaults' => ['kind' => 'image', 'tone' => 'accent'],
                'item' => [
                    'kind' => ['type' => 'select', 'label' => 'Qué lleva', 'options' => self::options(['image' => 'Una imagen', 'color' => 'Un color con texto'])],
                    'media_id' => ['type' => 'media', 'label' => 'Imagen', 'show_if' => ['field' => 'kind', 'value' => 'image']],
                    'tone' => [
                        'type' => 'select',
                        'label' => 'Color',
                        'options' => self::options(['accent' => 'De la marca', 'deal' => 'De oferta', 'dark' => 'Oscuro', 'soft' => 'Suave']),
                        'show_if' => ['field' => 'kind', 'value' => 'color'],
                    ],
                    'title' => ['type' => 'text', 'label' => 'Título', 'max' => 40],
                    'text' => ['type' => 'text', 'label' => 'Texto', 'max' => 80],
                    'target' => ['type' => 'target', 'label' => 'A dónde lleva', 'kinds' => self::targetKinds($context)],
                ],
            ],
            'autoplay' => [
                'type' => 'select',
                'label' => 'Pasar solas',
                'options' => self::options([0 => 'No, el cliente las pasa', 3 => 'Cada 3 segundos', 5 => 'Cada 5 segundos', 7 => 'Cada 7 segundos', 10 => 'Cada 10 segundos']),
            ],
            'height' => [
                'type' => 'select',
                'label' => 'Alto',
                'options' => self::options(['low' => 'Bajo', 'medium' => 'Medio', 'high' => 'Alto']),
            ],
        ];
    }

    public function defaults(): array
    {
        return [
            'layout' => 'full',
            'slides' => [],
            'side' => [],
            'autoplay' => 5,
            'height' => 'medium',
        ];
    }

    /**
     * El banner de antes era una sola imagen con su titulo. Se vuelve la primera lamina, para
     * que una tienda ya armada se siga viendo igual.
     */
    protected function upgrade(array $payload): array
    {
        if (array_key_exists('slides', $payload) || ! (isset($payload['title']) || isset($payload['media_id']) || isset($payload['legacy_image']))) {
            return $payload;
        }

        return [
            'layout' => 'full',
            'slides' => [[
                'media_id' => $payload['media_id'] ?? null,
                'media_mobile_id' => null,
                'legacy_image' => $payload['legacy_image'] ?? null,
                'title' => $payload['title'] ?? '',
                'text' => $payload['subtitle'] ?? null,
                'button_label' => $payload['button_label'] ?? null,
                'target' => $payload['button_target'] ?? null,
                'text_color' => $payload['text_color'] ?? 'light',
                'overlay' => 40,
                'visible_from' => null,
                'visible_to' => null,
            ]],
            'side' => [],
            'autoplay' => 5,
            'height' => 'medium',
        ];
    }

    public function summary(array $payload): string
    {
        $slides = $payload['slides'] ?? [];
        $first = $this->short($slides[0]['title'] ?? '', 30);
        $count = count($slides);

        return 'Banner principal — '.($first !== '' ? $first : $count.' '.($count === 1 ? 'lámina' : 'láminas'))
            .(($payload['layout'] ?? 'full') === 'main_side' ? ' · con laterales' : '');
    }

    public function isEmpty(array $payload): bool
    {
        return ($payload['slides'] ?? []) === [];
    }

    protected function ownIssues(array $payload, array $media): array
    {
        $issues = [];

        if (($payload['slides'] ?? []) === []) {
            $issues[] = ['level' => 'block', 'message' => 'Necesita al menos una lámina.'];
        }

        foreach ($payload['slides'] ?? [] as $index => $slide) {
            $where = 'La lámina '.($index + 1);

            if (empty($slide['legacy_image'])) {
                array_push($issues, ...$this->imageIssues($slide['media_id'] ?? null, $media, $where));
            }

            array_push($issues, ...$this->imageIssues($slide['media_mobile_id'] ?? null, $media, $where.' (celular)', required: false));
        }

        foreach ($payload['side'] ?? [] as $index => $side) {
            if (($side['kind'] ?? 'image') === 'image') {
                array_push($issues, ...$this->imageIssues($side['media_id'] ?? null, $media, 'El banner lateral '.($index + 1)));
            }
        }

        return $issues;
    }
}

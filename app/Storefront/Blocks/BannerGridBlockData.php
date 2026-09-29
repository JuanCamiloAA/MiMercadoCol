<?php

namespace App\Storefront\Blocks;

use App\Models\StorefrontBlock;

/**
 * Mosaico de banners: de dos a cuatro piezas, cada una imagen o color con texto, con destino.
 */
class BannerGridBlockData extends BlockData
{
    /** Cuantas piezas pide cada diseño. */
    public const LAYOUTS = ['2' => 2, '3' => 3, '1+2' => 3, '4' => 4];

    public function type(): string
    {
        return 'banner_grid';
    }

    public function label(): string
    {
        return 'Mosaico de banners';
    }

    public function icon(): string
    {
        return 'SquaresFour';
    }

    public function help(): string
    {
        return 'Dos a cuatro banners juntos: categorías destacadas, temporadas, marcas.';
    }

    public function pages(): array
    {
        return [StorefrontBlock::PAGE_HOME, StorefrontBlock::PAGE_CATEGORY, StorefrontBlock::PAGE_SEARCH];
    }

    public function fields(string $context = self::CONTEXT_STORE): array
    {
        return [
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 50, 'help' => 'Opcional.'],
            'layout' => [
                'type' => 'select',
                'label' => 'Diseño',
                'options' => self::options(['2' => 'Dos iguales', '3' => 'Tres iguales', '1+2' => 'Uno grande y dos pequeños', '4' => 'Cuatro iguales']),
            ],
            'tiles' => [
                'type' => 'repeater',
                'label' => 'Banners',
                'singular' => 'banner',
                'max_items' => 4,
                'item_defaults' => ['kind' => 'image', 'tone' => 'accent'],
                'item' => [
                    'kind' => ['type' => 'select', 'label' => 'Qué lleva', 'options' => self::options(['image' => 'Una imagen', 'color' => 'Un color con texto'])],
                    'media_id' => ['type' => 'media', 'label' => 'Imagen para computador', 'show_if' => ['field' => 'kind', 'value' => 'image']],
                    'media_mobile_id' => ['type' => 'media', 'label' => 'Imagen para celular', 'help' => 'Opcional.', 'show_if' => ['field' => 'kind', 'value' => 'image']],
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
        ];
    }

    public function defaults(): array
    {
        return ['title' => null, 'layout' => '2', 'tiles' => []];
    }

    public function summary(array $payload): string
    {
        $count = count($payload['tiles'] ?? []);

        return 'Mosaico — '.$count.' '.($count === 1 ? 'banner' : 'banners');
    }

    public function isEmpty(array $payload): bool
    {
        return ($payload['tiles'] ?? []) === [];
    }

    protected function ownIssues(array $payload, array $media): array
    {
        $issues = [];
        $needed = self::LAYOUTS[(string) ($payload['layout'] ?? '2')] ?? 2;
        $count = count($payload['tiles'] ?? []);

        if ($count < $needed) {
            $issues[] = ['level' => 'warn', 'message' => 'El diseño pide '.$needed.' banners y hay '.$count.'.'];
        }

        foreach ($payload['tiles'] ?? [] as $index => $tile) {
            if (($tile['kind'] ?? 'image') === 'image') {
                $where = 'El banner '.($index + 1).' del mosaico';
                array_push($issues, ...$this->imageIssues($tile['media_id'] ?? null, $media, $where));
                array_push($issues, ...$this->imageIssues($tile['media_mobile_id'] ?? null, $media, $where.' (celular)', required: false));
            } elseif (trim((string) ($tile['title'] ?? '')) === '') {
                $issues[] = ['level' => 'block', 'message' => 'El banner '.($index + 1).' del mosaico es de color y no tiene título.'];
            }
        }

        return $issues;
    }
}

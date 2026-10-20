<?php

namespace App\Storefront\Blocks;

use App\Models\StorefrontBlock;
use App\Support\MediaVariants;

/**
 * «Compra el look»: una foto con puntos numerados y, al lado, las prendas que lleva, con su precio
 * vigente y su talla. «Agregar el look» suma todas al carrito.
 *
 * El punto 1 es la prenda 1, el 2 la 2, y así. En una tienda las prendas se eligen; una plantilla
 * no conoce los artículos de nadie y toma los más vendidos o lo nuevo de cada tienda (`source`).
 */
class LookBlockData extends BlockData
{
    /** Cuántas prendas lleva un look, como mucho. */
    public const MAX_ITEMS = 4;

    public const SOURCES = [
        'manual' => 'Las que elijo',
        'best_sellers' => 'Las más vendidas',
        'new_arrivals' => 'Lo nuevo',
    ];

    public function type(): string
    {
        return 'look';
    }

    public function label(): string
    {
        return 'Compra el look';
    }

    public function icon(): string
    {
        return 'TShirt';
    }

    public function help(): string
    {
        return 'Una foto con puntos sobre cada prenda y la lista para llevarlas todas de una vez.';
    }

    public function pages(): array
    {
        return [StorefrontBlock::PAGE_HOME, StorefrontBlock::PAGE_PRODUCT];
    }

    public function fields(string $context = self::CONTEXT_STORE): array
    {
        $template = $context === self::CONTEXT_TEMPLATE;

        $fields = [
            ...MediaVariants::field('media_id', ['type' => 'media', 'label' => 'Foto del look', 'help' => 'Vertical, con la persona completa. Los puntos se ubican igual en todas las versiones.']),
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 50],
            'text' => ['type' => 'text', 'label' => 'Texto', 'max' => 140],
            'button_label' => ['type' => 'text', 'label' => 'Texto del botón', 'max' => 30],
            'source' => [
                'type' => 'select',
                'label' => 'Qué prendas',
                'options' => self::options($template ? array_diff_key(self::SOURCES, ['manual' => true]) : self::SOURCES),
            ],
            'product_ids' => [
                'type' => 'products',
                'label' => 'Prendas',
                'help' => 'Hasta '.self::MAX_ITEMS.'. La primera es el punto 1, la segunda el 2…',
                'show_if' => ['field' => 'source', 'value' => 'manual'],
            ],
            'count' => [
                'type' => 'number',
                'label' => 'Cuántas',
                'min' => 1,
                'max' => self::MAX_ITEMS,
                'show_if' => ['field' => 'source', 'value' => ['best_sellers', 'new_arrivals']],
            ],
            'hotspots' => [
                'type' => 'repeater',
                'label' => 'Puntos sobre la foto',
                'singular' => 'punto',
                'max_items' => self::MAX_ITEMS,
                'help' => 'Dónde va cada número, en porcentaje desde la esquina de arriba a la izquierda.',
                'item_defaults' => ['x' => 50, 'y' => 50],
                'item' => [
                    'x' => ['type' => 'number', 'label' => 'De izquierda a derecha (%)', 'min' => 0, 'max' => 100],
                    'y' => ['type' => 'number', 'label' => 'De arriba a abajo (%)', 'min' => 0, 'max' => 100],
                ],
            ],
        ];

        if ($template) {
            unset($fields['product_ids']);
        }

        return $fields;
    }

    public function defaults(): array
    {
        return [
            'media_id' => null,
            'title' => 'Compra el look',
            'text' => null,
            'button_label' => 'Agregar el look completo',
            'source' => 'best_sellers',
            'product_ids' => [],
            'count' => 3,
            'hotspots' => [],
        ];
    }

    protected function ownViolations(array $payload): array
    {
        return ($payload['source'] ?? 'best_sellers') === 'manual' || $this->ids($payload['product_ids'] ?? []) !== []
            ? ['Una plantilla no puede elegir prendas de una empresa: usa «las más vendidas» o «lo nuevo».']
            : [];
    }

    public function summary(array $payload): string
    {
        $title = $this->short($payload['title'] ?? '');

        return 'Compra el look — '.($title !== '' ? $title : mb_strtolower(self::SOURCES[$payload['source'] ?? 'best_sellers'] ?? ''));
    }

    public function isEmpty(array $payload): bool
    {
        return empty($payload['media_id'])
            || (($payload['source'] ?? 'best_sellers') === 'manual' && $this->ids($payload['product_ids'] ?? []) === []);
    }

    protected function ownIssues(array $payload, array $media): array
    {
        return [
            ...$this->imageIssues($payload['media_id'] ?? null, $media, 'La foto del look'),
            ...$this->variantIssues($this->fields(), 'media_id', $payload, $media, 'La foto del look'),
        ];
    }
}

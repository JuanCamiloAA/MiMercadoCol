<?php

namespace App\Storefront\Blocks;

use App\Models\StorefrontBlock;
use App\Support\MediaVariants;

/** Carrusel de promociones con sus propias imagenes, cada una con su destino y su vigencia. */
class PromoCarouselBlockData extends BlockData
{
    public function type(): string
    {
        return 'promo_carousel';
    }

    public function label(): string
    {
        return 'Carrusel';
    }

    public function icon(): string
    {
        return 'Images';
    }

    public function help(): string
    {
        return 'Varias imágenes que se pasan de lado, cada una con su destino.';
    }

    public function pages(): array
    {
        return [StorefrontBlock::PAGE_HOME, StorefrontBlock::PAGE_CATEGORY, StorefrontBlock::PAGE_SEARCH];
    }

    public function fields(string $context = self::CONTEXT_STORE): array
    {
        return [
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 50, 'help' => 'Opcional.'],
            'slides' => [
                'type' => 'repeater',
                'label' => 'Láminas',
                'singular' => 'lámina',
                'max_items' => 8,
                'item' => [
                    ...MediaVariants::field('media_id', ['type' => 'media', 'label' => 'Imagen', 'required' => true]),
                    'title' => ['type' => 'text', 'label' => 'Título', 'max' => 50],
                    'target' => ['type' => 'target', 'label' => 'A dónde lleva', 'kinds' => self::targetKinds($context)],
                    'visible_from' => ['type' => 'datetime', 'label' => 'Se ve desde', 'help' => 'Vacío: desde ya.'],
                    'visible_to' => ['type' => 'datetime', 'label' => 'Se ve hasta', 'help' => 'Vacío: sin fin.'],
                ],
            ],
            'size' => [
                'type' => 'select',
                'label' => 'Tamaño',
                'options' => self::options(['small' => 'Pequeño', 'medium' => 'Mediano', 'large' => 'Grande']),
            ],
            'per_view' => [
                'type' => 'select',
                'label' => 'Cuántas se ven a la vez en computador',
                'options' => self::options([1 => 'Una', 2 => 'Dos', 3 => 'Tres']),
            ],
            'autoplay' => [
                'type' => 'select',
                'label' => 'Pasar solas',
                'options' => self::options([0 => 'No', 4 => 'Cada 4 segundos', 6 => 'Cada 6 segundos', 8 => 'Cada 8 segundos']),
            ],
        ];
    }

    public function defaults(): array
    {
        return ['title' => null, 'slides' => [], 'size' => 'medium', 'per_view' => 2, 'autoplay' => 0];
    }

    public function summary(array $payload): string
    {
        $count = count($payload['slides'] ?? []);

        return 'Carrusel — '.$count.' '.($count === 1 ? 'lámina' : 'láminas');
    }

    public function isEmpty(array $payload): bool
    {
        return ($payload['slides'] ?? []) === [];
    }

    protected function ownIssues(array $payload, array $media): array
    {
        $issues = [];

        foreach ($payload['slides'] ?? [] as $index => $slide) {
            $where = 'La lámina '.($index + 1).' del carrusel';
            array_push($issues, ...$this->imageIssues($slide['media_id'] ?? null, $media, $where));
            array_push($issues, ...$this->variantIssues($this->fields()['slides']['item'], 'media_id', $slide, $media, $where));
        }

        return $issues;
    }
}

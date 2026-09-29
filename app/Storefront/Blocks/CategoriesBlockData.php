<?php

namespace App\Storefront\Blocks;

/**
 * Los estantes de la tienda: en circulos con icono, en mosaicos con foto o en chips.
 *
 * El icono y la foto salen de la ficha de cada categoria (panel > Categorias).
 */
class CategoriesBlockData extends BlockData
{
    /** @var array<string, string> */
    public const SOURCES = [
        'all' => 'Todas las activas',
        'top' => 'Las más vendidas en la sede',
        'manual' => 'Las que yo elija',
    ];

    public function type(): string
    {
        return 'categories';
    }

    public function label(): string
    {
        return 'Categorías';
    }

    public function icon(): string
    {
        return 'SquaresFour';
    }

    public function help(): string
    {
        return 'Los estantes de la tienda, para que el cliente llegue rápido a lo suyo.';
    }

    public function fields(string $context = self::CONTEXT_STORE): array
    {
        $sources = $context === self::CONTEXT_TEMPLATE
            ? array_intersect_key(self::SOURCES, array_flip(['all', 'top']))
            : self::SOURCES;

        $fields = [
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 40],
            'style' => [
                'type' => 'select',
                'label' => 'Cómo se ven',
                'options' => self::options(['icon_circles' => 'Círculos con ícono', 'image_tiles' => 'Mosaicos con foto', 'chips' => 'Etiquetas']),
            ],
            'source' => ['type' => 'select', 'label' => 'Cuáles se muestran', 'options' => self::options($sources)],
            'category_ids' => [
                'type' => 'categories',
                'label' => 'Categorías',
                'show_if' => ['field' => 'source', 'value' => 'manual'],
            ],
            'limit' => ['type' => 'number', 'label' => 'Cuántas', 'min' => 2, 'max' => 24],
        ];

        if ($context === self::CONTEXT_TEMPLATE) {
            unset($fields['category_ids']);
        }

        return $fields;
    }

    public function defaults(): array
    {
        return ['title' => 'Compra por categoría', 'style' => 'icon_circles', 'source' => 'all', 'category_ids' => [], 'limit' => 12];
    }

    protected function ownViolations(array $payload): array
    {
        return ($payload['source'] ?? 'all') === 'manual' || $this->ids($payload['category_ids'] ?? []) !== []
            ? ['Una plantilla no puede elegir categorías de una empresa: usa «todas» o «las más vendidas».']
            : [];
    }

    public function summary(array $payload): string
    {
        return match ($payload['source'] ?? 'all') {
            'manual' => 'Categorías — '.count($payload['category_ids'] ?? []).' elegidas',
            'top' => 'Categorías — las '.(int) ($payload['limit'] ?? 12).' más vendidas',
            default => 'Categorías — todas las activas',
        };
    }

    public function isEmpty(array $payload): bool
    {
        return ($payload['source'] ?? 'all') === 'manual' && ($payload['category_ids'] ?? []) === [];
    }
}

<?php

namespace App\Storefront\Blocks;

use App\Models\StorefrontBlock;

/**
 * Estante de productos.
 *
 * El origen es la regla del bloque y es lo que lo distingue en la lista: dos estantes sin su
 * regla a la vista son indistinguibles.
 */
class ProductGridBlockData extends BlockData
{
    /** @var array<string, string> */
    public const SOURCES = [
        'featured' => 'Los destacados',
        'best_sellers' => 'Los más vendidos',
        'on_sale' => 'Todo lo que esté en promoción',
        'new_arrivals' => 'Lo último que llegó',
        'buy_again' => 'Vuelve a comprar (lo que ya compró el cliente)',
        'promotion' => 'Los de una promoción',
        'category' => 'Los de una categoría',
        'manual' => 'Una lista que yo elijo',
    ];

    /** Lo que admite una plantilla: reglas que no nombran filas de una empresa. */
    public const TEMPLATE_SOURCES = ['featured', 'best_sellers', 'on_sale', 'new_arrivals', 'buy_again'];

    public function type(): string
    {
        return 'product_grid';
    }

    public function label(): string
    {
        return 'Estante de productos';
    }

    public function icon(): string
    {
        return 'Package';
    }

    public function help(): string
    {
        return 'Una fila de artículos con su regla: ofertas, más vendidos, lo nuevo, «vuelve a comprar» o los que elijas.';
    }

    public function pages(): array
    {
        return StorefrontBlock::PAGES;
    }

    public function fields(string $context = self::CONTEXT_STORE): array
    {
        $sources = $context === self::CONTEXT_TEMPLATE
            ? array_intersect_key(self::SOURCES, array_flip(self::TEMPLATE_SOURCES))
            : self::SOURCES;

        $fields = [
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 40],
            'source' => [
                'type' => 'select',
                'label' => 'Qué artículos muestra',
                'help' => 'Los destacados y los de una categoría incluyen también los servicios publicados. «Vuelve a comprar» solo aparece a un cliente con sesión y compras.',
                'options' => self::options($sources),
            ],
            'value' => [
                'type' => 'select',
                'label' => 'Categoría',
                'options_from' => 'categories',
                'show_if' => ['field' => 'source', 'value' => 'category'],
            ],
            'promotion_id' => [
                'type' => 'select',
                'label' => 'Promoción',
                'options_from' => 'promotions',
                'show_if' => ['field' => 'source', 'value' => 'promotion'],
            ],
            'product_ids' => [
                'type' => 'products',
                'label' => 'Artículos',
                'help' => 'Este bloque muestra artículos: los servicios no se eligen uno por uno.',
                'show_if' => ['field' => 'source', 'value' => 'manual'],
            ],
            'layout' => [
                'type' => 'select',
                'label' => 'Cómo se ve',
                'options' => self::options(['carousel' => 'Fila que se desliza, con flechas', 'grid' => 'Rejilla']),
            ],
            'columns_desktop' => [
                'type' => 'select',
                'label' => 'Columnas en computador',
                'help' => 'Con 2 o 3 las fotos salen grandes, como en las tiendas de moda.',
                'options' => self::options([2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6']),
            ],
            'title_align' => [
                'type' => 'select',
                'label' => 'Título',
                'options' => self::options(['left' => 'A la izquierda', 'center' => 'Centrado']),
            ],
            'arrows' => [
                'type' => 'select',
                'label' => 'Flechas de la fila',
                'options' => self::options(['side' => 'A los lados', 'top' => 'Redondas, junto al título']),
                'show_if' => ['field' => 'layout', 'value' => 'carousel'],
            ],
            'scrollbar' => [
                'type' => 'toggle',
                'label' => 'Barra de avance debajo de la fila',
                'show_if' => ['field' => 'layout', 'value' => 'carousel'],
            ],
            'columns_mobile' => [
                'type' => 'select',
                'label' => 'En celular',
                'options' => self::options(['2' => 'Dos columnas', 'row' => 'Fila que se desliza']),
            ],
            'limit' => ['type' => 'number', 'label' => 'Cuántos', 'min' => 2, 'max' => 24],
            'see_all_target' => [
                'type' => 'target',
                'label' => 'Enlace «Ver todo»',
                'help' => 'Opcional. En los de una categoría y en ofertas se pone solo.',
                'kinds' => self::targetKinds($context),
            ],
            'see_all_label' => ['type' => 'text', 'label' => 'Texto del enlace', 'max' => 24, 'help' => 'Vacío: «Ver todo». Por ejemplo «Ver todas» o «Ver más».'],
            'show_price_per_unit' => [
                'type' => 'toggle',
                'label' => 'Mostrar el precio por unidad de medida',
                'help' => 'Por ejemplo «$ 5.100 x L», útil para comparar presentaciones.',
            ],
        ];

        if ($context === self::CONTEXT_TEMPLATE) {
            unset($fields['value'], $fields['promotion_id'], $fields['product_ids']);
        }

        return $fields;
    }

    public function defaults(): array
    {
        return [
            'title' => 'Destacados de hoy',
            'source' => 'featured',
            'value' => null,
            'promotion_id' => null,
            'product_ids' => [],
            'layout' => 'carousel',
            'columns_desktop' => 5,
            'columns_mobile' => 'row',
            'limit' => 10,
            'see_all_target' => null,
            'see_all_label' => null,
            'show_price_per_unit' => true,
            'title_align' => 'left',
            'arrows' => 'side',
            'scrollbar' => false,
        ];
    }

    /** Antes habia una sola opcion de columnas, pensada para el celular. */
    protected function upgrade(array $payload): array
    {
        if (array_key_exists('columns', $payload) && ! array_key_exists('columns_mobile', $payload)) {
            $payload['columns_mobile'] = (int) $payload['columns'] === 2 ? '2' : 'row';
            $payload['layout'] ??= (int) $payload['columns'] === 2 ? 'grid' : 'carousel';
        }

        unset($payload['columns']);

        return $payload;
    }

    protected function ownViolations(array $payload): array
    {
        $found = [];

        if (! in_array($payload['source'] ?? 'featured', self::TEMPLATE_SOURCES, true)) {
            $found[] = 'Una plantilla no puede mostrar artículos, categorías ni promociones de una empresa: elige destacados, más vendidos, ofertas, lo nuevo o «vuelve a comprar».';
        }

        if ($this->ids($payload['product_ids'] ?? []) !== [] || ! empty($payload['promotion_id']) || ! empty($payload['value'])) {
            $found[] = 'Una plantilla no puede guardar artículos, categorías ni promociones por su id.';
        }

        return $found;
    }

    public function summary(array $payload): string
    {
        $title = $this->short($payload['title'] ?? '', 28);
        $source = self::SOURCES[$payload['source'] ?? 'featured'] ?? 'Artículos';
        $limit = (int) ($payload['limit'] ?? 8);

        return trim('Estante · '.($title !== '' ? $title.' — ' : '').mb_strtolower($source).' · '.$limit.' artículos');
    }

    public function isEmpty(array $payload): bool
    {
        return ($payload['source'] ?? '') === 'manual' && ($payload['product_ids'] ?? []) === [];
    }
}

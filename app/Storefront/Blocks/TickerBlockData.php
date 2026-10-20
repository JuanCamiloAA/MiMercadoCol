<?php

namespace App\Storefront\Blocks;

use App\Models\StorefrontBlock;

/**
 * Cinta que corre: frases cortas que pasan en bucle («Nueva colección · Envío gratis · …»). La
 * mueve el CSS; quien pidió menos movimiento la ve quieta, y se detiene al pasar el mouse.
 */
class TickerBlockData extends BlockData
{
    public function type(): string
    {
        return 'ticker';
    }

    public function label(): string
    {
        return 'Cinta que corre';
    }

    public function icon(): string
    {
        return 'Megaphone';
    }

    public function help(): string
    {
        return 'Frases cortas que pasan en bucle de lado a lado: colección nueva, envío gratis, cambios.';
    }

    public function pages(): array
    {
        return StorefrontBlock::PAGES;
    }

    public function fields(string $context = self::CONTEXT_STORE): array
    {
        return [
            'items' => [
                'type' => 'repeater',
                'label' => 'Frases',
                'singular' => 'frase',
                'max_items' => 8,
                'item' => [
                    'text' => ['type' => 'text', 'label' => 'Texto', 'max' => 60, 'required' => true],
                ],
            ],
            'tone' => [
                'type' => 'select',
                'label' => 'Color',
                'options' => self::options(['accent' => 'De la marca', 'dark' => 'Oscuro', 'deal' => 'De oferta', 'soft' => 'Suave']),
            ],
            'speed' => [
                'type' => 'select',
                'label' => 'Velocidad',
                'options' => self::options(['slow' => 'Lenta', 'normal' => 'Normal', 'fast' => 'Rápida']),
            ],
            'separator' => ['type' => 'text', 'label' => 'Separador', 'max' => 3, 'help' => 'Lo que va entre frase y frase, por ejemplo «·» o «★».'],
        ];
    }

    public function defaults(): array
    {
        return ['items' => [], 'tone' => 'accent', 'speed' => 'normal', 'separator' => '·'];
    }

    public function summary(array $payload): string
    {
        $first = $this->short($payload['items'][0]['text'] ?? '', 30);

        return 'Cinta — '.($first !== '' ? $first : 'sin frases');
    }

    public function isEmpty(array $payload): bool
    {
        return collect($payload['items'] ?? [])->filter(fn ($item) => is_array($item) && trim((string) ($item['text'] ?? '')) !== '')->isEmpty();
    }
}

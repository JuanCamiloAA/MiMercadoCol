<?php

namespace App\Storefront\Blocks;

use App\Models\StorefrontBlock;

/** Preguntas frecuentes, plegables. */
class FaqBlockData extends BlockData
{
    public function type(): string
    {
        return 'faq';
    }

    public function label(): string
    {
        return 'Preguntas frecuentes';
    }

    public function icon(): string
    {
        return 'Question';
    }

    public function help(): string
    {
        return 'Las dudas que llegan por teléfono todos los días, resueltas de una vez.';
    }

    public function fields(string $context = self::CONTEXT_STORE): array
    {
        return [
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 50],
            'items' => [
                'type' => 'repeater',
                'label' => 'Preguntas',
                'singular' => 'pregunta',
                'max_items' => 10,
                'item' => [
                    'question' => ['type' => 'text', 'label' => 'Pregunta', 'max' => 120, 'required' => true],
                    'answer' => ['type' => 'textarea', 'label' => 'Respuesta', 'max' => 400, 'rows' => 3],
                ],
            ],
        ];
    }

    public function defaults(): array
    {
        return ['title' => 'Preguntas frecuentes', 'items' => []];
    }

    public function pages(): array
    {
        return [StorefrontBlock::PAGE_HOME, StorefrontBlock::PAGE_PRODUCT];
    }

    public function summary(array $payload): string
    {
        $count = count($payload['items'] ?? []);

        return 'Preguntas — '.$count.' '.($count === 1 ? 'pregunta' : 'preguntas');
    }

    public function isEmpty(array $payload): bool
    {
        return ($payload['items'] ?? []) === [];
    }
}

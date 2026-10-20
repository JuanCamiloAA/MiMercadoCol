<?php

namespace App\Storefront\Blocks;

use App\Models\StorefrontBlock;

/** Franja de avisos que va arriba de todo. */
class AnnouncementBlockData extends BlockData
{
    public function type(): string
    {
        return 'announcement';
    }

    public function label(): string
    {
        return 'Aviso';
    }

    public function icon(): string
    {
        return 'Megaphone';
    }

    public function help(): string
    {
        return 'Una franja angosta arriba de todo: un horario especial, un aviso de temporada.';
    }

    public function fields(string $context = self::CONTEXT_STORE): array
    {
        return [
            'text' => ['type' => 'text', 'label' => 'Texto', 'max' => 120, 'required' => true],
            'icon' => ['type' => 'icon', 'label' => 'Ícono', 'help' => 'Sin elegir: el megáfono.'],
            'link' => ['type' => 'target', 'label' => 'A dónde lleva', 'help' => 'Opcional.', 'kinds' => self::targetKinds($context)],
            'tone' => [
                'type' => 'select',
                'label' => 'Color',
                'options' => [
                    ['value' => 'accent', 'label' => 'De la marca'],
                    ['value' => 'warn', 'label' => 'De atención'],
                    ['value' => 'deal', 'label' => 'De oferta'],
                ],
            ],
        ];
    }

    public function defaults(): array
    {
        return ['text' => '', 'link' => null, 'tone' => 'accent', 'icon' => null];
    }

    public function pages(): array
    {
        return StorefrontBlock::PAGES;
    }

    public function summary(array $payload): string
    {
        $text = $this->short($payload['text'] ?? '');

        return $text === '' ? 'Aviso — sin texto' : 'Aviso — '.$text;
    }

    public function isEmpty(array $payload): bool
    {
        return trim((string) ($payload['text'] ?? '')) === '';
    }
}

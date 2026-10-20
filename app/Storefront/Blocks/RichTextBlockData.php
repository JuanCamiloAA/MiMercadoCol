<?php

namespace App\Storefront\Blocks;

use App\Models\StorefrontBlock;

/** Texto libre, con un markdown acotado. */
class RichTextBlockData extends BlockData
{
    public function type(): string
    {
        return 'rich_text';
    }

    public function label(): string
    {
        return 'Texto';
    }

    public function icon(): string
    {
        return 'TextAa';
    }

    public function help(): string
    {
        return 'Un mensaje libre: condiciones del domicilio, un aviso de temporada.';
    }

    public function fields(string $context = self::CONTEXT_STORE): array
    {
        return [
            'style' => [
                'type' => 'select',
                'label' => 'Estilo',
                'options' => self::options([
                    'plain' => 'Tarjeta sencilla',
                    'bold' => 'Título enorme en mayúsculas, centrado',
                    'serif' => 'Editorial, con letra de revista',
                ]),
            ],
            'eyebrow' => ['type' => 'text', 'label' => 'Antetítulo', 'max' => 40, 'help' => 'Opcional: una línea pequeña arriba del título.'],
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 60],
            'body' => [
                'type' => 'markdown',
                'label' => 'Texto',
                'max' => 800,
                'rows' => 6,
                'help' => 'Admite **negrita**, listas con «- » y enlaces [texto](destino).',
            ],
            'align' => [
                'type' => 'select',
                'label' => 'Alineación',
                'options' => [
                    ['value' => 'left', 'label' => 'Izquierda'],
                    ['value' => 'center', 'label' => 'Centrado'],
                ],
            ],
            'button_label' => ['type' => 'text', 'label' => 'Texto del botón', 'max' => 30, 'help' => 'Opcional.'],
            'target' => ['type' => 'target', 'label' => 'A dónde lleva el botón', 'kinds' => self::targetKinds($context)],
        ];
    }

    public function defaults(): array
    {
        return ['title' => null, 'body' => '', 'align' => 'left', 'style' => 'plain', 'eyebrow' => null, 'button_label' => null, 'target' => null];
    }

    public function pages(): array
    {
        return [StorefrontBlock::PAGE_HOME, StorefrontBlock::PAGE_PRODUCT];
    }

    public function summary(array $payload): string
    {
        $title = $this->short($payload['title'] ?? '');

        if ($title !== '') {
            return 'Texto — '.$title;
        }

        $body = $this->short($payload['body'] ?? '', 34);

        return $body === '' ? 'Texto — vacío' : 'Texto — '.$body;
    }

    public function isEmpty(array $payload): bool
    {
        return trim((string) ($payload['body'] ?? '')) === '' && trim((string) ($payload['title'] ?? '')) === '';
    }
}

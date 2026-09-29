<?php

namespace App\Storefront\Blocks;

use App\Models\StorefrontBlock;

/**
 * Oferta relampago: una promocion, su cuenta regresiva hasta que vence y sus articulos.
 *
 * Se va sola al vencer. Una promocion sin fecha de fin no tiene cuenta que llevar: no se pinta.
 */
class DealCountdownBlockData extends BlockData
{
    /** @var array<string, string> */
    public const SOURCES = [
        'next_ending' => 'La promoción que vence primero',
        'promotion' => 'Una promoción que yo elijo',
    ];

    public function type(): string
    {
        return 'deal_countdown';
    }

    public function label(): string
    {
        return 'Oferta relámpago';
    }

    public function icon(): string
    {
        return 'Lightning';
    }

    public function help(): string
    {
        return 'Una promoción con su cuenta regresiva y sus artículos. Desaparece sola cuando vence.';
    }

    public function pages(): array
    {
        return [StorefrontBlock::PAGE_HOME, StorefrontBlock::PAGE_CATEGORY, StorefrontBlock::PAGE_SEARCH, StorefrontBlock::PAGE_CART];
    }

    public function fields(string $context = self::CONTEXT_STORE): array
    {
        $fields = [
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 40, 'help' => 'Vacío: el nombre de la promoción.'],
            'source' => [
                'type' => 'select',
                'label' => 'Qué promoción',
                'options' => self::options($context === self::CONTEXT_TEMPLATE ? ['next_ending' => self::SOURCES['next_ending']] : self::SOURCES),
            ],
            'promotion_id' => [
                'type' => 'select',
                'label' => 'Promoción',
                'help' => 'Tiene que tener fecha de fin.',
                'options_from' => 'promotions',
                'show_if' => ['field' => 'source', 'value' => 'promotion'],
            ],
            'limit' => ['type' => 'number', 'label' => 'Cuántos artículos', 'min' => 2, 'max' => 16],
        ];

        if ($context === self::CONTEXT_TEMPLATE) {
            unset($fields['promotion_id']);
        }

        return $fields;
    }

    public function defaults(): array
    {
        return ['title' => null, 'source' => 'next_ending', 'promotion_id' => null, 'limit' => 8];
    }

    protected function ownViolations(array $payload): array
    {
        return ($payload['source'] ?? 'next_ending') !== 'next_ending' || ! empty($payload['promotion_id'])
            ? ['Una plantilla no puede elegir una promoción de una empresa: usa «la que vence primero».']
            : [];
    }

    public function summary(array $payload): string
    {
        $title = $this->short($payload['title'] ?? '', 28);

        return 'Oferta relámpago — '.($title !== '' ? $title : mb_strtolower(self::SOURCES[$payload['source'] ?? 'next_ending'] ?? ''));
    }

    public function isEmpty(array $payload): bool
    {
        return ($payload['source'] ?? '') === 'promotion' && empty($payload['promotion_id']);
    }
}

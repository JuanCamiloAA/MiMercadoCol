<?php

namespace App\Storefront\Blocks;

use App\Models\StorefrontBlock;
use Carbon\CarbonImmutable;

/**
 * Oferta relampago: una promocion, su cuenta regresiva hasta que vence y sus articulos.
 *
 * Se va sola al vencer. Una promocion sin fecha de fin no tiene cuenta que llevar: no se pinta.
 * El estilo «Lanzamiento» (`drop`) cuenta hasta su propia fecha y ofrece «Avísame» por WhatsApp.
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
            'style' => [
                'type' => 'select',
                'label' => 'Estilo',
                'options' => self::options([
                    'flash' => 'Oferta relámpago: la promoción que vence, con sus artículos',
                    'drop' => 'Lanzamiento: cuenta regresiva grande hasta una fecha',
                ]),
            ],
            'starts_at' => [
                'type' => 'datetime',
                'label' => 'Sale el',
                'help' => 'La fecha del lanzamiento. Cuando pasa, el bloque se va solo.',
                'show_if' => ['field' => 'style', 'value' => 'drop'],
            ],
            'text' => ['type' => 'text', 'label' => 'Texto', 'max' => 120, 'show_if' => ['field' => 'style', 'value' => 'drop']],
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
        return ['title' => null, 'source' => 'next_ending', 'promotion_id' => null, 'limit' => 8, 'style' => 'flash', 'starts_at' => null, 'text' => null];
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

        if (($payload['style'] ?? 'flash') === 'drop') {
            return 'Lanzamiento — '.($title !== '' ? $title : 'cuenta regresiva');
        }

        return 'Oferta relámpago — '.($title !== '' ? $title : mb_strtolower(self::SOURCES[$payload['source'] ?? 'next_ending'] ?? ''));
    }

    public function isEmpty(array $payload): bool
    {
        // Un lanzamiento sin fecha por venir no tiene nada que contar.
        if (($payload['style'] ?? 'flash') === 'drop') {
            return ! self::dropIsAhead($payload);
        }

        return ($payload['source'] ?? '') === 'promotion' && empty($payload['promotion_id']);
    }

    /** Si el lanzamiento tiene su fecha en el futuro. */
    public static function dropIsAhead(array $payload, ?CarbonImmutable $now = null): bool
    {
        if (empty($payload['starts_at'])) {
            return false;
        }

        try {
            return CarbonImmutable::parse((string) $payload['starts_at'], 'America/Bogota')->isAfter($now ?? CarbonImmutable::now());
        } catch (\Throwable) {
            return false;
        }
    }
}

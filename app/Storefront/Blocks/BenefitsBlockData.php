<?php

namespace App\Storefront\Blocks;

use App\Models\StorefrontBlock;

/** Banda de promesas cortas: domicilio, horario, formas de pago. */
class BenefitsBlockData extends BlockData
{
    public function type(): string
    {
        return 'benefits';
    }

    public function label(): string
    {
        return 'Beneficios';
    }

    public function icon(): string
    {
        return 'ShieldCheck';
    }

    public function help(): string
    {
        return 'Tres o cuatro promesas cortas con su ícono.';
    }

    public function fields(string $context = self::CONTEXT_STORE): array
    {
        return [
            'style' => [
                'type' => 'select',
                'label' => 'Cómo se ven',
                'options' => self::options(['cards' => 'En una tarjeta, con círculos', 'inline' => 'En una fila con líneas finas']),
            ],
            'items' => [
                'type' => 'repeater',
                'label' => 'Beneficios',
                'singular' => 'beneficio',
                'max_items' => 4,
                'item' => [
                    'icon' => ['type' => 'icon', 'label' => 'Ícono'],
                    'title' => ['type' => 'text', 'label' => 'Título', 'max' => 30, 'required' => true],
                    'text' => ['type' => 'text', 'label' => 'Detalle', 'max' => 60],
                ],
            ],
        ];
    }

    public function defaults(): array
    {
        return [
            'style' => 'cards',
            'items' => [
                ['icon' => 'Truck', 'title' => 'Domicilio en 40 min', 'text' => 'En toda la comuna'],
                ['icon' => 'Clock', 'title' => 'Abierto 7 a 9', 'text' => 'Todos los días'],
                ['icon' => 'CreditCard', 'title' => 'Paga contraentrega', 'text' => 'Efectivo o tarjeta'],
            ],
        ];
    }

    public function pages(): array
    {
        return [StorefrontBlock::PAGE_HOME, StorefrontBlock::PAGE_PRODUCT];
    }

    public function summary(array $payload): string
    {
        $count = count($payload['items'] ?? []);

        return 'Beneficios — '.$count.' '.($count === 1 ? 'promesa' : 'promesas');
    }

    public function isEmpty(array $payload): bool
    {
        return ($payload['items'] ?? []) === [];
    }
}

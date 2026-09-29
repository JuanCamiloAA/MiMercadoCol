<?php

namespace App\Landing\Blocks;

/** Los pasos: elige tienda, regístrate, compra, recibe. */
class HowItWorksBlock extends LandingBlock
{
    public function type(): string
    {
        return 'how_it_works';
    }

    public function label(): string
    {
        return 'Cómo funciona';
    }

    public function icon(): string
    {
        return 'ListNumbers';
    }

    public function help(): string
    {
        return 'Los pasos para comprar, con su ícono.';
    }

    public function fields(): array
    {
        return [
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 60],
            'steps' => self::repeater('Pasos', 'paso', [
                'icon' => ['type' => 'icon', 'label' => 'Ícono', 'default' => 'Sparkle'],
                'title' => ['type' => 'text', 'label' => 'Título', 'max' => 40],
                'text' => ['type' => 'textarea', 'label' => 'Texto', 'max' => 160],
            ], 6),
        ];
    }

    public function defaults(): array
    {
        return [
            'title' => 'Así de fácil',
            'steps' => [
                ['icon' => 'MapPin', 'title' => 'Elige tu tienda', 'text' => 'La más cercana, o la de siempre.'],
                ['icon' => 'UserPlus', 'title' => 'Regístrate', 'text' => 'Con tu documento y tu correo, en un minuto.'],
                ['icon' => 'ShoppingCart', 'title' => 'Arma tu pedido', 'text' => 'Con los precios y promociones de la tienda.'],
                ['icon' => 'Truck', 'title' => 'Recíbelo', 'text' => 'A domicilio o recógelo en la sede.'],
            ],
        ];
    }
}

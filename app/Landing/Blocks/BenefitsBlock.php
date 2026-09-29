<?php

namespace App\Landing\Blocks;

/** Tarjetas de beneficios con ícono, título y texto. */
class BenefitsBlock extends LandingBlock
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
        return 'Tarjetas cortas con lo que gana quien usa la plataforma.';
    }

    public function fields(): array
    {
        return [
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 60],
            'text' => ['type' => 'textarea', 'label' => 'Texto', 'max' => 200],
            'items' => self::repeater('Tarjetas', 'tarjeta', [
                'icon' => ['type' => 'icon', 'label' => 'Ícono', 'default' => 'Sparkle'],
                'title' => ['type' => 'text', 'label' => 'Título', 'max' => 40],
                'text' => ['type' => 'textarea', 'label' => 'Texto', 'max' => 160],
            ], 6),
        ];
    }

    public function defaults(): array
    {
        return [
            'title' => 'Para los mercados de barrio',
            'text' => 'Todo lo que un mercado necesita para vender en caja y en línea, en un solo lugar.',
            'items' => [
                ['icon' => 'CashRegister', 'title' => 'Caja rápida', 'text' => 'Cobros en efectivo, tarjeta y crédito, con cierre de caja cuadrado.'],
                ['icon' => 'Package', 'title' => 'Inventario al día', 'text' => 'Cada venta descuenta; cada compra suma y recalcula el costo.'],
                ['icon' => 'Storefront', 'title' => 'Tienda en línea', 'text' => 'Tu catálogo con tus precios, listo para pedidos a domicilio.'],
            ],
        ];
    }
}

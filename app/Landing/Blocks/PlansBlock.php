<?php

namespace App\Landing\Blocks;

/**
 * Los planes públicos con su conmutador de ciclo. Los precios los calcula
 * `MembershipPricing` en `LandingRenderer`: el bloque solo pone los textos.
 */
class PlansBlock extends LandingBlock
{
    public function type(): string
    {
        return 'plans';
    }

    public function label(): string
    {
        return 'Planes';
    }

    public function icon(): string
    {
        return 'CrownSimple';
    }

    public function help(): string
    {
        return 'Los planes públicos y activos, con el precio de cada ciclo.';
    }

    public function unique(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 60],
            'text' => ['type' => 'textarea', 'label' => 'Texto', 'max' => 200],
            'featured_label' => ['type' => 'text', 'label' => 'Etiqueta del plan destacado', 'max' => 24],
            'cta_label' => ['type' => 'text', 'label' => 'Botón de cada plan', 'max' => 24],
        ];
    }

    public function defaults(): array
    {
        return [
            'title' => 'Planes para cada mercado',
            'text' => 'Paga mes a mes o ahorra con un ciclo más largo. Cambias de plan cuando quieras.',
            'featured_label' => 'Más elegido',
            'cta_label' => 'Contratar',
        ];
    }
}

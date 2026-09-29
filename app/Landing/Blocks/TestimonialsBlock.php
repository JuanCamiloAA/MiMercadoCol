<?php

namespace App\Landing\Blocks;

/** Lo que dicen los clientes. */
class TestimonialsBlock extends LandingBlock
{
    public function type(): string
    {
        return 'testimonials';
    }

    public function label(): string
    {
        return 'Testimonios';
    }

    public function icon(): string
    {
        return 'Quotes';
    }

    public function help(): string
    {
        return 'Citas cortas de quienes ya usan la plataforma.';
    }

    public function fields(): array
    {
        return [
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 60],
            'items' => self::repeater('Testimonios', 'testimonio', [
                'quote' => ['type' => 'textarea', 'label' => 'Cita', 'max' => 280],
                'name' => ['type' => 'text', 'label' => 'Nombre', 'max' => 60],
                'role' => ['type' => 'text', 'label' => 'Quién es', 'max' => 80],
            ], 6),
        ];
    }

    public function defaults(): array
    {
        return [
            'title' => 'Lo que dicen',
            'items' => [
                ['quote' => 'Ahora cuadro la caja en cinco minutos y los pedidos del barrio llegan solos.', 'name' => 'Gloria M.', 'role' => 'Dueña de un mercado en Itagüí'],
                ['quote' => 'Pido a las 8 y a las 10 ya tengo el mercado en la casa, con los precios de siempre.', 'name' => 'Andrés R.', 'role' => 'Cliente en Envigado'],
            ],
        ];
    }
}

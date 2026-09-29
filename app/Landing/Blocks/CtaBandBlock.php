<?php

namespace App\Landing\Blocks;

/** Una franja con un llamado y un botón. */
class CtaBandBlock extends LandingBlock
{
    public function type(): string
    {
        return 'cta_band';
    }

    public function label(): string
    {
        return 'Llamado a la acción';
    }

    public function icon(): string
    {
        return 'Megaphone';
    }

    public function help(): string
    {
        return 'Una franja de color con un mensaje y un botón.';
    }

    public function fields(): array
    {
        return [
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 80],
            'text' => ['type' => 'textarea', 'label' => 'Texto', 'max' => 200],
            'button_label' => ['type' => 'text', 'label' => 'Botón', 'max' => 30],
            'button_url' => ['type' => 'url', 'label' => 'Destino del botón'],
            'tone' => ['type' => 'select', 'label' => 'Color', 'options' => [
                ['value' => 'accent', 'label' => 'Color de la marca'],
                ['value' => 'dark', 'label' => 'Oscuro'],
            ]],
        ];
    }

    public function defaults(): array
    {
        return [
            'title' => '¿Tienes un mercado? Véndelo también en línea',
            'text' => 'Caja, inventario y tienda en línea en una sola cuenta. Empieza hoy.',
            'button_label' => 'Ver planes',
            'button_url' => '#planes',
            'tone' => 'accent',
        ];
    }
}

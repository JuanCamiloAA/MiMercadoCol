<?php

namespace App\Landing\Blocks;

/** Pie de página: enlaces, redes, texto legal y los enlaces a términos y datos. Obligatorio. */
class FooterBlock extends LandingBlock
{
    public function type(): string
    {
        return 'footer';
    }

    public function label(): string
    {
        return 'Pie de página';
    }

    public function icon(): string
    {
        return 'Rows';
    }

    public function help(): string
    {
        return 'Enlaces, redes y textos legales. Los términos y la política de datos se editan en Ajustes de la landing.';
    }

    public function unique(): bool
    {
        return true;
    }

    public function deletable(): bool
    {
        return false;
    }

    public function hideable(): bool
    {
        return false;
    }

    public function fields(): array
    {
        return [
            'about' => ['type' => 'textarea', 'label' => 'Texto', 'max' => 240],
            'links' => self::repeater('Enlaces', 'enlace', [
                'label' => ['type' => 'text', 'label' => 'Texto', 'max' => 30],
                'url' => ['type' => 'url', 'label' => 'Destino'],
            ], 8),
            'social' => self::repeater('Redes', 'red', [
                'network' => ['type' => 'select', 'label' => 'Red', 'default' => 'instagram', 'options' => [
                    ['value' => 'instagram', 'label' => 'Instagram'],
                    ['value' => 'facebook', 'label' => 'Facebook'],
                    ['value' => 'tiktok', 'label' => 'TikTok'],
                    ['value' => 'youtube', 'label' => 'YouTube'],
                    ['value' => 'linkedin', 'label' => 'LinkedIn'],
                ]],
                'url' => ['type' => 'url', 'label' => 'Enlace'],
            ], 5),
            'legal' => ['type' => 'text', 'label' => 'Línea legal', 'max' => 160],
        ];
    }

    public function defaults(): array
    {
        return [
            'about' => 'La plataforma de los mercados de barrio: caja, inventario y tienda en línea.',
            'links' => [
                ['label' => 'Buscar tienda', 'url' => '#tiendas'],
                ['label' => 'Planes', 'url' => '#planes'],
                ['label' => 'Contratar', 'url' => '/contratar'],
                ['label' => 'Ingresar como empresa', 'url' => '/login'],
            ],
            'social' => [],
            'legal' => 'Todos los derechos reservados.',
        ];
    }
}
